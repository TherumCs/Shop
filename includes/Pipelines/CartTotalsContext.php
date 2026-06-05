<?php
/**
 * Shop by Therum — context object passed through CartTotalsPipeline.
 *
 * Mutable. Each step reads what previous steps wrote and adds its own.
 * Single source of truth for "what does this cart total right now"
 * throughout the pipeline run.
 *
 * Why a mutable context (vs. immutable + return-new): cart-totals pipelines
 * accrete data — subtotal, then coupons, then tax, then shipping, then
 * rounding. Returning a new instance from every step works but pipes
 * boilerplate through every implementation. The pipeline runs in a single
 * tx; mutability inside that tx is fine and matches mental model.
 */

namespace Shop\Pipelines;

use Shop\Models\Cart;
use Shop\Models\CartItem;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartTotalsContext {

	public Money $subtotal;
	public Money $discountTotal;
	public Money $shippingTotal;
	public Money $taxTotal;
	public Money $grandTotal;

	/**
	 * Per-line discount allocation. Step writers populate this so the
	 * persistence layer can persist `discount_total` per line and so
	 * checkout UI can show line-level deductions.
	 *
	 * Keyed by CartItem::$id.
	 *
	 * @var array<int, Money>
	 */
	public array $lineDiscounts = [];

	/**
	 * Applied coupon ledger — used by the persistence step to write
	 * coupon_redemptions on order finalize.
	 *
	 * @var array<int, array{couponId:int,amount:Money}>
	 */
	public array $appliedCoupons = [];

	/**
	 * Free-form notes that downstream steps or the persistence layer
	 * might want to consult. Keep it small; don't smuggle real state.
	 *
	 * @var array<string,mixed>
	 */
	public array $meta = [];

	public function __construct(
		public readonly Cart $cart,
	) {
		$c = $cart->currency;
		$this->subtotal      = Money::zero( $c );
		$this->discountTotal = Money::zero( $c );
		$this->shippingTotal = Money::zero( $c );
		$this->taxTotal      = Money::zero( $c );
		$this->grandTotal    = Money::zero( $c );
	}

	/** @return CartItem[] */
	public function items(): array { return $this->cart->items; }
}
