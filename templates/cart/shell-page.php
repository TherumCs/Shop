<?php
/**
 * Shop by Therum — page shell.
 *
 * Standalone /cart/ page contents. No drawer, no backdrop — just the cart
 * inside its container, ready for a theme/Bricks layout to wrap it.
 *
 * Variables in scope:
 *   $cart     : Shop\Models\Cart
 *   $contents : string  (already-rendered contents.php HTML)
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-page.php
 */

/** @var \Shop\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="shop-cart-page" data-shop-cart-shell="page" data-shop-cart-mount>
	<header class="shop-cart-page__header">
		<h1 class="shop-cart-page__title">
			<?php esc_html_e( 'Your cart', 'shop' ); ?>
		</h1>
	</header>

	<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
</section>
