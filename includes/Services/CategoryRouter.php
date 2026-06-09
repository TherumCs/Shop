<?php
/**
 * Counter by Therum — CategoryRouter.
 *
 * Owns the `product_cat` taxonomy when Counter is the storefront engine.
 * In Pure / Counter-takeover mode WooCommerce is typically deactivated, so
 * without this shim:
 *
 *   - `product_cat` doesn't exist as a registered taxonomy
 *   - `wp_setup_nav_menu_item()` marks every menu item linked to a
 *     product category as `_invalid` → frontend nav silently drops them
 *   - `get_term_link()` returns WP_Error so menu items also have no URL
 *
 * Responsibilities:
 *
 *   1. Register `product_cat` only when nothing else has (idempotent —
 *      Woo or another plugin already owning it short-circuits us).
 *   2. Add a `^c/{slug}/?$` rewrite pointing at the existing shop
 *      archive route, with a `counter_category` query var so the
 *      archive render can scope products to the category later.
 *   3. Filter `term_link` for product_cat terms so `get_term_link()`
 *      consistently returns `/c/{slug}/` regardless of taxonomy slug
 *      mismatches between Woo and Counter.
 *
 * Wired from counter.php alongside PageRouter when
 * `PageRouter::isActive()` is true (the same gate the rest of
 * Counter's storefront takeover uses).
 */

namespace Counter\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CategoryRouter {

	public const TAXONOMY = 'product_cat';
	public const URL_BASE = 'c';
	public const QUERY_VAR = 'counter_category';

	public function register(): void {
		if ( ! PageRouter::isActive() ) return;

		// Register the taxonomy at priority 4 — earlier than WooCommerce
		// (priority 5 / 10 depending on version) so we skip cleanly when
		// Woo is also active. Our own check inside registerTaxonomy()
		// uses taxonomy_exists() to make this 100% defensive.
		add_action( 'init',       [ $this, 'registerTaxonomy' ], 4 );
		add_action( 'init',       [ $this, 'addRewrites' ],     11 );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_filter( 'term_link',  [ $this, 'filterTermLink' ], 10, 3 );
	}

	/**
	 * Register product_cat taxonomy unless another plugin already has.
	 *
	 * We register it as PUBLIC so:
	 *   - menu items pass `_is_valid_nav_menu_item()`
	 *   - `get_term_link()` returns a real URL (we override the slug below)
	 *   - admin term UI still works for editing / reordering
	 */
	public function registerTaxonomy(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) return;

		register_taxonomy( self::TAXONOMY, [ 'product' ], [
			'label'              => 'Product Categories',
			'labels'             => [
				'name'          => 'Product Categories',
				'singular_name' => 'Product Category',
				'menu_name'     => 'Categories',
				'all_items'     => 'All Categories',
				'edit_item'     => 'Edit Category',
				'view_item'     => 'View Category',
				'update_item'   => 'Update Category',
				'add_new_item'  => 'Add New Category',
				'new_item_name' => 'New Category Name',
				'search_items'  => 'Search Categories',
			],
			'public'             => true,
			'publicly_queryable' => true,
			'hierarchical'       => true,
			'show_ui'            => true,
			'show_in_menu'       => false,         // Counter has its own admin page; hide the WP default
			'show_in_nav_menus'  => true,           // CRITICAL: makes terms appear in nav menu UI + survive validity check
			'show_admin_column'  => false,
			'show_in_rest'       => true,
			'rewrite'            => [
				'slug'         => self::URL_BASE,
				'hierarchical' => true,
				'with_front'   => false,
			],
			'capabilities'       => [
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			],
		] );
	}

	/**
	 * Add the `/c/{slug}/` rewrite. Maps to the same shop archive route
	 * Counter already owns, with `counter_category` as an extra query
	 * var so the archive render can scope by category.
	 *
	 * The hierarchical wildcard `(.+?)` (instead of `[^/]+`) lets sub-
	 * categories work (`/c/mens/shoes/`) the same way WordPress core
	 * does it for hierarchical taxonomies.
	 */
	public function addRewrites(): void {
		if ( ! PageRouter::isActive() ) return;
		add_rewrite_rule(
			'^' . self::URL_BASE . '/(.+?)/?$',
			'index.php?counter_pure_archive=1&' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Force the `/c/{slug}/` URL even if WP's rewrite-rules cache is
	 * stale or a sibling plugin altered the rewrite slug. Safer than
	 * trusting the rewrite cache.
	 *
	 * Signature matches WP's term_link filter:
	 *   apply_filters( 'term_link', $termlink, $term, $taxonomy ).
	 */
	public function filterTermLink( string $termlink, $term, string $taxonomy ): string {
		if ( $taxonomy !== self::TAXONOMY ) return $termlink;
		$slug = is_object( $term ) ? (string) ( $term->slug ?? '' ) : '';
		if ( $slug === '' ) return $termlink;
		// Honor hierarchical slug — if the term has a parent, prepend the
		// parent's path. get_term_link normally handles this when our
		// rewrite is registered, but we re-derive here as a backstop.
		$path = $slug;
		if ( is_object( $term ) && ! empty( $term->parent ) ) {
			$ancestors = get_ancestors( (int) $term->term_id, self::TAXONOMY );
			$ancestors = array_reverse( $ancestors );
			$parts     = [];
			foreach ( $ancestors as $aid ) {
				$a = get_term( $aid, self::TAXONOMY );
				if ( $a && ! is_wp_error( $a ) && ! empty( $a->slug ) ) {
					$parts[] = (string) $a->slug;
				}
			}
			$parts[] = $slug;
			$path    = implode( '/', $parts );
		}
		return home_url( '/' . self::URL_BASE . '/' . $path . '/' );
	}
}
