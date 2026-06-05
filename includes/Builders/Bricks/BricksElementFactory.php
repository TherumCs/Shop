<?php
/**
 * Shop by Therum — Bricks element factory.
 *
 * Builds Bricks element classes at runtime from Shop element schemas.
 * Each Shop element gets its own subclass of \Bricks\Element wrapping
 * the headless Shop element.
 *
 * The translation logic for Shop control schemas → Bricks controls
 * lives in BricksControlMap. The classes generated here are thin
 * adapters — register controls, hand settings to Shop's render(),
 * echo the result.
 */

namespace Shop\Builders\Bricks;

use Shop\Container;
use Shop\Elements\Element;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BricksElementFactory {

	/**
	 * Returns the fully-qualified class name of the runtime-generated
	 * Bricks element class for this Shop element, or null if Bricks
	 * isn't loaded.
	 */
	public static function makeElementClass( Element $shopElement ): ?string {
		if ( ! class_exists( '\Bricks\Element' ) ) return null;

		$shop_id    = $shopElement->id();
		$class_name = 'Shop_Bricks_' . str_replace( '-', '_', ucwords( $shop_id, '-' ) );
		$class_name = preg_replace( '/[^A-Za-z0-9_]/', '', $class_name );

		if ( class_exists( $class_name ) ) return $class_name;

		// Anonymous-extend pattern via eval. Bricks expects a real class
		// at registration time, so we can't return an anonymous instance.
		$controls = wp_json_encode( BricksControlMap::translate( $shopElement->controls() ) );

		$code = <<<PHP
class {$class_name} extends \\Bricks\\Element {
	public \$category    = 'shop';
	public \$name        = '{$shop_id}';
	public \$icon        = 'ti-package';
	public \$css_selector = '.shop-el';

	public function get_label(): string {
		return '{$shopElement->name()}';
	}

	public function set_controls(): void {
		\$this->controls = json_decode( '{$controls}', true ) ?: [];
	}

	public function render(): void {
		\$settings = \$this->settings ?? [];
		\$adapter  = \\Shop\\Container::instance()->get( \\Shop\\Builders\\Bricks\\BricksAdapter::class );
		echo \$adapter->render( '{$shop_id}', \$settings );
	}
}
PHP;
		eval( $code );

		return $class_name;
	}
}
