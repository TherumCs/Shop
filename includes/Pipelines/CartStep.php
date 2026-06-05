<?php
/**
 * Shop by Therum — interface for a step in the cart totals pipeline.
 *
 * Each step receives the running CartTotalsContext, mutates it, returns.
 * Throw to abort (the pipeline catches and surfaces it to the caller).
 *
 * Extending the pipeline = write a new CartStep, register it in the
 * container's `Shop\Pipelines\CartTotalsPipeline` binding at a specific
 * position. No priority numbers, no hooks — explicit ordered list.
 */

namespace Shop\Pipelines;

if ( ! defined( 'ABSPATH' ) ) exit;

interface CartStep {
	public function run( CartTotalsContext $ctx ): void;
}
