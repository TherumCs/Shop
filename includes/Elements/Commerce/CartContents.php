<?php
/**
 * Shop — Cart Contents element.
 *
 * Renders the customer's current cart inline. Used inside the cart
 * template. Delegates to CartRenderer::contents() so styling stays
 * unified with the drawer.
 */

namespace Shop\Elements\Commerce;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Services\CartRenderer;
use Shop\Services\CartService;
use Shop\Services\CartTokenManager;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartContents implements Element {

	public function __construct(
		private readonly CartService $cart,
		private readonly CartRenderer $renderer,
		private readonly CartTokenManager $token,
	) {}

	public function id(): string       { return 'cart-contents'; }
	public function name(): string     { return 'Cart contents'; }
	public function category(): string { return 'commerce'; }
	public function icon(): string     { return 'shopping-bag'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$cart = $this->cart->getOrCreate( $this->token->current() );
		return '<div class="shop-el shop-el-cart-contents">' . $this->renderer->contents( $cart ) . '</div>';
	}
}
