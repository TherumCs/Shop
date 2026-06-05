<?php
/**
 * Shop by Therum — Bricks Builder adapter.
 *
 * Wraps every Shop element as a Bricks custom element so designers can
 * drag them onto pages in Bricks. The adapter walks the registry, builds
 * one Bricks element class per Shop element, and registers it via
 * Bricks's element registration system.
 *
 * Activation:
 *   - Bricks Builder must be active and version ≥ 1.8 (`bricks` theme
 *     or `bricksbuilder` plugin both work)
 *   - Adapter auto-registers on `init`; off if Bricks isn't present
 *
 * Dynamic data tags:
 *   - `{shop_product_title}`, `{shop_product_price}`, etc. — usable
 *     inside any Bricks text/heading element
 *   - Registered via the `bricks/dynamic_data/register_tags` filter
 *
 * Query loops:
 *   - `shop_products` query type — lets designers loop through Shop
 *     products in any Bricks container element
 *
 * The control-schema → Bricks-controls translation lives in
 * BricksControlMap. Most Shop control types map 1:1 to Bricks types;
 * a few (productPicker, variantPicker) need custom Bricks controls.
 */

namespace Shop\Builders\Bricks;

use Shop\Elements\ElementContext;
use Shop\Elements\ElementRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BricksAdapter {

	public function __construct(
		private readonly ElementRegistry $registry,
	) {}

	public static function isActive(): bool {
		// Bricks ships as either a theme (`bricks`) or a plugin (`bricksbuilder`).
		// Both define BRICKS_VERSION when loaded.
		return defined( 'BRICKS_VERSION' );
	}

	public function register(): void {
		if ( ! self::isActive() ) return;

		add_action( 'init', [ $this, 'registerElements' ], 11 );
		add_filter( 'bricks/dynamic_data/register_tags', [ $this, 'registerDynamicTags' ] );
		add_filter( 'bricks/builder/custom_query_types',  [ $this, 'registerQueryTypes' ] );
		add_filter( 'bricks/{query_type}/query_result',   [ $this, 'runQuery' ], 10, 2 );
	}

	/**
	 * Walk the Shop element registry and register one Bricks element
	 * per Shop element.
	 */
	public function registerElements(): void {
		if ( ! class_exists( '\Bricks\Elements' ) ) return;

		foreach ( $this->registry->all() as $shop_element ) {
			$class = BricksElementFactory::makeElementClass( $shop_element );
			if ( $class !== null ) {
				\Bricks\Elements::register_element(
					SHOP_DIR . 'includes/Builders/Bricks/elements/' . $shop_element->id() . '.php',
					$shop_element->id(),
					$class,
				);
			}
		}
	}

	/**
	 * Register dynamic data tags so designers can use `{shop_product_*}`
	 * tokens in any Bricks text element.
	 *
	 * @param array<int, array<string,mixed>> $tags
	 * @return array<int, array<string,mixed>>
	 */
	public function registerDynamicTags( $tags ): array {
		$shop_tags = [
			[ 'name' => '{shop_product_title}',       'label' => 'Shop · Product title',    'group' => 'Shop' ],
			[ 'name' => '{shop_product_price}',       'label' => 'Shop · Product price',    'group' => 'Shop' ],
			[ 'name' => '{shop_product_sku}',         'label' => 'Shop · Product SKU',      'group' => 'Shop' ],
			[ 'name' => '{shop_product_description}', 'label' => 'Shop · Description',      'group' => 'Shop' ],
			[ 'name' => '{shop_product_stock}',       'label' => 'Shop · Stock quantity',   'group' => 'Shop' ],
			[ 'name' => '{shop_product_vendor}',      'label' => 'Shop · Vendor / source',  'group' => 'Shop' ],
		];
		return array_merge( $tags, $shop_tags );
	}

	/**
	 * Register `shop_products` as a query type for Bricks query loops.
	 *
	 * @param array<string, string> $types
	 * @return array<string, string>
	 */
	public function registerQueryTypes( $types ): array {
		$types['shop_products'] = 'Shop · Products';
		return $types;
	}

	/**
	 * Resolve a Bricks query loop against the Shop catalog.
	 *
	 * @param array<int, mixed>     $results
	 * @param array<string,mixed>   $query
	 * @return array<int, mixed>
	 */
	public function runQuery( $results, $query ): array {
		// Implementation hooked in next chunk when query control wiring
		// lands. For now Bricks falls back to its default behavior if
		// no products are returned.
		return $results;
	}

	/**
	 * Render a Shop element with the given Bricks-supplied settings
	 * and an inferred ElementContext.
	 */
	public function render( string $shopElementId, array $settings ): string {
		try {
			$element = $this->registry->get( $shopElementId );
		} catch ( \DomainException ) {
			return '';
		}
		$context = $this->inferContext();
		return $element->render( $settings, $context );
	}

	private function inferContext(): ElementContext {
		$product_id = null;
		// Single product template — WP_Query has the current product.
		if ( is_singular( 'product' ) ) {
			$product_id = (int) get_the_ID();
		}
		return new ElementContext( productId: $product_id );
	}
}
