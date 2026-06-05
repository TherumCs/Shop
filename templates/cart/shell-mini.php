<?php
/**
 * Shop by Therum — mini-cart shell.
 *
 * Header dropdown variant. Compact summary; click any item or footer to go
 * full drawer or /cart/ page. Hidden until cart icon hover or click.
 *
 * Variables in scope:
 *   $cart     : Shop\Models\Cart
 *   $contents : string
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-mini.php
 */

/** @var \Shop\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="shop-cart-mini"
	data-shop-cart-shell="mini"
	data-shop-cart-state="closed"
>
	<div class="shop-cart-mini__panel" data-shop-cart-mount>
		<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
	</div>
</div>
