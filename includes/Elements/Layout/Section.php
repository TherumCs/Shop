<?php
/**
 * Shop — Section layout primitive.
 *
 * A full-width band with constrained inner content. The container of
 * containers — every page typically starts with sections.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Section implements Element {

	public function id(): string       { return 'section'; }
	public function name(): string     { return 'Section'; }
	public function category(): string { return 'layout'; }
	public function icon(): string     { return 'layout'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Layout' )
				->select( 'inner_max', 'Inner width', [
					'sm'   => 'Small (640px)',
					'md'   => 'Medium (960px)',
					'lg'   => 'Large (1200px)',
					'xl'   => 'Extra (1440px)',
					'full' => 'Full width',
				], 'lg' )
				->number( 'padding_y', 'Vertical padding (px)', 64, [ 'min' => 0, 'max' => 240 ] )
				->number( 'padding_x', 'Horizontal padding (px)', 24, [ 'min' => 0, 'max' => 80 ] )
				->number( 'gap', 'Gap between children (px)', 16, [ 'min' => 0, 'max' => 120 ] )
			->group( 'Style' )
				->color( 'background', 'Background color', '' )
				->color( 'text_color',  'Text color',       '' )
				->image( 'background_image', 'Background image', null )
				->toggle( 'background_overlay', 'Dark overlay (15%)', false )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$inner_max = $settings['inner_max'] ?? 'lg';
		$pad_y     = (int) ( $settings['padding_y'] ?? 64 );
		$pad_x     = (int) ( $settings['padding_x'] ?? 24 );
		$gap       = (int) ( $settings['gap']       ?? 16 );
		$bg        = (string) ( $settings['background'] ?? '' );
		$fg        = (string) ( $settings['text_color'] ?? '' );
		$bg_image  = (int) ( $settings['background_image'] ?? 0 );
		$overlay   = ! empty( $settings['background_overlay'] );

		$styles = [
			'--shop-pad-y: ' . $pad_y . 'px',
			'--shop-pad-x: ' . $pad_x . 'px',
			'--shop-gap: '   . $gap   . 'px',
		];
		if ( $bg !== '' ) $styles[] = '--shop-bg: ' . $bg;
		if ( $fg !== '' ) $styles[] = '--shop-fg: ' . $fg;
		if ( $bg_image > 0 ) {
			$url = (string) wp_get_attachment_image_url( $bg_image, 'full' );
			if ( $url ) $styles[] = '--shop-bg-image: url(' . $url . ')';
		}

		$class = sprintf(
			'shop-el shop-el-section shop-el-section--%s%s%s',
			esc_attr( $inner_max ),
			$bg_image > 0 ? ' shop-el-section--has-bg' : '',
			$overlay      ? ' shop-el-section--overlay' : '',
		);

		// In a real builder, children render inside `data-shop-children`.
		// Pure passes pre-rendered child HTML via $context->extras['children'].
		$children = (string) ( $context->extras['children'] ?? '' );

		return sprintf(
			'<section class="%s" style="%s"><div class="shop-el-section__inner" data-shop-children>%s</div></section>',
			$class,
			esc_attr( implode( '; ', $styles ) ),
			$children,
		);
	}
}
