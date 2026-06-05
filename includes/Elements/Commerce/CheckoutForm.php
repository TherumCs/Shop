<?php
/**
 * Shop — Checkout Form element.
 *
 * Renders the configured Therum Checkout template inside a Pure page.
 * Delegates to CheckoutRenderer so the visual choice (Classic / Therum /
 * Sequence) follows the existing settings.
 */

namespace Shop\Elements\Commerce;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Services\CartService;
use Shop\Services\CartTokenManager;
use Shop\Services\CheckoutRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CheckoutForm implements Element {

	public function __construct(
		private readonly CartService $cart,
		private readonly CheckoutRenderer $renderer,
		private readonly CartTokenManager $token,
	) {}

	public function id(): string       { return 'checkout-form'; }
	public function name(): string     { return 'Checkout form'; }
	public function category(): string { return 'commerce'; }
	public function icon(): string     { return 'credit-card'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Style' )
				->select( 'presentation', 'Override presentation (leave on Default to follow Settings)', [
					'default'  => 'Default (Settings)',
					'classic'  => 'Classic',
					'therum'   => 'Therum',
					'sequence' => 'Sequence',
				], 'default' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$cart = $this->cart->getOrCreate( $this->token->current() );
		$mode = $settings['presentation'] ?? 'default';
		$mode = $mode === 'default' ? null : (string) $mode;
		return '<div class="shop-el shop-el-checkout">' . $this->renderer->render( $cart, $mode ) . '</div>';
	}
}
