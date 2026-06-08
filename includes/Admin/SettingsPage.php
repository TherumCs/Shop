<?php
/**
 * Counter by Therum — admin Settings page.
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

namespace Counter\Admin;

use Counter\Services\CartRenderer;
use Counter\Services\CheckoutRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SettingsPage {

	public function render(): void {
		$cart_present     = (string) get_option( 'counter_cart_presentation',     CartRenderer::MODE_STUDIO );
		$checkout_present = (string) get_option( 'counter_checkout_presentation', CheckoutRenderer::MODE_CLASSIC );
		$button_pos       = (string) get_option( 'counter_cart_button_position',  'bottom-right' );
		$product_source   = (string) get_option( 'counter_product_source',        'native' );
		$woo_detected     = function_exists( 'wc_get_product' );

		// Register Stripe BYO-keys option group on first paint so options.php
		// will accept the form post. Idempotent — register_setting() dedupes.
		register_setting( 'counter_stripe', 'counter_stripe_publishable_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'counter_stripe', 'counter_stripe_secret_key',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
		$stripe_pub = (string) get_option( 'counter_stripe_publishable_key', '' );
		$stripe_sec = (string) get_option( 'counter_stripe_secret_key', '' );

		// Provider key groups — register once per request, idempotent.
		foreach ( [
			'counter_sezzle'  => [ 'counter_sezzle_public_key', 'counter_sezzle_private_key', 'counter_sezzle_environment' ],
			'counter_zip'     => [ 'counter_zip_merchant_id', 'counter_zip_api_key', 'counter_zip_environment' ],
			'counter_anypay'  => [ 'counter_anypay_api_key', 'counter_anypay_environment' ],
			'counter_zelle'   => [ 'counter_zelle_handle', 'counter_zelle_display_name' ],
			'counter_shoppay' => [ 'counter_shop_pay_mode', 'counter_shop_pay_shopify_store', 'counter_shop_pay_shopify_storefront_token' ],
		] as $group => $keys ) {
			foreach ( $keys as $k ) {
				register_setting( $group, $k, [ 'sanitize_callback' => 'sanitize_text_field' ] );
			}
		}

		$sezzle_pub  = (string) get_option( 'counter_sezzle_public_key', '' );
		$sezzle_prv  = (string) get_option( 'counter_sezzle_private_key', '' );
		$sezzle_env  = (string) get_option( 'counter_sezzle_environment', 'live' );
		$zip_mid     = (string) get_option( 'counter_zip_merchant_id', '' );
		$zip_key     = (string) get_option( 'counter_zip_api_key', '' );
		$zip_env     = (string) get_option( 'counter_zip_environment', 'live' );
		$anypay_key  = (string) get_option( 'counter_anypay_api_key', '' );
		$anypay_env  = (string) get_option( 'counter_anypay_environment', 'live' );
		$zelle_handle= (string) get_option( 'counter_zelle_handle', '' );
		$zelle_name  = (string) get_option( 'counter_zelle_display_name', '' );
		$shoppay_mode= (string) get_option( 'counter_shop_pay_mode', 'stripe_link' );
		$shoppay_store= (string) get_option( 'counter_shop_pay_shopify_store', '' );
		$shoppay_tok = (string) get_option( 'counter_shop_pay_shopify_storefront_token', '' );

		?>
		<div class="wrap counter-admin">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Settings', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>
			<?php SectionTabs::render( 'counter-settings' ); ?>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="counter-admin__form">
				<?php settings_fields( 'counter_appearance' ); ?>

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Cart experience', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'How the cart appears to customers across your store.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_cart_presentation"><?php esc_html_e( 'Presentation', 'counter' ); ?></label>
						<div>
							<select name="counter_cart_presentation" id="counter_cart_presentation">
								<?php foreach ( $this->cartPresentations() as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cart_present, $key ); ?>>
										<?php echo esc_html( $info['label'] ); ?> — <?php echo esc_html( $info['desc'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Studio = drawer with thumbnails and in-drawer checkout. Counter = full /cart/ page. Vitrine = centered modal. Each carries through to the matching checkout style.', 'counter' ); ?>
							</p>
						</div>
					</div>

					<div class="counter-admin__row">
						<label for="counter_cart_button_position"><?php esc_html_e( 'Floating button position', 'counter' ); ?></label>
						<div>
							<select name="counter_cart_button_position" id="counter_cart_button_position">
								<?php foreach ( [
									'bottom-right' => __( 'Bottom right', 'counter' ),
									'bottom-left'  => __( 'Bottom left', 'counter' ),
									'top-right'    => __( 'Top right', 'counter' ),
									'top-left'     => __( 'Top left', 'counter' ),
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

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Checkout', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Page-level checkout pattern for cart presentations that hand off to a separate /checkout/ page.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_checkout_presentation"><?php esc_html_e( 'Presentation', 'counter' ); ?></label>
						<div>
							<select name="counter_checkout_presentation" id="counter_checkout_presentation">
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

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_catalog' ); ?>

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Catalog source', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Where Shop reads product data from.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_product_source"><?php esc_html_e( 'Source', 'counter' ); ?></label>
						<div>
							<select name="counter_product_source" id="counter_product_source">
								<option value="native" <?php selected( $product_source, 'native' ); ?>>
									<?php esc_html_e( 'Native — products live in Counter\'s SQLite', 'counter' ); ?>
								</option>
								<option value="woo" <?php selected( $product_source, 'woo' ); ?> <?php disabled( ! $woo_detected ); ?>>
									<?php esc_html_e( 'WooCommerce — read existing Woo products in place', 'counter' ); ?>
									<?php if ( ! $woo_detected ) echo ' ' . esc_html__( '(install Woo first)', 'counter' ); ?>
								</option>
							</select>
							<p class="description">
								<?php if ( $woo_detected ) : ?>
									<?php esc_html_e( 'In Woo mode, Shop reads from wp_posts via wc_get_product() and mirrors paid orders back to WC_Orders so POD plugins (Printful, Printify, PodPartner, TapStitch, PodPluser) fulfill normally. No migration, no data copy.', 'shop' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'WooCommerce not detected. Native is the only option until Woo is installed.', 'counter' ); ?>
								<?php endif; ?>
							</p>
						</div>
					</div>
				</div>

				<?php submit_button( __( 'Save catalog source', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_stripe' ); ?>

				<div class="counter-admin__section" id="provider-stripe">
					<header>
						<h2><?php esc_html_e( 'Stripe (BYO keys)', 'counter' ); ?></h2>
						<p><?php esc_html_e( "Paste your Stripe API keys to enable the Stripe provider — checkout, refunds, balance, and instant payouts to your Square Debit Mastercard.", 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_stripe_publishable_key"><?php esc_html_e( 'Publishable key', 'counter' ); ?></label>
						<div>
							<input type="text" name="counter_stripe_publishable_key" id="counter_stripe_publishable_key"
								value="<?php echo esc_attr( $stripe_pub ); ?>" placeholder="pk_live_…" autocomplete="off" spellcheck="false">
							<p class="description"><?php esc_html_e( 'Used by the Payouts tab to tokenize your debit card client-side.', 'counter' ); ?></p>
						</div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_stripe_secret_key"><?php esc_html_e( 'Secret key', 'counter' ); ?></label>
						<div>
							<input type="password" name="counter_stripe_secret_key" id="counter_stripe_secret_key"
								value="<?php echo esc_attr( $stripe_sec ); ?>" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
							<p class="description"><?php esc_html_e( 'Stored as a WordPress option. Treat like a password — anyone with WP admin access can read it.', 'counter' ); ?></p>
						</div>
					</div>
				</div>

				<?php submit_button( __( 'Save Stripe keys', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_sezzle' ); ?>
				<div class="counter-admin__section" id="provider-sezzle">
					<header>
						<h2><?php esc_html_e( 'Sezzle', 'counter' ); ?></h2>
						<p><?php esc_html_e( "4-installment BNPL. Settles to your bank on Sezzle's ACH cycle.", 'counter' ); ?></p>
					</header>
					<div class="counter-admin__row">
						<label for="counter_sezzle_public_key"><?php esc_html_e( 'Public key', 'counter' ); ?></label>
						<div><input type="text" name="counter_sezzle_public_key" id="counter_sezzle_public_key" value="<?php echo esc_attr( $sezzle_pub ); ?>" autocomplete="off"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_sezzle_private_key"><?php esc_html_e( 'Private key', 'counter' ); ?></label>
						<div><input type="password" name="counter_sezzle_private_key" id="counter_sezzle_private_key" value="<?php echo esc_attr( $sezzle_prv ); ?>" autocomplete="off"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_sezzle_environment"><?php esc_html_e( 'Environment', 'counter' ); ?></label>
						<div>
							<select name="counter_sezzle_environment" id="counter_sezzle_environment">
								<option value="live"    <?php selected( $sezzle_env, 'live' ); ?>>Live</option>
								<option value="sandbox" <?php selected( $sezzle_env, 'sandbox' ); ?>>Sandbox</option>
							</select>
						</div>
					</div>
				</div>
				<?php submit_button( __( 'Save Sezzle', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_zip' ); ?>
				<div class="counter-admin__section" id="provider-zip">
					<header>
						<h2><?php esc_html_e( 'Zip', 'counter' ); ?></h2>
						<p><?php esc_html_e( '4 interest-free installments. Direct integration — Zip pays out to your bank on T+2.', 'counter' ); ?></p>
					</header>
					<div class="counter-admin__row">
						<label for="counter_zip_merchant_id"><?php esc_html_e( 'Merchant ID', 'counter' ); ?></label>
						<div><input type="text" name="counter_zip_merchant_id" id="counter_zip_merchant_id" value="<?php echo esc_attr( $zip_mid ); ?>" autocomplete="off"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_zip_api_key"><?php esc_html_e( 'API key', 'counter' ); ?></label>
						<div><input type="password" name="counter_zip_api_key" id="counter_zip_api_key" value="<?php echo esc_attr( $zip_key ); ?>" autocomplete="off"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_zip_environment"><?php esc_html_e( 'Environment', 'counter' ); ?></label>
						<div>
							<select name="counter_zip_environment" id="counter_zip_environment">
								<option value="live"    <?php selected( $zip_env, 'live' ); ?>>Live</option>
								<option value="sandbox" <?php selected( $zip_env, 'sandbox' ); ?>>Sandbox</option>
							</select>
						</div>
					</div>
				</div>
				<?php submit_button( __( 'Save Zip', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_anypay' ); ?>
				<div class="counter-admin__section" id="provider-crypto">
					<header>
						<h2><?php esc_html_e( 'Crypto (AnyPay)', 'counter' ); ?></h2>
						<p><?php esc_html_e( '50+ coins. AnyPay generates QR invoices; settlement (fiat or hold) is set in your AnyPay dashboard.', 'counter' ); ?></p>
					</header>
					<div class="counter-admin__row">
						<label for="counter_anypay_api_key"><?php esc_html_e( 'API key', 'counter' ); ?></label>
						<div><input type="password" name="counter_anypay_api_key" id="counter_anypay_api_key" value="<?php echo esc_attr( $anypay_key ); ?>" autocomplete="off"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_anypay_environment"><?php esc_html_e( 'Environment', 'counter' ); ?></label>
						<div>
							<select name="counter_anypay_environment" id="counter_anypay_environment">
								<option value="live"    <?php selected( $anypay_env, 'live' ); ?>>Live</option>
								<option value="sandbox" <?php selected( $anypay_env, 'sandbox' ); ?>>Sandbox</option>
							</select>
						</div>
					</div>
				</div>
				<?php submit_button( __( 'Save AnyPay', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_zelle' ); ?>
				<div class="counter-admin__section" id="provider-zelle">
					<header>
						<h2><?php esc_html_e( 'Zelle', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Bank-to-bank P2P. No API — customers send funds; you confirm receipt in Counter Orders.', 'counter' ); ?></p>
					</header>
					<div class="counter-admin__row">
						<label for="counter_zelle_handle"><?php esc_html_e( 'Zelle handle', 'counter' ); ?></label>
						<div>
							<input type="text" name="counter_zelle_handle" id="counter_zelle_handle" value="<?php echo esc_attr( $zelle_handle ); ?>" placeholder="you@business.com or +15551234567">
							<p class="description"><?php esc_html_e( 'Email or phone tied to your business bank\'s Zelle profile.', 'counter' ); ?></p>
						</div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_zelle_display_name"><?php esc_html_e( 'Display name', 'counter' ); ?></label>
						<div>
							<input type="text" name="counter_zelle_display_name" id="counter_zelle_display_name" value="<?php echo esc_attr( $zelle_name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Shown to the customer at checkout. Defaults to your store name.', 'counter' ); ?></p>
						</div>
					</div>
				</div>
				<?php submit_button( __( 'Save Zelle', 'counter' ) ); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_shoppay' ); ?>
				<div class="counter-admin__section" id="provider-shop_pay">
					<header>
						<h2><?php esc_html_e( 'Shop Pay', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Shopify owns Shop Pay. Pick how Counter renders the Shop Pay button at checkout.', 'counter' ); ?></p>
					</header>
					<div class="counter-admin__row">
						<label for="counter_shop_pay_mode"><?php esc_html_e( 'Mode', 'counter' ); ?></label>
						<div>
							<select name="counter_shop_pay_mode" id="counter_shop_pay_mode">
								<option value="stripe_link" <?php selected( $shoppay_mode, 'stripe_link' ); ?>>Stripe Link (recommended for non-Shopify stores)</option>
								<option value="shopify"     <?php selected( $shoppay_mode, 'shopify' ); ?>>Shopify deep-link (requires a Shopify storefront)</option>
							</select>
							<p class="description"><?php esc_html_e( 'Stripe Link gives one-tap saved-card checkout for non-Shopify stores. Shopify mode requires an existing Shopify storefront the customer is redirected into.', 'counter' ); ?></p>
						</div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_shop_pay_shopify_store"><?php esc_html_e( 'Shopify store domain', 'counter' ); ?></label>
						<div><input type="text" name="counter_shop_pay_shopify_store" id="counter_shop_pay_shopify_store" value="<?php echo esc_attr( $shoppay_store ); ?>" placeholder="your-store.myshopify.com"></div>
					</div>
					<div class="counter-admin__row">
						<label for="counter_shop_pay_shopify_storefront_token"><?php esc_html_e( 'Shopify Admin API token', 'counter' ); ?></label>
						<div><input type="password" name="counter_shop_pay_shopify_storefront_token" id="counter_shop_pay_shopify_storefront_token" value="<?php echo esc_attr( $shoppay_tok ); ?>" autocomplete="off"></div>
					</div>
				</div>
				<?php submit_button( __( 'Save Shop Pay', 'counter' ) ); ?>
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
