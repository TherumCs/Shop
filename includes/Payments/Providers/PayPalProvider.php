<?php
/**
 * Shop by Therum — PayPalProvider.
 *
 * Covers everything in PayPal's family: paypal, paypal_credit, venmo
 * (PayPal owns Venmo). Uses PayPal Orders v2 API — REST + OAuth2 client
 * credentials. No SDK.
 *
 * Sandbox/live is driven by `shop_paypal_environment` ('sandbox' |
 * 'live'). Credentials live in `shop_paypal_client_id` /
 * `shop_paypal_client_secret`. Studio Pay Connect mode stores those
 * under the `shop_studio_pay_paypal_*` keys instead.
 */

namespace Shop\Payments\Providers;

use Shop\Models\Order;
use Shop\Money;
use Shop\Payments\PaymentIntent;
use Shop\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PayPalProvider implements PaymentProvider {

	public function id(): string          { return 'paypal'; }
	public function displayName(): string { return 'PayPal'; }

	public function supportedMethods(): array {
		return [ 'paypal', 'paypal_credit', 'venmo' ];
	}

	public function isConnected(): bool {
		return $this->clientId() !== '' && $this->clientSecret() !== '';
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		$body = $this->call( 'POST', 'v2/checkout/orders', [
			'intent' => 'CAPTURE',
			'purchase_units' => [ [
				'reference_id' => (string) $order->number,
				'amount' => [
					'currency_code' => $order->grandTotal->currency,
					'value'         => number_format( $order->grandTotal->amount / 100, 2, '.', '' ),
				],
			] ],
			'payment_source' => $this->paymentSource( $method ),
		] );
		$approve = '';
		foreach ( (array) ( $body['links'] ?? [] ) as $link ) {
			if ( ( $link['rel'] ?? '' ) === 'approve' || ( $link['rel'] ?? '' ) === 'payer-action' ) {
				$approve = (string) $link['href'];
				break;
			}
		}
		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $body['id'] ?? '' ),
			clientSecret: '',
			redirectUrl:  $approve,
			raw:          $body,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function paymentSource( string $method ): array {
		// PayPal's Payment Source object hints which buttons / funding
		// sources to surface in the approval flow.
		return match ( $method ) {
			'paypal'        => [ 'paypal' => new \stdClass() ],
			'paypal_credit' => [ 'paypal' => [ 'experience_context' => [ 'shipping_preference' => 'GET_FROM_FILE' ] ], 'pay_later' => new \stdClass() ],
			'venmo'         => [ 'venmo'  => new \stdClass() ],
			default         => [ 'paypal' => new \stdClass() ],
		};
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		// PayPal Orders v2 — the "intent_id" we stored at capture time
		// IS the PayPal order id, which is what /captures/{id}/refund
		// wants. No separate capture id is tracked on our Order DTO.
		$captureId = (string) ( $order->paymentIntentId ?? '' );
		if ( $captureId === '' ) throw new \RuntimeException( 'Order has no PayPal capture id.' );
		$body = $this->call( 'POST', "v2/payments/captures/$captureId/refund", [
			'amount' => [
				'currency_code' => $amount->currency,
				'value'         => number_format( $amount->amount / 100, 2, '.', '' ),
			],
		], $idempotencyKey );
		return (string) ( $body['id'] ?? '' );
	}

	public function availableBalance(): ?Money {
		// PayPal exposes balance via /v1/reporting/balances but it's
		// often restricted. Defer to the merchant dashboard.
		return null;
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		// PayPal "Payouts" sends to a recipient — not appropriate here
		// since the merchant's own balance settles to their bank on
		// their own schedule. Treat as not-supported.
		throw new \RuntimeException( 'PayPal payouts are managed in the PayPal dashboard.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		$id = $headers['paypal-transmission-id']   ?? $headers['Paypal-Transmission-Id']   ?? '';
		$ts = $headers['paypal-transmission-time'] ?? $headers['Paypal-Transmission-Time'] ?? '';
		if ( $id === '' || $ts === '' ) return null;
		// PayPal's verify-webhook-signature endpoint round-trips the
		// signature for us so we don't have to implement their cert
		// chain. Single extra HTTP call per webhook.
		$res = $this->call( 'POST', 'v1/notifications/verify-webhook-signature', [
			'transmission_id'    => $id,
			'transmission_time'  => $ts,
			'cert_url'           => $headers['paypal-cert-url']   ?? $headers['Paypal-Cert-Url']   ?? '',
			'auth_algo'          => $headers['paypal-auth-algo']  ?? $headers['Paypal-Auth-Algo']  ?? '',
			'transmission_sig'   => $headers['paypal-transmission-sig'] ?? $headers['Paypal-Transmission-Sig'] ?? '',
			'webhook_id'         => (string) get_option( 'shop_paypal_webhook_id', '' ),
			'webhook_event'      => json_decode( $rawBody, true ),
		] );
		if ( ( $res['verification_status'] ?? '' ) !== 'SUCCESS' ) {
			throw new \RuntimeException( 'PayPal webhook signature mismatch.' );
		}
		return json_decode( $rawBody, true ) ?: null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$type = (string) ( $verified['event_type'] ?? '' );
		$res  = (array)  ( $verified['resource'] ?? [] );
		$kind = match ( $type ) {
			'PAYMENT.CAPTURE.COMPLETED'       => 'payment.succeeded',
			'PAYMENT.CAPTURE.DENIED'          => 'payment.failed',
			'PAYMENT.CAPTURE.REFUNDED'        => 'refund.succeeded',
			'CUSTOMER.DISPUTE.CREATED'        => 'dispute.opened',
			default                           => 'unknown',
		};
		$money = (array) ( $res['amount'] ?? $res['gross_amount'] ?? [] );
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['id'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $res['supplementary_data']['related_ids']['order_id'] ?? $res['id'] ?? '' ),
			amount:          isset( $money['value'], $money['currency_code'] ) ? new Money( (int) round( ( (float) $money['value'] ) * 100 ), (string) $money['currency_code'] ) : null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function baseUrl(): string {
		$env = (string) get_option( 'shop_paypal_environment', 'live' );
		return $env === 'sandbox'
			? 'https://api-m.sandbox.paypal.com/'
			: 'https://api-m.paypal.com/';
	}

	private function clientId(): string {
		return (string) get_option( 'shop_studio_pay_paypal_client_id', '' )
			?: (string) get_option( 'shop_paypal_client_id', '' );
	}

	private function clientSecret(): string {
		return (string) get_option( 'shop_studio_pay_paypal_client_secret', '' )
			?: (string) get_option( 'shop_paypal_client_secret', '' );
	}

	/** Cached for 8 minutes (token TTL is 9). */
	private function accessToken(): string {
		$cached = get_transient( 'shop_paypal_oauth_token' );
		if ( is_string( $cached ) && $cached !== '' ) return $cached;
		$res = wp_remote_post( $this->baseUrl() . 'v1/oauth2/token', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $this->clientId() . ':' . $this->clientSecret() ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => 'grant_type=client_credentials',
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'PayPal token HTTP error: ' . $res->get_error_message() );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$tok  = (string) ( $json['access_token'] ?? '' );
		if ( $tok === '' ) throw new \RuntimeException( 'PayPal token request failed.' );
		set_transient( 'shop_paypal_oauth_token', $tok, 8 * MINUTE_IN_SECONDS );
		return $tok;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call( string $method, string $path, array $params, ?string $idempotencyKey = null ): array {
		$headers = [
			'Authorization' => 'Bearer ' . $this->accessToken(),
			'Content-Type'  => 'application/json',
		];
		if ( $idempotencyKey ) $headers['PayPal-Request-Id'] = $idempotencyKey;

		$res = wp_remote_request( $this->baseUrl() . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'PayPal HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'PayPal returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['message'] ?? $json['error_description'] ?? 'PayPal API error' );
			throw new \RuntimeException( "PayPal $code: $msg" );
		}
		return $json;
	}
}
