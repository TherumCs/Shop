<?php
/**
 * Shop — Site nav element.
 *
 * Two modes — explicit links typed into a textarea (one per line as
 * "Label|/url"), or a WP menu by slug. Defaults to explicit so it
 * works out-of-the-box without anyone touching Appearance → Menus.
 */

namespace Shop\Elements\Chrome;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SiteNav implements Element {

	public function id(): string       { return 'site-nav'; }
	public function name(): string     { return 'Site nav'; }
	public function category(): string { return 'chrome'; }
	public function icon(): string     { return 'nav'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->select( 'source', 'Source', [ 'explicit' => 'Explicit list', 'wp_menu' => 'WP menu' ], default: 'explicit' )
			->textarea( 'links', 'Links (one per line: Label|/url)', default: "Shop|/shop/\nCart|/cart/" )
			->text(     'wp_menu_slug', 'WP menu slug',          default: 'primary' )
			->select(   'alignment',    'Alignment',             [ 'start' => 'Start', 'center' => 'Center', 'end' => 'End' ], default: 'end' )
			->number(   'gap',          'Gap (px)',              default: 20 )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$source = (string) ( $settings['source'] ?? 'explicit' );
		$gap    = (int) ( $settings['gap'] ?? 20 );
		$align  = (string) ( $settings['alignment'] ?? 'end' );

		$items_html = '';
		if ( $source === 'wp_menu' ) {
			$items_html = wp_nav_menu( [
				'menu'        => (string) ( $settings['wp_menu_slug'] ?? 'primary' ),
				'container'   => false,
				'items_wrap'  => '%3$s',
				'depth'       => 1,
				'echo'        => false,
				'fallback_cb' => '__return_empty_string',
			] );
		} else {
			$lines = preg_split( '/\r?\n/', (string) ( $settings['links'] ?? '' ) ) ?: [];
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line === '' ) continue;
				[ $label, $url ] = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				if ( $label === '' ) continue;
				$items_html .= '<a class="shop-el-nav__link" href="' . esc_url( $url ?: '#' ) . '">' . esc_html( $label ) . '</a>';
			}
		}

		$style = 'gap:' . $gap . 'px;justify-content:' . ( $align === 'start' ? 'flex-start' : ( $align === 'center' ? 'center' : 'flex-end' ) ) . ';';
		return '<nav class="shop-el shop-el-nav" style="' . esc_attr( $style ) . '">' . $items_html . '</nav>';
	}
}
