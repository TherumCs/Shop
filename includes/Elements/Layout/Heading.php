<?php
/**
 * Shop — Heading element.
 *
 * Static text heading (vs ProductTitle which pulls dynamic title from
 * the current product). Used for arbitrary section titles.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Heading implements Element {

	public function id(): string       { return 'heading'; }
	public function name(): string     { return 'Heading'; }
	public function category(): string { return 'content'; }
	public function icon(): string     { return 'heading'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->text( 'text', 'Heading text', 'Heading' )
				->select( 'tag', 'Tag', [ 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4' ], 'h2' )
			->group( 'Style' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->color( 'color', 'Color', '' )
				->select( 'size', 'Size', [
					'xs' => 'XS', 'sm' => 'S', 'md' => 'M', 'lg' => 'L', 'xl' => 'XL', '2xl' => '2XL',
				], 'xl' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$text      = (string) ( $settings['text']  ?? '' );
		if ( $text === '' ) return '';
		$tag       = in_array( $settings['tag'] ?? 'h2', [ 'h1','h2','h3','h4' ], true ) ? $settings['tag'] : 'h2';
		$alignment = $settings['alignment'] ?? 'left';
		$color     = (string) ( $settings['color'] ?? '' );
		$size      = (string) ( $settings['size']  ?? 'xl' );

		$style = $color !== '' ? sprintf( ' style="color:%s"', esc_attr( $color ) ) : '';
		$class = sprintf(
			'shop-el shop-el-heading shop-el-heading--%s shop-el--align-%s',
			esc_attr( $size ),
			esc_attr( $alignment ),
		);

		// Dynamic data: replace `{shop_product_title}` etc when on a product.
		$text = $this->resolveDynamic( $text, $context );

		return sprintf( '<%1$s class="%2$s"%3$s>%4$s</%1$s>', $tag, $class, $style, esc_html( $text ) );
	}

	private function resolveDynamic( string $text, ElementContext $context ): string {
		if ( $context->productId === null ) return $text;
		if ( ! str_contains( $text, '{shop_product_' ) ) return $text;

		$products = \Shop\Container::instance()->get( \Shop\Repositories\ProductRepository::class );
		$product  = $products->findById( $context->productId );
		if ( $product === null ) return $text;

		return str_replace(
			[ '{shop_product_title}', '{shop_product_sku}' ],
			[ $product->title, $product->sku ?? '' ],
			$text,
		);
	}
}
