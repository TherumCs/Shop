<?php
/**
 * Shop by Therum — CouponService.
 *
 * Apply, validate, and release coupons. The cart stores applied
 * coupon IDs in its `meta.coupons` JSON; the CouponStep pipeline reads
 * them on every recalc to compute discount_total.
 *
 * Validation rules (all enforced on apply AND on recalc — coupons can
 * become invalid mid-session if usage limits are hit by another customer):
 *
 *   - status must be 'active'
 *   - date window (date_starts ≤ now ≤ date_expires) must hold
 *   - usage_limit (global) must not be exhausted
 *   - usage_limit_per_user must not be exhausted for the cart owner
 *   - minimum_amount: subtotal must meet or exceed
 *   - maximum_amount: subtotal must not exceed
 *   - individual_use: no other coupon may be applied alongside
 *
 * On order finalization, CheckoutService calls recordRedemptionsForOrder()
 * which moves the applied coupon list from cart.meta into the
 * coupon_redemptions ledger and bumps usage_count.
 *
 * On refund, RefundService calls releaseRedemptionsForOrder() which
 * marks the redemption rows released_at — so per-customer limits don't
 * permanently burn a slot on a refunded order.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Models\Cart;
use Shop\Models\Coupon;
use Shop\Models\Order;
use Shop\Repositories\CartRepository;
use Shop\Repositories\CouponRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CouponService {

	public function __construct(
		private readonly CouponRepository $coupons,
		private readonly CartRepository $carts,
	) {}

	/**
	 * Apply a coupon code to a cart. Throws DomainException if invalid.
	 * Returns the refreshed Cart on success.
	 */
	public function apply( Cart $cart, string $code ): Cart {
		$code = trim( $code );
		if ( $code === '' ) {
			throw new \DomainException( 'Coupon code required.' );
		}

		$coupon = $this->coupons->findByCode( $code, $cart->currency );
		if ( $coupon === null ) {
			throw new \DomainException( 'Coupon not found.' );
		}

		$applied = $this->appliedIds( $cart );
		if ( in_array( $coupon->id, $applied, true ) ) {
			throw new \DomainException( 'Coupon already applied.' );
		}

		// individual_use means nothing else can ride alongside.
		if ( $coupon->individualUse && count( $applied ) > 0 ) {
			throw new \DomainException( 'This coupon cannot be combined with other discounts.' );
		}
		foreach ( $applied as $existing_id ) {
			$existing = $this->coupons->findById( $existing_id, $cart->currency );
			if ( $existing && $existing->individualUse ) {
				throw new \DomainException( 'Another coupon already applied does not allow stacking.' );
			}
		}

		$this->validateForCart( $coupon, $cart );

		$applied[] = $coupon->id;
		return $this->setApplied( $cart, $applied );
	}

	public function remove( Cart $cart, int $couponId ): Cart {
		$applied = $this->appliedIds( $cart );
		$next = array_values( array_filter( $applied, fn( int $id ): bool => $id !== $couponId ) );
		return $this->setApplied( $cart, $next );
	}

	/**
	 * Validate every applied coupon against current cart state. Returns
	 * the applied list with any invalid ones dropped — called by the
	 * pipeline step on each recalc.
	 *
	 * @return Coupon[]
	 */
	public function validForCart( Cart $cart ): array {
		$out = [];
		foreach ( $this->appliedIds( $cart ) as $id ) {
			$coupon = $this->coupons->findById( $id, $cart->currency );
			if ( $coupon === null ) continue;
			try {
				$this->validateForCart( $coupon, $cart );
				$out[] = $coupon;
			} catch ( \DomainException ) {
				// silently drop — admin will see this in the order notes
			}
		}
		return $out;
	}

	/**
	 * On order finalization: convert applied list → redemption rows,
	 * bump usage_count. Called from CheckoutService after order create.
	 *
	 * @param array<int, int> $appliedAmountsMinor coupon_id → discount cents on this order
	 */
	public function recordRedemptionsForOrder( Order $order, array $appliedAmountsMinor ): void {
		foreach ( $appliedAmountsMinor as $couponId => $amountMinor ) {
			$this->coupons->recordRedemption(
				couponId:    $couponId,
				orderId:     $order->id,
				userId:      $order->userId,
				email:       $order->email,
				amountMinor: $amountMinor,
			);
			$this->coupons->bumpUsage( $couponId );
		}
	}

	/**
	 * Called from RefundService when an order is fully refunded — releases
	 * the per-customer usage so the slot isn't permanently burned.
	 *
	 * @param int[] $couponIds redemption rows that ran against this order
	 */
	public function releaseRedemptionsForOrder( Order $order, array $couponIds ): void {
		foreach ( $couponIds as $couponId ) {
			$this->coupons->releaseRedemption( $couponId, $order->id );
			$this->coupons->decUsage( $couponId );
		}
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function validateForCart( Coupon $coupon, Cart $cart ): void {
		if ( $coupon->status !== Coupon::STATUS_ACTIVE ) {
			throw new \DomainException( 'Coupon is not active.' );
		}
		$now = time();
		if ( $coupon->dateStarts  !== null && $now < $coupon->dateStarts ) {
			throw new \DomainException( 'Coupon is not yet valid.' );
		}
		if ( $coupon->dateExpires !== null && $now > $coupon->dateExpires ) {
			throw new \DomainException( 'Coupon has expired.' );
		}

		if ( $coupon->usageLimit !== null && $coupon->usageCount >= $coupon->usageLimit ) {
			throw new \DomainException( 'Coupon usage limit reached.' );
		}

		if ( $coupon->usageLimitPerUser !== null ) {
			$used = $this->coupons->usageCountForCustomer( $coupon->id, null, $cart->email );
			if ( $used >= $coupon->usageLimitPerUser ) {
				throw new \DomainException( 'You have already used this coupon the maximum number of times.' );
			}
		}

		if ( $coupon->minimumAmount !== null && $cart->subtotal->lessThan( $coupon->minimumAmount ) ) {
			throw new \DomainException( sprintf(
				'Spend %s to use this coupon.',
				$coupon->minimumAmount->format()
			) );
		}
		if ( $coupon->maximumAmount !== null && $cart->subtotal->greaterThan( $coupon->maximumAmount ) ) {
			throw new \DomainException( sprintf(
				'Cart exceeds %s — coupon not applicable.',
				$coupon->maximumAmount->format()
			) );
		}
	}

	/**
	 * Read applied coupon IDs from cart.meta.coupons.
	 * @return int[]
	 */
	public function appliedIds( Cart $cart ): array {
		$ids = (array) ( $cart->meta['coupons'] ?? [] );
		return array_values( array_map( 'intval', array_filter( $ids ) ) );
	}

	/**
	 * Write the applied coupon list back to cart.meta.coupons.
	 * @param int[] $ids
	 */
	private function setApplied( Cart $cart, array $ids ): Cart {
		$meta = $cart->meta;
		$meta['coupons'] = array_values( array_map( 'intval', $ids ) );

		DB::pdo()->prepare(
			"UPDATE sessions SET meta = :m, updated_at = unixepoch() WHERE id = :i"
		)->execute( [
			':m' => wp_json_encode( $meta ),
			':i' => $cart->id,
		] );

		return $this->carts->findById( $cart->id )
			?? throw new \RuntimeException( 'Cart vanished mid-coupon-update' );
	}
}
