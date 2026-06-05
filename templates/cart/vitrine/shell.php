<?php
/**
 * Vitrine cart — modal shell (stub). Full port from preview/vitrine.html
 * lands next chunk.
 *
 * @var \Shop\Models\Cart $cart
 * @var string $contents
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="shop-vitrine" data-shop-cart-shell="modal" data-shop-cart-state="closed" role="dialog" aria-modal="true" aria-hidden="true">
	<div class="shop-vitrine__backdrop" data-shop-cart-close></div>
	<div class="shop-vitrine__dialog" data-shop-cart-mount>
		<?php echo $contents; // phpcs:ignore ?>
	</div>
</div>
