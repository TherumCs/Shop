<?php
/**
 * Shop — Divider element. Horizontal rule.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Divider implements Element {

	public function id(): string       { return 'divider'; }
	public function name(): string     { return 'Divider'; }
	public function category(): string { return 'layout'; }
	public function icon(): string     { return 'minus'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Style' )
				->select( 'style', 'Style', [
					'solid'  => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted',
				], 'solid' )
				->number( 'thickness', 'Thickness (px)', 1, [ 'min' => 1, 'max' => 8 ] )
				->color( 'color', 'Color', '' )
				->number( 'margin_y', 'Vertical margin (px)', 24, [ 'min' => 0, 'max' => 120 ] )
				->number( 'width_pct', 'Width %', 100, [ 'min' => 10, 'max' => 100 ] )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$style     = (string) ( $settings['style']  ?? 'solid' );
		$thickness = (int)    ( $settings['thickness'] ?? 1 );
		$color     = (string) ( $settings['color']  ?? '' );
		$margin_y  = (int)    ( $settings['margin_y']  ?? 24 );
		$width_pct = (int)    ( $settings['width_pct'] ?? 100 );

		$styles = [
			'border-top-style: ' . $style,
			'border-top-width: ' . $thickness . 'px',
			'margin: ' . $margin_y . 'px auto',
			'width: '  . $width_pct . '%',
		];
		if ( $color !== '' ) $styles[] = 'border-top-color: ' . $color;

		return sprintf( '<hr class="shop-el shop-el-divider" style="%s" />', esc_attr( implode( '; ', $styles ) ) );
	}
}
