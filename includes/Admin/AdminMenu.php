<?php
/**
 * Counter by Therum — admin menu registration.
 *
 * Top-level "Counter" menu in the WP admin sidebar with sub-pages:
 *
 *   Settings   — cart/checkout presentation, product source, button position
 *   Products   — spreadsheet-style manager (inline edit, bulk actions)
 *   Import     — universal catalog importer wizard
 *   Orders     — list/detail (next chunk — uses same grid component as Products)
 *
 * Each sub-page is its own class so the menu file stays a thin shell.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminMenu {

	public function __construct(
		private readonly SettingsPage               $settings,
		private readonly ImporterPage               $importer,
		private readonly ProductsPage               $products,
		private readonly OrdersPage                 $orders,
		private readonly BuilderPage                $builder,
		private readonly StudioPayPage              $studioPay,
		private readonly CustomersPage              $customers,
		private readonly OrderIoPage                $orderIo,
		private readonly UpdatesPage                $updates,
		private readonly ProductCategoryOrderPage   $categoryOrder,
		private readonly ProductVariantOrderPage    $variantOrder,
		private readonly CustomTaxonomyOrderPage    $taxonomyOrder,
	) {}

	public function register(): void {
		add_action( 'admin_menu',   [ $this, 'menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menus(): void {
		add_menu_page(
			page_title: __( 'Counter by Therum', 'counter' ),
			menu_title: __( 'Counter', 'counter' ),
			capability: 'manage_woocommerce',
			menu_slug:   'counter',
			callback:   [ $this->settings, 'render' ],
			icon_url:   'dashicons-cart',
			position:   58,
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Settings', 'counter' ),
			menu_title:  __( 'Settings', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter',
			callback:    [ $this->settings, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Products', 'counter' ),
			menu_title:  __( 'Products', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-products',
			callback:    [ $this->products, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Orders', 'counter' ),
			menu_title:  __( 'Orders', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-orders',
			callback:    [ $this->orders, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Import Catalog', 'counter' ),
			menu_title:  __( 'Import', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-import',
			callback:    [ $this->importer, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Customers', 'counter' ),
			menu_title:  __( 'Customers', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-customers',
			callback:    [ $this->customers, 'render' ],
		);
		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Order Import / Export', 'counter' ),
			menu_title:  __( 'Order I/O', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-orders-io',
			callback:    [ $this->orderIo, 'render' ],
		);
		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Studio Pay', 'counter' ),
			menu_title:  __( 'Studio Pay', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-studio-pay',
			callback:    [ $this->studioPay, 'render' ],
		);
		// Updates is gated on `manage_options` (full WP admin) since
		// it can rewrite the plugin filesystem.
		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Updates', 'counter' ),
			menu_title:  __( 'Updates', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-updates',
			callback:    [ $this->updates, 'render' ],
		);

		// Taxonomy ordering — manage term display order hierarchically.
		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Category Order', 'counter' ),
			menu_title:  __( 'Category Order', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-categories-order',
			callback:    [ $this->categoryOrder, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Variant Order', 'counter' ),
			menu_title:  __( 'Variant Order', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-variants-order',
			callback:    [ $this->variantOrder, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'counter',
			page_title:  __( 'Taxonomy Order', 'counter' ),
			menu_title:  __( 'Taxonomy Order', 'counter' ),
			capability:  'manage_woocommerce',
			menu_slug:   'counter-taxonomies',
			callback:    [ $this->taxonomyOrder, 'render' ],
		);

		// Pure page builder — only when the active mode wants it.
		if ( \Counter\Mode::loadsPureBuilder() ) {
			add_submenu_page(
				parent_slug: 'counter',
				page_title:  __( 'Pages', 'counter' ),
				menu_title:  __( 'Pages', 'counter' ),
				capability:  'manage_woocommerce',
				menu_slug:   'counter-pages',
				callback:    [ $this->builder, 'renderPageList' ],
			);

			// The actual builder canvas — hidden from the menu but
			// reachable via ?page=counter-builder&page_id=X
			add_submenu_page(
				parent_slug: null,
				page_title:  __( 'Builder', 'counter' ),
				menu_title:  __( 'Builder', 'counter' ),
				capability:  'manage_woocommerce',
				menu_slug:   'counter-builder',
				callback:    [ $this->builder, 'render' ],
			);
		}
	}

	public function assets( string $hook ): void {
		// Only enqueue on Counter's own admin pages.
		if ( strpos( $hook, 'shop' ) === false ) return;

		wp_register_style( 'counter-admin', COUNTER_URL . 'assets/admin/admin.css', [], COUNTER_VERSION );
		wp_enqueue_style( 'counter-admin' );

		$rest_config = wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] );

		if ( str_contains( $hook, 'counter-import' ) ) {
			wp_register_script( 'counter-importer', COUNTER_URL . 'assets/admin/importer.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-importer',
				'window.ShopImporterConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-importer' );
		}

		if ( str_contains( $hook, 'counter-products' ) ) {
			wp_register_script( 'counter-products-grid', COUNTER_URL . 'assets/admin/products-grid.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-products-grid',
				'window.ShopAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-products-grid' );

			// Product editor drawer — ES module (Preact + htm via esm.sh).
			// Registered with `type=module` filter so WP emits the right
			// script tag attribute.
			wp_register_style(  'counter-product-editor', COUNTER_URL . 'assets/admin/product-editor.css', [], COUNTER_VERSION );
			wp_enqueue_style(   'counter-product-editor' );
			add_filter( 'script_loader_tag', function ( $tag, $handle ) {
				if ( $handle === 'counter-product-editor' ) {
					return str_replace( '<script ', '<script type="module" ', $tag );
				}
				return $tag;
			}, 10, 2 );
			wp_register_script( 'counter-product-editor', COUNTER_URL . 'assets/admin/product-editor.js', [], COUNTER_VERSION, [ 'in_footer' => true ] );
			wp_enqueue_script(  'counter-product-editor' );
		}

		if ( str_contains( $hook, 'counter-orders' ) ) {
			wp_register_script( 'counter-orders-grid', COUNTER_URL . 'assets/admin/orders-grid.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-orders-grid',
				'window.ShopAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-orders-grid' );
		}

		if ( str_contains( $hook, 'counter-builder' ) ) {
			// Builder is full-bleed — hide standard admin chrome by
			// adding a body class.
			add_filter( 'admin_body_class', fn( $c ) => $c . ' counter-builder-active' );

			// WP media library is used by the image picker
			wp_enqueue_media();

			wp_register_style(  'counter-builder-css', COUNTER_URL . 'assets/builder/builder.css', [], COUNTER_VERSION );
			wp_enqueue_style(   'counter-builder-css' );

			// ES module — Preact + htm from esm.sh, no build step
			wp_register_script_module( 'counter-builder',
				COUNTER_URL . 'assets/builder/builder.js',
				[],
				COUNTER_VERSION,
			);
			wp_enqueue_script_module( 'counter-builder' );
		}

		// Taxonomy ordering pages — drag-drop term reordering
		if ( str_contains( $hook, 'counter-categories-order' ) || str_contains( $hook, 'counter-variants-order' ) || str_contains( $hook, 'counter-taxonomies' ) ) {
			// Load Sortable.js from CDN
			wp_register_script( 'sortable', 'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js', [], '1.15.0', [ 'strategy' => 'defer' ] );

			wp_register_style(  'counter-taxonomy-order', COUNTER_URL . 'assets/admin/taxonomy-order.css', [], COUNTER_VERSION );
			wp_register_script( 'counter-taxonomy-order', COUNTER_URL . 'assets/admin/taxonomy-order.js', [ 'sortable' ], COUNTER_VERSION, [ 'strategy' => 'defer' ] );

			wp_enqueue_style(  'counter-taxonomy-order' );
			wp_enqueue_script( 'counter-taxonomy-order' );
		}
	}
}
