<?php
/**
 * Shop by Therum — admin Settings page.
 *
 * Native WP admin (no React/no app). Renders the option fields that
 * `register_setting()` already declared in shop.php. The form posts to
 * options.php — WP's built-in handler — so we get sanitization +
 * nonce verification for free.
 *
 * Sections:
 *   Cart experience    — presentation + button position
 *   Checkout           — presentation
 *   Catalog source     — native vs Woo
 */

namespace Shop\Admin;

use Shop\Services\CartRenderer;
use Shop\Services\CheckoutRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SettingsPage {

	public function render(): void {
		$cart_present     = (string) get_option( 'shop_cart_presentation',     CartRenderer::MODE_STUDIO );
		$checkout_present = (string) get_option( 'shop_checkout_presentation', CheckoutRenderer::MODE_CLASSIC );
		$button_pos       = (string) get_option( 'shop_cart_button_position',  'bottom-right' );
		$product_source   = (string) get_option( 'shop_product_source',        'native' );
		$woo_detected     = function_exists( 'wc_get_product' );

		?>
		<div class="wrap shop-admin">

			<h1 class="shop-admin__title">
				<span class="shop-admin__mark">T</span>
				<?php esc_html_e( 'Shop by Therum', 'shop' ); ?>
				<span class="shop-admin__version">v<?php echo esc_html( SHOP_VERSION ); ?></span>
			</h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="shop-admin__form">
				<?php settings_fields( 'shop_appearance' ); ?>

				<div class="shop-admin__section">
					<header>
						<h2><?php esc_html_e( 'Cart experience', 'shop' ); ?></h2>
						<p><?php esc_html_e( 'How the cart appears to customers across your store.', 'shop' ); ?></p>
					</header>

					<div class="shop-admin__row">
						<label for="shop_cart_presentation"><?php esc_html_e( 'Presentation', 'shop' ); ?></label>
						<div>
							<select name="shop_cart_presentation" id="shop_cart_presentation">
								<?php foreach ( $this->cartPresentations() as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cart_present, $key ); ?>>
										<?php echo esc_html( $info['label'] ); ?> — <?php echo esc_html( $info['desc'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Studio = drawer with thumbnails and in-drawer checkout. Counter = full /cart/ page. Vitrine = centered modal. Each carries through to the matching checkout style.', 'shop' ); ?>
							</p>
						</div>
					</div>

					<div class="shop-admin__row">
						<label for="shop_cart_button_position"><?php esc_html_e( 'Floating button position', 'shop' ); ?></label>
						<div>
							<select name="shop_cart_button_position" id="shop_cart_button_position">
								<?php foreach ( [
									'bottom-right' => __( 'Bottom right', 'shop' ),
									'bottom-left'  => __( 'Bottom left',  'shop' ),
									'top-right'    => __( 'Top right',    'shop' ),
									'top-left'     => __( 'Top left',     'shop' ),
								] as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $button_pos, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Where the persistent cart button anchors on the page. Only used when presentation includes a floating button (Studio, Vitrine).', 'shop' ); ?>
							</p>
						</div>
					</div>
				</div>

				<div class="shop-admin__section">
					<header>
						<h2><?php esc_html_e( 'Checkout', 'shop' ); ?></h2>
						<p><?php esc_html_e( 'Page-level checkout pattern for cart presentations that hand off to a separate /checkout/ page.', 'shop' ); ?></p>
					</header>

					<div class="shop-admin__row">
						<label for="shop_checkout_presentation"><?php esc_html_e( 'Presentation', 'shop' ); ?></label>
						<div>
							<select name="shop_checkout_presentation" id="shop_checkout_presentation">
								<?php foreach ( $this->checkoutPresentations() as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $checkout_present, $key ); ?>>
										<?php echo esc_html( $info['label'] ); ?> — <?php echo esc_html( $info['desc'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Studio cart owns its in-drawer checkout — this setting only affects Counter, Vitrine, Mini.', 'shop' ); ?>
							</p>
						</div>
					</div>
				</div>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="options.php" class="shop-admin__form shop-admin__form--secondary">
				<?php settings_fields( 'shop_catalog' ); ?>

				<div class="shop-admin__section">
					<header>
						<h2><?php esc_html_e( 'Catalog source', 'shop' ); ?></h2>
						<p><?php esc_html_e( 'Where Shop reads product data from.', 'shop' ); ?></p>
					</header>

					<div class="shop-admin__row">
						<label for="shop_product_source"><?php esc_html_e( 'Source', 'shop' ); ?></label>
						<div>
							<select name="shop_product_source" id="shop_product_source">
								<option value="native" <?php selected( $product_source, 'native' ); ?>>
									<?php esc_html_e( 'Native — products live in Shop\'s SQLite', 'shop' ); ?>
								</option>
								<option value="woo" <?php selected( $product_source, 'woo' ); ?> <?php disabled( ! $woo_detected ); ?>>
									<?php esc_html_e( 'WooCommerce — read existing Woo products in place', 'shop' ); ?>
									<?php if ( ! $woo_detected ) echo ' ' . esc_html__( '(install Woo first)', 'shop' ); ?>
								</option>
							</select>
							<p class="description">
								<?php if ( $woo_detected ) : ?>
									<?php esc_html_e( 'In Woo mode, Shop reads from wp_posts via wc_get_product() and mirrors paid orders back to WC_Orders so POD plugins (Printful, Printify, PodPartner, TapStitch, PodPluser) fulfill normally. No migration, no data copy.', 'shop' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'WooCommerce not detected. Native is the only option until Woo is installed.', 'shop' ); ?>
								<?php endif; ?>
							</p>
						</div>
					</div>
				</div>

				<?php submit_button( __( 'Save catalog source', 'shop' ) ); ?>
			</form>

		</div>
		<?php
	}

	/**
	 * @return array<string, array{label:string, desc:string}>
	 */
	private function cartPresentations(): array {
		return [
			CartRenderer::MODE_STUDIO  => [ 'label' => 'Studio',  'desc' => 'Drawer + in-drawer checkout' ],
			CartRenderer::MODE_COUNTER => [ 'label' => 'Counter', 'desc' => 'Full page, dark footer' ],
			CartRenderer::MODE_VITRINE => [ 'label' => 'Vitrine', 'desc' => 'Centered modal' ],
			CartRenderer::MODE_MINI    => [ 'label' => 'Mini',    'desc' => 'Header dropdown' ],
			CartRenderer::MODE_NONE    => [ 'label' => 'None',    'desc' => 'Skip cart, go straight to checkout' ],
		];
	}

	/**
	 * @return array<string, array{label:string, desc:string}>
	 */
	private function checkoutPresentations(): array {
		return [
			CheckoutRenderer::MODE_CLASSIC  => [ 'label' => 'Classic',  'desc' => 'Sections stacked + sticky summary' ],
			CheckoutRenderer::MODE_THERUM   => [ 'label' => 'Therum',   'desc' => 'Editable summary + payment form' ],
			CheckoutRenderer::MODE_SEQUENCE => [ 'label' => 'Sequence', 'desc' => 'Stepped Info → Payment → Done' ],
		];
	}
}
