<?php
/**
 * Counter by Therum — ZipProvider.
 *
 * Zip (formerly Quadpay) — 4 installments, "$1 per installment" model.
 * Direct integration via Zip's Checkout API. Settles to your bank on
 * Zip's own ACH schedule (T+2 typical).
 *
 * Why not piggyback Stripe? Stripe deprecated Zip as a payment_method_type
 * mid-2024; merchants who want Zip now wire it directly.
 *
 * Auth: BYO `counter_zip_merchant_id` + `counter_zip_api_key` from the
 * Zip Merchant Dashboard. Sandbox via `counter_zip_environment=sandbox`.
 *
 * Reference: https://developers.zip.co/
 */

namespace Counter\Payments\Providers;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ZipProvider implements PaymentProvider {

	public function id(): string          { return 'zip'; }
	public function displayName(): string { return 'Zip'; }

	public function supportedMethods(): array { return [ 'zip' ]; }

	public function isConnected(): bool {
		return $this->merchantId() !== '' && $this->apiKey() !== '';
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		// Zip Checkouts API → hosted-checkout URL pattern.
		$body = $this->call( 'POST', 'checkouts', [
			'type'              => 'standard',
			'shopper'           => [
				'first_name' => (string) ( $order->billAddress['first_name'] ?? '' ),
				'last_name'  => (string) ( $order->billAddress['last_name']  ?? '' ),
				'email'      => (string) ( $order->email ?? '' ),
			],
			'order'             => [
				'reference' => (string) $order->number,
				'amount'    => number_format( $order->grandTotal->amount / 100, 2, '.', '' ),
				'currency'  => $order->grandTotal->currency,
			],
			'config' => [
				'redirect_uri' => $this->returnUrl( $order ),
			],
		] );

		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $body['id'] ?? '' ),
			clientSecret: '',
			redirectUrl:  (string) ( $body['uri'] ?? '' ),
			raw:          $body,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		$chargeId = (string) ( $order->paymentIntentId ?? '' );
		if ( $chargeId === '' ) throw new \RuntimeException( 'Order has no Zip charge id.' );

		$body = $this->call( 'POST', 'refunds', [
			'charge_id' => $chargeId,
			'reason'    => 'requested_by_customer',
			'amount'    => number_format( $amount->amount / 100, 2, '.', '' ),
			'currency'  => $amount->currency,
		], $idempotencyKey );
		return (string) ( $body['id'] ?? '' );
	}

	public function availableBalance(): ?Money { return null; }

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		throw new \RuntimeException( 'Zip settles directly to your bank on its ACH cycle — no API payouts.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		$sig = $headers['zip-signature'] ?? $headers['Zip-Signature'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'counter_zip_webhook_secret', '' );
		if ( $secret === '' ) return null;

		$expected = hash_hmac( 'sha256', $rawBody, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'Zip webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$type = (string) ( $verified['event_type'] ?? '' );
		$kind = match ( $type ) {
			'charge.captured'  => 'payment.succeeded',
			'charge.cancelled', 'charge.declined' => 'payment.failed',
			'refund.created'   => 'refund.succeeded',
			default            => 'unknown',
		};
		$data = (array) ( $verified['data'] ?? [] );
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['id'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $data['id'] ?? '' ),
			amount:          isset( $data['amount'], $data['currency'] )
				? new Money( (int) round( (float) $data['amount'] * 100 ), (string) $data['currency'] )
				: null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function baseUrl(): string {
		return (string) get_option( 'counter_zip_environment', 'live' ) === 'sandbox'
			? 'https://api.sandbox.zipmoney.com.au/merchant/v1/'
			: 'https://api.zipmoney.com.au/merchant/v1/';
	}

	private function merchantId(): string { return (string) get_option( 'counter_zip_merchant_id', '' ); }
	private function apiKey(): string     { return (string) get_option( 'counter_zip_api_key',      '' ); }

	private function returnUrl( Order $order ): string {
		return rest_url( 'counter/v1/checkout/return?provider=zip&order=' . $order->id );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call( string $method, string $path, array $params, ?string $idempotencyKey = null ): array {
		$headers = [
			'Authorization'  => 'Bearer ' . $this->apiKey(),
			'Content-Type'   => 'application/json',
			'Zip-Version'    => '2024-08-01',
			'Zip-Merchant-Id'=> $this->merchantId(),
		];
		if ( $idempotencyKey ) $headers['Idempotency-Key'] = $idempotencyKey;

		$res = wp_remote_request( $this->baseUrl() . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Zip HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'Zip returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['error']['message'] ?? $json['message'] ?? 'Zip API error' );
			throw new \RuntimeException( "Zip $code: $msg" );
		}
		return $json;
	}
}
