<?php
/**
 * Shop — Product Price element.
 *
 * Renders the current product / variant price. If a variant is active
 * in context, variant price wins; otherwise product price.
 *
 * Renders compare-at-price as a strikethrough when present and greater
 * than the active price.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductPrice implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'product-price'; }
	public function name(): string     { return 'Price'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'dollar-sign'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Style' )
				->select( 'size', 'Size', [
					'sm' => 'Small',
					'md' => 'Medium',
					'lg' => 'Large',
					'xl' => 'Extra large',
				], 'lg' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->color( 'color', 'Color', '' )
				->toggle( 'mono', 'Tabular numerics', true )
			->group( 'Compare-at price' )
				->toggle( 'show_compare', 'Show strikethrough when on sale', true )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$variant = $context->variantId !== null
			? $this->products->findVariant( $context->variantId )
			: null;

		$price    = $variant?->price ?? $product->price;
		$compare  = $variant?->compareAtPrice ?? $product->compareAtPrice;
		if ( $price === null ) return '';

		$mono = ! empty( $settings['mono'] );
		$size = $settings['size'] ?? 'lg';
		$alignment = $settings['alignment'] ?? 'left';
		$color = (string) ( $settings['color'] ?? '' );
		$show_compare = ! empty( $settings['show_compare'] ) && $compare !== null && $compare->greaterThan( $price );

		$class = sprintf(
			'shop-el shop-el-product-price shop-el--align-%s shop-el-price--%s%s',
			esc_attr( $alignment ),
			esc_attr( $size ),
			$mono ? ' shop-el-price--mono' : '',
		);
		$style = $color !== '' ? sprintf( ' style="color:%s"', esc_attr( $color ) ) : '';

		$out = '<div class="' . $class . '"' . $style . '>';
		$out .= '<span class="shop-el-price__amount" data-shop-product-price="' . esc_attr( (string) $product->id ) . '">' . esc_html( $price->format() ) . '</span>';
		if ( $show_compare ) {
			$out .= ' <span class="shop-el-price__compare">' . esc_html( $compare->format() ) . '</span>';
		}
		$out .= '</div>';
		return $out;
	}
}
