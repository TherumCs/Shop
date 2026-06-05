<?php
/**
 * Shop by Therum — coupon pipeline step.
 *
 * Runs after SubtotalStep, before RoundingStep. For each applied coupon
 * that still validates, computes its discount and adds to
 * $ctx->discountTotal. Records per-line allocation in
 * $ctx->lineDiscounts so the persistence layer can attribute the
 * discount when the order is finalized, and per-coupon amounts in
 * $ctx->appliedCoupons so CouponService::recordRedemptionsForOrder
 * knows how much each one took.
 *
 * Allocation rules per discount_type:
 *
 *   percent       — percent off cart subtotal (or scoped subtotal),
 *                   distributed across matching lines proportional
 *                   to line_total
 *   fixed_cart    — flat cents off cart subtotal, distributed
 *                   proportionally across all lines
 *   fixed_product — flat cents off PER MATCHING LINE (caps at line_total)
 *   free_shipping — handled in ShippingStep (this step is a no-op for it)
 *
 * Scope rules:
 *
 *   cart     → applies to all lines
 *   product  → only lines where product_id == scope_ref
 *   variant  → only lines where variant_id == scope_ref
 *   vendor   → only lines where the variant's pod_provider == scope_ref
 */

namespace Shop\Pipelines\Steps;

use Shop\Models\CartItem;
use Shop\Models\Coupon;
use Shop\Money;
use Shop\Pipelines\CartStep;
use Shop\Pipelines\CartTotalsContext;
use Shop\Services\CouponService;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CouponStep implements CartStep {

	public function __construct(
		private readonly CouponService $coupons,
		private readonly ProductRepository $products,
	) {}

	public function run( CartTotalsContext $ctx ): void {
		$cart    = $ctx->cart;
		$valid   = $this->coupons->validForCart( $cart );
		if ( ! $valid ) return;

		$total_discount = Money::zero( $cart->currency );

		foreach ( $valid as $coupon ) {
			if ( $coupon->discountType === Coupon::TYPE_FREE_SHIPPING ) {
				// Marker for ShippingStep — actual zeroing happens there.
				$ctx->meta['free_shipping_coupons'][] = $coupon->id;
				$ctx->appliedCoupons[ $coupon->id ] = [
					'couponId' => $coupon->id,
					'amount'   => Money::zero( $cart->currency ),
				];
				continue;
			}

			$matching = $this->matchingLines( $coupon, $cart->items );
			if ( ! $matching ) {
				$ctx->appliedCoupons[ $coupon->id ] = [
					'couponId' => $coupon->id,
					'amount'   => Money::zero( $cart->currency ),
				];
				continue;
			}

			$discount = $this->computeDiscount( $coupon, $matching, $cart->currency, $ctx );
			$total_discount = $total_discount->plus( $discount );

			$ctx->appliedCoupons[ $coupon->id ] = [
				'couponId' => $coupon->id,
				'amount'   => $discount,
			];
		}

		// Never let discount push grand total negative
		if ( $total_discount->greaterThan( $ctx->subtotal ) ) {
			$total_discount = $ctx->subtotal;
		}

		$ctx->discountTotal = $ctx->discountTotal->plus( $total_discount );
	}

	/**
	 * @param CartItem[] $items
	 * @return CartItem[]
	 */
	private function matchingLines( Coupon $coupon, array $items ): array {
		switch ( $coupon->scope ) {
			case Coupon::SCOPE_CART:
				return $items;

			case Coupon::SCOPE_PRODUCT:
				$pid = (int) $coupon->scopeRef;
				return array_values( array_filter( $items, fn( CartItem $i ): bool => $i->productId === $pid ) );

			case Coupon::SCOPE_VARIANT:
				$vid = (int) $coupon->scopeRef;
				return array_values( array_filter( $items, fn( CartItem $i ): bool => $i->variantId === $vid ) );

			case Coupon::SCOPE_VENDOR:
				$vendor = (string) $coupon->scopeRef;
				return array_values( array_filter( $items, function ( CartItem $i ) use ( $vendor ): bool {
					if ( $i->variantId === null ) return false;
					$variant = $this->products->findVariant( $i->variantId );
					return $variant !== null && $variant->podProvider === $vendor;
				} ) );

			default:
				return [];
		}
	}

	/**
	 * @param CartItem[] $lines
	 */
	private function computeDiscount(
		Coupon $coupon,
		array $lines,
		string $currency,
		CartTotalsContext $ctx,
	): Money {
		$base_total = Money::zero( $currency );
		foreach ( $lines as $l ) $base_total = $base_total->plus( $l->lineTotal );
		if ( $base_total->isZero() ) return Money::zero( $currency );

		switch ( $coupon->discountType ) {
			case Coupon::TYPE_PERCENT:
				$total = $base_total->percent( (float) $coupon->amount );
				$this->distributeProportional( $lines, $total, $base_total, $ctx );
				return $total;

			case Coupon::TYPE_FIXED_CART:
				$total = Money::cents( min( $coupon->amount, $base_total->minor ), $currency );
				$this->distributeProportional( $lines, $total, $base_total, $ctx );
				return $total;

			case Coupon::TYPE_FIXED_PRODUCT:
				$total = Money::zero( $currency );
				foreach ( $lines as $l ) {
					$per_line = Money::cents( min( $coupon->amount * $l->quantity, $l->lineTotal->minor ), $currency );
					$total    = $total->plus( $per_line );
					$ctx->lineDiscounts[ $l->id ] = ( $ctx->lineDiscounts[ $l->id ] ?? Money::zero( $currency ) )->plus( $per_line );
				}
				return $total;

			default:
				return Money::zero( $currency );
		}
	}

	/**
	 * Distribute a total discount across lines proportional to line_total.
	 * Last line absorbs the rounding remainder so the per-line sum equals
	 * the announced total exactly.
	 *
	 * @param CartItem[] $lines
	 */
	private function distributeProportional(
		array $lines,
		Money $total_discount,
		Money $base_total,
		CartTotalsContext $ctx,
	): void {
		if ( ! $lines ) return;
		$currency = $total_discount->currency;
		$allocated_minor = 0;
		$last_idx = count( $lines ) - 1;
		foreach ( $lines as $i => $line ) {
			if ( $i === $last_idx ) {
				$share_minor = $total_discount->minor - $allocated_minor;
			} else {
				$share_minor = (int) round(
					$total_discount->minor * ( $line->lineTotal->minor / $base_total->minor )
				);
				$allocated_minor += $share_minor;
			}
			$share = Money::cents( $share_minor, $currency );
			$ctx->lineDiscounts[ $line->id ] = ( $ctx->lineDiscounts[ $line->id ] ?? Money::zero( $currency ) )->plus( $share );
		}
	}
}
