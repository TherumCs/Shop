<?php
/**
 * Shop by Therum — Order DTO.
 *
 * Read view of an `orders` row + its items. Immutable. Returned by
 * OrderRepository. State transitions go through OrderService, which
 * produces a fresh Order.
 *
 * Statuses follow Woo vocabulary:
 *   pending      — created, awaiting payment
 *   processing   — payment succeeded, vendor fulfillment not yet complete
 *   on-hold      — manual review hold
 *   completed    — fulfilled
 *   cancelled    — cancelled before payment / by admin
 *   refunded     — fully refunded
 *   failed       — payment failed
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Order {

	public function __construct(
		public readonly int $id,
		public readonly string $number,
		public readonly ?int $sessionId,
		public readonly ?int $userId,
		public readonly string $email,
		public readonly string $currency,
		public readonly string $status,

		public readonly Money $subtotal,
		public readonly Money $shippingTotal,
		public readonly Money $taxTotal,
		public readonly Money $discountTotal,
		public readonly Money $grandTotal,
		public readonly Money $refundedTotal,

		/** @var array<string,mixed>|null */
		public readonly ?array $shipAddress,
		/** @var array<string,mixed>|null */
		public readonly ?array $billAddress,

		public readonly ?string $paymentProvider,
		public readonly ?string $paymentMethod,
		public readonly ?string $paymentIntentId,
		public readonly ?int $paidAt,

		/** @var OrderItem[] */
		public readonly array $items,

		public readonly int $createdAt,
		public readonly int $updatedAt,
	) {}

	/**
	 * @param array<string,mixed> $row
	 * @param OrderItem[]         $items
	 */
	public static function fromRow( array $row, array $items ): self {
		$currency = (string) ( $row['currency'] ?? 'USD' );
		return new self(
			id:               (int) $row['id'],
			number:           (string) $row['number'],
			sessionId:        isset( $row['session_id'] ) ? (int) $row['session_id'] : null,
			userId:           isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
			email:            (string) $row['email'],
			currency:         $currency,
			status:           (string) $row['status'],
			subtotal:         Money::cents( (int) $row['subtotal'],         $currency ),
			shippingTotal:    Money::cents( (int) ( $row['shipping_total'] ?? 0 ), $currency ),
			taxTotal:         Money::cents( (int) ( $row['tax_total']      ?? 0 ), $currency ),
			discountTotal:    Money::cents( (int) ( $row['discount_total'] ?? 0 ), $currency ),
			grandTotal:       Money::cents( (int) $row['grand_total'],      $currency ),
			refundedTotal:    Money::cents( (int) ( $row['refunded_total'] ?? 0 ), $currency ),
			shipAddress:      self::parseJsonObject( $row['ship_address']   ?? null ),
			billAddress:      self::parseJsonObject( $row['bill_address']   ?? null ),
			paymentProvider:  $row['payment_provider']  ?? null,
			paymentMethod:    $row['payment_method']    ?? null,
			paymentIntentId:  $row['payment_intent_id'] ?? null,
			paidAt:           isset( $row['paid_at'] ) ? (int) $row['paid_at'] : null,
			items:            $items,
			createdAt:        (int) $row['created_at'],
			updatedAt:        (int) $row['updated_at'],
		);
	}

	/** @return array<string,mixed>|null */
	private static function parseJsonObject( ?string $json ): ?array {
		if ( $json === null || $json === '' ) return null;
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
