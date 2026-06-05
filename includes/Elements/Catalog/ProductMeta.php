<?php
/**
 * Shop — Product Meta element.
 *
 * Tiny meta line: SKU, vendor source, attribute breadcrumb, etc.
 * The "small text under the title" pattern.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\AttributeRepository;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductMeta implements Element {

	public function __construct(
		private readonly ProductRepository $products,
		private readonly AttributeRepository $attributes,
	) {}

	public function id(): string       { return 'product-meta'; }
	public function name(): string     { return 'Product meta'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'info'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->toggle( 'show_sku',     'Show SKU',     true )
				->toggle( 'show_vendor',  'Show vendor',  true )
				->toggle( 'show_options', 'Show selected option breadcrumb', true )
				->text( 'separator', 'Separator', ' · ' )
			->group( 'Style' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->color( 'color', 'Color', '' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$variant = $context->variantId !== null
			? $this->products->findVariant( $context->variantId )
			: null;

		$parts = [];

		if ( ! empty( $settings['show_vendor'] ) && $variant?->podProvider ) {
			$parts[] = ucwords( str_replace( [ '-', '_' ], ' ', $variant->podProvider ) );
		}
		if ( ! empty( $settings['show_options'] ) && $variant !== null ) {
			$opts = $this->attributes->optionsForVariant( $variant->id );
			foreach ( $opts as $val ) $parts[] = $val;
		}
		if ( ! empty( $settings['show_sku'] ) ) {
			$sku = $variant?->sku ?? $product->sku;
			if ( $sku !== null && $sku !== '' ) $parts[] = 'SKU ' . $sku;
		}

		if ( ! $parts ) return '';

		$separator = (string) ( $settings['separator'] ?? ' · ' );
		$alignment = $settings['alignment'] ?? 'left';
		$color     = (string) ( $settings['color'] ?? '' );

		$style = $color !== '' ? sprintf( ' style="color:%s"', esc_attr( $color ) ) : '';
		return sprintf(
			'<div class="shop-el shop-el-product-meta shop-el--align-%s"%s>%s</div>',
			esc_attr( $alignment ),
			$style,
			esc_html( implode( $separator, $parts ) ),
		);
	}
}
