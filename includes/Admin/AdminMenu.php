<?php
/**
 * Shop by Therum — admin menu registration.
 *
 * Top-level "Shop" menu in the WP admin sidebar with sub-pages:
 *
 *   Settings   — cart/checkout presentation, product source, button position
 *   Products   — spreadsheet-style manager (inline edit, bulk actions)
 *   Import     — universal catalog importer wizard
 *   Orders     — list/detail (next chunk — uses same grid component as Products)
 *
 * Each sub-page is its own class so the menu file stays a thin shell.
 */

namespace Shop\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminMenu {

	public function __construct(
		private readonly SettingsPage     $settings,
		private readonly ImporterPage     $importer,
		private readonly ProductsPage     $products,
		private readonly OrdersPage       $orders,
		private readonly BuilderPage      $builder,
		private readonly StudioPayPage    $studioPay,
		private readonly CustomersPage    $customers,
		private readonly OrderIoPage      $orderIo,
	) {}

	public function register(): void {
		add_action( 'admin_menu',   [ $this, 'menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menus(): void {
		add_menu_page(
			page_title: __( 'Shop by Therum', 'shop' ),
			menu_title: __( 'Shop', 'shop' ),
			capability: 'manage_woocommerce',
			menu_slug:  'shop',
			callback:   [ $this->settings, 'render' ],
			icon_url:   'dashicons-cart',
			position:   58,
		);

		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Settings', 'shop' ),
			menu_title:  __( 'Settings', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop',
			callback:    [ $this->settings, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Products', 'shop' ),
			menu_title:  __( 'Products', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-products',
			callback:    [ $this->products, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Orders', 'shop' ),
			menu_title:  __( 'Orders', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-orders',
			callback:    [ $this->orders, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Import Catalog', 'shop' ),
			menu_title:  __( 'Import', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-import',
			callback:    [ $this->importer, 'render' ],
		);

		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Customers', 'shop' ),
			menu_title:  __( 'Customers', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-customers',
			callback:    [ $this->customers, 'render' ],
		);
		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Order Import / Export', 'shop' ),
			menu_title:  __( 'Order I/O', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-orders-io',
			callback:    [ $this->orderIo, 'render' ],
		);
		add_submenu_page(
			parent_slug: 'shop',
			page_title:  __( 'Studio Pay', 'shop' ),
			menu_title:  __( 'Studio Pay', 'shop' ),
			capability:  'manage_woocommerce',
			menu_slug:   'shop-studio-pay',
			callback:    [ $this->studioPay, 'render' ],
		);

		// Pure page builder — only when the active mode wants it.
		if ( \Shop\Mode::loadsPureBuilder() ) {
			add_submenu_page(
				parent_slug: 'shop',
				page_title:  __( 'Pages', 'shop' ),
				menu_title:  __( 'Pages', 'shop' ),
				capability:  'manage_woocommerce',
				menu_slug:   'shop-pages',
				callback:    [ $this->builder, 'renderPageList' ],
			);

			// The actual builder canvas — hidden from the menu but
			// reachable via ?page=shop-builder&page_id=X
			add_submenu_page(
				parent_slug: null,
				page_title:  __( 'Builder', 'shop' ),
				menu_title:  __( 'Builder', 'shop' ),
				capability:  'manage_woocommerce',
				menu_slug:   'shop-builder',
				callback:    [ $this->builder, 'render' ],
			);
		}
	}

	public function assets( string $hook ): void {
		// Only enqueue on Shop's own admin pages.
		if ( strpos( $hook, 'shop' ) === false ) return;

		wp_register_style( 'shop-admin', SHOP_URL . 'assets/admin/admin.css', [], SHOP_VERSION );
		wp_enqueue_style( 'shop-admin' );

		$rest_config = wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] );

		if ( str_contains( $hook, 'shop-import' ) ) {
			wp_register_script( 'shop-importer', SHOP_URL . 'assets/admin/importer.js', [], SHOP_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'shop-importer',
				'window.ShopImporterConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'shop-importer' );
		}

		if ( str_contains( $hook, 'shop-products' ) ) {
			wp_register_script( 'shop-products-grid', SHOP_URL . 'assets/admin/products-grid.js', [], SHOP_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'shop-products-grid',
				'window.ShopAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'shop-products-grid' );
		}

		if ( str_contains( $hook, 'shop-orders' ) ) {
			wp_register_script( 'shop-orders-grid', SHOP_URL . 'assets/admin/orders-grid.js', [], SHOP_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'shop-orders-grid',
				'window.ShopAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'shop-orders-grid' );
		}

		if ( str_contains( $hook, 'shop-builder' ) ) {
			// Builder is full-bleed — hide standard admin chrome by
			// adding a body class.
			add_filter( 'admin_body_class', fn( $c ) => $c . ' shop-builder-active' );

			// WP media library is used by the image picker
			wp_enqueue_media();

			wp_register_style(  'shop-builder-css', SHOP_URL . 'assets/builder/builder.css', [], SHOP_VERSION );
			wp_enqueue_style(   'shop-builder-css' );

			// ES module — Preact + htm from esm.sh, no build step
			wp_register_script_module( 'shop-builder',
				SHOP_URL . 'assets/builder/builder.js',
				[],
				SHOP_VERSION,
			);
			wp_enqueue_script_module( 'shop-builder' );
		}
	}
}
