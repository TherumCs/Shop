<?php
/**
 * Shop by Therum — ElementContext.
 *
 * Render-time data passed to every element. Carries:
 *
 *   - productId    the product being rendered (single product template)
 *                  or null for non-product pages
 *   - productSlug  a slug fallback when callers don't have an id handy
 *   - variantId    the customer's currently-selected variant on the
 *                  product page (set by VariantPicker via state)
 *   - cart         the current cart (for cart-aware elements like the
 *                  mini-cart, header cart count, etc.)
 *   - extras       arbitrary builder-supplied data (loop iteration vars,
 *                  page context, etc.)
 *
 * Immutable. Builders constructing this for a render pass pass everything
 * up-front.
 */

namespace Shop\Elements;

use Shop\Models\Cart;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class ElementContext {

	public function __construct(
		public ?int $productId   = null,
		public ?string $productSlug = null,
		public ?int $variantId   = null,
		public ?Cart $cart       = null,
		/** @var array<string,mixed> */
		public array $extras     = [],
	) {}

	public function withVariant( int $variantId ): self {
		return new self(
			productId:   $this->productId,
			productSlug: $this->productSlug,
			variantId:   $variantId,
			cart:        $this->cart,
			extras:      $this->extras,
		);
	}

	public function withExtras( array $extras ): self {
		return new self(
			productId:   $this->productId,
			productSlug: $this->productSlug,
			variantId:   $this->variantId,
			cart:        $this->cart,
			extras:      array_merge( $this->extras, $extras ),
		);
	}
}
