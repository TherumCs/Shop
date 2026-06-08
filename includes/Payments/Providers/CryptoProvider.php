<?php
/**
 * Counter by Therum — CryptoProvider (AnyPay).
 *
 * Crypto checkout via AnyPay's payment-invoice API. Customer scans a QR
 * for BTC / ETH / USDC / USDT / SOL / XRP (50+ coins supported); AnyPay
 * confirms on-chain and webhooks us "paid."
 *
 * Settlement: per the merchant's AnyPay settings — either auto-convert
 * to USD and ACH to bank, OR hold as crypto in a wallet they control.
 * The merchant configures this in AnyPay's dashboard, not here.
 *
 * Auth: BYO API key from anypayinc.com. Sandbox via
 * `counter_anypay_environment=sandbox`.
 *
 * Reference: https://anypayinc.com/docs
 */

namespace Counter\Payments\Providers;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CryptoProvider implements PaymentProvider {

	public function id(): string          { return 'crypto'; }
	public function displayName(): string { return 'Crypto (AnyPay)'; }

	public function supportedMethods(): array {
		// One method per coin so the merchant can enable individual
		// chains. The base `crypto` id stays accepted as a "let the
		// customer pick" fallback for any legacy code paths.
		return [
			'crypto',
			'crypto_btc', 'crypto_eth', 'crypto_usdc', 'crypto_usdt',
			'crypto_sol', 'crypto_xrp',
			'crypto_link', 'crypto_xlm', 'crypto_hbar',
		];
	}

	/** Canonical method id → AnyPay coin code. */
	private const COIN_MAP = [
		'crypto_btc'  => 'BTC',
		'crypto_eth'  => 'ETH',
		'crypto_usdc' => 'USDC',
		'crypto_usdt' => 'USDT',
		'crypto_sol'  => 'SOL',
		'crypto_xrp'  => 'XRP',
		'crypto_link' => 'LINK',
		'crypto_xlm'  => 'XLM',
		'crypto_hbar' => 'HBAR',
	];

	public function isConnected(): bool { return $this->apiKey() !== ''; }

	public function createIntent( Order $order, string $method ): PaymentIntent {
		// AnyPay's "invoice" is a QR + watch-address. We post the order
		// amount in USD; AnyPay quotes the equivalent in whatever coin
		// the customer picks at pay-time — or the specific coin when the
		// merchant picked a single-chain method.
		$payload = [
			'currency'    => $order->grandTotal->currency,
			'amount'      => $order->grandTotal->amount / 100,
			'reference'   => (string) $order->number,
			'description' => 'Order ' . $order->number,
			'success_url' => $this->returnUrl( $order ),
			'webhook_url' => rest_url( 'counter/v1/webhooks/crypto' ),
		];
		if ( isset( self::COIN_MAP[ $method ] ) ) {
			$payload['coin'] = self::COIN_MAP[ $method ];
		}
		$body = $this->call( 'POST', 'invoices', $payload );

		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $body['uid'] ?? $body['id'] ?? '' ),
			clientSecret: '',
			redirectUrl:  (string) ( $body['payment_url'] ?? $body['url'] ?? '' ),
			raw:          $body,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		// Crypto refunds are typically off-chain — the merchant sends back
		// to the customer's submitted refund address. AnyPay does not have
		// an automatic refund endpoint. Surface a clear error.
		throw new \RuntimeException(
			'Crypto refunds must be issued manually via your AnyPay dashboard. ' .
			'On-chain reversals are not possible — confirm the customer\'s refund address first.'
		);
	}

	public function availableBalance(): ?Money {
		// AnyPay holds funds only briefly during settlement; balance is in
		// the merchant's connected wallets, not on AnyPay. Return null.
		return null;
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		throw new \RuntimeException( 'Crypto payouts are managed in your AnyPay merchant dashboard.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		$sig = $headers['x-anypay-signature'] ?? $headers['X-AnyPay-Signature'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'counter_anypay_webhook_secret', '' );
		if ( $secret === '' ) return null;

		$expected = hash_hmac( 'sha256', $rawBody, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'AnyPay webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$status = (string) ( $verified['status'] ?? $verified['event'] ?? '' );
		$kind = match ( strtolower( $status ) ) {
			'paid', 'confirmed', 'invoice.paid'   => 'payment.succeeded',
			'failed', 'expired', 'invoice.failed' => 'payment.failed',
			'refunded'                            => 'refund.succeeded',
			default                               => 'unknown',
		};
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['id'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $verified['uid'] ?? $verified['invoice_id'] ?? '' ),
			amount:          isset( $verified['amount'], $verified['currency'] )
				? new Money( (int) round( (float) $verified['amount'] * 100 ), (string) $verified['currency'] )
				: null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function baseUrl(): string {
		return (string) get_option( 'counter_anypay_environment', 'live' ) === 'sandbox'
			? 'https://test.anypayinc.com/api/'
			: 'https://anypayinc.com/api/';
	}

	private function apiKey(): string { return (string) get_option( 'counter_anypay_api_key', '' ); }

	private function returnUrl( Order $order ): string {
		return rest_url( 'counter/v1/checkout/return?provider=crypto&order=' . $order->id );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call( string $method, string $path, array $params ): array {
		$res = wp_remote_request( $this->baseUrl() . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey(),
				'Content-Type'  => 'application/json',
			],
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'AnyPay HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'AnyPay returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['error']['message'] ?? $json['message'] ?? 'AnyPay API error' );
			throw new \RuntimeException( "AnyPay $code: $msg" );
		}
		return $json;
	}
}
