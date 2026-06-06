<?php
/**
 * Plugin Name:       Shop by Therum
 * Plugin URI:        https://therum.studio/plugins/shop
 * Description:       A native commerce engine built for speed. One product entity with capability toggles (variants, shipping, digital delivery, POD routing), purpose-built SQLite schema, unified cart/checkout session, typed events + pipelines instead of hook spam. Pluggable payment, tax, shipping, and fulfillment providers via Nexus by Therum.
 * Version:           0.28.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Therum Creative Studios
 * Author URI:        https://therum.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shop
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

define( 'SHOP_VERSION', '0.28.0' );
define( 'SHOP_FILE', __FILE__ );
define( 'SHOP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOP_URL', plugin_dir_url( __FILE__ ) );

// ──────────────────────────────────────────────────────────────────────────
//  Autoload
// ──────────────────────────────────────────────────────────────────────────
//
// PSR-4 lite. Classes under the `Shop\` namespace live in `includes/` with
// directory-per-subnamespace. e.g.:
//   Shop\DB                            → includes/DB.php
//   Shop\Services\CartService          → includes/Services/CartService.php
//   Shop\Events\OrderPaid              → includes/Events/OrderPaid.php
//
// No Composer dependency — we want this plugin to drop into any WP install.
spl_autoload_register( function ( string $class ): void {
	if ( strncmp( $class, 'Shop\\', 5 ) !== 0 ) {
		return;
	}
	$relative = str_replace( '\\', '/', substr( $class, 5 ) );
	$path     = SHOP_DIR . 'includes/' . $relative . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
} );

// Legacy free-function shim — old activation hooks may reference it.
require_once SHOP_DIR . 'includes/migrations.php';

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
register_activation_hook( __FILE__, [ \Shop\Migrations::class, 'run' ] );

// Catch-up on every admin load: if the schema version in the SQLite file is
// behind Schema::VERSION (e.g. user updated via FTP without re-activating),
// run migrations. Cheap — one SELECT MAX(version) FROM schema_version.
add_action( 'admin_init', function (): void {
	try {
		if ( \Shop\Migrations::currentVersion() < \Shop\Schema::VERSION ) {
			\Shop\Migrations::run();
		}
	} catch ( \Throwable $e ) {
		// First-run before the file exists, or genuine DB error. Force a full
		// run; if THAT fails, surface it in the admin notice rather than
		// silently breaking the site.
		try {
			\Shop\Migrations::run();
		} catch ( \Throwable $e2 ) {
			add_action( 'admin_notices', function () use ( $e2 ): void {
				echo '<div class="notice notice-error"><p><strong>Shop by Therum:</strong> migration failed — ' . esc_html( $e2->getMessage() ) . '</p></div>';
			} );
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
add_action( 'shop_bootstrap_container_for_activation', 'shop_register_container_bindings' );
// Priority 0 — must run before any other plugins_loaded callback that
// reads from the container (admin menu at 5, REST registration, etc).
add_action( 'plugins_loaded', 'shop_register_container_bindings', 0 );
function shop_register_container_bindings(): void {
	static $done = false;
	if ( $done ) return;
	$done = true;
	$c = \Shop\Container::instance();

	// ─── Event bus ──────────────────────────────────────────────────────
	$c->singleton( \Shop\Events\EventBus::class, fn() => new \Shop\Events\EventBus() );

	// ─── Repositories ───────────────────────────────────────────────────
	$c->singleton( \Shop\Repositories\CartRepository::class,
		fn() => new \Shop\Repositories\CartRepository() );
	$c->singleton( \Shop\Repositories\CouponRepository::class,
		fn() => new \Shop\Repositories\CouponRepository() );
	$c->singleton( \Shop\Repositories\RefundRepository::class,
		fn() => new \Shop\Repositories\RefundRepository() );
	$c->singleton( \Shop\Repositories\AttributeRepository::class,
		fn() => new \Shop\Repositories\AttributeRepository() );
	$c->singleton( \Shop\Repositories\OrderShipmentRepository::class,
		fn() => new \Shop\Repositories\OrderShipmentRepository() );
	$c->singleton( \Shop\Repositories\VendorOptionTermsRepository::class,
		fn() => new \Shop\Repositories\VendorOptionTermsRepository() );
	// Product catalog: native (our SQLite) or woo (read existing WooCommerce
	// products in place). Auto-default to 'woo' if Woo is detected on first
	// install — otherwise 'native'. Admin can override in settings.
	$c->singleton( \Shop\Repositories\ProductRepository::class, function () {
		$source = get_option( 'shop_product_source' );
		if ( $source === false ) {
			$source = ( defined( 'WC_VERSION' ) || function_exists( 'wc_get_product' ) ) ? 'woo' : 'native';
			update_option( 'shop_product_source', $source );
		}
		return $source === 'woo' && function_exists( 'wc_get_product' )
			? new \Shop\Repositories\WooProductRepository()
			: new \Shop\Repositories\NativeProductRepository();
	} );

	// ─── Services (couponservice needed by pipeline below) ──────────────
	$c->singleton( \Shop\Services\CouponService::class, fn( $c ) =>
		new \Shop\Services\CouponService(
			$c->get( \Shop\Repositories\CouponRepository::class ),
			$c->get( \Shop\Repositories\CartRepository::class ),
		)
	);

	// ─── Pipelines ──────────────────────────────────────────────────────
	// Use resolveDefault() so CouponStep gets its dependencies wired.
	$c->singleton( \Shop\Pipelines\CartTotalsPipeline::class, fn( $c ) =>
		new \Shop\Pipelines\CartTotalsPipeline(
			\Shop\Pipelines\CartTotalsPipeline::resolveDefault( $c )
		)
	);

	// ─── Services ───────────────────────────────────────────────────────
	$c->singleton( \Shop\Services\CartService::class, fn( $c ) =>
		new \Shop\Services\CartService(
			$c->get( \Shop\Repositories\CartRepository::class ),
			$c->get( \Shop\Repositories\ProductRepository::class ),
			$c->get( \Shop\Pipelines\CartTotalsPipeline::class ),
			$c->get( \Shop\Events\EventBus::class ),
		)
	);

	$c->singleton( \Shop\Services\CartTokenManager::class,
		fn() => new \Shop\Services\CartTokenManager() );

	$c->singleton( \Shop\Services\CartRenderer::class, fn( $c ) =>
		new \Shop\Services\CartRenderer(
			$c->get( \Shop\Services\CartService::class )
		)
	);

	$c->singleton( \Shop\Services\CheckoutRenderer::class, fn( $c ) =>
		new \Shop\Services\CheckoutRenderer(
			$c->get( \Shop\Services\CartService::class )
		)
	);

	// ─── Order layer ───────────────────────────────────────────────────
	$c->singleton( \Shop\Repositories\OrderRepository::class, fn( $c ) =>
		new \Shop\Repositories\OrderRepository(
			$c->get( \Shop\Repositories\ProductRepository::class )
		)
	);
	$c->singleton( \Shop\Services\OrderService::class, fn( $c ) =>
		new \Shop\Services\OrderService(
			$c->get( \Shop\Repositories\OrderRepository::class ),
			$c->get( \Shop\Events\EventBus::class ),
		)
	);

	// ─── Payment providers (Studio Pay underlying rails) ───────────────
	$c->singleton( \Shop\Payments\Providers\StripeProvider::class, fn() => new \Shop\Payments\Providers\StripeProvider() );
	$c->singleton( \Shop\Payments\Providers\SquareProvider::class, fn() => new \Shop\Payments\Providers\SquareProvider() );
	$c->singleton( \Shop\Payments\Providers\PayPalProvider::class, fn() => new \Shop\Payments\Providers\PayPalProvider() );
	$c->singleton( \Shop\Payments\Providers\PlaidProvider::class,  fn() => new \Shop\Payments\Providers\PlaidProvider() );

	$c->singleton( \Shop\Payments\Studio\StudioPay::class, function ( $c ) {
		$providers = [
			'stripe' => $c->get( \Shop\Payments\Providers\StripeProvider::class ),
			'square' => $c->get( \Shop\Payments\Providers\SquareProvider::class ),
			'paypal' => $c->get( \Shop\Payments\Providers\PayPalProvider::class ),
			'plaid'  => $c->get( \Shop\Payments\Providers\PlaidProvider::class ),
		];
		// Filter for `apply_filters( 'shop_studio_pay_providers', $providers )` so
		// 3rd-party adapters (Sezzle, crypto, Zelle) can register themselves.
		$providers = apply_filters( 'shop_studio_pay_providers', $providers );
		return new \Shop\Payments\Studio\StudioPay( $providers );
	} );
	$c->singleton( \Shop\Payments\Studio\Payouts::class, fn( $c ) =>
		new \Shop\Payments\Studio\Payouts(
			$c->get( \Shop\Payments\Studio\StudioPay::class ),
		)
	);
	$c->singleton( \Shop\Payments\Studio\StudioConnect::class, fn() => new \Shop\Payments\Studio\StudioConnect() );

	// ─── Payment gateways ──────────────────────────────────────────────
	$c->singleton( \Shop\Repositories\PaymentGatewayRegistry::class, function ( $c ) {
		$reg = new \Shop\Repositories\PaymentGatewayRegistry();
		// Studio Pay is the primary gateway — aggregates all methods
		// over Stripe/Square/PayPal under one connect-once UX.
		$reg->register( $c->get( \Shop\Payments\Studio\StudioPay::class ) );
		// Mock stays for tests / demos.
		$reg->register( new \Shop\Payments\MockGateway() );
		do_action( 'shop_register_gateways', $reg );
		return $reg;
	} );

	// ─── Checkout + webhook ────────────────────────────────────────────
	$c->singleton( \Shop\Services\CheckoutService::class, fn( $c ) =>
		new \Shop\Services\CheckoutService(
			$c->get( \Shop\Services\CartService::class ),
			$c->get( \Shop\Services\OrderService::class ),
			$c->get( \Shop\Repositories\OrderRepository::class ),
			$c->get( \Shop\Repositories\PaymentGatewayRegistry::class ),
		)
	);
	$c->singleton( \Shop\Services\WebhookReceiver::class, fn( $c ) =>
		new \Shop\Services\WebhookReceiver(
			$c->get( \Shop\Repositories\PaymentGatewayRegistry::class ),
			$c->get( \Shop\Repositories\OrderRepository::class ),
			$c->get( \Shop\Services\OrderService::class ),
			$c->get( \Shop\Services\CheckoutService::class ),
		)
	);

	// WooCommerce order mirror — only meaningful when product source is 'woo'.
	// Subscribes to OrderPaid below. When active, every paid Therum order
	// gets a matching WC_Order so POD plugins (Printful, Printify, etc.)
	// fulfill exactly as they do for Woo-native orders.
	$c->singleton( \Shop\Services\WooOrderMirror::class, fn( $c ) =>
		new \Shop\Services\WooOrderMirror(
			$c->get( \Shop\Repositories\OrderRepository::class )
		)
	);

	// Subscribe the mirror to OrderPaid. The handler itself checks the
	// `shop_product_source` setting and short-circuits if Woo isn't the
	// catalog, so this stays cheap when not in use.
	$c->get( \Shop\Events\EventBus::class )->on(
		\Shop\Events\OrderPaid::class,
		[ $c->get( \Shop\Services\WooOrderMirror::class ), 'handle' ]
	);

	// ─── REST controllers ──────────────────────────────────────────────
	$c->singleton( \Shop\Rest\CartController::class, fn( $c ) =>
		new \Shop\Rest\CartController(
			$c->get( \Shop\Services\CartService::class ),
			$c->get( \Shop\Services\CartRenderer::class ),
			$c->get( \Shop\Services\CartTokenManager::class ),
			$c->get( \Shop\Services\CouponService::class ),
		)
	);

	// ─── Refunds ───────────────────────────────────────────────────────
	$c->singleton( \Shop\Services\RefundService::class, fn( $c ) =>
		new \Shop\Services\RefundService(
			$c->get( \Shop\Repositories\RefundRepository::class ),
			$c->get( \Shop\Repositories\OrderRepository::class ),
			$c->get( \Shop\Repositories\PaymentGatewayRegistry::class ),
			$c->get( \Shop\Services\CouponService::class ),
		)
	);

	// ─── Vendor routing + dictionary ───────────────────────────────────
	$c->singleton( \Shop\Services\VendorRouter::class, fn( $c ) =>
		new \Shop\Services\VendorRouter(
			$c->get( \Shop\Repositories\OrderShipmentRepository::class ),
			$c->get( \Shop\Events\EventBus::class ),
		)
	);
	$c->singleton( \Shop\Services\VendorDictionaryService::class, fn( $c ) =>
		new \Shop\Services\VendorDictionaryService(
			$c->get( \Shop\Repositories\VendorOptionTermsRepository::class ),
		)
	);

	// Subscribe VendorRouter to OrderPaid so every paid order automatically
	// queues per-vendor routing events. Subscribers (POD adapters, Nexus,
	// WooOrderMirror) listen for ShipmentReadyToRoute.
	$c->get( \Shop\Events\EventBus::class )->on(
		\Shop\Events\OrderPaid::class,
		[ $c->get( \Shop\Services\VendorRouter::class ), 'onOrderPaid' ]
	);

	$c->singleton( \Shop\Rest\DictionaryController::class, fn( $c ) =>
		new \Shop\Rest\DictionaryController(
			$c->get( \Shop\Services\VendorDictionaryService::class ),
			$c->get( \Shop\Repositories\VendorOptionTermsRepository::class ),
		)
	);

	// ─── Exporters / feeds ─────────────────────────────────────────────
	$c->singleton( \Shop\Exporters\CatalogReader::class, fn( $c ) =>
		new \Shop\Exporters\CatalogReader(
			$c->get( \Shop\Repositories\ProductRepository::class )
		)
	);
	$c->singleton( \Shop\Services\ExporterRegistry::class, function ( $c ) {
		$reg = new \Shop\Services\ExporterRegistry();
		$reader = $c->get( \Shop\Exporters\CatalogReader::class );
		$products = $c->get( \Shop\Repositories\ProductRepository::class );
		$dict = $c->get( \Shop\Services\VendorDictionaryService::class );
		$reg->register( new \Shop\Exporters\CsvExporter( $reader, $products ) );
		$reg->register( new \Shop\Exporters\MarkdownExporter( $reader ) );
		$reg->register( new \Shop\Exporters\GoogleShoppingFeed( $reader, $products, $dict ) );
		$reg->register( new \Shop\Exporters\MetaCatalogFeed( $reader, $products, $dict ) );
		$reg->register( new \Shop\Exporters\TikTokFeed( $reader, $products, $dict ) );
		do_action( 'shop_register_exporters', $reg );
		return $reg;
	} );
	$c->singleton( \Shop\Repositories\CustomerRepository::class, fn() => new \Shop\Repositories\CustomerRepository() );
	$c->singleton( \Shop\Importers\CustomerImporter::class, fn( $c ) =>
		new \Shop\Importers\CustomerImporter( $c->get( \Shop\Repositories\CustomerRepository::class ) )
	);
	$c->singleton( \Shop\Exporters\CustomerExporter::class, fn( $c ) =>
		new \Shop\Exporters\CustomerExporter( $c->get( \Shop\Repositories\CustomerRepository::class ) )
	);
	$c->singleton( \Shop\Importers\OrderImporter::class, fn( $c ) =>
		new \Shop\Importers\OrderImporter( $c->get( \Shop\Repositories\OrderRepository::class ) )
	);
	$c->singleton( \Shop\Exporters\OrderExporter::class, fn( $c ) =>
		new \Shop\Exporters\OrderExporter( $c->get( \Shop\Repositories\OrderRepository::class ) )
	);
	$c->singleton( \Shop\Rest\OrderIoController::class, fn( $c ) =>
		new \Shop\Rest\OrderIoController(
			$c->get( \Shop\Importers\OrderImporter::class ),
			$c->get( \Shop\Exporters\OrderExporter::class ),
		)
	);
	$c->singleton( \Shop\Rest\CustomersController::class, fn( $c ) =>
		new \Shop\Rest\CustomersController(
			$c->get( \Shop\Repositories\CustomerRepository::class ),
			$c->get( \Shop\Importers\CustomerImporter::class ),
			$c->get( \Shop\Exporters\CustomerExporter::class ),
		)
	);
	$c->singleton( \Shop\Rest\GridViewsController::class, fn() => new \Shop\Rest\GridViewsController() );
	$c->singleton( \Shop\Rest\StudioPayController::class, fn( $c ) =>
		new \Shop\Rest\StudioPayController(
			$c->get( \Shop\Payments\Studio\StudioPay::class ),
			$c->get( \Shop\Payments\Studio\Payouts::class ),
			$c->get( \Shop\Payments\Studio\StudioConnect::class ),
		)
	);
	$c->singleton( \Shop\Rest\FeedController::class, fn( $c ) =>
		new \Shop\Rest\FeedController(
			$c->get( \Shop\Services\ExporterRegistry::class )
		)
	);

	// ─── HPOS-style order export (opt-in compat for Woo extensions) ────
	$c->singleton( \Shop\Compat\HposOrderAdapter::class, fn( $c ) =>
		new \Shop\Compat\HposOrderAdapter(
			$c->get( \Shop\Repositories\OrderRepository::class )
		)
	);

	// ─── Element library + page builder adapters ──────────────────────
	$c->singleton( \Shop\Elements\ElementRegistry::class, function ( $c ) {
		$reg = new \Shop\Elements\ElementRegistry();
		$products   = $c->get( \Shop\Repositories\ProductRepository::class );
		$attributes = $c->get( \Shop\Repositories\AttributeRepository::class );
		// Catalog elements (Shop-specific)
		$reg->register( new \Shop\Elements\Catalog\ProductTitle( $products ) );
		$reg->register( new \Shop\Elements\Catalog\ProductPrice( $products ) );
		$reg->register( new \Shop\Elements\Catalog\ProductGallery( $products ) );
		$reg->register( new \Shop\Elements\Catalog\VariantPicker( $products, $attributes ) );
		$reg->register( new \Shop\Elements\Catalog\AddToCart( $products ) );
		$reg->register( new \Shop\Elements\Catalog\StockStatus( $products ) );
		$reg->register( new \Shop\Elements\Catalog\ProductDescription( $products ) );
		$reg->register( new \Shop\Elements\Catalog\ProductMeta( $products, $attributes ) );
		$reg->register( new \Shop\Elements\Catalog\ProductGrid( $products ) );
		// Commerce surfaces (cart/checkout/received)
		$reg->register( new \Shop\Elements\Commerce\CartContents(
			$c->get( \Shop\Services\CartService::class ),
			$c->get( \Shop\Services\CartRenderer::class ),
			$c->get( \Shop\Services\CartTokenManager::class ),
		) );
		$reg->register( new \Shop\Elements\Commerce\CheckoutForm(
			$c->get( \Shop\Services\CartService::class ),
			$c->get( \Shop\Services\CheckoutRenderer::class ),
			$c->get( \Shop\Services\CartTokenManager::class ),
		) );
		$reg->register( new \Shop\Elements\Commerce\OrderReceived(
			$c->get( \Shop\Repositories\OrderRepository::class )
		) );
		// Layout primitives
		$reg->register( new \Shop\Elements\Layout\Section() );
		$reg->register( new \Shop\Elements\Layout\Column() );
		$reg->register( new \Shop\Elements\Layout\Heading() );
		$reg->register( new \Shop\Elements\Layout\Image() );
		$reg->register( new \Shop\Elements\Layout\Button() );
		$reg->register( new \Shop\Elements\Layout\Spacer() );
		$reg->register( new \Shop\Elements\Layout\Divider() );
		// Chrome (header / footer essentials)
		$reg->register( new \Shop\Elements\Chrome\SiteLogo() );
		$reg->register( new \Shop\Elements\Chrome\SiteNav() );
		$reg->register( new \Shop\Elements\Chrome\CartButton() );
		// Content
		$reg->register( new \Shop\Elements\Content\RichText() );
		do_action( 'shop_register_elements', $reg );
		return $reg;
	} );

	$c->singleton( \Shop\Builders\Bricks\BricksAdapter::class, fn( $c ) =>
		new \Shop\Builders\Bricks\BricksAdapter(
			$c->get( \Shop\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Shop\Builders\Elementor\ElementorAdapter::class, fn( $c ) =>
		new \Shop\Builders\Elementor\ElementorAdapter(
			$c->get( \Shop\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Shop\Builders\Gutenberg\GutenbergAdapter::class, fn( $c ) =>
		new \Shop\Builders\Gutenberg\GutenbergAdapter(
			$c->get( \Shop\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Shop\Rest\CheckoutController::class, fn( $c ) =>
		new \Shop\Rest\CheckoutController(
			$c->get( \Shop\Services\CheckoutService::class ),
			$c->get( \Shop\Services\CartTokenManager::class ),
			$c->get( \Shop\Services\WebhookReceiver::class ),
		)
	);
	$c->singleton( \Shop\Rest\WebhookController::class, fn( $c ) =>
		new \Shop\Rest\WebhookController(
			$c->get( \Shop\Services\WebhookReceiver::class )
		)
	);

	// ─── Importer / writer ─────────────────────────────────────────────
	$c->singleton( \Shop\Services\ProductWriter::class,
		fn() => new \Shop\Services\ProductWriter() );

	$c->singleton( \Shop\Services\ImporterRegistry::class, function () {
		$reg = new \Shop\Services\ImporterRegistry();
		// Order matters — more specific importers come first so generic
		// fallbacks (markdown swallowing .txt) don't shadow them.
		$reg->register( new \Shop\Importers\CsvImporter() );
		$reg->register( new \Shop\Importers\PdfImporter() );
		$reg->register( new \Shop\Importers\ImageImporter() );
		$reg->register( new \Shop\Importers\FigmaImporter() );
		$reg->register( new \Shop\Importers\UrlImporter() );
		$reg->register( new \Shop\Importers\MarkdownImporter() );
		do_action( 'shop_register_importers', $reg );
		return $reg;
	} );

	$c->singleton( \Shop\Rest\ImporterController::class, fn( $c ) =>
		new \Shop\Rest\ImporterController(
			$c->get( \Shop\Services\ImporterRegistry::class ),
			$c->get( \Shop\Services\ProductWriter::class ),
		)
	);

	// ─── Admin UI ──────────────────────────────────────────────────────
	$c->singleton( \Shop\Admin\SettingsPage::class, fn() => new \Shop\Admin\SettingsPage() );
	$c->singleton( \Shop\Admin\ImporterPage::class, fn( $c ) =>
		new \Shop\Admin\ImporterPage(
			$c->get( \Shop\Services\ImporterRegistry::class )
		)
	);
	$c->singleton( \Shop\Admin\ProductsPage::class, fn() => new \Shop\Admin\ProductsPage() );
	$c->singleton( \Shop\Admin\OrdersPage::class,   fn() => new \Shop\Admin\OrdersPage() );
	$c->singleton( \Shop\Admin\BuilderPage::class,  fn() => new \Shop\Admin\BuilderPage() );
	$c->singleton( \Shop\Admin\StudioPayPage::class, fn() => new \Shop\Admin\StudioPayPage() );
	$c->singleton( \Shop\Admin\CustomersPage::class, fn() => new \Shop\Admin\CustomersPage() );
	$c->singleton( \Shop\Admin\OrderIoPage::class,   fn() => new \Shop\Admin\OrderIoPage() );
	$c->singleton( \Shop\Admin\UpdatesPage::class,   fn() => new \Shop\Admin\UpdatesPage() );
	$c->singleton( \Shop\Services\Updater::class,    fn() => new \Shop\Services\Updater() );
	$c->singleton( \Shop\Rest\UpdaterController::class, fn( $c ) =>
		new \Shop\Rest\UpdaterController( $c->get( \Shop\Services\Updater::class ) )
	);
	$c->singleton( \Shop\Admin\AdminMenu::class, fn( $c ) =>
		new \Shop\Admin\AdminMenu(
			$c->get( \Shop\Admin\SettingsPage::class ),
			$c->get( \Shop\Admin\ImporterPage::class ),
			$c->get( \Shop\Admin\ProductsPage::class ),
			$c->get( \Shop\Admin\OrdersPage::class ),
			$c->get( \Shop\Admin\BuilderPage::class ),
			$c->get( \Shop\Admin\StudioPayPage::class ),
			$c->get( \Shop\Admin\CustomersPage::class ),
			$c->get( \Shop\Admin\OrderIoPage::class ),
			$c->get( \Shop\Admin\UpdatesPage::class ),
		)
	);

	// ─── Pure builder support ──────────────────────────────────────────
	$c->singleton( \Shop\Repositories\PageRepository::class, fn() => new \Shop\Repositories\PageRepository() );
	$c->singleton( \Shop\Services\PageRenderer::class, fn( $c ) =>
		new \Shop\Services\PageRenderer(
			$c->get( \Shop\Elements\ElementRegistry::class )
		)
	);
	$c->singleton( \Shop\Services\ChromeResolver::class, fn( $c ) =>
		new \Shop\Services\ChromeResolver(
			$c->get( \Shop\Repositories\PageRepository::class ),
		)
	);
	$c->singleton( \Shop\Services\PageRouter::class, fn( $c ) =>
		new \Shop\Services\PageRouter(
			$c->get( \Shop\Repositories\PageRepository::class ),
			$c->get( \Shop\Services\PageRenderer::class ),
			$c->get( \Shop\Services\ChromeResolver::class ),
		)
	);
	$c->singleton( \Shop\Services\TemplateSeeder::class, fn( $c ) =>
		new \Shop\Services\TemplateSeeder(
			$c->get( \Shop\Repositories\PageRepository::class ),
		)
	);
	$c->singleton( \Shop\AI\ClaudeClient::class, fn() => new \Shop\AI\ClaudeClient() );
	$c->singleton( \Shop\Services\BuilderAi::class, fn( $c ) =>
		new \Shop\Services\BuilderAi(
			$c->get( \Shop\AI\ClaudeClient::class ),
			$c->get( \Shop\Elements\ElementRegistry::class ),
		)
	);
	$c->singleton( \Shop\Rest\PagesController::class, fn( $c ) =>
		new \Shop\Rest\PagesController(
			$c->get( \Shop\Repositories\PageRepository::class ),
			$c->get( \Shop\Services\PageRenderer::class ),
			$c->get( \Shop\Elements\ElementRegistry::class ),
			$c->get( \Shop\Services\BuilderAi::class ),
		)
	);

	// Admin REST controller — spreadsheet endpoints + refunds
	$c->singleton( \Shop\Rest\AdminController::class, fn( $c ) =>
		new \Shop\Rest\AdminController(
			$c->get( \Shop\Repositories\OrderRepository::class ),
			$c->get( \Shop\Services\RefundService::class ),
		)
	);
}

// ─── Admin menu registration ───────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	if ( ! is_admin() ) return;
	\Shop\Container::instance()->get( \Shop\Admin\AdminMenu::class )->register();
}, 5 );

// ─── REST registration ─────────────────────────────────────────────────────
add_action( 'rest_api_init', function (): void {
	$c = \Shop\Container::instance();
	$c->get( \Shop\Rest\CartController::class )->register();
	$c->get( \Shop\Rest\CheckoutController::class )->register();
	$c->get( \Shop\Rest\WebhookController::class )->register();
	$c->get( \Shop\Rest\ImporterController::class )->register();
	$c->get( \Shop\Rest\AdminController::class )->register();
	$c->get( \Shop\Rest\DictionaryController::class )->register();
	$c->get( \Shop\Rest\FeedController::class )->register();
	$c->get( \Shop\Rest\PagesController::class )->register();
	$c->get( \Shop\Rest\StudioPayController::class )->register();
	$c->get( \Shop\Rest\CustomersController::class )->register();
	$c->get( \Shop\Rest\OrderIoController::class )->register();
	$c->get( \Shop\Rest\GridViewsController::class )->register();
	$c->get( \Shop\Rest\UpdaterController::class )->register();
} );

// ─── Asset enqueue ─────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function (): void {
	wp_register_style(  'shop-cart', SHOP_URL . 'assets/cart/cart.css', [], SHOP_VERSION );
	wp_register_script( 'shop-cart', SHOP_URL . 'assets/cart/cart.js',  [], SHOP_VERSION, [
		'in_footer' => true,
		'strategy'  => 'defer',
	] );

	wp_add_inline_script( 'shop-cart',
		'window.ShopCartConfig = ' . wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] ) . ';',
		'before'
	);

	wp_enqueue_style(  'shop-cart' );
	wp_enqueue_script( 'shop-cart' );

	// Elements CSS + JS — small, always enqueued so pages with any
	// Shop element render styled out of the box. Elements that need
	// interactivity (Gallery, VariantPicker, AddToCart) live in
	// elements.js; static elements (Heading, Image, Button, Section,
	// Spacer, Divider, RichText) don't need JS but the script is
	// harmless when none are present.
	wp_register_style(  'shop-elements', SHOP_URL . 'assets/elements/elements.css', [],          SHOP_VERSION );
	wp_register_script( 'shop-elements', SHOP_URL . 'assets/elements/elements.js',  [ 'shop-cart' ], SHOP_VERSION, [
		'in_footer' => true,
		'strategy'  => 'defer',
	] );
	wp_enqueue_style(  'shop-elements' );
	wp_enqueue_script( 'shop-elements' );

	// Studio checkout assets — only when the page contains the Studio
	// pattern (cheap heuristic: presentation option matches).
	if ( get_option( 'shop_checkout_presentation' ) === 'studio' ) {
		wp_register_style(  'shop-checkout-studio', SHOP_URL . 'assets/checkout/studio.css', [], SHOP_VERSION );
		wp_register_script( 'shop-checkout-studio', SHOP_URL . 'assets/checkout/studio.js',  [], SHOP_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_enqueue_style(  'shop-checkout-studio' );
		wp_enqueue_script( 'shop-checkout-studio' );
	}
} );

// ─── Floating button injection ─────────────────────────────────────────────
// Renders the FAB + paired drawer at the end of every front-end page.
// Suppressed when the default mode is 'none' or 'page', or on the
// /checkout/ page (where it would compete with the checkout flow).
add_action( 'wp_footer', function (): void {
	if ( is_admin() ) return;

	$renderer = \Shop\Container::instance()->get( \Shop\Services\CartRenderer::class );
	$mode     = $renderer->defaultMode();

	if ( in_array( $mode, [
		\Shop\Services\CartRenderer::MODE_PAGE,
		\Shop\Services\CartRenderer::MODE_NONE,
	], true ) ) return;

	if ( ! (bool) apply_filters( 'shop_show_floating_cart', true ) ) return;

	$token = \Shop\Container::instance()->get( \Shop\Services\CartTokenManager::class )->current();
	$cart  = \Shop\Container::instance()->get( \Shop\Services\CartService::class )->getOrCreate( $token );

	echo $renderer->floatingButton( $cart ); // phpcs:ignore — template-escaped at source
} );

// ─── Shortcodes ────────────────────────────────────────────────────────────
add_action( 'init', function (): void {
	$c = \Shop\Container::instance();

	// [shop_cart] — renders the configured cart shell on the current page.
	add_shortcode( 'shop_cart', function () use ( $c ): string {
		$token    = $c->get( \Shop\Services\CartTokenManager::class )->current();
		$cart     = $c->get( \Shop\Services\CartService::class )->getOrCreate( $token );
		$renderer = $c->get( \Shop\Services\CartRenderer::class );
		// Use the configured default (Studio / Counter / etc); admins
		// switch in Settings → Shop → Cart presentation.
		return $renderer->shell( $renderer->defaultMode(), $cart );
	} );

	// [shop_checkout] — renders the configured checkout template. Source of
	// truth for the customer's payment surface.
	add_shortcode( 'shop_checkout', function () use ( $c ): string {
		$token    = $c->get( \Shop\Services\CartTokenManager::class )->current();
		$cart     = $c->get( \Shop\Services\CartService::class )->getOrCreate( $token );
		$renderer = $c->get( \Shop\Services\CheckoutRenderer::class );
		return $renderer->render( $cart );
	} );

	// [shop_order_received] — post-payment confirmation. Reads ?order= from
	// the URL and looks it up. Falls through to the empty-state if absent.
	add_shortcode( 'shop_order_received', function () use ( $c ): string {
		$number = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['order'] ) ) : '';
		$order  = null;
		if ( $number !== '' ) {
			$order = $c->get( \Shop\Repositories\OrderRepository::class )->findByNumber( $number );
		}
		$candidates = [
			get_stylesheet_directory() . '/shop/order-received.php',
			get_template_directory()   . '/shop/order-received.php',
			SHOP_DIR . 'templates/order-received.php',
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
	register_setting( 'shop_appearance', 'shop_cart_presentation', [
		'type'              => 'string',
		'default'           => \Shop\Services\CartRenderer::MODE_STUDIO,
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, \Shop\Services\CartRenderer::ALL_MODES, true )
				? $v
				: \Shop\Services\CartRenderer::MODE_STUDIO;
		},
	] );

	register_setting( 'shop_appearance', 'shop_checkout_presentation', [
		'type'              => 'string',
		'default'           => \Shop\Services\CheckoutRenderer::MODE_CLASSIC,
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, \Shop\Services\CheckoutRenderer::ALL_MODES, true )
				? $v
				: \Shop\Services\CheckoutRenderer::MODE_CLASSIC;
		},
	] );

	register_setting( 'shop_appearance', 'shop_cart_button_position', [
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
	register_setting( 'shop_catalog', 'shop_product_source', [
		'type'              => 'string',
		'default'           => 'native',
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, [ 'native', 'woo' ], true ) ? $v : 'native';
		},
	] );

	// HPOS-style order export — opt-in compat layer for Woo extensions
	// that need to read Shop orders. Off by default.
	register_setting( 'shop_compat', 'shop_hpos_export', [
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => fn( $v ) => (bool) $v,
	] );
} );

// HPOS adapter registers its Woo filters when enabled, after Woo loads.
add_action( 'woocommerce_init', function (): void {
	\Shop\Container::instance()->get( \Shop\Compat\HposOrderAdapter::class )->register();
} );

// Bricks adapter — fires on plugins_loaded so Bricks (theme or plugin)
// has had a chance to define BRICKS_VERSION before we check.
add_action( 'plugins_loaded', function (): void {
	if ( ! \Shop\Builders\Bricks\BricksAdapter::isActive() ) return;
	\Shop\Container::instance()->get( \Shop\Builders\Bricks\BricksAdapter::class )->register();
\Shop\Container::instance()->get( \Shop\Builders\Elementor\ElementorAdapter::class )->register();
\Shop\Container::instance()->get( \Shop\Builders\Gutenberg\GutenbergAdapter::class )->register();
}, 20 );

// Pure front-end routing. Mode helper short-circuits in Unlocked mode.
add_action( 'plugins_loaded', function (): void {
	\Shop\Container::instance()->get( \Shop\Services\PageRouter::class )->register();
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
	if ( ! \Shop\Container::instance()->has( \Shop\Services\PageRouter::class ) ) {
		// Trigger our own plugins_loaded handlers — only ours, not the
		// global action — so the container is fully populated before
		// the activation hook tries to read from it.
		do_action( 'shop_bootstrap_container_for_activation' );
	}
	// Pure-mode routes only — Unlocked mode delegates routing to
	// whichever page builder is active.
	if ( \Shop\Container::instance()->has( \Shop\Services\PageRouter::class ) ) {
		\Shop\Container::instance()->get( \Shop\Services\PageRouter::class )->rewrites();
	}
	flush_rewrite_rules();
} );

// ─── Queued event worker ────────────────────────────────────────────────────
// EventBus::queue() schedules under this hook (both Action Scheduler and
// wp-cron). The worker reconstructs and dispatches the event synchronously.
add_action( 'shop_queued_event', function ( array $payload ): void {
	\Shop\Container::instance()
		->get( \Shop\Events\EventBus::class )
		->handleQueued( $payload );
}, 10, 1 );

// HPOS / WC compatibility note: Shop does NOT use WooCommerce's order tables
// (or any WP tables at all). All commerce data lives in our own SQLite file
// under wp-content/uploads/therum-shop/shop.sqlite. This plugin can run
// alongside WooCommerce on the same site without conflict.
