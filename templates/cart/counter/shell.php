<?php
/**
 * Counter cart — page shell (stub). Full port from preview/counter.html
 * lands next chunk.
 *
 * @var \Shop\Models\Cart $cart
 * @var string $contents
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="shop-counter" data-shop-cart-shell="page" data-shop-cart-mount>
	<header class="shop-counter__header">
		<h1 class="shop-counter__title"><?php esc_html_e( 'Shopping Cart', 'shop' ); ?></h1>
	</header>
	<?php echo $contents; // phpcs:ignore ?>
</section>
