<?php
/**
 * Shop by Therum — SquareProvider.
 *
 * Covers methods that Square does better than Stripe:
 *   - cashapp  (Square owns Cash App Pay — native flow)
 *   - card     (BYO Square accounts that want their own rates)
 *   - afterpay (Square is Afterpay's parent now)
 *
 * Square's API is REST + JSON. No SDK — saves bundle and keeps version
 * upgrades trivial. Access token comes from BYO `shop_square_access_token`
 * or, in Studio Pay Connect mode, from `shop_studio_pay_square_token`.
 */

namespace Shop\Payments\Providers;

use Shop\Models\Order;
use Shop\Money;
use Shop\Payments\PaymentIntent;
use Shop\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SquareProvider implements PaymentProvider {

	private const API     = 'https://connect.squareup.com/v2/';
	private const VERSION = '2025-10-15';

	public function id(): string          { return 'square'; }
	public function displayName(): string { return 'Square'; }

	public function supportedMethods(): array {
		return [ 'card', 'cashapp', 'afterpay' ];
	}

	public function isConnected(): bool {
		return $this->accessToken() !== '';
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		// Square uses "Payment Links" for online flows that mirror
		// Stripe's PaymentIntent + client_secret pattern.
		$body = $this->call( 'POST', 'online-checkout/payment-links', [
			'idempotency_key' => 'order-' . $order->id . '-' . $method,
			'quick_pay' => [
				'name'        => 'Order ' . $order->number,
				'price_money' => [
					'amount'   => $order->grandTotal->amount,
					'currency' => $order->grandTotal->currency,
				],
				'location_id' => $this->locationId(),
			],
			'payment_note' => 'Method: ' . $method,
		] );
		$link = $body['payment_link'] ?? [];
		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $link['id'] ?? '' ),
			clientSecret: '',
			redirectUrl:  (string) ( $link['url'] ?? '' ),
			raw:          $body,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		$intentId = (string) ( $order->paymentIntentId ?? '' );
		if ( $intentId === '' ) throw new \RuntimeException( 'Order has no Square payment id.' );
		$body = $this->call( 'POST', 'refunds', [
			'idempotency_key' => $idempotencyKey,
			'amount_money'    => [ 'amount' => $amount->amount, 'currency' => $amount->currency ],
			'payment_id'      => $intentId,
		] );
		return (string) ( $body['refund']['id'] ?? '' );
	}

	public function availableBalance(): ?Money {
		// Square doesn't expose a single "available" balance via REST —
		// merchants check via dashboard. Return null so the Payouts
		// service falls back to "balance unknown" UX.
		return null;
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		// Square auto-deposits daily; instant is opt-in per merchant on
		// their Square dashboard, not via API. Return a sentinel so the
		// Payouts service surfaces a "configure in Square" message.
		throw new \RuntimeException( 'Square instant deposits are configured per-merchant in the Square dashboard.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		$sig = $headers['x-square-hmacsha256-signature'] ?? $headers['X-Square-HmacSha256-Signature'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'shop_square_webhook_secret', '' );
		if ( $secret === '' ) return null;
		$url    = rest_url( 'shop/v1/webhooks/square' );
		$expected = base64_encode( hash_hmac( 'sha256', $url . $rawBody, $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'Square webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$type = (string) ( $verified['type'] ?? '' );
		$obj  = (array)  ( $verified['data']['object'] ?? [] );
		$kind = match ( $type ) {
			'payment.created', 'payment.updated' => str_contains( strtolower( (string) ( $obj['payment']['status'] ?? '' ) ), 'completed' ) ? 'payment.succeeded' : 'payment.pending',
			'refund.created', 'refund.updated'   => 'refund.succeeded',
			'dispute.created'                    => 'dispute.opened',
			default                              => 'unknown',
		};
		$payment = (array) ( $obj['payment'] ?? [] );
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['event_id'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $payment['id'] ?? '' ),
			amount:          isset( $payment['amount_money']['amount'] ) ? new Money( (int) $payment['amount_money']['amount'], (string) ( $payment['amount_money']['currency'] ?? 'USD' ) ) : null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function accessToken(): string {
		return (string) get_option( 'shop_studio_pay_square_token', '' )
			?: (string) get_option( 'shop_square_access_token', '' );
	}

	private function locationId(): string {
		return (string) get_option( 'shop_square_location_id', '' );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call( string $method, string $path, array $params ): array {
		$token = $this->accessToken();
		if ( $token === '' ) throw new \RuntimeException( 'Square not configured.' );
		$res = wp_remote_request( self::API . $path, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization'    => 'Bearer ' . $token,
				'Square-Version'   => self::VERSION,
				'Content-Type'     => 'application/json',
			],
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Square HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'Square returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['errors'][0]['detail'] ?? 'Square API error' );
			throw new \RuntimeException( "Square $code: $msg" );
		}
		return $json;
	}
}
