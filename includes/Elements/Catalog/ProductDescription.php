<?php
/**
 * Shop — Product Description element.
 *
 * Outputs the product's description (full or short). Designers
 * typically pair Short on the hero band and Full further down.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductDescription implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'product-description'; }
	public function name(): string     { return 'Description'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'align-left'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->select( 'source', 'Source', [
					'full'  => 'Full description',
					'short' => 'Short description',
				], 'full' )
				->toggle( 'allow_html', 'Allow HTML formatting', true )
			->group( 'Style' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->color( 'color', 'Color', '' )
				->number( 'max_lines', 'Max lines (0 = unlimited)', 0, [ 'min' => 0, 'max' => 20 ] )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$source = $settings['source'] ?? 'full';
		$text   = $source === 'short' ? $product->shortDescription : $product->description;
		if ( $text === null || trim( $text ) === '' ) return '';

		$allow_html = ! empty( $settings['allow_html'] );
		$alignment  = $settings['alignment']  ?? 'left';
		$color      = (string) ( $settings['color'] ?? '' );
		$max_lines  = (int) ( $settings['max_lines'] ?? 0 );

		$style_parts = [];
		if ( $color !== '' )  $style_parts[] = 'color:' . $color;
		if ( $max_lines > 0 ) {
			$style_parts[] = 'display:-webkit-box';
			$style_parts[] = '-webkit-line-clamp:' . $max_lines;
			$style_parts[] = '-webkit-box-orient:vertical';
			$style_parts[] = 'overflow:hidden';
		}
		$style = $style_parts ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';

		$content = $allow_html ? wp_kses_post( $text ) : esc_html( $text );

		return sprintf(
			'<div class="shop-el shop-el-product-desc shop-el--align-%s"%s>%s</div>',
			esc_attr( $alignment ),
			$style,
			$content,
		);
	}
}
