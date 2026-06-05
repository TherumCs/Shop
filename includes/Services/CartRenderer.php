<?php
/**
 * Shop by Therum — CartRenderer.
 *
 * Server-side rendering for cart surfaces. Picks the shell from settings,
 * delegates to the inner template (cart/contents.php), returns the HTML
 * string. Used by:
 *
 *   - [shop_cart] shortcode             — chooses page or current default
 *   - REST CartController responses     — always renders contents.php so
 *                                          clients can morph the DOM
 *   - Footer floating-button injector   — emits the trigger + drawer
 *
 * Templates can be overridden by a theme — copy any file from
 * <plugin>/templates/cart/ to <theme>/shop/cart/ and yours wins.
 */

namespace Shop\Services;

use Shop\Models\Cart;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartRenderer {

	// Legacy mode names (kept for back-compat)
	public const MODE_DRAWER   = 'drawer';
	public const MODE_PAGE     = 'page';
	public const MODE_MINI     = 'mini';
	public const MODE_OVERLAY  = 'overlay';
	public const MODE_NONE     = 'none';

	// Named presentation patterns (v0.3.0+)
	public const MODE_STUDIO   = 'studio';     // drawer + in-drawer checkout stages
	public const MODE_COUNTER  = 'counter';    // full /cart/ page with dark footer panel
	public const MODE_ATELIER  = 'atelier';    // split: cart left, payment right
	public const MODE_VITRINE  = 'vitrine';    // modal overlay with save-for-later

	/** Every supported presentation mode. */
	public const ALL_MODES = [
		self::MODE_STUDIO, self::MODE_COUNTER, self::MODE_ATELIER, self::MODE_VITRINE,
		self::MODE_DRAWER, self::MODE_PAGE, self::MODE_MINI, self::MODE_OVERLAY, self::MODE_NONE,
	];

	public function __construct(
		private readonly CartService $cart,
	) {}

	/**
	 * Render a specific shell around the contents.
	 */
	public function shell( string $mode, Cart $cart ): string {
		$shell = match ( $mode ) {
			// Named presentation patterns (v0.3.0+)
			self::MODE_STUDIO  => 'cart/studio/shell.php',
			self::MODE_COUNTER => 'cart/counter/shell.php',
			self::MODE_ATELIER => 'cart/atelier/shell.php',
			self::MODE_VITRINE => 'cart/vitrine/shell.php',
			// Legacy
			self::MODE_DRAWER  => 'cart/shell-drawer.php',
			self::MODE_PAGE    => 'cart/shell-page.php',
			self::MODE_MINI    => 'cart/shell-mini.php',
			self::MODE_OVERLAY => 'cart/shell-overlay.php',
			default            => 'cart/studio/shell.php',
		};
		return $this->template( $shell, [
			'cart'     => $cart,
			'contents' => $this->contents( $cart, $mode ),
		] );
	}

	/**
	 * Render inner cart contents in the variant matching the chosen mode.
	 * Studio uses a thumbnail+meta layout; Counter uses a table; Vitrine uses
	 * a large-thumb layout with save-for-later; Atelier shares Studio's
	 * line markup. Mode-agnostic callers pass null and get the configured default.
	 */
	public function contents( Cart $cart, ?string $mode = null ): string {
		$mode = $mode ?? $this->defaultMode();
		$tpl = match ( $mode ) {
			self::MODE_STUDIO, self::MODE_ATELIER => 'cart/studio/contents.php',
			self::MODE_COUNTER                    => 'cart/counter/contents.php',
			self::MODE_VITRINE                    => 'cart/vitrine/contents.php',
			default                               => 'cart/studio/contents.php',
		};
		return $this->template( $tpl, [ 'cart' => $cart ] );
	}

	/**
	 * Render the floating button trigger. Includes the drawer markup so the
	 * first click is zero-latency.
	 */
	public function floatingButton( Cart $cart ): string {
		return $this->template( 'cart/floating-button.php', [
			'cart'     => $cart,
			'contents' => $this->contents( $cart ),
		] );
	}

	/**
	 * Site-configured default presentation mode.
	 *
	 * Option key: `shop_cart_presentation` (new) — falls back to legacy
	 * `shop_cart_default_mode` for back-compat with 0.2.x installs.
	 *
	 * Filterable via `shop_cart_default_mode`.
	 */
	public function defaultMode(): string {
		$mode = (string) get_option(
			'shop_cart_presentation',
			(string) get_option( 'shop_cart_default_mode', self::MODE_STUDIO )
		);
		$mode = (string) apply_filters( 'shop_cart_default_mode', $mode );

		return in_array( $mode, self::ALL_MODES, true ) ? $mode : self::MODE_STUDIO;
	}

	/**
	 * Theme-overridable template loader.
	 *
	 * Lookup order:
	 *   1. <stylesheet>/shop/<relative>
	 *   2. <template>/shop/<relative>
	 *   3. <plugin>/templates/<relative>
	 *
	 * @param array<string,mixed> $vars
	 */
	private function template( string $relative, array $vars = [] ): string {
		$candidates = [
			get_stylesheet_directory() . '/shop/' . $relative,
			get_template_directory()   . '/shop/' . $relative,
			SHOP_DIR . 'templates/' . $relative,
		];

		$path = '';
		foreach ( $candidates as $c ) {
			if ( is_file( $c ) ) { $path = $c; break; }
		}
		if ( $path === '' ) return '';

		// Extract vars into scope for the template, render to a string.
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}
}
