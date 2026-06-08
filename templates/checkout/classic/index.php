<?php
/**
 * Classic checkout — sections-stacked form left, sticky summary right.
 *
 * The OG Therum checkout silhouette. Six payment rails. Pay button label
 * adapts per method. JS interaction: assets/checkout/classic.js (next chunk).
 *
 * Variables in scope:
 *   $cart : Counter\Models\Cart
 *   $mode : string (always 'classic' here)
 *
 * Override: copy to <theme>/shop/checkout/classic/index.php
 */

/** @var \Counter\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;

$products = \Counter\Container::instance()->get( \Counter\Repositories\ProductRepository::class );
$bnpl_quarter = $cart->grandTotal->dividedBy( 4 );
?>
<div class="counter-classic" data-counter-checkout="classic" data-counter-cart-token="<?php echo esc_attr( $cart->token ); ?>">

	<div class="counter-classic__brand">
		<div class="counter-classic__brand-mark">T</div>
		<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
		<small><?php esc_html_e( '· Checkout', 'counter' ); ?></small>
	</div>

	<div class="counter-classic__layout">

		<div class="counter-classic__form">

			<!-- ─── Section 1 — info ───────────────────────────── -->
			<section class="counter-classic__section is-focus" data-section="info">
				<header class="counter-classic__section-head">
					<h2><span class="counter-classic__section-num">1</span> <?php esc_html_e( 'Your info', 'counter' ); ?></h2>
					<span class="counter-classic__section-status"><?php esc_html_e( 'Ready', 'counter' ); ?></span>
				</header>

				<div class="counter-classic__field">
					<label><?php esc_html_e( 'Email', 'counter' ); ?></label>
					<input class="counter-classic__input" type="email" name="email" placeholder="you@example.com"
						value="<?php echo esc_attr( (string) ( $cart->email ?? '' ) ); ?>" autofocus />
				</div>

				<div class="counter-classic__field">
					<label><?php esc_html_e( 'Ship to', 'counter' ); ?></label>
					<input class="counter-classic__input" name="address_line1" placeholder="<?php esc_attr_e( 'Street address', 'counter' ); ?>" />
				</div>

				<div class="counter-classic__row">
					<input class="counter-classic__input" name="address_line2" placeholder="<?php esc_attr_e( 'Apt, suite (optional)', 'shop' ); ?>" />
					<input class="counter-classic__input" name="city" placeholder="<?php esc_attr_e( 'City', 'counter' ); ?>" />
				</div>
				<div class="counter-classic__row counter-classic__row--3">
					<input class="counter-classic__input" name="state" placeholder="<?php esc_attr_e( 'State', 'counter' ); ?>" />
					<input class="counter-classic__input" name="zip" placeholder="<?php esc_attr_e( 'ZIP', 'counter' ); ?>" maxlength="10" />
					<input class="counter-classic__input" name="country" placeholder="<?php esc_attr_e( 'Country', 'counter' ); ?>" value="United States" />
				</div>
			</section>

			<!-- ─── Section 2 — shipping ───────────────────────── -->
			<section class="counter-classic__section" data-section="shipping">
				<header class="counter-classic__section-head">
					<h2><span class="counter-classic__section-num">2</span> <?php esc_html_e( 'Shipping', 'counter' ); ?></h2>
					<span class="counter-classic__section-status"><?php esc_html_e( 'Auto-calculated', 'counter' ); ?></span>
				</header>

				<div class="counter-classic__ship-list" role="radiogroup">
					<label class="counter-classic__ship-row active" data-ship="standard" data-cost="0">
						<div>
							<div class="counter-classic__ship-name"><?php esc_html_e( 'Standard', 'counter' ); ?></div>
							<div class="counter-classic__ship-sub"><?php esc_html_e( '5–7 business days · USPS Priority', 'counter' ); ?></div>
						</div>
						<div class="counter-classic__ship-price"><?php esc_html_e( 'Free', 'counter' ); ?></div>
					</label>
					<label class="counter-classic__ship-row" data-ship="express" data-cost="999">
						<div>
							<div class="counter-classic__ship-name"><?php esc_html_e( 'Express', 'counter' ); ?></div>
							<div class="counter-classic__ship-sub"><?php esc_html_e( '2–3 business days · UPS', 'counter' ); ?></div>
						</div>
						<div class="counter-classic__ship-price">$9.99</div>
					</label>
					<label class="counter-classic__ship-row" data-ship="overnight" data-cost="2499">
						<div>
							<div class="counter-classic__ship-name"><?php esc_html_e( 'Overnight', 'counter' ); ?></div>
							<div class="counter-classic__ship-sub"><?php esc_html_e( 'Next business day · FedEx', 'counter' ); ?></div>
						</div>
						<div class="counter-classic__ship-price">$24.99</div>
					</label>
				</div>
			</section>

			<!-- ─── Section 3 — payment ────────────────────────── -->
			<section class="counter-classic__section" data-section="payment">
				<header class="counter-classic__section-head">
					<h2><span class="counter-classic__section-num">3</span> <?php esc_html_e( 'Payment', 'counter' ); ?></h2>
					<span class="counter-classic__section-status"><?php esc_html_e( 'Pick a method', 'counter' ); ?></span>
				</header>

				<div class="counter-classic__method-strip" role="tablist">
					<button class="counter-classic__method-pill active" data-method="card" type="button">
						<span class="counter-classic__pill-ico">CC</span> <?php esc_html_e( 'Card', 'counter' ); ?>
					</button>
					<button class="counter-classic__method-pill" data-method="wallets" type="button">
						<span class="counter-classic__pill-ico">&#x2318;</span> <?php esc_html_e( 'Wallets', 'counter' ); ?>
					</button>
					<button class="counter-classic__method-pill" data-method="bnpl" type="button">
						<span class="counter-classic__pill-ico">4&times;</span> <?php esc_html_e( 'Pay later', 'counter' ); ?>
					</button>
					<button class="counter-classic__method-pill" data-method="bank" type="button">
						<span class="counter-classic__pill-ico">&#x23E7;</span> <?php esc_html_e( 'Bank', 'counter' ); ?>
					</button>
					<button class="counter-classic__method-pill" data-method="crypto" type="button">
						<span class="counter-classic__pill-ico">&#x20BF;</span> <?php esc_html_e( 'Crypto', 'counter' ); ?>
					</button>
					<button class="counter-classic__method-pill" data-method="p2p" type="button">
						<span class="counter-classic__pill-ico">$</span> <?php esc_html_e( 'P2P', 'counter' ); ?>
					</button>
				</div>

				<!-- Card -->
				<div class="counter-classic__method-panel active" data-panel="card">
					<div class="counter-classic__field">
						<label><?php esc_html_e( 'Card number', 'counter' ); ?></label>
						<input class="counter-classic__input counter-classic__input--mono" name="card_number" placeholder="1234 1234 1234 1234" inputmode="numeric" maxlength="19" />
					</div>
					<div class="counter-classic__row">
						<div><label><?php esc_html_e( 'Expiry', 'counter' ); ?></label><input class="counter-classic__input counter-classic__input--mono" name="card_exp" placeholder="MM / YY" maxlength="7" /></div>
						<div><label><?php esc_html_e( 'CVC', 'counter' ); ?></label><input class="counter-classic__input counter-classic__input--mono" name="card_cvc" placeholder="&bull;&bull;&bull;" maxlength="4" inputmode="numeric" /></div>
					</div>
					<div class="counter-classic__field">
						<label><?php esc_html_e( 'Name on card', 'counter' ); ?></label>
						<input class="counter-classic__input" name="card_name" placeholder="<?php esc_attr_e( 'As shown on card', 'counter' ); ?>" />
					</div>
				</div>

				<!-- Wallets -->
				<div class="counter-classic__method-panel" data-panel="wallets">
					<div class="counter-classic__wallet-grid">
						<button class="counter-classic__wallet counter-classic__wallet--apple" type="button">&#xF8FF;&nbsp;Pay</button>
						<button class="counter-classic__wallet counter-classic__wallet--google" type="button">G Pay</button>
						<button class="counter-classic__wallet counter-classic__wallet--link" type="button" data-method="link">Link</button>
						<!-- PayPal Smart Buttons mount: replaced at runtime by the PayPal SDK. -->
						<div   class="counter-classic__wallet counter-classic__wallet--paypal" data-paypal-funding="paypal">PayPal</div>
						<button class="counter-classic__wallet counter-classic__wallet--shop" type="button">Shop Pay</button>
					</div>
				</div>

				<!-- BNPL -->
				<div class="counter-classic__method-panel" data-panel="bnpl">
					<?php foreach ( [
						[ 'klarna',       'Klarna',    __( '0% interest · every 2 weeks', 'counter' ),    null ],
						[ 'affirm',       'affirm',    __( '3, 6, or 12 monthly payments', 'shop' ),      null ],
						[ 'afterpay',     'Afterpay',  __( '0% interest · every 2 weeks', 'counter' ),    null ],
						[ 'sezzle',       'Sezzle',    __( 'Soft credit check', 'counter' ),              null ],
						[ 'zip',          'Zip',       __( '$1 per installment', 'counter' ),             null ],
						// PP Credit is rendered by PayPal Smart Buttons, not a static label.
						[ 'paypalcredit', 'PP Credit', __( '6 months no interest on $99+', 'counter' ),   'paylater' ],
					] as $i => [ $slug, $label, $sub, $paypal_funding ] ) : ?>
						<label class="counter-classic__bnpl-card <?php echo $i === 0 ? 'active' : ''; ?>"
							<?php if ( $paypal_funding ) : ?>data-paypal-funding="<?php echo esc_attr( $paypal_funding ); ?>"<?php endif; ?>>
							<span class="counter-classic__bnpl-logo counter-classic__bnpl-logo--<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></span>
							<div>
								<div class="counter-classic__bnpl-name">4 payments of <span data-quartertotal><?php echo esc_html( $bnpl_quarter->format() ); ?></span></div>
								<div class="counter-classic__bnpl-sub"><?php echo esc_html( $sub ); ?></div>
							</div>
							<span class="counter-classic__bnpl-chev">&rsaquo;</span>
						</label>
					<?php endforeach; ?>
				</div>

				<!-- Bank -->
				<div class="counter-classic__method-panel" data-panel="bank">
					<label class="counter-classic__bank-card active">
						<span class="counter-classic__bank-ico">&#x23E7;</span>
						<div>
							<div class="counter-classic__bank-name"><?php esc_html_e( 'Connect with Plaid', 'counter' ); ?></div>
							<div class="counter-classic__bank-sub"><?php esc_html_e( 'Pay directly from your bank. Saves ~2% in card fees — passed back to you.', 'counter' ); ?></div>
						</div>
						<span class="counter-classic__bnpl-chev">&rsaquo;</span>
					</label>
				</div>

				<!-- Crypto -->
				<div class="counter-classic__method-panel" data-panel="crypto">
					<div class="counter-classic__crypto-grid">
						<?php foreach ( [
							[ 'btc',  '&#x20BF;', 'BTC' ],   [ 'eth',  '&#x39E;', 'ETH' ],
							[ 'usdc', '$',        'USDC' ],  [ 'usdt', '&#x20AE;', 'USDT' ],
							[ 'sol',  '&#x25CE;', 'SOL' ],   [ 'xrp',  '&times;',  'XRP' ],
							[ 'link', '&#x26AD;', 'LINK' ],  [ 'xlm',  '&#x2606;', 'XLM' ],
							[ 'hbar', 'H',        'HBAR' ],
						] as $i => [ $slug, $sym, $label ] ) : ?>
							<label class="counter-classic__crypto-chip <?php echo $i === 0 ? 'active' : ''; ?>">
								<span class="counter-classic__crypto-sym counter-classic__crypto-sym--<?php echo esc_attr( $slug ); ?>"><?php echo $sym; ?></span>
								<span class="counter-classic__crypto-label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="counter-classic__note">
						<?php esc_html_e( 'QR code on confirm · ~10–15 min for network settlement · routed through AnyPay (50+ coins)', 'counter' ); ?>
					</p>
				</div>

				<!-- P2P -->
				<div class="counter-classic__method-panel" data-panel="p2p">
					<div class="counter-classic__p2p-grid">
						<button class="counter-classic__p2p counter-classic__p2p--cashapp" type="button" data-method="cashapp">$ Cash App</button>
						<!-- Venmo: PayPal Smart Buttons mount -->
						<div    class="counter-classic__p2p counter-classic__p2p--venmo"   data-paypal-funding="venmo">Venmo</div>
						<button class="counter-classic__p2p counter-classic__p2p--zelle"   type="button" data-method="zelle">Zelle</button>
					</div>
				</div>
			</section>
		</div>

		<!-- ─── Summary ───────────────────────────────────────── -->
		<aside class="counter-classic__summary">
			<h3><?php esc_html_e( 'Order summary', 'counter' ); ?></h3>

			<div class="counter-classic__lines">
				<?php foreach ( $cart->items as $item ) :
					$product = $products->findById( $item->productId, $cart->currency );
					$variant = $item->variantId !== null ? $products->findVariant( $item->variantId, $cart->currency ) : null;
					$title   = $product?->title ?? sprintf( __( 'Product #%d', 'counter' ), $item->productId );
				?>
					<div class="counter-classic__line">
						<div class="counter-classic__line-img" aria-hidden="true">
							<span class="counter-classic__line-qty"><?php echo esc_html( (string) $item->quantity ); ?></span>
						</div>
						<div class="counter-classic__line-info">
							<div class="counter-classic__line-name"><?php echo esc_html( $title ); ?></div>
							<?php if ( $variant !== null ) : ?>
								<div class="counter-classic__line-meta">
									<?php echo esc_html( implode( ' · ', array_filter( [
										$variant->podProvider,
										is_array( $variant->meta['options'] ?? null ) ? implode( ' · ', $variant->meta['options'] ) : null,
									] ) ) ); ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="counter-classic__line-price"><?php echo esc_html( $item->lineTotal->format() ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="counter-classic__totals">
				<div class="counter-classic__totals-row">
					<span><?php esc_html_e( 'Subtotal', 'counter' ); ?></span>
					<span id="counter-classic-subtotal"><?php echo esc_html( $cart->subtotal->format() ); ?></span>
				</div>
				<div class="counter-classic__totals-row">
					<span><?php esc_html_e( 'Shipping', 'counter' ); ?></span>
					<span id="counter-classic-shipping"><?php echo $cart->shippingTotal->isZero() ? esc_html__( 'Free', 'counter' ) : esc_html( $cart->shippingTotal->format() ); ?></span>
				</div>
				<div class="counter-classic__totals-row">
					<span><?php esc_html_e( 'Tax (est.)', 'counter' ); ?></span>
					<span id="counter-classic-tax"><?php echo esc_html( $cart->taxTotal->format() ); ?></span>
				</div>
				<div class="counter-classic__totals-row counter-classic__totals-row--total">
					<span><?php esc_html_e( 'Total', 'counter' ); ?></span>
					<span id="counter-classic-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
				</div>
			</div>

			<button class="counter-classic__pay-btn" type="button" id="counter-classic-pay">
				<?php /* translators: %s = grand total */
				printf( esc_html__( 'Pay %s', 'counter' ), esc_html( $cart->grandTotal->format() ) ); ?>
			</button>

			<p class="counter-classic__security">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				<?php esc_html_e( 'Encrypted end-to-end · No card data stored', 'counter' ); ?>
			</p>
		</aside>

	</div>
</div>
