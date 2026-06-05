<?php
/**
 * Shop by Therum — TemplateSeeder.
 *
 * Creates the starter templates on first activation. Each template is
 * a Page row with kind='template' and an assigned_to slot:
 *
 *   single-product   — the single product page layout
 *   product-archive  — catalog grid
 *   cart-page        — the /cart/ page when shoppers click "view cart"
 *   checkout-page    — the /checkout/ page
 *   order-received   — post-pay confirmation
 *
 * Idempotent — only seeds if no template is assigned for that slot.
 * Admins can edit or replace any of these in the builder.
 */

namespace Shop\Services;

use Shop\Models\Page;
use Shop\Repositories\PageRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TemplateSeeder {

	public function __construct(
		private readonly PageRepository $pages,
	) {}

	/**
	 * Seed one starter header + footer if no header / footer exists yet.
	 * Sets `shop_active_header_id` / `shop_active_footer_id` so the
	 * ChromeResolver immediately picks them up. Idempotent — a second
	 * call after a header already exists is a no-op for that kind.
	 */
	public function seedChrome(): void {
		$existingHeaders = $this->pages->list( Page::KIND_HEADER, null, 1 );
		if ( ! $existingHeaders ) {
			$page = $this->pages->create( 'Default Header', Page::KIND_HEADER, null );
			$this->pages->save( $page->id, [
				'status' => 'published',
				'tree'   => $this->defaultHeaderTree(),
			] );
			update_option( 'shop_active_header_id', $page->id );
		}

		$existingFooters = $this->pages->list( Page::KIND_FOOTER, null, 1 );
		if ( ! $existingFooters ) {
			$page = $this->pages->create( 'Default Footer', Page::KIND_FOOTER, null );
			$this->pages->save( $page->id, [
				'status' => 'published',
				'tree'   => $this->defaultFooterTree(),
			] );
			update_option( 'shop_active_footer_id', $page->id );
		}
	}

	public function seedAll(): void {
		$defaults = [
			[ 'single-product',  'Single Product (default)',  $this->singleProductTree() ],
			[ 'product-archive', 'Product Archive (default)', $this->productArchiveTree() ],
			[ 'cart-page',       'Cart (default)',            $this->cartTree() ],
			[ 'checkout-page',   'Checkout (default)',        $this->checkoutTree() ],
			[ 'order-received',  'Order Received (default)',  $this->orderReceivedTree() ],
		];
		foreach ( $defaults as [ $slot, $title, $tree ] ) {
			if ( $this->pages->findByAssignment( $slot ) !== null ) continue;
			$page = $this->pages->create( $title, Page::KIND_TEMPLATE, $slot );
			$this->pages->save( $page->id, [
				'status' => 'published',
				'tree'   => $tree,
			] );
		}
	}

	/**
	 * Default single-product template — two-column hero with gallery
	 * left and meta/variant/add-to-cart right. Pure HTML when rendered
	 * via PageRenderer.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function singleProductTree(): array {
		return [
			$this->section( 'lg', 48, [
				$this->row( [
					$this->column( 6, [
						$this->node( 'product-gallery', [
							'layout' => 'side-thumbs',
							'aspect' => '4/5',
							'radius' => 12,
							'zoom'   => true,
						] ),
					] ),
					$this->column( 6, [
						$this->node( 'stock-status',         [ 'show_dot' => true ] ),
						$this->node( 'product-title',        [ 'tag' => 'h1' ] ),
						$this->node( 'product-meta',         [ 'show_sku' => true, 'show_vendor' => true ] ),
						$this->node( 'product-price',        [ 'size' => 'lg', 'mono' => true ] ),
						$this->node( 'spacer',               [ 'height' => 24 ] ),
						$this->node( 'product-description',  [ 'source' => 'short', 'max_lines' => 6 ] ),
						$this->node( 'spacer',               [ 'height' => 24 ] ),
						$this->node( 'variant-picker',       [ 'style' => 'auto', 'show_labels' => true ] ),
						$this->node( 'spacer',               [ 'height' => 16 ] ),
						$this->node( 'add-to-cart',          [
							'label'      => 'Add to cart',
							'size'       => 'lg',
							'variant'    => 'primary',
							'full_width' => true,
							'show_qty'   => true,
						] ),
					] ),
				] ),
			] ),
			$this->section( 'md', 32, [
				$this->node( 'heading',             [ 'text' => 'Details', 'tag' => 'h2', 'size' => 'lg' ] ),
				$this->node( 'product-description', [ 'source' => 'full' ] ),
			] ),
		];
	}

	/**
	 * Default product-archive template — heading + product grid.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function productArchiveTree(): array {
		return [
			$this->section( 'lg', 48, [
				$this->node( 'heading', [
					'text'      => 'Shop',
					'tag'       => 'h1',
					'size'      => '2xl',
					'alignment' => 'left',
				] ),
				$this->node( 'spacer', [ 'height' => 24 ] ),
				$this->node( 'product-grid', [
					'sort'           => 'newest',
					'limit'          => 24,
					'columns'        => '4',
					'aspect'         => '4/5',
					'gap'            => 16,
					'show_vendor'    => true,
					'show_price'     => true,
					'show_compare'   => true,
					'show_stock'     => false,
					'show_quick_add' => false,
				] ),
			] ),
		];
	}

	/**
	 * Default cart-page template — just the cart contents element.
	 * The drawer-style cart still lives in templates/cart/*; this is
	 * the standalone /cart/ page for direct-link / email use.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function cartTree(): array {
		return [
			$this->section( 'md', 48, [
				$this->node( 'heading', [
					'text' => 'Your cart',
					'tag'  => 'h1',
					'size' => 'xl',
				] ),
				$this->node( 'spacer',         [ 'height' => 16 ] ),
				$this->node( 'cart-contents',  [] ),
			] ),
		];
	}

	/**
	 * Default checkout-page template.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function checkoutTree(): array {
		return [
			$this->section( 'lg', 32, [
				$this->node( 'checkout-form', [ 'presentation' => 'default' ] ),
			] ),
		];
	}

	/**
	 * Default order-received template.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function orderReceivedTree(): array {
		return [
			$this->section( 'md', 56, [
				$this->node( 'order-received', [] ),
			] ),
		];
	}

	/**
	 * Default site header — logo on the left, nav + cart button on the right.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function defaultHeaderTree(): array {
		return [
			$this->node( 'section', [
				'inner_max' => 'xl',
				'padding_y' => 18,
				'padding_x' => 24,
				'gap'       => 24,
				'background' => '#ffffff',
				'sticky'     => true,
			], [
				$this->node( 'section', [
					'inner_max' => 'full',
					'padding_y' => 0,
					'padding_x' => 0,
					'gap'       => 32,
				], [
					$this->node( 'site-logo',   [ 'height' => 28, 'link' => '/' ] ),
					$this->node( 'site-nav',    [
						'source'    => 'explicit',
						'links'     => "Shop|/shop/\nAbout|/p/about/\nContact|/p/contact/",
						'alignment' => 'center',
						'gap'       => 24,
					] ),
					$this->node( 'cart-button', [ 'style' => 'pill', 'label' => 'Cart', 'show_count' => true ] ),
				] ),
			] ),
		];
	}

	/**
	 * Default site footer — heading + nav strip + tiny copyright.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	private function defaultFooterTree(): array {
		$year = (int) gmdate( 'Y' );
		$name = (string) get_bloginfo( 'name' );
		return [
			$this->node( 'section', [
				'inner_max' => 'lg',
				'padding_y' => 48,
				'padding_x' => 24,
				'gap'       => 20,
				'background' => '#0a0a0a',
				'color'      => '#ffffff',
			], [
				$this->node( 'heading', [
					'text' => $name,
					'tag'  => 'div',
					'size' => 'md',
				] ),
				$this->node( 'site-nav', [
					'source'    => 'explicit',
					'links'     => "Shop|/shop/\nCart|/cart/\nPrivacy|/p/privacy/\nTerms|/p/terms/",
					'alignment' => 'start',
					'gap'       => 20,
				] ),
				$this->node( 'divider', [ 'color' => 'rgba(255,255,255,0.12)' ] ),
				$this->node( 'rich-text', [
					'html' => '<p style="opacity:.6;font-size:12px;">© ' . $year . ' ' . esc_html( $name ) . '. Built with Shop by Therum.</p>',
				] ),
			] ),
		];
	}

	// ─── Tree builders ───────────────────────────────────────────────────

	/**
	 * @param array<int, array<string,mixed>> $children
	 */
	private function section( string $inner_max, int $padding_y, array $children ): array {
		return $this->node( 'section', [
			'inner_max' => $inner_max,
			'padding_y' => $padding_y,
			'padding_x' => 24,
			'gap'       => 24,
		], $children );
	}

	/**
	 * Two-column row — sections that need side-by-side layout wrap
	 * their columns in this. Currently each column gets equal width.
	 *
	 * @param array<int, array<string,mixed>> $columns
	 */
	private function row( array $columns ): array {
		// Reuse Section as a flex container with no padding for grouped columns.
		return $this->node( 'section', [
			'inner_max' => 'full',
			'padding_y' => 0,
			'padding_x' => 0,
			'gap'       => 32,
		], $columns );
	}

	/**
	 * @param array<int, array<string,mixed>> $children
	 */
	private function column( int $span, array $children ): array {
		return $this->node( 'column', [
			'span'        => $span,
			'gap'         => 14,
			'align_items' => 'start',
		], $children );
	}

	/**
	 * @param array<string,mixed>             $settings
	 * @param array<int, array<string,mixed>> $children
	 */
	private function node( string $type, array $settings = [], array $children = [] ): array {
		return [
			'id'       => 'seed-' . substr( md5( $type . wp_json_encode( $settings ) . microtime( true ) ), 0, 10 ),
			'type'     => $type,
			'settings' => $settings,
			'children' => $children,
		];
	}
}
