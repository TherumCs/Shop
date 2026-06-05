<?php
/**
 * Shop by Therum — drawer shell.
 *
 * Slide-in from the right. Includes a backdrop and a close button. Hidden
 * by default; cart.js toggles `is-open`. Focus-trapped while open.
 *
 * Variables in scope:
 *   $cart     : Shop\Models\Cart
 *   $contents : string  (already-rendered contents.php HTML)
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-drawer.php
 */

/** @var \Shop\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="shop-cart-drawer"
	data-shop-cart-shell="drawer"
	data-shop-cart-state="closed"
	role="dialog"
	aria-modal="true"
	aria-labelledby="shop-cart-drawer-title"
	aria-hidden="true"
>
	<div class="shop-cart-drawer__backdrop" data-shop-cart-close></div>

	<aside class="shop-cart-drawer__panel" role="document">
		<header class="shop-cart-drawer__header">
			<h2 id="shop-cart-drawer-title" class="shop-cart-drawer__title">
				<?php esc_html_e( 'Cart', 'shop' ); ?>
				<span class="shop-cart-drawer__count" data-shop-cart-count-label>
					<?php echo esc_html( (string) $cart->itemCount() ); ?>
				</span>
			</h2>
			<button
				type="button"
				class="shop-cart-drawer__close"
				data-shop-cart-close
				aria-label="<?php esc_attr_e( 'Close cart', 'shop' ); ?>"
			>×</button>
		</header>

		<div class="shop-cart-drawer__body" data-shop-cart-mount>
			<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
		</div>
	</aside>
</div>
