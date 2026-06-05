<?php
/**
 * Shop by Therum — PageRouter.
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

namespace Shop\Services;

use Shop\Elements\ElementContext;
use Shop\Mode;
use Shop\Models\Page;
use Shop\Repositories\PageRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PageRouter {

	public function __construct(
		private readonly PageRepository $pages,
		private readonly PageRenderer $renderer,
		private readonly ChromeResolver $chrome,
	) {}

	public function register(): void {
		if ( ! Mode::isPure() ) return;

		add_action( 'init',               [ $this, 'rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'queryVars' ] );
		add_filter( 'template_include',   [ $this, 'templateInclude' ] );
	}

	public function rewrites(): void {
		add_rewrite_rule( '^p/([^/]+)/?$',        'index.php?shop_pure=$matches[1]',          'top' );
		add_rewrite_rule( '^product/([^/]+)/?$',  'index.php?shop_pure_product=$matches[1]',  'top' );
		add_rewrite_rule( '^shop/?$',             'index.php?shop_pure_archive=1',            'top' );
		add_rewrite_rule( '^cart/?$',             'index.php?shop_pure_cart=1',               'top' );
		add_rewrite_rule( '^checkout/?$',         'index.php?shop_pure_checkout=1',           'top' );
		add_rewrite_rule( '^order-received/?$',   'index.php?shop_pure_received=1',           'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function queryVars( array $vars ): array {
		$vars[] = 'shop_pure';
		$vars[] = 'shop_pure_product';
		$vars[] = 'shop_pure_archive';
		$vars[] = 'shop_pure_cart';
		$vars[] = 'shop_pure_checkout';
		$vars[] = 'shop_pure_received';
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
		return SHOP_DIR . 'templates/pure-page.php';
	}

	/**
	 * Resolves the current request to the Page that should render.
	 * Used by both templateInclude and the bundled pure-page.php
	 * template via renderCurrent().
	 */
	public function currentPage(): ?Page {
		$slug = get_query_var( 'shop_pure' );
		if ( is_string( $slug ) && $slug !== '' ) {
			return $this->pages->findBySlug( $slug, Page::KIND_PAGE );
		}

		$product = get_query_var( 'shop_pure_product' );
		if ( is_string( $product ) && $product !== '' ) {
			return $this->pages->findByAssignment( 'single-product' );
		}

		$archive = get_query_var( 'shop_pure_archive' );
		if ( $archive !== '' ) {
			return $this->pages->findByAssignment( 'product-archive' );
		}

		if ( get_query_var( 'shop_pure_cart' )     !== '' ) return $this->pages->findByAssignment( 'cart-page' );
		if ( get_query_var( 'shop_pure_checkout' ) !== '' ) return $this->pages->findByAssignment( 'checkout-page' );
		if ( get_query_var( 'shop_pure_received' ) !== '' ) return $this->pages->findByAssignment( 'order-received' );

		return null;
	}

	/**
	 * Build a context for the current request. For single-product
	 * pages we pre-resolve the product so the elements can read it
	 * without re-querying.
	 */
	public function currentContext(): ElementContext {
		$slug = get_query_var( 'shop_pure_product' );
		if ( is_string( $slug ) && $slug !== '' ) {
			$products = \Shop\Container::instance()->get( \Shop\Repositories\ProductRepository::class );
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
		if ( $header !== null ) $out .= '<div class="shop-pure-header">' . $this->renderer->render( $header->tree, $ctx ) . '</div>';
		$out .= '<div class="shop-pure-body">'   . $this->renderer->render( $page->tree, $ctx ) . '</div>';
		if ( $footer !== null ) $out .= '<div class="shop-pure-footer">' . $this->renderer->render( $footer->tree, $ctx ) . '</div>';
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
