<?php
/**
 * Shop by Therum — StripeProvider.
 *
 * Wraps Stripe via raw HTTP — no SDK. Saves ~600KB of vendor code and
 * means upgrades are a config bump, not a Composer dance.
 *
 * Studio Pay routes the broadest set of methods through Stripe:
 *   card, apple_pay, google_pay, link, klarna, affirm, afterpay,
 *   bank_ach (via Financial Connections), shop_pay (Link fallback).
 *
 * Two ways the provider can be configured:
 *   1. BYO keys  — merchant pasted their own publishable + secret keys
 *   2. Connect    — Studio Pay OAuth'd the merchant onto our platform
 *                   account and stored `stripe_account_id`. Every API
 *                   call adds a Stripe-Account header to scope to them.
 *
 * Detection is automatic — if `shop_studio_pay_stripe_account_id` is
 * set we're in Connect mode, else BYO.
 */

namespace Shop\Payments\Providers;

use Shop\Models\Order;
use Shop\Money;
use Shop\Payments\PaymentIntent;
use Shop\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StripeProvider implements PaymentProvider {

	private const API     = 'https://api.stripe.com/v1/';
	private const VERSION = '2025-09-30';

	public function id(): string          { return 'stripe'; }
	public function displayName(): string { return 'Stripe'; }

	/**
	 * Canonical method id → Stripe `payment_method_types[]` value.
	 * Listed in the order Stripe expects to receive them.
	 */
	private const METHOD_MAP = [
		'card'         => 'card',
		'apple_pay'    => 'card',          // wallet via PaymentRequest
		'google_pay'   => 'card',          // wallet via PaymentRequest
		'link'         => 'link',
		'shop_pay'     => 'link',          // SPC fallback to Link
		'klarna'       => 'klarna',
		'affirm'       => 'affirm',
		'afterpay'     => 'afterpay_clearpay',
		'zip'          => 'zip',
		'bank_ach'     => 'us_bank_account',
		'cashapp'      => 'cashapp',
	];

	public function supportedMethods(): array {
		return array_keys( self::METHOD_MAP );
	}

	public function isConnected(): bool {
		return $this->secretKey() !== '';
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		if ( ! isset( self::METHOD_MAP[ $method ] ) ) {
			throw new \InvalidArgumentException( "StripeProvider does not support method '$method'." );
		}
		$type = self::METHOD_MAP[ $method ];
		$body = $this->call( 'POST', 'payment_intents', [
			'amount'                 => $order->grandTotal->amount,
			'currency'               => strtolower( $order->grandTotal->currency ),
			'payment_method_types[]' => $type,
			'metadata[order_id]'     => (string) $order->id,
			'metadata[order_number]' => (string) $order->number,
			'metadata[method]'       => $method,
		] );
		return new PaymentIntent(
			providerId: $this->id(),
			intentId:   (string) ( $body['id'] ?? '' ),
			clientSecret: (string) ( $body['client_secret'] ?? '' ),
			redirectUrl: null,
			raw:         $body,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		$intentId = (string) ( $order->paymentIntentId ?? '' );
		if ( $intentId === '' ) throw new \RuntimeException( 'Order has no Stripe intent id.' );
		$body = $this->call( 'POST', 'refunds', [
			'payment_intent' => $intentId,
			'amount'         => $amount->amount,
			'reason'         => 'requested_by_customer',
		], $idempotencyKey );
		return (string) ( $body['id'] ?? '' );
	}

	public function availableBalance(): ?Money {
		$body = $this->call( 'GET', 'balance', [] );
		$cents = 0;
		foreach ( (array) ( $body['available'] ?? [] ) as $row ) {
			$cents += (int) ( $row['amount'] ?? 0 );
		}
		return new Money( $cents, 'USD' );
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		$body = $this->call( 'POST', 'payouts', array_filter( [
			'amount'   => $amount->amount,
			'currency' => strtolower( $amount->currency ),
			'method'   => $instant ? 'instant' : 'standard',
		] ), $idempotencyKey );
		return (string) ( $body['id'] ?? '' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		$sig = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'shop_studio_pay_stripe_webhook_secret', '' );
		if ( $secret === '' ) return null;

		// Parse "t=...,v1=..." format
		$parts = [];
		foreach ( explode( ',', $sig ) as $kv ) {
			[ $k, $v ] = array_pad( explode( '=', $kv, 2 ), 2, '' );
			$parts[ trim( $k ) ] = trim( $v );
		}
		$ts = $parts['t'] ?? '';
		$v1 = $parts['v1'] ?? '';
		if ( $ts === '' || $v1 === '' ) throw new \RuntimeException( 'Stripe webhook signature malformed.' );

		$signed = $ts . '.' . $rawBody;
		$expected = hash_hmac( 'sha256', $signed, $secret );
		if ( ! hash_equals( $expected, $v1 ) ) {
			throw new \RuntimeException( 'Stripe webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$type = (string) ( $verified['type'] ?? '' );
		$obj  = (array)  ( $verified['data']['object'] ?? [] );

		$kind = match ( $type ) {
			'payment_intent.succeeded'                 => 'payment.succeeded',
			'payment_intent.payment_failed'            => 'payment.failed',
			'charge.refunded', 'refund.created'        => 'refund.succeeded',
			'charge.dispute.created'                   => 'dispute.opened',
			'payout.paid'                              => 'payout.paid',
			'payout.failed'                            => 'payout.failed',
			default                                    => 'unknown',
		};
		return new WebhookEvent(
			providerId:    $this->id(),
			providerEventId: (string) ( $verified['id'] ?? '' ),
			kind:          $kind,
			intentId:      (string) ( $obj['payment_intent'] ?? $obj['id'] ?? '' ),
			amount:        isset( $obj['amount'] ) ? new Money( (int) $obj['amount'], strtoupper( (string) ( $obj['currency'] ?? 'USD' ) ) ) : null,
			raw:           $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function secretKey(): string {
		// Studio Pay Connect mode — secret is our platform key, the
		// connected account is targeted via the Stripe-Account header.
		if ( $this->connectAccountId() !== '' ) {
			return (string) get_option( 'shop_studio_pay_platform_secret', '' );
		}
		// BYO mode — merchant's own secret.
		return (string) get_option( 'shop_stripe_secret_key', '' );
	}

	private function connectAccountId(): string {
		return (string) get_option( 'shop_studio_pay_stripe_account_id', '' );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call( string $method, string $path, array $params, ?string $idempotencyKey = null ): array {
		$secret = $this->secretKey();
		if ( $secret === '' ) throw new \RuntimeException( 'Stripe not configured.' );

		$headers = [
			'Authorization'    => 'Bearer ' . $secret,
			'Stripe-Version'   => self::VERSION,
			'Content-Type'     => 'application/x-www-form-urlencoded',
		];
		if ( $this->connectAccountId() !== '' ) {
			$headers['Stripe-Account'] = $this->connectAccountId();
		}
		if ( $idempotencyKey ) $headers['Idempotency-Key'] = $idempotencyKey;

		$url  = self::API . $path;
		$body = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		if ( $method === 'GET' ) {
			$url .= ( $params ? '?' . $body : '' );
			$body = null;
		}

		$res = wp_remote_request( $url, [
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Stripe HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'Stripe returned non-JSON.' );

		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['error']['message'] ?? 'Stripe API error' );
			throw new \RuntimeException( "Stripe $code: $msg" );
		}
		return $json;
	}
}
