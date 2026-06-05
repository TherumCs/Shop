<?php
/**
 * Shop — Column layout primitive.
 *
 * Sits inside a Section (or a row container) to split the layout. Span
 * is a fraction of the parent — 12-column grid model. Mobile collapses
 * to full-width by default.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Column implements Element {

	public function id(): string       { return 'column'; }
	public function name(): string     { return 'Column'; }
	public function category(): string { return 'layout'; }
	public function icon(): string     { return 'columns'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Layout' )
				->select( 'span', 'Width (of 12)', [
					'2'  => '2/12 ≈ 17%',
					'3'  => '3/12 = 25%',
					'4'  => '4/12 ≈ 33%',
					'6'  => '6/12 = 50%',
					'8'  => '8/12 ≈ 67%',
					'9'  => '9/12 = 75%',
					'12' => '12/12 = 100%',
				], '6' )
				->select( 'align_items', 'Vertical alignment', [
					'start'   => 'Top',
					'center'  => 'Center',
					'end'     => 'Bottom',
					'stretch' => 'Stretch',
				], 'start' )
				->number( 'gap', 'Gap between children (px)', 16, [ 'min' => 0, 'max' => 80 ] )
			->group( 'Style' )
				->color( 'background', 'Background color', '' )
				->number( 'padding', 'Padding (px)', 0, [ 'min' => 0, 'max' => 80 ] )
				->number( 'radius',  'Corner radius (px)', 0, [ 'min' => 0, 'max' => 32 ] )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$span        = (int) ( $settings['span']       ?? 6 );
		$align_items = $settings['align_items']       ?? 'start';
		$gap         = (int) ( $settings['gap']         ?? 16 );
		$bg          = (string) ( $settings['background'] ?? '' );
		$padding     = (int) ( $settings['padding']    ?? 0 );
		$radius      = (int) ( $settings['radius']     ?? 0 );

		$styles = [
			'--shop-span: '  . $span,
			'--shop-gap: '   . $gap . 'px',
			'--shop-pad: '   . $padding . 'px',
			'--shop-radius: '. $radius . 'px',
		];
		if ( $bg !== '' ) $styles[] = '--shop-bg: ' . $bg;

		$children = (string) ( $context->extras['children'] ?? '' );

		return sprintf(
			'<div class="shop-el shop-el-column shop-el-column--align-%s" style="%s" data-shop-children>%s</div>',
			esc_attr( $align_items ),
			esc_attr( implode( '; ', $styles ) ),
			$children,
		);
	}
}
