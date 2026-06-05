<?php
/**
 * Shop — Product Title element.
 *
 * Renders the current product's title as an editable heading. Settings:
 *   - tag           h1 | h2 | h3 (default h1)
 *   - alignment     left | center | right
 *   - color         CSS color (optional override)
 *   - link          link to product (default false; usually on archive)
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductTitle implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'product-title'; }
	public function name(): string     { return 'Product title'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'tag'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Layout' )
				->select( 'tag', 'Heading level', [ 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3' ], 'h1' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->toggle( 'link', 'Link to product page', false )
			->group( 'Style' )
				->color( 'color', 'Text color', '' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$tag       = in_array( $settings['tag'] ?? 'h1', [ 'h1', 'h2', 'h3' ], true ) ? $settings['tag'] : 'h1';
		$alignment = $settings['alignment'] ?? 'left';
		$color     = (string) ( $settings['color'] ?? '' );
		$link      = ! empty( $settings['link'] );

		$style = $color !== '' ? sprintf( ' style="color:%s"', esc_attr( $color ) ) : '';
		$class = sprintf( 'shop-el shop-el-product-title shop-el--align-%s', esc_attr( $alignment ) );

		$content = esc_html( $product->title );
		if ( $link ) {
			$content = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_permalink( $product->id ) ?: home_url() ),
				$content,
			);
		}

		return sprintf( '<%1$s class="%2$s"%3$s>%4$s</%1$s>', $tag, $class, $style, $content );
	}
}
