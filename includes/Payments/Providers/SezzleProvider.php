<?php
/**
 * Counter by Therum — SezzleProvider.
 *
 * Direct integration with Sezzle's Checkout v2 API. Sezzle is a BNPL
 * provider (4 interest-free installments). They settle directly to your
 * bank account on a daily ACH cycle — you don't hold a Sezzle balance.
 *
 * Auth: BYO public + private API keys from the Sezzle merchant dashboard.
 * Sandbox/live driven by `counter_sezzle_environment`.
 *
 * Refunds via /v2/order/<id>/refund.
 *
 * Reference: https://docs.sezzle.com/
 */

namespace Counter\Payments\Providers;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SezzleProvider implements PaymentProvider {

	public function id(): string          { return 'sezzle'; }
	public function displayName(): string { return 'Sezzle'; }

	public function supportedMethods(): array { return [ 'sezzle' ]; }

	public function isConnected(): bool {
		return $this->publicKey() !== '' && $this->privateKey() !== '';
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		// Sezzle's "session" is their PaymentIntent equivalent — returns a
		// hosted-checkout URL the customer is redirected to.
		$body = $this->call( 'POST', 'session', [
			'order' => [
				'intent'             => 'AUTH_AND_CAPTURE',
				'reference_id'       => (string) $order->number,
				'description'        => 'Order ' . $order->number,
				'order_amount'       => [
					'amount_in_cents' => $order->grandTotal->amount,
					'currency'        => $order->grandTotal->currency,
				],
				'requires_shipping_info' => false,
			],
			'checkout_url'   => $this->checkoutSuccessUrl( $order ),
			'cancel_url'     => $this->checkoutCancelUrl( $order ),
		] );

		$session = (array) ( $body['order'] ?? [] );
		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $session['uuid'] ?? '' ),
			clientSecret: '',
			redirectUrl:  (string) ( $body['order']['checkout_url'] ?? '' ),
			raw:          $body,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		$orderId = (string) ( $order->paymentIntentId ?? '' );
		if ( $orderId === '' ) throw new \RuntimeException( 'Order has no Sezzle order id.' );

		$body = $this->call( 'POST', "order/$orderId/refund", [
			'amount_in_cents' => $amount->amount,
			'currency'        => $amount->currency,
		], $idempotencyKey );
		return (string) ( $body['uuid'] ?? '' );
	}

	public function availableBalance(): ?Money {
		// Sezzle pays out to bank — no platform balance to expose.
		return null;
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		throw new \RuntimeException( 'Sezzle settles to your bank on its own ACH cycle — no API payouts.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		// Sezzle signs webhooks via x-sezzle-signature header.
		$sig = $headers['x-sezzle-signature'] ?? $headers['X-Sezzle-Signature'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'counter_sezzle_webhook_secret', '' );
		if ( $secret === '' ) return null;

		$expected = hash_hmac( 'sha256', $rawBody, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'Sezzle webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$type = (string) ( $verified['event_type'] ?? '' );
		$obj  = (array)  ( $verified['data'] ?? [] );
		$kind = match ( $type ) {
			'session.checkout_completed'    => 'payment.succeeded',
			'session.checkout_canceled'     => 'payment.failed',
			'order.refunded'                => 'refund.succeeded',
			'order.payment_failure'         => 'payment.failed',
			default                         => 'unknown',
		};
		$amt = (array) ( $obj['order_amount'] ?? [] );
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['uuid'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $obj['uuid'] ?? '' ),
			amount:          isset( $amt['amount_in_cents'], $amt['currency'] )
				? new Money( (int) $amt['amount_in_cents'], (string) $amt['currency'] )
				: null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function baseUrl(): string {
		return (string) get_option( 'counter_sezzle_environment', 'live' ) === 'sandbox'
			? 'https://sandbox.gateway.sezzle.com/v2/'
			: 'https://gateway.sezzle.com/v2/';
	}

	private function publicKey(): string  { return (string) get_option( 'counter_sezzle_public_key',  '' ); }
	private function privateKey(): string { return (string) get_option( 'counter_sezzle_private_key', '' ); }

	/** Cached for 50 minutes (token TTL is 60). */
	private function accessToken(): string {
		$cached = get_transient( 'counter_sezzle_oauth_token' );
		if ( is_string( $cached ) && $cached !== '' ) return $cached;

		$res = wp_remote_post( $this->baseUrl() . 'authentication', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'public_key'  => $this->publicKey(),
				'private_key' => $this->privateKey(),
			] ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Sezzle auth HTTP error: ' . $res->get_error_message() );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$tok  = (string) ( $body['token'] ?? '' );
		if ( $tok === '' ) throw new \RuntimeException( 'Sezzle auth failed.' );
		set_transient( 'counter_sezzle_oauth_token', $tok, 50 * MINUTE_IN_SECONDS );
		return $tok;
	}

	private function checkoutSuccessUrl( Order $order ): string {
		return rest_url( 'counter/v1/checkout/return?provider=sezzle&order=' . $order->id );
	}
	private function checkoutCancelUrl( Order $order ): string {
		return rest_url( 'counter/v1/checkout/cancel?provider=sezzle&order=' . $order->id );
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
		if ( $idempotencyKey ) $headers['idempotency-key'] = $idempotencyKey;

		$res = wp_remote_request( $this->baseUrl() . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Sezzle HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'Sezzle returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['error']['message'] ?? $json['message'] ?? 'Sezzle API error' );
			throw new \RuntimeException( "Sezzle $code: $msg" );
		}
		return $json;
	}
}
