<?php
/**
 * Shop — Button element.
 *
 * Static link button. AddToCart is a separate element with its own
 * cart logic; this one is for marketing CTAs, page nav, etc.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Button implements Element {

	public function id(): string       { return 'button'; }
	public function name(): string     { return 'Button'; }
	public function category(): string { return 'content'; }
	public function icon(): string     { return 'mouse-pointer-click'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->text( 'label', 'Label', 'Learn more' )
				->text( 'link',  'Link URL', '#' )
				->toggle( 'new_tab', 'Open in new tab', false )
			->group( 'Style' )
				->select( 'variant', 'Variant', [
					'primary' => 'Primary',
					'outline' => 'Outline',
					'ghost'   => 'Ghost',
					'link'    => 'Link',
				], 'primary' )
				->select( 'size', 'Size', [
					'sm' => 'Small',
					'md' => 'Medium',
					'lg' => 'Large',
				], 'md' )
				->toggle( 'full_width', 'Full width', false )
				->color( 'background', 'Background color', '' )
				->color( 'text_color',  'Text color',      '' )
				->number( 'radius', 'Corner radius (px)', 10, [ 'min' => 0, 'max' => 32 ] )
				->alignment( 'alignment', 'Alignment', 'left' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$label   = (string) ( $settings['label'] ?? 'Click' );
		$link    = (string) ( $settings['link']  ?? '#' );
		$new_tab = ! empty( $settings['new_tab'] );
		$variant = (string) ( $settings['variant'] ?? 'primary' );
		$size    = (string) ( $settings['size']    ?? 'md' );
		$full    = ! empty( $settings['full_width'] );
		$bg      = (string) ( $settings['background'] ?? '' );
		$fg      = (string) ( $settings['text_color'] ?? '' );
		$radius  = (int)    ( $settings['radius']     ?? 10 );
		$align   = $settings['alignment'] ?? 'left';

		$styles = [ '--shop-radius: ' . $radius . 'px' ];
		if ( $bg !== '' ) $styles[] = '--shop-btn-bg: ' . $bg;
		if ( $fg !== '' ) $styles[] = '--shop-btn-fg: ' . $fg;

		$class = sprintf(
			'shop-el shop-el-button shop-el-button--%s shop-el-button--%s%s',
			esc_attr( $variant ),
			esc_attr( $size ),
			$full ? ' shop-el-button--full' : '',
		);

		$target_attr = $new_tab ? ' target="_blank" rel="noopener"' : '';

		return sprintf(
			'<div class="shop-el-button-wrap shop-el--align-%s"><a class="%s" style="%s" href="%s"%s>%s</a></div>',
			esc_attr( $align ),
			$class,
			esc_attr( implode( '; ', $styles ) ),
			esc_url( $link ),
			$target_attr,
			esc_html( $label ),
		);
	}
}
