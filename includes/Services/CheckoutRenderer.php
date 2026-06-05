<?php
/**
 * Shop by Therum — CheckoutRenderer.
 *
 * Server-side renderer for checkout pages. Mirrors CartRenderer. Three
 * presentation patterns (settle on one per store via settings, or per-page
 * via filter):
 *
 *   Classic   — form left (sections stacked), sticky summary right.
 *               The OG Therum checkout silhouette.
 *   Therum    — editable summary left, payment form right. Stripe-style.
 *   Sequence  — stepped progress (Info → Payment → Done) with persistent
 *               summary.
 *
 * Templates are theme-overridable: copy any file from
 *   <plugin>/templates/checkout/<mode>/
 * to
 *   <theme>/shop/checkout/<mode>/
 * and yours wins.
 *
 * Resolution order for a checkout page:
 *   1. Per-request filter `shop_checkout_presentation`
 *   2. `get_option('shop_checkout_presentation')`
 *   3. Default: 'classic'
 */

namespace Shop\Services;

use Shop\Models\Cart;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CheckoutRenderer {

	public const MODE_CLASSIC  = 'classic';
	public const MODE_THERUM   = 'therum';
	public const MODE_SEQUENCE = 'sequence';
	public const MODE_STUDIO   = 'studio';

	public const ALL_MODES = [
		self::MODE_CLASSIC, self::MODE_THERUM, self::MODE_SEQUENCE, self::MODE_STUDIO,
	];

	public function __construct(
		private readonly CartService $cart,
	) {}

	/**
	 * Render the chosen checkout page for the customer's current cart.
	 */
	public function render( Cart $cart, ?string $mode = null ): string {
		$mode = $mode ?? $this->defaultMode();
		$tpl  = match ( $mode ) {
			self::MODE_THERUM   => 'checkout/therum/index.php',
			self::MODE_SEQUENCE => 'checkout/sequence/index.php',
			self::MODE_STUDIO   => 'checkout/studio/index.php',
			default             => 'checkout/classic/index.php',
		};
		// Studio pattern needs REST + totals threaded into scope so its
		// inline JS can hit /studio-pay/methods on boot.
		$extra = $mode === self::MODE_STUDIO ? [
			'totals'   => [
				'subtotal' => $cart->subtotal->amount ?? 0,
				'shipping' => $cart->shippingTotal->amount ?? 0,
				'tax'      => $cart->taxTotal->amount ?? 0,
				'grand'    => $cart->grandTotal->amount ?? 0,
			],
			'rest_url' => rest_url(),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		] : [];
		return $this->template( $tpl, array_merge( [ 'cart' => $cart, 'mode' => $mode ], $extra ) );
	}

	public function defaultMode(): string {
		$mode = (string) get_option( 'shop_checkout_presentation', self::MODE_CLASSIC );
		$mode = (string) apply_filters( 'shop_checkout_presentation', $mode );
		return in_array( $mode, self::ALL_MODES, true ) ? $mode : self::MODE_CLASSIC;
	}

	/**
	 * Theme-overridable template loader.
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

		extract( $vars, EXTR_SKIP );
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}
}
