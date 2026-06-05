<?php
/**
 * Shop by Therum — Studio Connect.
 *
 * The OAuth-style connect flow merchants see when wiring up Studio
 * Pay. One button per provider; everything else is hidden:
 *
 *   1. Admin clicks "Connect Stripe" → our /connect/start endpoint
 *      builds the provider's OAuth URL and redirects.
 *   2. Provider sends them back to /connect/callback with a code.
 *   3. We exchange code → access token / account id → save into
 *      `shop_studio_pay_{provider}_*` options.
 *   4. Webhook endpoint URL is auto-registered with the provider so
 *      events start landing immediately.
 *
 * Why our own connect (vs. just having the merchant paste keys):
 *   - WooPayments-tier UX. One click.
 *   - We register the webhook secret correctly first try — eliminates
 *     the single biggest source of integration support tickets.
 *   - Merchant never touches Stripe / Square / PayPal dashboards.
 *
 * State is signed (HMAC of nonce + provider) so a callback can't be
 * swapped between providers mid-flight.
 */

namespace Shop\Payments\Studio;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioConnect {

	/**
	 * Build the redirect URL the merchant should be sent to to begin
	 * the OAuth handshake.
	 */
	public function startUrl( string $provider ): string {
		return match ( $provider ) {
			'stripe' => $this->stripeAuthorizeUrl(),
			'square' => $this->squareAuthorizeUrl(),
			'paypal' => $this->paypalAuthorizeUrl(),
			'plaid'  => $this->plaidLinkPlaceholderUrl(),
			default  => throw new \InvalidArgumentException( "Unknown provider '$provider'." ),
		};
	}

	/**
	 * Process the OAuth callback for a provider. Stores credentials +
	 * registers webhook. Returns the provider id on success.
	 */
	public function finish( string $provider, array $query ): string {
		$this->verifyState( $provider, (string) ( $query['state'] ?? '' ) );

		return match ( $provider ) {
			'stripe' => $this->finishStripe( $query ),
			'square' => $this->finishSquare( $query ),
			'paypal' => $this->finishPayPal( $query ),
			'plaid'  => $this->finishPlaid( $query ),
			default  => throw new \InvalidArgumentException( "Unknown provider '$provider'." ),
		};
	}

	/**
	 * Disconnect — wipes the saved credentials. Doesn't deauthorize at
	 * the provider's side (admins do that in Stripe/Square dashboards),
	 * since OAuth deauth tokens are short-lived and often unavailable
	 * after the auth code is consumed.
	 */
	public function disconnect( string $provider ): void {
		$keys = $this->credentialKeys( $provider );
		foreach ( $keys as $k ) delete_option( $k );
	}

	// ─── Provider-specific authorize URLs ────────────────────────────────

	private function stripeAuthorizeUrl(): string {
		// Standard Stripe Connect OAuth — works for both Standard and
		// Express accounts. Studio platform's client_id is required.
		$clientId = (string) get_option( 'shop_studio_pay_stripe_platform_client_id', '' );
		if ( $clientId === '' ) {
			throw new \RuntimeException( 'Stripe Connect not yet configured. Studio Pay platform client_id missing.' );
		}
		return 'https://connect.stripe.com/oauth/v2/authorize?' . http_build_query( [
			'response_type' => 'code',
			'client_id'     => $clientId,
			'scope'         => 'read_write',
			'redirect_uri'  => $this->callbackUrl( 'stripe' ),
			'state'         => $this->signedState( 'stripe' ),
		] );
	}

	private function squareAuthorizeUrl(): string {
		$appId = (string) get_option( 'shop_studio_pay_square_app_id', '' );
		if ( $appId === '' ) throw new \RuntimeException( 'Square OAuth not configured.' );
		return 'https://connect.squareup.com/oauth2/authorize?' . http_build_query( [
			'client_id'    => $appId,
			'scope'        => 'PAYMENTS_READ PAYMENTS_WRITE ORDERS_READ ORDERS_WRITE MERCHANT_PROFILE_READ',
			'session'      => 'false',
			'state'        => $this->signedState( 'square' ),
		] );
	}

	private function paypalAuthorizeUrl(): string {
		$clientId = (string) get_option( 'shop_studio_pay_paypal_platform_client_id', '' );
		if ( $clientId === '' ) throw new \RuntimeException( 'PayPal Partner not configured.' );
		// PayPal Partner Referral — generates a one-shot signup link.
		// Real implementation calls /v2/customer/partner-referrals to
		// mint an action URL. Stubbed here for the platform setup.
		return 'https://www.paypal.com/connect?' . http_build_query( [
			'flowEntry'    => 'static',
			'client_id'    => $clientId,
			'response_type'=> 'code',
			'scope'        => 'openid email',
			'redirect_uri' => $this->callbackUrl( 'paypal' ),
			'state'        => $this->signedState( 'paypal' ),
		] );
	}

	private function plaidLinkPlaceholderUrl(): string {
		// Plaid doesn't use redirect OAuth for merchant onboarding —
		// it's a client-side Link flow. The /admin/studio-pay/connect
		// page detects 'plaid' and renders the Link button inline.
		return admin_url( 'admin.php?page=shop-studio-pay&plaid=link' );
	}

	// ─── Finishers ───────────────────────────────────────────────────────

	private function finishStripe( array $q ): string {
		$code = (string) ( $q['code'] ?? '' );
		if ( $code === '' ) throw new \RuntimeException( 'Stripe: missing code.' );

		$res = wp_remote_post( 'https://connect.stripe.com/oauth/token', [
			'timeout' => 20,
			'body' => [
				'client_secret' => (string) get_option( 'shop_studio_pay_platform_secret', '' ),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( $res->get_error_message() );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['stripe_user_id'] ) ) {
			throw new \RuntimeException( 'Stripe: bad token response.' );
		}
		update_option( 'shop_studio_pay_stripe_account_id', (string) $body['stripe_user_id'] );

		// Auto-register the webhook endpoint on the connected account.
		$this->registerStripeWebhook( (string) $body['stripe_user_id'] );
		return 'stripe';
	}

	private function registerStripeWebhook( string $accountId ): void {
		$url = rest_url( 'shop/v1/webhooks/studio-pay' );
		$res = wp_remote_post( 'https://api.stripe.com/v1/webhook_endpoints', [
			'timeout' => 20,
			'headers' => [
				'Authorization'  => 'Bearer ' . (string) get_option( 'shop_studio_pay_platform_secret', '' ),
				'Stripe-Account' => $accountId,
				'Content-Type'   => 'application/x-www-form-urlencoded',
			],
			'body' => http_build_query( [
				'url'                 => $url,
				'enabled_events[]'    => 'payment_intent.succeeded',
				'enabled_events[]'    => 'payment_intent.payment_failed',
				'enabled_events[]'    => 'charge.refunded',
				'enabled_events[]'    => 'charge.dispute.created',
				'enabled_events[]'    => 'payout.paid',
			] ),
		] );
		if ( is_wp_error( $res ) ) return;
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_array( $body ) && ! empty( $body['secret'] ) ) {
			update_option( 'shop_studio_pay_stripe_webhook_secret', (string) $body['secret'] );
		}
	}

	private function finishSquare( array $q ): string {
		$code = (string) ( $q['code'] ?? '' );
		if ( $code === '' ) throw new \RuntimeException( 'Square: missing code.' );

		$res = wp_remote_post( 'https://connect.squareup.com/oauth2/token', [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body' => wp_json_encode( [
				'client_id'     => (string) get_option( 'shop_studio_pay_square_app_id', '' ),
				'client_secret' => (string) get_option( 'shop_studio_pay_square_app_secret', '' ),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
			] ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( $res->get_error_message() );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			throw new \RuntimeException( 'Square: bad token response.' );
		}
		update_option( 'shop_studio_pay_square_token', (string) $body['access_token'] );
		if ( ! empty( $body['merchant_id'] ) ) {
			update_option( 'shop_studio_pay_square_merchant_id', (string) $body['merchant_id'] );
		}
		return 'square';
	}

	private function finishPayPal( array $q ): string {
		// PayPal Partner Referrals flow returns the merchant id in the
		// callback query as `merchantId` / `merchantIdInPayPal`.
		$merchantId = (string) ( $q['merchantIdInPayPal'] ?? $q['merchantId'] ?? '' );
		if ( $merchantId === '' ) throw new \RuntimeException( 'PayPal: missing merchant id.' );
		update_option( 'shop_studio_pay_paypal_merchant_id', $merchantId );
		return 'paypal';
	}

	private function finishPlaid( array $q ): string {
		$pubToken = (string) ( $q['public_token'] ?? '' );
		if ( $pubToken === '' ) throw new \RuntimeException( 'Plaid: missing public_token.' );

		$base = match ( (string) get_option( 'shop_plaid_environment', 'sandbox' ) ) {
			'production'  => 'https://production.plaid.com/',
			'development' => 'https://development.plaid.com/',
			default       => 'https://sandbox.plaid.com/',
		};
		$res = wp_remote_post( $base . 'item/public_token/exchange', [
			'timeout' => 20,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body' => wp_json_encode( [
				'client_id'    => (string) get_option( 'shop_plaid_client_id', '' ) ?: (string) get_option( 'shop_studio_pay_plaid_client_id', '' ),
				'secret'       => (string) get_option( 'shop_plaid_secret', '' )    ?: (string) get_option( 'shop_studio_pay_plaid_secret', '' ),
				'public_token' => $pubToken,
			] ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( $res->get_error_message() );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			throw new \RuntimeException( 'Plaid: bad exchange response.' );
		}
		update_option( 'shop_plaid_merchant_access_token', (string) $body['access_token'] );
		return 'plaid';
	}

	// ─── State signing ───────────────────────────────────────────────────

	private function signedState( string $provider ): string {
		$nonce = wp_create_nonce( 'shop_studio_connect' );
		$mac   = hash_hmac( 'sha256', $nonce . '|' . $provider, wp_salt() );
		return $nonce . '.' . $provider . '.' . $mac;
	}

	private function verifyState( string $expectedProvider, string $state ): void {
		$parts = explode( '.', $state );
		if ( count( $parts ) !== 3 ) throw new \RuntimeException( 'Invalid state.' );
		[ $nonce, $provider, $mac ] = $parts;
		if ( $provider !== $expectedProvider ) throw new \RuntimeException( 'State provider mismatch.' );
		$expected = hash_hmac( 'sha256', $nonce . '|' . $provider, wp_salt() );
		if ( ! hash_equals( $expected, $mac ) ) throw new \RuntimeException( 'State HMAC mismatch.' );
	}

	private function callbackUrl( string $provider ): string {
		return rest_url( 'shop/v1/admin/studio-pay/connect/' . $provider . '/callback' );
	}

	/** @return string[] */
	private function credentialKeys( string $provider ): array {
		return match ( $provider ) {
			'stripe' => [ 'shop_studio_pay_stripe_account_id', 'shop_studio_pay_stripe_webhook_secret' ],
			'square' => [ 'shop_studio_pay_square_token', 'shop_studio_pay_square_merchant_id' ],
			'paypal' => [ 'shop_studio_pay_paypal_merchant_id' ],
			'plaid'  => [ 'shop_plaid_merchant_access_token' ],
			default  => [],
		};
	}
}
