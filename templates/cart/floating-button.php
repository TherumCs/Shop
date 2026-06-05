<?php
/**
 * Shop by Therum — floating button + paired drawer.
 *
 * Always-on cart trigger, positioned fixed on the viewport. Includes the
 * drawer markup inline so the first click is zero-latency — no fetch
 * required to open.
 *
 * Variables in scope:
 *   $cart     : Shop\Models\Cart
 *   $contents : string
 *
 * Override:
 *   Copy to <theme>/shop/cart/floating-button.php
 *
 * Position is configurable via the `shop_cart_button_position` option:
 *   bottom-right (default), bottom-left, top-right, top-left
 */

/** @var \Shop\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;

$position = (string) get_option( 'shop_cart_button_position', 'bottom-right' );
$position = in_array( $position, [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ], true )
	? $position
	: 'bottom-right';
?>
<button
	type="button"
	class="shop-cart-fab shop-cart-fab--<?php echo esc_attr( $position ); ?>"
	data-shop-cart-open
	data-shop-cart-target="drawer"
	aria-label="<?php esc_attr_e( 'Open cart', 'shop' ); ?>"
	<?php if ( $cart->isEmpty() ) : ?>data-shop-cart-empty<?php endif; ?>
>
	<svg class="shop-cart-fab__icon" viewBox="0 0 24 24" aria-hidden="true">
		<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
			d="M3 4h2l2.5 12h11l2-8H6.5"/>
		<circle cx="9" cy="20" r="1.5" fill="currentColor"/>
		<circle cx="17" cy="20" r="1.5" fill="currentColor"/>
	</svg>
	<span
		class="shop-cart-fab__count"
		data-shop-cart-count-label
		<?php if ( $cart->isEmpty() ) : ?>hidden<?php endif; ?>
	><?php echo esc_html( (string) $cart->itemCount() ); ?></span>
</button>

<?php
// Paired drawer — preloaded so the first open is instant.
echo \Shop\shop( \Shop\Services\CartRenderer::class )
	->shell( \Shop\Services\CartRenderer::MODE_DRAWER, $cart ); // phpcs:ignore
