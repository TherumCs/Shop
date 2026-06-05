<?php
/**
 * Final step — sums components into grand_total.
 *
 * Currency-level rounding is unnecessary at this layer because Money is
 * always integer minor units; there are no fractional minor units to round.
 * This step exists as the explicit "everything before me has run; finalize"
 * marker, and as the hook for cash-rounding-style currency rules (e.g.
 * Switzerland's 5-rappen rounding) when we need them.
 */

namespace Shop\Pipelines\Steps;

use Shop\Pipelines\CartStep;
use Shop\Pipelines\CartTotalsContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class RoundingStep implements CartStep {

	public function run( CartTotalsContext $ctx ): void {
		$ctx->grandTotal = $ctx->subtotal
			->minus( $ctx->discountTotal )
			->plus( $ctx->shippingTotal )
			->plus( $ctx->taxTotal );
	}
}
