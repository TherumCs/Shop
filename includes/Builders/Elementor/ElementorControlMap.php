<?php
/**
 * Shop by Therum — Shop control schema → Elementor controls.
 *
 * Returns a PHP-source string that, when eval'd inside an Elementor
 * widget's `register_controls()`, registers the equivalent controls.
 *
 * Type mapping:
 *   text       → Controls_Manager::TEXT
 *   textarea   → Controls_Manager::TEXTAREA
 *   number     → Controls_Manager::NUMBER
 *   toggle     → Controls_Manager::SWITCHER
 *   select     → Controls_Manager::SELECT
 *   color      → Controls_Manager::COLOR
 *   image      → Controls_Manager::MEDIA  (Elementor's media picker)
 *   alignment  → Controls_Manager::CHOOSE (icon row)
 */

namespace Shop\Builders\Elementor;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ElementorControlMap {

	/**
	 * @param array<int, array<string,mixed>> $controls
	 */
	public static function php( array $controls ): string {
		$out = '';
		foreach ( $controls as $c ) {
			$id    = (string) ( $c['id']    ?? '' );
			$label = (string) ( $c['label'] ?? $id );
			$type  = (string) ( $c['type']  ?? 'text' );
			$def   = $c['default'] ?? '';

			$args = [
				'label'   => $label,
				'default' => $def,
			];
			switch ( $type ) {
				case 'textarea':
					$args['type'] = '\\Elementor\\Controls_Manager::TEXTAREA'; break;
				case 'number':
					$args['type'] = '\\Elementor\\Controls_Manager::NUMBER'; break;
				case 'toggle':
					$args['type']  = '\\Elementor\\Controls_Manager::SWITCHER';
					$args['default'] = $def ? 'yes' : '';
					break;
				case 'select':
					$args['type']    = '\\Elementor\\Controls_Manager::SELECT';
					$args['options'] = (array) ( $c['options'] ?? [] );
					break;
				case 'color':
					$args['type'] = '\\Elementor\\Controls_Manager::COLOR'; break;
				case 'image':
					$args['type']    = '\\Elementor\\Controls_Manager::MEDIA';
					$args['default'] = [ 'url' => '' ];
					break;
				case 'alignment':
					$args['type'] = '\\Elementor\\Controls_Manager::CHOOSE';
					$args['options'] = [
						'left'   => [ 'title' => 'Left',   'icon' => 'eicon-text-align-left' ],
						'center' => [ 'title' => 'Center', 'icon' => 'eicon-text-align-center' ],
						'right'  => [ 'title' => 'Right',  'icon' => 'eicon-text-align-right' ],
					];
					break;
				default:
					$args['type'] = '\\Elementor\\Controls_Manager::TEXT';
			}

			$out .= "\$this->add_control( " . var_export( $id, true ) . ", [\n";
			foreach ( $args as $k => $v ) {
				$out .= "\t" . var_export( $k, true ) . " => ";
				if ( is_string( $v ) && str_starts_with( $v, '\\Elementor\\' ) ) {
					$out .= $v;
				} else {
					$out .= var_export( $v, true );
				}
				$out .= ",\n";
			}
			$out .= "] );\n";
		}
		return $out;
	}
}
