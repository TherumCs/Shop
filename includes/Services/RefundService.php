<?php
/**
 * Shop by Therum — RefundService.
 *
 * Full and partial refunds. Flow:
 *
 *   1. Validate: order must be in a refundable status, requested amount
 *      ≤ (grand_total - already_refunded).
 *   2. Create a `pending` refund row.
 *   3. Call the PSP gateway: refund( order, amount, idempotency_key ).
 *      Gateway returns its refund ID (or throws).
 *   4. Mark the refund `completed`, bump `orders.refunded_total`,
 *      flip order status to `refunded` if fully refunded, else
 *      `partially_refunded` (we tag the column "refunded" once it's
 *      whole; partial uses a status note since the order state machine
 *      keeps `processing`/`completed` semantically meaningful).
 *   5. Release coupon redemptions for the customer.
 *   6. Note on the order.
 *
 * v1 supports refund-by-amount (the most common case). Refund-by-line
 * with per-line restock semantics lands in #5.x when admin UI exposes
 * the line picker.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Models\Order;
use Shop\Repositories\OrderRepository;
use Shop\Repositories\PaymentGatewayRegistry;
use Shop\Repositories\RefundRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class RefundService {

	private const REFUNDABLE_STATUSES = [ 'processing', 'completed', 'on-hold' ];

	public function __construct(
		private readonly RefundRepository $refunds,
		private readonly OrderRepository $orders,
		private readonly PaymentGatewayRegistry $gateways,
		private readonly CouponService $coupons,
	) {}

	/**
	 * Issue a refund for `amountCents` against `order`. Returns refund id.
	 *
	 * @throws \DomainException on validation failure
	 * @throws \RuntimeException on gateway failure
	 */
	public function refund(
		Order $order,
		int $amountCents,
		string $reason = 'customer_request',
		string $initiatedBy = 'admin',
		?int $userId = null,
	): int {
		if ( ! in_array( $order->status, self::REFUNDABLE_STATUSES, true ) ) {
			throw new \DomainException( "Order #{$order->number} is not refundable from status '{$order->status}'." );
		}
		if ( $amountCents <= 0 ) {
			throw new \DomainException( 'Refund amount must be positive.' );
		}

		$already_refunded = $order->refundedTotal->minor;
		$max_refundable   = $order->grandTotal->minor - $already_refunded;
		if ( $amountCents > $max_refundable ) {
			throw new \DomainException( sprintf(
				'Refund amount exceeds refundable balance (%s available).',
				\Shop\Money::cents( $max_refundable, $order->currency )->format(),
			) );
		}
		if ( $order->paymentProvider === null || $order->paymentIntentId === null ) {
			throw new \DomainException( 'Order has no payment to refund against.' );
		}

		// Create pending refund row first so we have an idempotency key.
		$refund = $this->refunds->create(
			orderId:          $order->id,
			amountMinor:      $amountCents,
			reason:           $reason,
			initiatedBy:      $initiatedBy,
			refundedByUserId: $userId,
		);

		// Hand off to the PSP gateway. If this throws, mark refund failed
		// and let the caller decide (probably re-raise).
		try {
			$gateway = $this->gateways->get( $order->paymentProvider );
			$gateway_id = $gateway->refund(
				order:          $order,
				amount:         \Shop\Money::cents( $amountCents, $order->currency ),
				idempotencyKey: $refund->uuid,
			);
		} catch ( \Throwable $e ) {
			$this->refunds->markFailed( $refund->id, $e->getMessage() );
			throw new \RuntimeException( 'Gateway refund failed: ' . $e->getMessage(), 0, $e );
		}

		DB::tx( function () use ( $order, $refund, $amountCents, $gateway_id ): void {
			$this->refunds->markComplete( $refund->id, $order->paymentProvider, $gateway_id );

			$total_refunded_after = $this->refunds->totalRefundedFor( $order->id );
			DB::pdo()->prepare(
				"UPDATE orders
				    SET refunded_total = :t,
				        status = CASE WHEN :t >= grand_total THEN 'refunded' ELSE status END,
				        updated_at = unixepoch()
				  WHERE id = :i"
			)->execute( [ ':t' => $total_refunded_after, ':i' => $order->id ] );

			$is_full = $total_refunded_after >= $order->grandTotal->minor;

			$this->orders->note(
				orderId:      $order->id,
				content:      sprintf(
					'%s refund of %s issued via %s (refund #%d, gateway %s).',
					$is_full ? 'Full' : 'Partial',
					\Shop\Money::cents( $amountCents, $order->currency )->format(),
					$order->paymentProvider,
					$refund->id,
					$gateway_id,
				),
				isSystemNote: true,
			);

			if ( $is_full ) {
				$this->releaseCouponsForOrder( $order );
			}
		} );

		return $refund->id;
	}

	private function releaseCouponsForOrder( Order $order ): void {
		// Pull the redemption rows for this order, then release each.
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT coupon_id FROM coupon_redemptions
			  WHERE order_id = :o AND released_at IS NULL"
		);
		$stmt->execute( [ ':o' => $order->id ] );
		$ids = array_map( fn( array $r ): int => (int) $r['coupon_id'], $stmt->fetchAll() );
		if ( $ids ) $this->coupons->releaseRedemptionsForOrder( $order, $ids );
	}
}
