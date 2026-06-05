<?php
/**
 * Shop — Stock Status element.
 *
 * Renders "In stock" / "Only X left" / "Out of stock" based on the
 * product/variant's stock state. Designers tend to want this small
 * and quiet but it has real conversion impact.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StockStatus implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'stock-status'; }
	public function name(): string     { return 'Stock status'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'package'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->text( 'in_stock_label',    'In stock label',    'In stock' )
				->text( 'low_stock_label',   'Low stock label',   'Only {qty} left' )
				->text( 'out_of_stock_label','Out of stock label','Out of stock' )
				->number( 'low_threshold', 'Low-stock threshold (qty)', 5, [ 'min' => 0 ] )
			->group( 'Style' )
				->toggle( 'show_dot', 'Show colored dot', true )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$variant = $context->variantId !== null
			? $this->products->findVariant( $context->variantId )
			: null;

		$track = $variant ? true : $product->trackInventory;
		if ( ! $track ) return ''; // no badge when stock isn't tracked

		$qty = $variant?->stockQty ?? $product->stockQty;
		$threshold = (int) ( $settings['low_threshold'] ?? 5 );
		$show_dot  = ! empty( $settings['show_dot'] );

		[ $state, $label ] = match ( true ) {
			$qty === null            => [ 'in_stock',     (string) $settings['in_stock_label']    ],
			$qty <= 0                => [ 'out_of_stock', (string) $settings['out_of_stock_label'] ],
			$qty <= $threshold       => [ 'low',          str_replace( '{qty}', (string) $qty, (string) $settings['low_stock_label'] ) ],
			default                  => [ 'in_stock',     (string) $settings['in_stock_label']    ],
		};

		$class = 'shop-el shop-el-stock shop-el-stock--' . esc_attr( $state );
		$dot   = $show_dot ? '<span class="shop-el-stock__dot"></span>' : '';

		return sprintf( '<span class="%s">%s%s</span>', $class, $dot, esc_html( $label ) );
	}
}
