<?php
/**
 * Shop by Therum — Elementor widget factory.
 *
 * Generates a `\Elementor\Widget_Base` subclass per Shop element via
 * `eval()`. This is the same trick BricksElementFactory uses — it lets
 * Shop define elements once in `Shop\Elements\*` and have them appear
 * in every supported builder without writing N adapter classes.
 *
 * The generated class:
 *   - get_name()     returns 'shop-{element-id}'
 *   - get_title()    returns the element's display name
 *   - get_icon()     returns 'eicon-store' (Shop branding everywhere)
 *   - get_categories() returns [ 'shop-by-therum' ]
 *   - _register_controls() builds Elementor controls from the element's
 *     control schema via ElementorControlMap
 *   - render() delegates to the Shop element's render() with the
 *     Elementor `get_settings_for_display()` array
 */

namespace Shop\Builders\Elementor;

use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ElementorWidgetFactory {

	/** @var array<string, class-string> */
	private static array $generated = [];

	public static function generate( Element $el ): string {
		$elementId = $el->id();
		if ( isset( self::$generated[ $elementId ] ) ) return self::$generated[ $elementId ];

		$className = 'ShopElementor_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $elementId );
		$elementClass = '\\' . get_class( $el );

		// Build the control registration code at generation time so we
		// don't reparse the schema on every render.
		$controls = $el->controls();
		$controlsCode = ElementorControlMap::php( $controls );

		eval( <<<PHP
		final class $className extends \\Elementor\\Widget_Base {
			public function get_name() { return 'shop-{$elementId}'; }
			public function get_title() { return '{$el->name()}'; }
			public function get_icon() { return 'eicon-store'; }
			public function get_categories() { return [ 'shop-by-therum' ]; }
			protected function register_controls() {
				\$this->start_controls_section( 'shop_main', [
					'label' => 'Settings',
					'tab'   => \\Elementor\\Controls_Manager::TAB_CONTENT,
				] );
				$controlsCode
				\$this->end_controls_section();
			}
			protected function render() {
				\$settings = \$this->get_settings_for_display();
				\$el = \\Shop\\Container::instance()->get( \\Shop\\Elements\\ElementRegistry::class )->get( '{$elementId}' );
				if ( \$el ) {
					echo \$el->render( (array) \$settings, new \\Shop\\Elements\\ElementContext() );
				}
			}
		}
PHP );
		self::$generated[ $elementId ] = $className;
		return $className;
	}
}
