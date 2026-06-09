<?php
/**
 * Counter by Therum — PageRouter.
 *
 * Front-end routing for Pure pages. Two responsibilities:
 *
 *   1. Catch storefront requests that should render a Pure page:
 *      - /p/{slug}                — a regular page
 *      - /shop/                   — product archive (uses 'archive' template)
 *      - /product/{slug}/         — single product (uses 'single-product' template)
 *      - /cart/                   — Studio cart (template) when in Pure mode
 *      - /checkout/               — Pure checkout template
 *      - /order-received/         — receipt template
 *
 *   2. Render the matched template into the theme via the
 *      `template_include` filter. We ship a fallback theme template at
 *      templates/pure-page.php that calls $this->renderCurrent() so any
 *      theme can host Pure rendering with one line.
 *
 * In Unlocked mode the router quietly does nothing — Bricks /
 * Elementor / Gutenberg handle these routes via their own template
 * systems.
 */

namespace Counter\Services;

use Counter\Elements\ElementContext;
use Counter\Mode;
use Counter\Models\Page;
use Counter\Repositories\PageRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PageRouter {

	public function __construct(
		private readonly PageRepository $pages,
		private readonly PageRenderer $renderer,
		private readonly ChromeResolver $chrome,
	) {}

	public function register(): void {
		if ( ! self::isActive() ) return;

		add_action( 'init',               [ $this, 'rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'queryVars' ] );
		// Priority 99 so Counter wins against WooCommerce's template
		// loader (runs at default 10) when both register the filter.
		// Counter still returns the input $template untouched when no
		// matching Page exists, so WC keeps its templates everywhere
		// outside the routes Counter owns.
		add_filter( 'template_include',   [ $this, 'templateInclude' ], 99 );
	}

	/**
	 * Whether Counter should own the storefront URLs (/shop/, /product/{slug},
	 * /cart/, /checkout/, /order-received/). True in Pure mode by default,
	 * but the `counter_storefront_takeover` filter lets Woo-mode and
	 * theme-driven setups (e.g. Moderno) opt in without flipping Mode.
	 *
	 * Themes / mu-plugins enable Counter as the storefront engine with:
	 *
	 *     add_filter( 'counter_storefront_takeover', '__return_true' );
	 *
	 * counter.php auto-enables it for the Moderno theme so the product /
	 * cart / checkout pages render through Counter while the theme paints
	 * the surrounding chrome via get_header() / get_footer().
	 */
	public static function isActive(): bool {
		return Mode::isPure() || (bool) apply_filters( 'counter_storefront_takeover', false );
	}

	public function rewrites(): void {
		add_rewrite_rule( '^p/([^/]+)/?$',        'index.php?shop_pure=$matches[1]',          'top' );
		add_rewrite_rule( '^product/([^/]+)/?$',  'index.php?shop_pure_product=$matches[1]',  'top' );
		// /shop/ only when the merchant hasn't picked a custom shop page —
		// otherwise their chosen page (theme template) renders normally.
		if ( self::shopMode() === 'counter' ) {
			add_rewrite_rule( '^shop/?$',         'index.php?shop_pure_archive=1',            'top' );
		}
		add_rewrite_rule( '^cart/?$',             'index.php?shop_pure_cart=1',               'top' );
		add_rewrite_rule( '^checkout/?$',         'index.php?shop_pure_checkout=1',           'top' );
		add_rewrite_rule( '^order-received/?$',   'index.php?shop_pure_received=1',           'top' );
	}

	/**
	 * Shop page mode: 'counter' for the built-in /shop/ archive, or a
	 * numeric string holding a WP Page ID the merchant has chosen as
	 * the storefront landing.
	 */
	public static function shopMode(): string {
		return (string) get_option( 'counter_shop_page', 'counter' );
	}

	/**
	 * Resolve the URL Counter should send "Shop" links to. Honors the
	 * counter_shop_page setting: built-in /shop/, or the permalink of
	 * the WP Page the merchant selected. Code that emits storefront
	 * links (theme bridges, menu items, "View all" CTAs) calls this
	 * instead of hardcoding /shop/.
	 */
	public static function shopUrl(): string {
		$mode = self::shopMode();
		if ( $mode === 'counter' ) return home_url( '/shop/' );
		$id = (int) $mode;
		$url = $id > 0 ? get_permalink( $id ) : false;
		return $url ?: home_url( '/shop/' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = 'counter_pure';
		$vars[] = 'counter_pure_product';
		$vars[] = 'counter_pure_archive';
		$vars[] = 'counter_pure_cart';
		$vars[] = 'counter_pure_checkout';
		$vars[] = 'counter_pure_received';
		return $vars;
	}

	public function templateInclude( string $template ): string {
		$page    = $this->currentPage();
		if ( $page === null ) return $template;

		// Theme override — copy templates/pure-page.php into the theme
		// to customize chrome around our render.
		$theme = locate_template( [ 'shop/pure-page.php' ] );
		if ( $theme !== '' ) return $theme;

		// Plugin's bundled template
		return COUNTER_DIR . 'templates/pure-page.php';
	}

	/**
	 * Resolves the current request to the Page that should render.
	 * Used by both templateInclude and the bundled pure-page.php
	 * template via renderCurrent().
	 */
	public function currentPage(): ?Page {
		$slug = get_query_var( 'counter_pure' );
		if ( is_string( $slug ) && $slug !== '' ) {
			return $this->pages->findBySlug( $slug, Page::KIND_PAGE );
		}

		$product = get_query_var( 'counter_pure_product' );
		if ( is_string( $product ) && $product !== '' ) {
			return $this->pages->findByAssignment( 'single-product' );
		}

		$archive = get_query_var( 'counter_pure_archive' );
		if ( $archive !== '' && self::shopMode() === 'counter' ) {
			return $this->pages->findByAssignment( 'product-archive' );
		}

		if ( get_query_var( 'counter_pure_cart' )     !== '' ) return $this->pages->findByAssignment( 'cart-page' );
		if ( get_query_var( 'counter_pure_checkout' ) !== '' ) return $this->pages->findByAssignment( 'checkout-page' );
		if ( get_query_var( 'counter_pure_received' ) !== '' ) return $this->pages->findByAssignment( 'order-received' );

		return null;
	}

	/**
	 * Build a context for the current request. For single-product
	 * pages we pre-resolve the product so the elements can read it
	 * without re-querying.
	 */
	public function currentContext(): ElementContext {
		$slug = get_query_var( 'counter_pure_product' );
		if ( is_string( $slug ) && $slug !== '' ) {
			$products = \Counter\Container::instance()->get( \Counter\Repositories\ProductRepository::class );
			$product  = $products->findBySlug( $slug );
			return new ElementContext(
				productId:   $product?->id,
				productSlug: $slug,
			);
		}
		return new ElementContext();
	}

	/**
	 * Convenience for theme templates — renders the current page's tree
	 * wrapped in the active header / footer chrome and returns the HTML.
	 * Themes that want bare body content (no chrome) can call
	 * `renderCurrentBody()` directly.
	 */
	public function renderCurrent(): string {
		$page = $this->currentPage();
		if ( $page === null ) return '';
		$ctx  = $this->currentContext();
		$out  = '';
		$header = $this->chrome->activeHeader();
		$footer = $this->chrome->activeFooter();
		if ( $header !== null ) $out .= '<div class="counter-pure-header">' . $this->renderer->render( $header->tree, $ctx ) . '</div>';
		$out .= '<div class="counter-pure-body">'   . $this->renderer->render( $page->tree, $ctx ) . '</div>';
		if ( $footer !== null ) $out .= '<div class="counter-pure-footer">' . $this->renderer->render( $footer->tree, $ctx ) . '</div>';
		return $out;
	}

	/**
	 * Body-only render — for themes that supply their own chrome and
	 * only want the page tree.
	 */
	public function renderCurrentBody(): string {
		$page = $this->currentPage();
		if ( $page === null ) return '';
		return $this->renderer->render( $page->tree, $this->currentContext() );
	}
}
