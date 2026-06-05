<?php
/**
 * Studio cart — drawer shell.
 *
 * Slide-in drawer. Houses a single panel that can transition between cart
 * view and in-drawer checkout stages (added in v0.4 via `data-shop-stage`).
 *
 * Variables in scope:
 *   $cart     : Shop\Models\Cart
 *   $contents : string  (pre-rendered contents.php HTML)
 *
 * Override: copy to <theme>/shop/cart/studio/shell.php
 */

/** @var \Shop\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="shop-studio-drawer"
	data-shop-cart-shell="drawer"
	data-shop-cart-state="closed"
	data-shop-stage="cart"
	role="dialog"
	aria-modal="true"
	aria-labelledby="shop-studio-drawer-title"
	aria-hidden="true"
>
	<div class="shop-studio-drawer__backdrop" data-shop-cart-close></div>

	<aside class="shop-studio-drawer__panel" role="document">
		<header class="shop-studio-drawer__header">
			<h2 id="shop-studio-drawer-title" class="shop-studio-drawer__title">
				<?php esc_html_e( 'Your Cart', 'shop' ); ?>
				<span class="shop-studio-drawer__count" data-shop-cart-count-label>
					<?php echo esc_html( (string) $cart->itemCount() ); ?>
				</span>
			</h2>
			<button
				type="button"
				class="shop-studio-drawer__close"
				data-shop-cart-close
				aria-label="<?php esc_attr_e( 'Close cart', 'shop' ); ?>"
			>&times;</button>
		</header>

		<div class="shop-studio-drawer__body" data-shop-cart-mount>
			<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
		</div>
	</aside>
</div>
