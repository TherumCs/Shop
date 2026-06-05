<?php
/**
 * Shop — Site logo element (header essential).
 *
 * Falls back gracefully:
 *   1. If a custom image was picked, render that.
 *   2. Otherwise pull the WP custom-logo if the theme registered one.
 *   3. Otherwise render the site name as text.
 */

namespace Shop\Elements\Chrome;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SiteLogo implements Element {

	public function id(): string       { return 'site-logo'; }
	public function name(): string     { return 'Site logo'; }
	public function category(): string { return 'chrome'; }
	public function icon(): string     { return 'logo'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->image(  'image',      'Logo image' )
			->number( 'height',     'Height (px)',  default: 32 )
			->text(   'link',       'Link to',      default: '/' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$height = (int) ( $settings['height'] ?? 32 );
		$link   = (string) ( $settings['link'] ?? '/' );
		$img    = (int) ( $settings['image'] ?? 0 );

		$inner = '';
		if ( $img > 0 ) {
			$src = wp_get_attachment_image_url( $img, 'medium' );
			if ( $src ) $inner = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="height:' . $height . 'px;width:auto;" />';
		}
		if ( $inner === '' ) {
			$custom = get_theme_mod( 'custom_logo' );
			if ( $custom ) {
				$src = wp_get_attachment_image_url( (int) $custom, 'medium' );
				if ( $src ) $inner = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="height:' . $height . 'px;width:auto;" />';
			}
		}
		if ( $inner === '' ) {
			$inner = '<span class="shop-el-logo__text">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
		}

		return '<a href="' . esc_url( $link ) . '" class="shop-el shop-el-logo">' . $inner . '</a>';
	}
}
