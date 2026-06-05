<?php
/**
 * Shop by Therum — cart totals pipeline.
 *
 * Composes an ordered list of CartStep instances. Each step mutates the
 * shared CartTotalsContext. The pipeline is the only thing that computes
 * cart totals — every caller (CartService, CheckoutService, REST handlers)
 * goes through here.
 *
 * Default step order:
 *
 *   1. SubtotalStep         — sum of (qty × unit_price) per line
 *   2. CouponStep            — apply active coupons (v1.2 — stubbed for now)
 *   3. ShippingStep          — per-vendor shipping quotes (v1.1+)
 *   4. TaxStep               — per-vendor tax quotes (v1.1+)
 *   5. RoundingStep          — final rounding + grand total
 *
 * v1 ships with Subtotal + Rounding only; the others are no-ops until
 * their owning milestone (#2, #4) lands.
 */

namespace Shop\Pipelines;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartTotalsPipeline {

	/**
	 * @param CartStep[] $steps Ordered list of pipeline steps.
	 */
	public function __construct(
		private readonly array $steps,
	) {}

	public function run( CartTotalsContext $ctx ): CartTotalsContext {
		foreach ( $this->steps as $step ) {
			$step->run( $ctx );
		}
		return $ctx;
	}

	/**
	 * Default step composition. Bootstrap binds this in the container;
	 * callers that need to extend rebind with their own array.
	 *
	 * The container resolves CouponStep because it has dependencies; we
	 * provide both a plain default (subtotal + rounding only) and a
	 * resolveDefault() that takes the container to wire CouponStep in.
	 *
	 * @return CartStep[]
	 */
	public static function defaultSteps(): array {
		return [
			new Steps\SubtotalStep(),
			new Steps\RoundingStep(),
		];
	}

	/**
	 * Default step composition WITH coupons wired (requires container
	 * to resolve CouponService + ProductRepository dependencies). Use
	 * this in the bootstrap; the bare defaultSteps() is for tests.
	 *
	 * @return CartStep[]
	 */
	public static function resolveDefault( \Shop\Container $c ): array {
		return [
			new Steps\SubtotalStep(),
			new Steps\CouponStep(
				$c->get( \Shop\Services\CouponService::class ),
				$c->get( \Shop\Repositories\ProductRepository::class ),
			),
			new Steps\RoundingStep(),
		];
	}
}
