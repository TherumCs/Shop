<?php
/**
 * Shop — Add to Cart button element.
 *
 * Renders a button that calls ShopCart.addItem() on click. Reads the
 * current product (and any selected variant) from context, plus the
 * customer's quantity from a sibling element if present.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AddToCart implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'add-to-cart'; }
	public function name(): string     { return 'Add to cart'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'shopping-cart'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->text( 'label', 'Button label', 'Add to cart' )
				->toggle( 'show_price', 'Show price on button', false )
				->toggle( 'show_qty',   'Show quantity stepper alongside', true )
			->group( 'Style' )
				->select( 'size', 'Size', [
					'sm' => 'Small',
					'md' => 'Medium',
					'lg' => 'Large',
				], 'lg' )
				->select( 'variant', 'Variant', [
					'primary' => 'Primary (filled)',
					'outline' => 'Outline',
					'ghost'   => 'Ghost',
				], 'primary' )
				->toggle( 'full_width', 'Full width', false )
				->color( 'background', 'Background color', '' )
				->color( 'text_color',  'Text color',       '' )
				->number( 'radius', 'Corner radius (px)', 10, [ 'min' => 0, 'max' => 32 ] )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$label      = (string) ( $settings['label']  ?? 'Add to cart' );
		$show_price = ! empty( $settings['show_price'] );
		$show_qty   = ! empty( $settings['show_qty'] );
		$size       = (string) ( $settings['size']   ?? 'lg' );
		$variant    = (string) ( $settings['variant']?? 'primary' );
		$full_width = ! empty( $settings['full_width'] );
		$bg         = (string) ( $settings['background'] ?? '' );
		$fg         = (string) ( $settings['text_color'] ?? '' );
		$radius     = (int)    ( $settings['radius']     ?? 10 );

		$price = $product->price;

		$styles = [
			'--shop-radius: ' . $radius . 'px',
		];
		if ( $bg !== '' ) $styles[] = '--shop-btn-bg: ' . $bg;
		if ( $fg !== '' ) $styles[] = '--shop-btn-fg: ' . $fg;
		$style_attr = ' style="' . esc_attr( implode( '; ', $styles ) ) . '"';

		$class = sprintf(
			'shop-el shop-el-add-to-cart shop-el-add-to-cart--%s shop-el-add-to-cart--%s%s',
			esc_attr( $size ),
			esc_attr( $variant ),
			$full_width ? ' shop-el-add-to-cart--full' : '',
		);

		$out  = '<div class="' . $class . '"' . $style_attr . ' data-shop-add-to-cart>';

		if ( $show_qty ) {
			$out .= '<div class="shop-el-add-to-cart__qty" data-shop-qty>';
			$out .= '<button type="button" data-shop-qty-dec aria-label="Decrease">−</button>';
			$out .= '<input type="number" data-shop-qty-input value="1" min="1" step="1" inputmode="numeric" />';
			$out .= '<button type="button" data-shop-qty-inc aria-label="Increase">+</button>';
			$out .= '</div>';
		}

		$out .= sprintf(
			'<button type="button" class="shop-el-add-to-cart__btn" data-shop-add-to-cart-btn data-shop-product-id="%d">'
			. '<span class="shop-el-add-to-cart__label">%s</span>',
			(int) $product->id,
			esc_html( $label ),
		);
		if ( $show_price && $price !== null ) {
			$out .= '<span class="shop-el-add-to-cart__price">' . esc_html( $price->format() ) . '</span>';
		}
		$out .= '</button>';

		$out .= '</div>';
		return $out;
	}
}
