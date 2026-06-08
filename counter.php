<?php
/**
 * Plugin Name:       Counter by Therum
 * Plugin URI:        https://therum.studio/plugins/counter
 * Description:       A native commerce engine built for speed. One product entity with capability toggles (variants, shipping, digital delivery, POD routing), purpose-built SQLite schema, unified cart/checkout session, typed events + pipelines instead of hook spam. Pluggable payment, tax, shipping, and fulfillment providers via Nexus by Therum.
 * Version:           0.29.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Therum Creative Studios
 * Author URI:        https://therum.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       counter
 *
 * ─── DEPLOYMENT ────────────────────────────────────────────────────────────
 * Intended to run as a MUST-USE plugin under wp-content/mu-plugins/. See
 * mu-plugin-loader.php in this directory for the one-line bootstrap that
 * WordPress auto-loads. Always-active; no admin enable/disable; no client
 * foot-gun. First-run migration happens on the next admin page load.
 *
 * Will also run as a regular plugin under wp-content/plugins/ — the
 * activation hook on Migrations::run() handles first-install in that case.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'COUNTER_VERSION', '0.39.1' );
define( 'COUNTER_FILE', __FILE__ );
define( 'COUNTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'COUNTER_URL', plugin_dir_url( __FILE__ ) );

// ──────────────────────────────────────────────────────────────────────────
//  Autoload
// ──────────────────────────────────────────────────────────────────────────
//
// PSR-4 lite. Classes under the `Counter\` namespace live in `includes/` with
// directory-per-subnamespace. e.g.:
//   Counter\DB                            → includes/DB.php
//   Counter\Services\CartService          → includes/Services/CartService.php
//   Counter\Events\OrderPaid              → includes/Events/OrderPaid.php
//
// No Composer dependency — we want this plugin to drop into any WP install.
spl_autoload_register( function ( string $class ): void {
	// 'Counter\\' is 8 characters — was 5 ('Shop\\') before the rename;
	// keep the count consistent with the namespace length or every
	// autoload will silently fail.
	if ( strncmp( $class, 'Counter\\', 8 ) !== 0 ) {
		return;
	}
	$relative = str_replace( '\\', '/', substr( $class, 8 ) );
	$path     = COUNTER_DIR . 'includes/' . $relative . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
} );

// Legacy free-function shim — old activation hooks may reference it.
require_once COUNTER_DIR . 'includes/migrations.php';

// ──────────────────────────────────────────────────────────────────────────
//  WooCommerce compatibility declarations
// ──────────────────────────────────────────────────────────────────────────
//
// MUST run on `before_woocommerce_init`. Tells Woo we're compatible with:
//
//   custom_order_tables   — High-Performance Order Storage (HPOS). Our
//                           order data lives in our own SQLite, and any
//                           WC_Order we create via WooOrderMirror uses
//                           the wc_create_order() factory which is
//                           HPOS-aware. We never query wp_posts for orders.
//
//   cart_checkout_blocks  — Woo's Gutenberg-based cart/checkout blocks.
//                           We replace those surfaces entirely with our
//                           own templates, so there's no conflict.
//
//   product_block_editor  — Woo's block-based product editor. We don't
//                           modify the product edit screen; we only READ
//                           via wc_get_product(), so we're transparent
//                           to whichever editor is active.
//
// Without these declarations modern Woo (8.2+) shows incompatibility
// warnings in the admin even when nothing's actually broken.
add_action( 'before_woocommerce_init', function (): void {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) return;

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables', __FILE__, true
	);
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'cart_checkout_blocks', __FILE__, true
	);
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'product_block_editor', __FILE__, true
	);
} );

// ──────────────────────────────────────────────────────────────────────────
//  Bootstrap
// ──────────────────────────────────────────────────────────────────────────

// Run migrations on activation — creates the SQLite file + schema on first install.
register_activation_hook( __FILE__, [ \Counter\Migrations::class, 'run' ] );

// Catch-up on every admin load: if the schema version in the SQLite file is
// behind Schema::VERSION (e.g. user updated via FTP without re-activating),
// run migrations. Cheap — one SELECT MAX(version) FROM schema_version.
add_action( 'admin_init', function (): void {
	// Ensure Schema is autoloaded before accessing
	if ( ! class_exists( \Counter\Schema::class ) ) {
		require_once COUNTER_DIR . 'includes/Schema.php';
	}

	try {
		if ( \Counter\Migrations::currentVersion() < \Counter\Schema::VERSION ) {
			\Counter\Migrations::run();
		}
	} catch ( \Throwable $e ) {
		// First-run before the file exists, or genuine DB error. Force a full
		// run; if THAT fails, surface it in the admin notice rather than
		// silently breaking the site.
		try {
			\Counter\Migrations::run();
		} catch ( \Throwable $e2 ) {
			add_action( 'admin_notices', function () use ( $e2 ): void {
				echo '<div class="notice notice-error"><p><strong>Counter by Therum:</strong> migration failed — ' . esc_html( $e2->getMessage() ) . '</p></div>';
			} );
		}
	}

	// Auto-configure product source: if not explicitly set AND WooCommerce is
	// detected, default to 'woo' mode (read from WooCommerce products).
	// Users can override in Counter → Settings if they want native mode instead.
	$source = get_option( 'counter_product_source' );
	if ( $source === false && ( defined( 'WC_VERSION' ) || function_exists( 'wc_get_product' ) ) ) {
		update_option( 'counter_product_source', 'woo' );
	}

	// Auto-import existing WooCommerce orders on first setup
	$woo_orders_imported = (bool) get_option( 'counter_woo_orders_imported' );
	if ( ! $woo_orders_imported && function_exists( 'wc_get_orders' ) ) {
		try {
			$importer = new \Counter\Services\WooOrderImporter();
			$imported = $importer->importFromWooCommerce();
			if ( $imported > 0 ) {
				update_option( 'counter_woo_orders_imported', true );
				error_log( "Counter: Imported $imported WooCommerce orders" );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Counter WooCommerce order import failed: ' . $e->getMessage() );
		}
	}
} );

// Register default service bindings. This is the wiring map — read this to
// understand what's swappable. Bindings can be overridden by other code that
// runs after `plugins_loaded` priority 0.
//
// Also hooked to `shop_bootstrap_container_for_activation` so the
// activation hook (which fires post-plugins_loaded on first install)
// can populate the container before reading from it.
add_action( 'counter_bootstrap_container_for_activation', 'counter_register_container_bindings' );
// Priority 0 — must run before any other plugins_loaded callback that
// reads from the container (admin menu at 5, REST registration, etc).
add_action( 'plugins_loaded', 'counter_register_container_bindings', 0 );
function counter_register_container_bindings(): void {
	static $done = false;
	if ( $done ) return;
	$done = true;
	$c = \Counter\Container::instance();

	// ─── Event bus ──────────────────────────────────────────────────────
	$c->singleton( \Counter\Events\EventBus::class, fn() => new \Counter\Events\EventBus() );

	// ─── Repositories ───────────────────────────────────────────────────
	$c->singleton( \Counter\Repositories\CartRepository::class,
		fn() => new \Counter\Repositories\CartRepository() );
	$c->singleton( \Counter\Repositories\CouponRepository::class,
		fn() => new \Counter\Repositories\CouponRepository() );
	$c->singleton( \Counter\Repositories\RefundRepository::class,
		fn() => new \Counter\Repositories\RefundRepository() );
	$c->singleton( \Counter\Repositories\AttributeRepository::class,
		fn() => new \Counter\Repositories\AttributeRepository() );
	$c->singleton( \Counter\Repositories\OrderShipmentRepository::class,
		fn() => new \Counter\Repositories\OrderShipmentRepository() );
	$c->singleton( \Counter\Repositories\VendorOptionTermsRepository::class,
		fn() => new \Counter\Repositories\VendorOptionTermsRepository() );
	// Product catalog: native (our SQLite) or woo (read existing WooCommerce
	// products in place). Auto-default to 'woo' if Woo is detected on first
	// install — otherwise 'native'. Admin can override in settings.
	$c->singleton( \Counter\Repositories\ProductRepository::class, function () {
		$source = get_option( 'counter_product_source' );
		if ( $source === false ) {
			$source = ( defined( 'WC_VERSION' ) || function_exists( 'wc_get_product' ) ) ? 'woo' : 'native';
			update_option( 'counter_product_source', $source );
		}
		return $source === 'woo' && function_exists( 'wc_get_product' )
			? new \Counter\Repositories\WooProductRepository()
			: new \Counter\Repositories\NativeProductRepository();
	} );

	// ─── Services (couponservice needed by pipeline below) ──────────────
	$c->singleton( \Counter\Services\CouponService::class, fn( $c ) =>
		new \Counter\Services\CouponService(
			$c->get( \Counter\Repositories\CouponRepository::class ),
			$c->get( \Counter\Repositories\CartRepository::class ),
		)
	);

	// ─── Pipelines ──────────────────────────────────────────────────────
	// Use resolveDefault() so CouponStep gets its dependencies wired.
	$c->singleton( \Counter\Pipelines\CartTotalsPipeline::class, fn( $c ) =>
		new \Counter\Pipelines\CartTotalsPipeline(
			\Counter\Pipelines\CartTotalsPipeline::resolveDefault( $c )
		)
	);

	// ─── Services ───────────────────────────────────────────────────────
	$c->singleton( \Counter\Services\CartService::class, fn( $c ) =>
		new \Counter\Services\CartService(
			$c->get( \Counter\Repositories\CartRepository::class ),
			$c->get( \Counter\Repositories\ProductRepository::class ),
			$c->get( \Counter\Pipelines\CartTotalsPipeline::class ),
			$c->get( \Counter\Events\EventBus::class ),
		)
	);

	$c->singleton( \Counter\Services\CartTokenManager::class,
		fn() => new \Counter\Services\CartTokenManager() );

	$c->singleton( \Counter\Services\CartRenderer::class, fn( $c ) =>
		new \Counter\Services\CartRenderer(
			$c->get( \Counter\Services\CartService::class )
		)
	);

	$c->singleton( \Counter\Services\CheckoutRenderer::class, fn( $c ) =>
		new \Counter\Services\CheckoutRenderer(
			$c->get( \Counter\Services\CartService::class )
		)
	);

	// ─── Order layer ───────────────────────────────────────────────────
	$c->singleton( \Counter\Repositories\OrderRepository::class, fn( $c ) =>
		new \Counter\Repositories\OrderRepository(
			$c->get( \Counter\Repositories\ProductRepository::class )
		)
	);
	$c->singleton( \Counter\Services\OrderService::class, fn( $c ) =>
		new \Counter\Services\OrderService(
			$c->get( \Counter\Repositories\OrderRepository::class ),
			$c->get( \Counter\Events\EventBus::class ),
		)
	);

	// ─── Taxonomy ordering layer ────────────────────────────────────────
	$c->singleton( \Counter\Repositories\TaxonomyOrderRepository::class, fn() =>
		new \Counter\Repositories\TaxonomyOrderRepository()
	);

	// ─── Payment providers (Studio Pay underlying rails) ───────────────
	$c->singleton( \Counter\Payments\Providers\StripeProvider::class,  fn() => new \Counter\Payments\Providers\StripeProvider() );
	$c->singleton( \Counter\Payments\Providers\SquareProvider::class,  fn() => new \Counter\Payments\Providers\SquareProvider() );
	$c->singleton( \Counter\Payments\Providers\PayPalProvider::class,  fn() => new \Counter\Payments\Providers\PayPalProvider() );
	$c->singleton( \Counter\Payments\Providers\PlaidProvider::class,   fn() => new \Counter\Payments\Providers\PlaidProvider() );
	$c->singleton( \Counter\Payments\Providers\SezzleProvider::class,  fn() => new \Counter\Payments\Providers\SezzleProvider() );
	$c->singleton( \Counter\Payments\Providers\ZipProvider::class,     fn() => new \Counter\Payments\Providers\ZipProvider() );
	$c->singleton( \Counter\Payments\Providers\CryptoProvider::class,  fn() => new \Counter\Payments\Providers\CryptoProvider() );
	$c->singleton( \Counter\Payments\Providers\ZelleProvider::class,   fn() => new \Counter\Payments\Providers\ZelleProvider() );
	$c->singleton( \Counter\Payments\Providers\ShopPayProvider::class, fn( $c ) =>
		new \Counter\Payments\Providers\ShopPayProvider(
			$c->get( \Counter\Payments\Providers\StripeProvider::class ),
		)
	);

	$c->singleton( \Counter\Payments\Studio\StudioPay::class, function ( $c ) {
		$providers = [
			'stripe'   => $c->get( \Counter\Payments\Providers\StripeProvider::class ),
			'square'   => $c->get( \Counter\Payments\Providers\SquareProvider::class ),
			'paypal'   => $c->get( \Counter\Payments\Providers\PayPalProvider::class ),
			'plaid'    => $c->get( \Counter\Payments\Providers\PlaidProvider::class ),
			'sezzle'   => $c->get( \Counter\Payments\Providers\SezzleProvider::class ),
			'zip'      => $c->get( \Counter\Payments\Providers\ZipProvider::class ),
			'crypto'   => $c->get( \Counter\Payments\Providers\CryptoProvider::class ),
			'zelle'    => $c->get( \Counter\Payments\Providers\ZelleProvider::class ),
			'shop_pay' => $c->get( \Counter\Payments\Providers\ShopPayProvider::class ),
		];
		// Filter for 3rd-party adapters to register themselves.
		$providers = apply_filters( 'counter_studio_pay_providers', $providers );
		return new \Counter\Payments\Studio\StudioPay( $providers );
	} );
	$c->singleton( \Counter\Payments\Studio\Payouts::class, fn( $c ) =>
		new \Counter\Payments\Studio\Payouts(
			$c->get( \Counter\Payments\Studio\StudioPay::class ),
		)
	);
	$c->singleton( \Counter\Payments\Studio\StudioConnect::class, fn() => new \Counter\Payments\Studio\StudioConnect() );

	// ─── Payment gateways ──────────────────────────────────────────────
	$c->singleton( \Counter\Repositories\PaymentGatewayRegistry::class, function ( $c ) {
		$reg = new \Counter\Repositories\PaymentGatewayRegistry();
		// Studio Pay is the primary gateway — aggregates all methods
		// over Stripe/Square/PayPal under one connect-once UX.
		$reg->register( $c->get( \Counter\Payments\Studio\StudioPay::class ) );
		// Mock stays for tests / demos.
		$reg->register( new \Counter\Payments\MockGateway() );
		do_action( 'counter_register_gateways', $reg );
		return $reg;
	} );

	// ─── Checkout + webhook ────────────────────────────────────────────
	$c->singleton( \Counter\Services\CheckoutService::class, fn( $c ) =>
		new \Counter\Services\CheckoutService(
			$c->get( \Counter\Services\CartService::class ),
			$c->get( \Counter\Services\OrderService::class ),
			$c->get( \Counter\Repositories\OrderRepository::class ),
			$c->get( \Counter\Repositories\PaymentGatewayRegistry::class ),
		)
	);
	$c->singleton( \Counter\Services\WebhookReceiver::class, fn( $c ) =>
		new \Counter\Services\WebhookReceiver(
			$c->get( \Counter\Repositories\PaymentGatewayRegistry::class ),
			$c->get( \Counter\Repositories\OrderRepository::class ),
			$c->get( \Counter\Services\OrderService::class ),
			$c->get( \Counter\Services\CheckoutService::class ),
		)
	);

	// WooCommerce order mirror — only meaningful when product source is 'woo'.
	// Subscribes to OrderPaid below. When active, every paid Therum order
	// gets a matching WC_Order so POD plugins (Printful, Printify, etc.)
	// fulfill exactly as they do for Woo-native orders.
	$c->singleton( \Counter\Services\WooOrderMirror::class, fn( $c ) =>
		new \Counter\Services\WooOrderMirror(
			$c->get( \Counter\Repositories\OrderRepository::class )
		)
	);

	// Subscribe the mirror to OrderPaid. The handler itself checks the
	// `shop_product_source` setting and short-circuits if Woo isn't the
	// catalog, so this stays cheap when not in use.
	$c->get( \Counter\Events\EventBus::class )->on(
		\Counter\Events\OrderPaid::class,
		[ $c->get( \Counter\Services\WooOrderMirror::class ), 'handle' ]
	);

	// ─── REST controllers ──────────────────────────────────────────────
	$c->singleton( \Counter\Rest\CartController::class, fn( $c ) =>
		new \Counter\Rest\CartController(
			$c->get( \Counter\Services\CartService::class ),
			$c->get( \Counter\Services\CartRenderer::class ),
			$c->get( \Counter\Services\CartTokenManager::class ),
			$c->get( \Counter\Services\CouponService::class ),
		)
	);

	// ─── Refunds ───────────────────────────────────────────────────────
	$c->singleton( \Counter\Services\RefundService::class, fn( $c ) =>
		new \Counter\Services\RefundService(
			$c->get( \Counter\Repositories\RefundRepository::class ),
			$c->get( \Counter\Repositories\OrderRepository::class ),
			$c->get( \Counter\Repositories\PaymentGatewayRegistry::class ),
			$c->get( \Counter\Services\CouponService::class ),
		)
	);

	// ─── Vendor routing + dictionary ───────────────────────────────────
	$c->singleton( \Counter\Services\VendorRouter::class, fn( $c ) =>
		new \Counter\Services\VendorRouter(
			$c->get( \Counter\Repositories\OrderShipmentRepository::class ),
			$c->get( \Counter\Events\EventBus::class ),
		)
	);
	$c->singleton( \Counter\Services\VendorDictionaryService::class, fn( $c ) =>
		new \Counter\Services\VendorDictionaryService(
			$c->get( \Counter\Repositories\VendorOptionTermsRepository::class ),
		)
	);

	// Subscribe VendorRouter to OrderPaid so every paid order automatically
	// queues per-vendor routing events. Subscribers (POD adapters, Nexus,
	// WooOrderMirror) listen for ShipmentReadyToRoute.
	$c->get( \Counter\Events\EventBus::class )->on(
		\Counter\Events\OrderPaid::class,
		[ $c->get( \Counter\Services\VendorRouter::class ), 'onOrderPaid' ]
	);

	$c->singleton( \Counter\Rest\DictionaryController::class, fn( $c ) =>
		new \Counter\Rest\DictionaryController(
			$c->get( \Counter\Services\VendorDictionaryService::class ),
			$c->get( \Counter\Repositories\VendorOptionTermsRepository::class ),
		)
	);

	// ─── Exporters / feeds ─────────────────────────────────────────────
	$c->singleton( \Counter\Exporters\CatalogReader::class, fn( $c ) =>
		new \Counter\Exporters\CatalogReader(
			$c->get( \Counter\Repositories\ProductRepository::class )
		)
	);
	$c->singleton( \Counter\Services\ExporterRegistry::class, function ( $c ) {
		$reg = new \Counter\Services\ExporterRegistry();
		$reader = $c->get( \Counter\Exporters\CatalogReader::class );
		$products = $c->get( \Counter\Repositories\ProductRepository::class );
		$dict = $c->get( \Counter\Services\VendorDictionaryService::class );
		$reg->register( new \Counter\Exporters\CsvExporter( $reader, $products ) );
		$reg->register( new \Counter\Exporters\MarkdownExporter( $reader ) );
		$reg->register( new \Counter\Exporters\GoogleShoppingFeed( $reader, $products, $dict ) );
		$reg->register( new \Counter\Exporters\MetaCatalogFeed( $reader, $products, $dict ) );
		$reg->register( new \Counter\Exporters\TikTokFeed( $reader, $products, $dict ) );
		do_action( 'counter_register_exporters', $reg );
		return $reg;
	} );
	$c->singleton( \Counter\Repositories\CustomerRepository::class, fn() => new \Counter\Repositories\CustomerRepository() );
	$c->singleton( \Counter\Importers\CustomerImporter::class, fn( $c ) =>
		new \Counter\Importers\CustomerImporter( $c->get( \Counter\Repositories\CustomerRepository::class ) )
	);
	$c->singleton( \Counter\Exporters\CustomerExporter::class, fn( $c ) =>
		new \Counter\Exporters\CustomerExporter( $c->get( \Counter\Repositories\CustomerRepository::class ) )
	);
	$c->singleton( \Counter\Importers\OrderImporter::class, fn( $c ) =>
		new \Counter\Importers\OrderImporter( $c->get( \Counter\Repositories\OrderRepository::class ) )
	);
	$c->singleton( \Counter\Exporters\OrderExporter::class, fn( $c ) =>
		new \Counter\Exporters\OrderExporter( $c->get( \Counter\Repositories\OrderRepository::class ) )
	);
	$c->singleton( \Counter\Rest\OrderIoController::class, fn( $c ) =>
		new \Counter\Rest\OrderIoController(
			$c->get( \Counter\Importers\OrderImporter::class ),
			$c->get( \Counter\Exporters\OrderExporter::class ),
		)
	);
	$c->singleton( \Counter\Rest\CustomersController::class, fn( $c ) =>
		new \Counter\Rest\CustomersController(
			$c->get( \Counter\Repositories\CustomerRepository::class ),
			$c->get( \Counter\Importers\CustomerImporter::class ),
			$c->get( \Counter\Exporters\CustomerExporter::class ),
		)
	);
	$c->singleton( \Counter\Rest\GridViewsController::class, fn() => new \Counter\Rest\GridViewsController() );
	$c->singleton( \Counter\Rest\TaxonomyOrderController::class, fn( $c ) =>
		new \Counter\Rest\TaxonomyOrderController(
			$c->get( \Counter\Repositories\TaxonomyOrderRepository::class ),
		)
	);
	$c->singleton( \Counter\Rest\StudioPayController::class, fn( $c ) =>
		new \Counter\Rest\StudioPayController(
			$c->get( \Counter\Payments\Studio\StudioPay::class ),
			$c->get( \Counter\Payments\Studio\Payouts::class ),
			$c->get( \Counter\Payments\Studio\StudioConnect::class ),
		)
	);
	$c->singleton( \Counter\Rest\FeedController::class, fn( $c ) =>
		new \Counter\Rest\FeedController(
			$c->get( \Counter\Services\ExporterRegistry::class )
		)
	);

	// ─── HPOS-style order export (opt-in compat for Woo extensions) ────
	$c->singleton( \Counter\Compat\HposOrderAdapter::class, fn( $c ) =>
		new \Counter\Compat\HposOrderAdapter(
			$c->get( \Counter\Repositories\OrderRepository::class )
		)
	);

	// ─── Element library + page builder adapters ──────────────────────
	$c->singleton( \Counter\Elements\ElementRegistry::class, function ( $c ) {
		$reg = new \Counter\Elements\ElementRegistry();
		$products   = $c->get( \Counter\Repositories\ProductRepository::class );
		$attributes = $c->get( \Counter\Repositories\AttributeRepository::class );
		// Catalog elements (Shop-specific)
		$reg->register( new \Counter\Elements\Catalog\ProductTitle( $products ) );
		$reg->register( new \Counter\Elements\Catalog\ProductPrice( $products ) );
		$reg->register( new \Counter\Elements\Catalog\ProductGallery( $products ) );
		$reg->register( new \Counter\Elements\Catalog\VariantPicker( $products, $attributes ) );
		$reg->register( new \Counter\Elements\Catalog\AddToCart( $products ) );
		$reg->register( new \Counter\Elements\Catalog\StockStatus( $products ) );
		$reg->register( new \Counter\Elements\Catalog\ProductDescription( $products ) );
		$reg->register( new \Counter\Elements\Catalog\ProductMeta( $products, $attributes ) );
		$reg->register( new \Counter\Elements\Catalog\ProductGrid( $products ) );
		// Commerce surfaces (cart/checkout/received)
		$reg->register( new \Counter\Elements\Commerce\CartContents(
			$c->get( \Counter\Services\CartService::class ),
			$c->get( \Counter\Services\CartRenderer::class ),
			$c->get( \Counter\Services\CartTokenManager::class ),
		) );
		$reg->register( new \Counter\Elements\Commerce\CheckoutForm(
			$c->get( \Counter\Services\CartService::class ),
			$c->get( \Counter\Services\CheckoutRenderer::class ),
			$c->get( \Counter\Services\CartTokenManager::class ),
		) );
		$reg->register( new \Counter\Elements\Commerce\OrderReceived(
			$c->get( \Counter\Repositories\OrderRepository::class )
		) );
		// Layout primitives
		$reg->register( new \Counter\Elements\Layout\Section() );
		$reg->register( new \Counter\Elements\Layout\Column() );
		$reg->register( new \Counter\Elements\Layout\Heading() );
		$reg->register( new \Counter\Elements\Layout\Image() );
		$reg->register( new \Counter\Elements\Layout\Button() );
		$reg->register( new \Counter\Elements\Layout\Spacer() );
		$reg->register( new \Counter\Elements\Layout\Divider() );
		// Chrome (header / footer essentials)
		$reg->register( new \Counter\Elements\Chrome\SiteLogo() );
		$reg->register( new \Counter\Elements\Chrome\SiteNav() );
		$reg->register( new \Counter\Elements\Chrome\CartButton() );
		// Content
		$reg->register( new \Counter\Elements\Content\RichText() );
		do_action( 'counter_register_elements', $reg );
		return $reg;
	} );

	$c->singleton( \Counter\Builders\Bricks\BricksAdapter::class, fn( $c ) =>
		new \Counter\Builders\Bricks\BricksAdapter(
			$c->get( \Counter\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Counter\Builders\Elementor\ElementorAdapter::class, fn( $c ) =>
		new \Counter\Builders\Elementor\ElementorAdapter(
			$c->get( \Counter\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Counter\Builders\Gutenberg\GutenbergAdapter::class, fn( $c ) =>
		new \Counter\Builders\Gutenberg\GutenbergAdapter(
			$c->get( \Counter\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Counter\Rest\CheckoutController::class, fn( $c ) =>
		new \Counter\Rest\CheckoutController(
			$c->get( \Counter\Services\CheckoutService::class ),
			$c->get( \Counter\Services\CartTokenManager::class ),
			$c->get( \Counter\Services\WebhookReceiver::class ),
		)
	);
	$c->singleton( \Counter\Rest\WebhookController::class, fn( $c ) =>
		new \Counter\Rest\WebhookController(
			$c->get( \Counter\Services\WebhookReceiver::class )
		)
	);

	// ─── Importer / writer ─────────────────────────────────────────────
	$c->singleton( \Counter\Services\ProductWriter::class,
		fn() => new \Counter\Services\ProductWriter() );

	$c->singleton( \Counter\Services\ImporterRegistry::class, function () {
		$reg = new \Counter\Services\ImporterRegistry();
		// Order matters — more specific importers come first so generic
		// fallbacks (markdown swallowing .txt) don't shadow them.
		$reg->register( new \Counter\Importers\CsvImporter() );
		$reg->register( new \Counter\Importers\PdfImporter() );
		$reg->register( new \Counter\Importers\ImageImporter() );
		$reg->register( new \Counter\Importers\FigmaImporter() );
		$reg->register( new \Counter\Importers\UrlImporter() );
		$reg->register( new \Counter\Importers\MarkdownImporter() );
		do_action( 'counter_register_importers', $reg );
		return $reg;
	} );

	$c->singleton( \Counter\Rest\ImporterController::class, fn( $c ) =>
		new \Counter\Rest\ImporterController(
			$c->get( \Counter\Services\ImporterRegistry::class ),
			$c->get( \Counter\Services\ProductWriter::class ),
		)
	);

	// ─── Admin UI ──────────────────────────────────────────────────────
	$c->singleton( \Counter\Admin\DashboardPage::class,  fn() => new \Counter\Admin\DashboardPage() );
	$c->singleton( \Counter\Admin\CategoriesPage::class, fn() => new \Counter\Admin\CategoriesPage() );
	$c->singleton( \Counter\Admin\ImportExportPage::class, fn( $c ) =>
		new \Counter\Admin\ImportExportPage(
			$c->get( \Counter\Admin\ImporterPage::class ),
			$c->get( \Counter\Admin\OrderIoPage::class ),
			$c->get( \Counter\Admin\CustomersPage::class ),
		)
	);
	$c->singleton( \Counter\Admin\SettingsPage::class, fn() => new \Counter\Admin\SettingsPage() );
	$c->singleton( \Counter\Admin\ImporterPage::class, fn( $c ) =>
		new \Counter\Admin\ImporterPage(
			$c->get( \Counter\Services\ImporterRegistry::class )
		)
	);
	$c->singleton( \Counter\Admin\ProductsPage::class, fn() => new \Counter\Admin\ProductsPage() );
	$c->singleton( \Counter\Admin\OrdersPage::class,   fn() => new \Counter\Admin\OrdersPage() );
	$c->singleton( \Counter\Admin\BuilderPage::class,  fn() => new \Counter\Admin\BuilderPage() );
	$c->singleton( \Counter\Admin\StudioPayPage::class, fn() => new \Counter\Admin\StudioPayPage() );
	$c->singleton( \Counter\Admin\CustomersPage::class, fn() => new \Counter\Admin\CustomersPage() );
	$c->singleton( \Counter\Admin\OrderIoPage::class,   fn() => new \Counter\Admin\OrderIoPage() );
	$c->singleton( \Counter\Admin\UpdatesPage::class,   fn() => new \Counter\Admin\UpdatesPage() );
	$c->singleton( \Counter\Admin\ProductCategoryOrderPage::class, fn( $c ) =>
		new \Counter\Admin\ProductCategoryOrderPage(
			$c->get( \Counter\Repositories\TaxonomyOrderRepository::class )
		)
	);
	$c->singleton( \Counter\Admin\ProductVariantOrderPage::class, fn( $c ) =>
		new \Counter\Admin\ProductVariantOrderPage(
			$c->get( \Counter\Repositories\TaxonomyOrderRepository::class )
		)
	);
	$c->singleton( \Counter\Admin\CustomTaxonomyOrderPage::class, fn( $c ) =>
		new \Counter\Admin\CustomTaxonomyOrderPage(
			$c->get( \Counter\Repositories\TaxonomyOrderRepository::class )
		)
	);
	$c->singleton( \Counter\Services\Updater::class,    fn() => new \Counter\Services\Updater() );
	$c->singleton( \Counter\Rest\UpdaterController::class, fn( $c ) =>
		new \Counter\Rest\UpdaterController( $c->get( \Counter\Services\Updater::class ) )
	);
	$c->singleton( \Counter\Admin\AdminMenu::class, fn( $c ) =>
		new \Counter\Admin\AdminMenu(
			$c->get( \Counter\Admin\SettingsPage::class ),
			$c->get( \Counter\Admin\ImporterPage::class ),
			$c->get( \Counter\Admin\ProductsPage::class ),
			$c->get( \Counter\Admin\OrdersPage::class ),
			$c->get( \Counter\Admin\BuilderPage::class ),
			$c->get( \Counter\Admin\StudioPayPage::class ),
			$c->get( \Counter\Admin\CustomersPage::class ),
			$c->get( \Counter\Admin\OrderIoPage::class ),
			$c->get( \Counter\Admin\UpdatesPage::class ),
			$c->get( \Counter\Admin\ProductCategoryOrderPage::class ),
			$c->get( \Counter\Admin\ProductVariantOrderPage::class ),
			$c->get( \Counter\Admin\CustomTaxonomyOrderPage::class ),
			$c->get( \Counter\Admin\DashboardPage::class ),
			$c->get( \Counter\Admin\CategoriesPage::class ),
			$c->get( \Counter\Admin\ImportExportPage::class ),
		)
	);

	// ─── Pure builder support ──────────────────────────────────────────
	$c->singleton( \Counter\Repositories\PageRepository::class, fn() => new \Counter\Repositories\PageRepository() );
	$c->singleton( \Counter\Services\PageRenderer::class, fn( $c ) =>
		new \Counter\Services\PageRenderer(
			$c->get( \Counter\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Counter\Services\ChromeResolver::class, fn( $c ) =>
		new \Counter\Services\ChromeResolver(
			$c->get( \Counter\Repositories\PageRepository::class ),
		)
	);
	$c->singleton( \Counter\Services\PageRouter::class, fn( $c ) =>
		new \Counter\Services\PageRouter(
			$c->get( \Counter\Repositories\PageRepository::class ),
			$c->get( \Counter\Services\PageRenderer::class ),
			$c->get( \Counter\Services\ChromeResolver::class ),
		)
	);
	$c->singleton( \Counter\Services\TemplateSeeder::class, fn( $c ) =>
		new \Counter\Services\TemplateSeeder(
			$c->get( \Counter\Repositories\PageRepository::class ),
		)
	);
	$c->singleton( \Counter\AI\ClaudeClient::class, fn() => new \Counter\AI\ClaudeClient() );
	$c->singleton( \Counter\Services\BuilderAi::class, fn( $c ) =>
		new \Counter\Services\BuilderAi(
			$c->get( \Counter\AI\ClaudeClient::class ),
			$c->get( \Counter\Elements\ElementRegistry::class ),
		)
	);
	$c->singleton( \Counter\Rest\PagesController::class, fn( $c ) =>
		new \Counter\Rest\PagesController(
			$c->get( \Counter\Repositories\PageRepository::class ),
			$c->get( \Counter\Services\PageRenderer::class ),
			$c->get( \Counter\Elements\ElementRegistry::class ),
			$c->get( \Counter\Services\BuilderAi::class ),
		)
	);

	// Admin REST controller — spreadsheet endpoints + refunds
	$c->singleton( \Counter\Admin\WooProductPatcher::class, fn( $c ) =>
		new \Counter\Admin\WooProductPatcher()
	);

	$c->singleton( \Counter\Rest\AdminController::class, fn( $c ) =>
		new \Counter\Rest\AdminController(
			$c->get( \Counter\Repositories\OrderRepository::class ),
			$c->get( \Counter\Services\RefundService::class ),
			$c->get( \Counter\Admin\WooProductPatcher::class ),
		)
	);
}

// ─── Admin menu registration ───────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	if ( ! is_admin() ) return;
	\Counter\Container::instance()->get( \Counter\Admin\AdminMenu::class )->register();
}, 5 );

// ─── REST registration ─────────────────────────────────────────────────────
add_action( 'rest_api_init', function (): void {
	$c = \Counter\Container::instance();
	$c->get( \Counter\Rest\CartController::class )->register();
	$c->get( \Counter\Rest\CheckoutController::class )->register();
	$c->get( \Counter\Rest\WebhookController::class )->register();
	$c->get( \Counter\Rest\ImporterController::class )->register();
	$c->get( \Counter\Rest\AdminController::class )->register();
	$c->get( \Counter\Rest\DictionaryController::class )->register();
	$c->get( \Counter\Rest\FeedController::class )->register();
	$c->get( \Counter\Rest\PagesController::class )->register();
	$c->get( \Counter\Rest\StudioPayController::class )->register();
	$c->get( \Counter\Rest\CustomersController::class )->register();
	$c->get( \Counter\Rest\OrderIoController::class )->register();
	$c->get( \Counter\Rest\TaxonomyOrderController::class )->register();
	$c->get( \Counter\Rest\GridViewsController::class )->register();
	$c->get( \Counter\Rest\UpdaterController::class )->register();
} );

// ─── Asset enqueue ─────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function (): void {
	wp_register_style(  'counter-cart', COUNTER_URL . 'assets/cart/cart.css', [], COUNTER_VERSION );
	wp_register_script( 'counter-cart', COUNTER_URL . 'assets/cart/cart.js',  [], COUNTER_VERSION, [
		'in_footer' => true,
		'strategy'  => 'defer',
	] );

	wp_add_inline_script( 'counter-cart',
		'window.ShopCartConfig = ' . wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] ) . ';',
		'before'
	);

	wp_enqueue_style(  'counter-cart' );
	wp_enqueue_script( 'counter-cart' );

	// Elements CSS + JS — small, always enqueued so pages with any
	// Shop element render styled out of the box. Elements that need
	// interactivity (Gallery, VariantPicker, AddToCart) live in
	// elements.js; static elements (Heading, Image, Button, Section,
	// Spacer, Divider, RichText) don't need JS but the script is
	// harmless when none are present.
	wp_register_style(  'counter-elements', COUNTER_URL . 'assets/elements/elements.css', [],          COUNTER_VERSION );
	wp_register_script( 'counter-elements', COUNTER_URL . 'assets/elements/elements.js',  [ 'counter-cart' ], COUNTER_VERSION, [
		'in_footer' => true,
		'strategy'  => 'defer',
	] );
	wp_enqueue_style(  'counter-elements' );
	wp_enqueue_script( 'counter-elements' );

	// Studio cart + checkout assets — only when the page contains the Studio
	// pattern (cheap heuristic: presentation option matches). Studio cart is
	// also used by Atelier mode.
	$cart_mode = (string) get_option( 'counter_cart_presentation', 'studio' );
	if ( $cart_mode === 'studio' || $cart_mode === 'atelier' ) {
		wp_register_style(  'counter-cart-studio', COUNTER_URL . 'assets/cart/studio.css', [], COUNTER_VERSION );
		wp_enqueue_style(  'counter-cart-studio' );
	}

	if ( get_option( 'counter_checkout_presentation' ) === 'studio' ) {
		wp_register_style(  'counter-checkout-studio', COUNTER_URL . 'assets/checkout/studio.css', [], COUNTER_VERSION );
		wp_register_script( 'counter-checkout-studio', COUNTER_URL . 'assets/checkout/studio.js',  [], COUNTER_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_enqueue_style(  'counter-checkout-studio' );
		wp_enqueue_script( 'counter-checkout-studio' );
	}

	// PayPal Smart Buttons — mounts visible PayPal / Venmo / PP-Credit
	// buttons on the checkout page IF PayPal is connected. The script
	// itself reads /studio-pay/paypal-config and no-ops gracefully when
	// PayPal isn't configured, so it's safe to enqueue universally on
	// the checkout view.
	wp_register_script( 'counter-paypal-smart-buttons',
		COUNTER_URL . 'assets/checkout/paypal-smart-buttons.js',
		[], COUNTER_VERSION,
		[ 'in_footer' => true, 'strategy' => 'defer' ]
	);
	wp_add_inline_script( 'counter-paypal-smart-buttons',
		'window.CounterCheckoutConfig = ' . wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] ) . ';',
		'before'
	);
	wp_enqueue_script( 'counter-paypal-smart-buttons' );
} );

// ─── Floating button injection ─────────────────────────────────────────────
// Renders the FAB + paired drawer at the end of every front-end page.
// Suppressed when the default mode is 'none' or 'page', or on the
// /checkout/ page (where it would compete with the checkout flow).
add_action( 'wp_footer', function (): void {
	if ( is_admin() ) return;

	$renderer = \Counter\Container::instance()->get( \Counter\Services\CartRenderer::class );
	$mode     = $renderer->defaultMode();

	if ( in_array( $mode, [
		\Counter\Services\CartRenderer::MODE_PAGE,
		\Counter\Services\CartRenderer::MODE_NONE,
	], true ) ) return;

	if ( ! (bool) apply_filters( 'counter_show_floating_cart', true ) ) return;

	$token = \Counter\Container::instance()->get( \Counter\Services\CartTokenManager::class )->current();
	$cart  = \Counter\Container::instance()->get( \Counter\Services\CartService::class )->getOrCreate( $token );

	echo $renderer->floatingButton( $cart ); // phpcs:ignore — template-escaped at source
} );

// ─── Shortcodes ────────────────────────────────────────────────────────────
add_action( 'init', function (): void {
	$c = \Counter\Container::instance();

	// [shop_cart] — renders the configured cart shell on the current page.
	add_shortcode( 'counter_cart', function () use ( $c ): string {
		$token    = $c->get( \Counter\Services\CartTokenManager::class )->current();
		$cart     = $c->get( \Counter\Services\CartService::class )->getOrCreate( $token );
		$renderer = $c->get( \Counter\Services\CartRenderer::class );
		// Use the configured default (Studio / Counter / etc); admins
		// switch in Settings → Shop → Cart presentation.
		return $renderer->shell( $renderer->defaultMode(), $cart );
	} );

	// [shop_checkout] — renders the configured checkout template. Source of
	// truth for the customer's payment surface.
	add_shortcode( 'counter_checkout', function () use ( $c ): string {
		$token    = $c->get( \Counter\Services\CartTokenManager::class )->current();
		$cart     = $c->get( \Counter\Services\CartService::class )->getOrCreate( $token );
		$renderer = $c->get( \Counter\Services\CheckoutRenderer::class );
		return $renderer->render( $cart );
	} );

	// [shop_order_received] — post-payment confirmation. Reads ?order= from
	// the URL and looks it up. Falls through to the empty-state if absent.
	add_shortcode( 'counter_order_received', function () use ( $c ): string {
		$number = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['order'] ) ) : '';
		$order  = null;
		if ( $number !== '' ) {
			$order = $c->get( \Counter\Repositories\OrderRepository::class )->findByNumber( $number );
		}
		$candidates = [
			get_stylesheet_directory() . '/shop/order-received.php',
			get_template_directory()   . '/shop/order-received.php',
			COUNTER_DIR . 'templates/order-received.php',
		];
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) ) {
				ob_start();
				include $path;
				return (string) ob_get_clean();
			}
		}
		return '';
	} );
} );

// ─── Settings registration ─────────────────────────────────────────────────
// Lightweight option storage. Full admin UI lives in milestone #9.
add_action( 'admin_init', function (): void {
	register_setting( 'counter_appearance', 'counter_cart_presentation', [
		'type'              => 'string',
		'default'           => \Counter\Services\CartRenderer::MODE_STUDIO,
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, \Counter\Services\CartRenderer::ALL_MODES, true )
				? $v
				: \Counter\Services\CartRenderer::MODE_STUDIO;
		},
	] );

	register_setting( 'counter_appearance', 'counter_checkout_presentation', [
		'type'              => 'string',
		'default'           => \Counter\Services\CheckoutRenderer::MODE_CLASSIC,
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, \Counter\Services\CheckoutRenderer::ALL_MODES, true )
				? $v
				: \Counter\Services\CheckoutRenderer::MODE_CLASSIC;
		},
	] );

	register_setting( 'counter_appearance', 'counter_cart_button_position', [
		'type'              => 'string',
		'default'           => 'bottom-right',
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ], true )
				? $v
				: 'bottom-right';
		},
	] );

	// Catalog source — native (our SQLite) or woo (read WooCommerce in place).
	// First-install default is computed at container bind time based on
	// whether Woo is detected; admins flip later via this setting.
	register_setting( 'counter_catalog', 'counter_product_source', [
		'type'              => 'string',
		'default'           => 'native',
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, [ 'native', 'woo' ], true ) ? $v : 'native';
		},
	] );

	// HPOS-style order export — opt-in compat layer for Woo extensions
	// that need to read Shop orders. Off by default.
	register_setting( 'counter_compat', 'counter_hpos_export', [
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => fn( $v ) => (bool) $v,
	] );
} );

// HPOS adapter registers its Woo filters when enabled, after Woo loads.
add_action( 'woocommerce_init', function (): void {
	\Counter\Container::instance()->get( \Counter\Compat\HposOrderAdapter::class )->register();
} );

// Bricks adapter — fires on plugins_loaded so Bricks (theme or plugin)
// has had a chance to define BRICKS_VERSION before we check.
add_action( 'plugins_loaded', function (): void {
	if ( ! \Counter\Builders\Bricks\BricksAdapter::isActive() ) return;
	\Counter\Container::instance()->get( \Counter\Builders\Bricks\BricksAdapter::class )->register();
\Counter\Container::instance()->get( \Counter\Builders\Elementor\ElementorAdapter::class )->register();
\Counter\Container::instance()->get( \Counter\Builders\Gutenberg\GutenbergAdapter::class )->register();
}, 20 );

// Pure front-end routing. Mode helper short-circuits in Unlocked mode.
add_action( 'plugins_loaded', function (): void {
	\Counter\Container::instance()->get( \Counter\Services\PageRouter::class )->register();
}, 25 );

// Flush rewrite rules on activation so /p/{slug} and /product/{slug}/
// routes resolve immediately. The router registers the rules; this
// just tells WP to refresh its compiled rewrite cache.
//
// First-time activation gotcha: container bindings register inside the
// `plugins_loaded` callback, but the activation hook fires AFTER
// `plugins_loaded` has already passed during `activate_plugin()`'s
// include of this file — so the binding closure for PageRouter (and
// every other service) has never run. We bootstrap manually here.
register_activation_hook( __FILE__, function (): void {
	if ( ! \Counter\Container::instance()->has( \Counter\Services\PageRouter::class ) ) {
		// Trigger our own plugins_loaded handlers — only ours, not the
		// global action — so the container is fully populated before
		// the activation hook tries to read from it.
		do_action( 'counter_bootstrap_container_for_activation' );
	}
	// Pure-mode routes only — Unlocked mode delegates routing to
	// whichever page builder is active.
	if ( \Counter\Container::instance()->has( \Counter\Services\PageRouter::class ) ) {
		\Counter\Container::instance()->get( \Counter\Services\PageRouter::class )->rewrites();
	}
	flush_rewrite_rules();
} );

// ─── Queued event worker ────────────────────────────────────────────────────
// EventBus::queue() schedules under this hook (both Action Scheduler and
// wp-cron). The worker reconstructs and dispatches the event synchronously.
add_action( 'counter_queued_event', function ( array $payload ): void {
	\Counter\Container::instance()
		->get( \Counter\Events\EventBus::class )
		->handleQueued( $payload );
}, 10, 1 );

// HPOS / WC compatibility note: Shop does NOT use WooCommerce's order tables
// (or any WP tables at all). All commerce data lives in our own SQLite file
// under wp-content/uploads/counter/shop.sqlite. This plugin can run
// alongside WooCommerce on the same site without conflict.
