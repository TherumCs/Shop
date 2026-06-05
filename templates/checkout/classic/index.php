<?php
/**
 * Classic checkout — sections-stacked form left, sticky summary right.
 *
 * The OG Therum checkout silhouette. Six payment rails. Pay button label
 * adapts per method. JS interaction: assets/checkout/classic.js (next chunk).
 *
 * Variables in scope:
 *   $cart : Shop\Models\Cart
 *   $mode : string (always 'classic' here)
 *
 * Override: copy to <theme>/shop/checkout/classic/index.php
 */

/** @var \Shop\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;

$products = \Shop\Container::instance()->get( \Shop\Repositories\ProductRepository::class );
$bnpl_quarter = $cart->grandTotal->dividedBy( 4 );
?>
<div class="shop-classic" data-shop-checkout="classic" data-shop-cart-token="<?php echo esc_attr( $cart->token ); ?>">

	<div class="shop-classic__brand">
		<div class="shop-classic__brand-mark">T</div>
		<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
		<small><?php esc_html_e( '· Checkout', 'shop' ); ?></small>
	</div>

	<div class="shop-classic__layout">

		<div class="shop-classic__form">

			<!-- ─── Section 1 — info ───────────────────────────── -->
			<section class="shop-classic__section is-focus" data-section="info">
				<header class="shop-classic__section-head">
					<h2><span class="shop-classic__section-num">1</span> <?php esc_html_e( 'Your info', 'shop' ); ?></h2>
					<span class="shop-classic__section-status"><?php esc_html_e( 'Ready', 'shop' ); ?></span>
				</header>

				<div class="shop-classic__field">
					<label><?php esc_html_e( 'Email', 'shop' ); ?></label>
					<input class="shop-classic__input" type="email" name="email" placeholder="you@example.com"
						value="<?php echo esc_attr( (string) ( $cart->email ?? '' ) ); ?>" autofocus />
				</div>

				<div class="shop-classic__field">
					<label><?php esc_html_e( 'Ship to', 'shop' ); ?></label>
					<input class="shop-classic__input" name="address_line1" placeholder="<?php esc_attr_e( 'Street address', 'shop' ); ?>" />
				</div>

				<div class="shop-classic__row">
					<input class="shop-classic__input" name="address_line2" placeholder="<?php esc_attr_e( 'Apt, suite (optional)', 'shop' ); ?>" />
					<input class="shop-classic__input" name="city" placeholder="<?php esc_attr_e( 'City', 'shop' ); ?>" />
				</div>
				<div class="shop-classic__row shop-classic__row--3">
					<input class="shop-classic__input" name="state" placeholder="<?php esc_attr_e( 'State', 'shop' ); ?>" />
					<input class="shop-classic__input" name="zip" placeholder="<?php esc_attr_e( 'ZIP', 'shop' ); ?>" maxlength="10" />
					<input class="shop-classic__input" name="country" placeholder="<?php esc_attr_e( 'Country', 'shop' ); ?>" value="United States" />
				</div>
			</section>

			<!-- ─── Section 2 — shipping ───────────────────────── -->
			<section class="shop-classic__section" data-section="shipping">
				<header class="shop-classic__section-head">
					<h2><span class="shop-classic__section-num">2</span> <?php esc_html_e( 'Shipping', 'shop' ); ?></h2>
					<span class="shop-classic__section-status"><?php esc_html_e( 'Auto-calculated', 'shop' ); ?></span>
				</header>

				<div class="shop-classic__ship-list" role="radiogroup">
					<label class="shop-classic__ship-row active" data-ship="standard" data-cost="0">
						<div>
							<div class="shop-classic__ship-name"><?php esc_html_e( 'Standard', 'shop' ); ?></div>
							<div class="shop-classic__ship-sub"><?php esc_html_e( '5–7 business days · USPS Priority', 'shop' ); ?></div>
						</div>
						<div class="shop-classic__ship-price"><?php esc_html_e( 'Free', 'shop' ); ?></div>
					</label>
					<label class="shop-classic__ship-row" data-ship="express" data-cost="999">
						<div>
							<div class="shop-classic__ship-name"><?php esc_html_e( 'Express', 'shop' ); ?></div>
							<div class="shop-classic__ship-sub"><?php esc_html_e( '2–3 business days · UPS', 'shop' ); ?></div>
						</div>
						<div class="shop-classic__ship-price">$9.99</div>
					</label>
					<label class="shop-classic__ship-row" data-ship="overnight" data-cost="2499">
						<div>
							<div class="shop-classic__ship-name"><?php esc_html_e( 'Overnight', 'shop' ); ?></div>
							<div class="shop-classic__ship-sub"><?php esc_html_e( 'Next business day · FedEx', 'shop' ); ?></div>
						</div>
						<div class="shop-classic__ship-price">$24.99</div>
					</label>
				</div>
			</section>

			<!-- ─── Section 3 — payment ────────────────────────── -->
			<section class="shop-classic__section" data-section="payment">
				<header class="shop-classic__section-head">
					<h2><span class="shop-classic__section-num">3</span> <?php esc_html_e( 'Payment', 'shop' ); ?></h2>
					<span class="shop-classic__section-status"><?php esc_html_e( 'Pick a method', 'shop' ); ?></span>
				</header>

				<div class="shop-classic__method-strip" role="tablist">
					<button class="shop-classic__method-pill active" data-method="card" type="button">
						<span class="shop-classic__pill-ico">CC</span> <?php esc_html_e( 'Card', 'shop' ); ?>
					</button>
					<button class="shop-classic__method-pill" data-method="wallets" type="button">
						<span class="shop-classic__pill-ico">&#x2318;</span> <?php esc_html_e( 'Wallets', 'shop' ); ?>
					</button>
					<button class="shop-classic__method-pill" data-method="bnpl" type="button">
						<span class="shop-classic__pill-ico">4&times;</span> <?php esc_html_e( 'Pay later', 'shop' ); ?>
					</button>
					<button class="shop-classic__method-pill" data-method="bank" type="button">
						<span class="shop-classic__pill-ico">&#x23E7;</span> <?php esc_html_e( 'Bank', 'shop' ); ?>
					</button>
					<button class="shop-classic__method-pill" data-method="crypto" type="button">
						<span class="shop-classic__pill-ico">&#x20BF;</span> <?php esc_html_e( 'Crypto', 'shop' ); ?>
					</button>
					<button class="shop-classic__method-pill" data-method="p2p" type="button">
						<span class="shop-classic__pill-ico">$</span> <?php esc_html_e( 'P2P', 'shop' ); ?>
					</button>
				</div>

				<!-- Card -->
				<div class="shop-classic__method-panel active" data-panel="card">
					<div class="shop-classic__field">
						<label><?php esc_html_e( 'Card number', 'shop' ); ?></label>
						<input class="shop-classic__input shop-classic__input--mono" name="card_number" placeholder="1234 1234 1234 1234" inputmode="numeric" maxlength="19" />
					</div>
					<div class="shop-classic__row">
						<div><label><?php esc_html_e( 'Expiry', 'shop' ); ?></label><input class="shop-classic__input shop-classic__input--mono" name="card_exp" placeholder="MM / YY" maxlength="7" /></div>
						<div><label><?php esc_html_e( 'CVC', 'shop' ); ?></label><input class="shop-classic__input shop-classic__input--mono" name="card_cvc" placeholder="&bull;&bull;&bull;" maxlength="4" inputmode="numeric" /></div>
					</div>
					<div class="shop-classic__field">
						<label><?php esc_html_e( 'Name on card', 'shop' ); ?></label>
						<input class="shop-classic__input" name="card_name" placeholder="<?php esc_attr_e( 'As shown on card', 'shop' ); ?>" />
					</div>
				</div>

				<!-- Wallets -->
				<div class="shop-classic__method-panel" data-panel="wallets">
					<div class="shop-classic__wallet-grid">
						<button class="shop-classic__wallet shop-classic__wallet--apple" type="button">&#xF8FF;&nbsp;Pay</button>
						<button class="shop-classic__wallet shop-classic__wallet--google" type="button">G Pay</button>
						<button class="shop-classic__wallet shop-classic__wallet--paypal" type="button">PayPal</button>
						<button class="shop-classic__wallet shop-classic__wallet--shop" type="button">Shop Pay</button>
					</div>
				</div>

				<!-- BNPL -->
				<div class="shop-classic__method-panel" data-panel="bnpl">
					<?php foreach ( [
						[ 'klarna', 'Klarna', __( '0% interest · every 2 weeks', 'shop' ) ],
						[ 'affirm', 'affirm', __( '3, 6, or 12 monthly payments', 'shop' ) ],
						[ 'afterpay', 'Afterpay', __( '0% interest · every 2 weeks', 'shop' ) ],
						[ 'sezzle', 'Sezzle', __( 'Soft credit check', 'shop' ) ],
						[ 'zip', 'Zip', __( '$1 per installment', 'shop' ) ],
						[ 'paypalcredit', 'PP Credit', __( '6 months no interest on $99+', 'shop' ) ],
					] as $i => [ $slug, $label, $sub ] ) : ?>
						<label class="shop-classic__bnpl-card <?php echo $i === 0 ? 'active' : ''; ?>">
							<span class="shop-classic__bnpl-logo shop-classic__bnpl-logo--<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></span>
							<div>
								<div class="shop-classic__bnpl-name">4 payments of <span data-quartertotal><?php echo esc_html( $bnpl_quarter->format() ); ?></span></div>
								<div class="shop-classic__bnpl-sub"><?php echo esc_html( $sub ); ?></div>
							</div>
							<span class="shop-classic__bnpl-chev">&rsaquo;</span>
						</label>
					<?php endforeach; ?>
				</div>

				<!-- Bank -->
				<div class="shop-classic__method-panel" data-panel="bank">
					<label class="shop-classic__bank-card active">
						<span class="shop-classic__bank-ico">&#x23E7;</span>
						<div>
							<div class="shop-classic__bank-name"><?php esc_html_e( 'Connect with Plaid', 'shop' ); ?></div>
							<div class="shop-classic__bank-sub"><?php esc_html_e( 'Pay directly from your bank. Saves ~2% in card fees — passed back to you.', 'shop' ); ?></div>
						</div>
						<span class="shop-classic__bnpl-chev">&rsaquo;</span>
					</label>
				</div>

				<!-- Crypto -->
				<div class="shop-classic__method-panel" data-panel="crypto">
					<div class="shop-classic__crypto-grid">
						<?php foreach ( [
							[ 'btc', '&#x20BF;', 'BTC' ], [ 'eth', '&#x39E;', 'ETH' ],
							[ 'usdc', '$', 'USDC' ], [ 'usdt', '&#x20AE;', 'USDT' ],
							[ 'sol', '&#x25CE;', 'SOL' ], [ 'xrp', '&times;', 'XRP' ],
						] as $i => [ $slug, $sym, $label ] ) : ?>
							<label class="shop-classic__crypto-chip <?php echo $i === 0 ? 'active' : ''; ?>">
								<span class="shop-classic__crypto-sym shop-classic__crypto-sym--<?php echo esc_attr( $slug ); ?>"><?php echo $sym; ?></span>
								<span class="shop-classic__crypto-label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="shop-classic__note">
						<?php esc_html_e( 'QR code on confirm · ~10–15 min for network settlement · routed through AnyPay (50+ coins)', 'shop' ); ?>
					</p>
				</div>

				<!-- P2P -->
				<div class="shop-classic__method-panel" data-panel="p2p">
					<div class="shop-classic__p2p-grid">
						<button class="shop-classic__p2p shop-classic__p2p--cashapp" type="button">$ Cash App</button>
						<button class="shop-classic__p2p shop-classic__p2p--venmo" type="button">Venmo</button>
						<button class="shop-classic__p2p shop-classic__p2p--zelle" type="button">Zelle</button>
					</div>
				</div>
			</section>
		</div>

		<!-- ─── Summary ───────────────────────────────────────── -->
		<aside class="shop-classic__summary">
			<h3><?php esc_html_e( 'Order summary', 'shop' ); ?></h3>

			<div class="shop-classic__lines">
				<?php foreach ( $cart->items as $item ) :
					$product = $products->findById( $item->productId, $cart->currency );
					$variant = $item->variantId !== null ? $products->findVariant( $item->variantId, $cart->currency ) : null;
					$title   = $product?->title ?? sprintf( __( 'Product #%d', 'shop' ), $item->productId );
				?>
					<div class="shop-classic__line">
						<div class="shop-classic__line-img" aria-hidden="true">
							<span class="shop-classic__line-qty"><?php echo esc_html( (string) $item->quantity ); ?></span>
						</div>
						<div class="shop-classic__line-info">
							<div class="shop-classic__line-name"><?php echo esc_html( $title ); ?></div>
							<?php if ( $variant !== null ) : ?>
								<div class="shop-classic__line-meta">
									<?php echo esc_html( implode( ' · ', array_filter( [
										$variant->podProvider,
										is_array( $variant->meta['options'] ?? null ) ? implode( ' · ', $variant->meta['options'] ) : null,
									] ) ) ); ?>
								</div>
							<?php endif; ?>
						</div>
						<div class="shop-classic__line-price"><?php echo esc_html( $item->lineTotal->format() ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="shop-classic__totals">
				<div class="shop-classic__totals-row">
					<span><?php esc_html_e( 'Subtotal', 'shop' ); ?></span>
					<span id="shop-classic-subtotal"><?php echo esc_html( $cart->subtotal->format() ); ?></span>
				</div>
				<div class="shop-classic__totals-row">
					<span><?php esc_html_e( 'Shipping', 'shop' ); ?></span>
					<span id="shop-classic-shipping"><?php echo $cart->shippingTotal->isZero() ? esc_html__( 'Free', 'shop' ) : esc_html( $cart->shippingTotal->format() ); ?></span>
				</div>
				<div class="shop-classic__totals-row">
					<span><?php esc_html_e( 'Tax (est.)', 'shop' ); ?></span>
					<span id="shop-classic-tax"><?php echo esc_html( $cart->taxTotal->format() ); ?></span>
				</div>
				<div class="shop-classic__totals-row shop-classic__totals-row--total">
					<span><?php esc_html_e( 'Total', 'shop' ); ?></span>
					<span id="shop-classic-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
				</div>
			</div>

			<button class="shop-classic__pay-btn" type="button" id="shop-classic-pay">
				<?php /* translators: %s = grand total */
				printf( esc_html__( 'Pay %s', 'shop' ), esc_html( $cart->grandTotal->format() ) ); ?>
			</button>

			<p class="shop-classic__security">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				<?php esc_html_e( 'Encrypted end-to-end · No card data stored', 'shop' ); ?>
			</p>
		</aside>

	</div>
</div>
