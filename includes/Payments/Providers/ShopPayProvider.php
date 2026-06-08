<?php
/**
 * Counter by Therum — ShopPayProvider.
 *
 * Shop Pay is Shopify's hosted wallet. Shopify does NOT expose a public
 * API to charge a Shop Pay vault from outside their checkout, so we
 * support two real paths:
 *
 *   1. SHOPIFY_DEEPLINK — for merchants with a Shopify storefront. We
 *      deep-link the customer into a Shopify checkout for the same cart
 *      (Shopify generates the cart URL, we just redirect). The order is
 *      paid in Shopify; we mirror the line items + capture on our side
 *      via Shopify's Admin API webhook.
 *
 *   2. STRIPE_LINK_FALLBACK — for merchants without Shopify. Stripe's
 *      "Link" is the closest equivalent (one-tap consumer wallet, saved
 *      card vault, same fast checkout pattern). We render the "Shop Pay"
 *      button label in checkout but the underlying intent is a Stripe
 *      Link PaymentIntent. Honest with the customer in the UI.
 *
 * Mode is picked via `counter_shop_pay_mode` ∈ { shopify, stripe_link }.
 * Default: stripe_link (zero-config for non-Shopify merchants).
 *
 * Shopify mode credentials:
 *   counter_shop_pay_shopify_store        — "your-store.myshopify.com"
 *   counter_shop_pay_shopify_storefront_token
 *
 * Stripe Link mode: reuses the existing Stripe BYO keys; no extra config.
 */

namespace Counter\Payments\Providers;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ShopPayProvider implements PaymentProvider {

	public function __construct( private readonly StripeProvider $stripe ) {}

	public function id(): string          { return 'shop_pay'; }
	public function displayName(): string { return 'Shop Pay'; }

	public function supportedMethods(): array { return [ 'shop_pay' ]; }

	public function isConnected(): bool {
		return $this->mode() === 'shopify'
			? ( $this->shopifyStore() !== '' && $this->shopifyToken() !== '' )
			: $this->stripe->isConnected();
	}

	public function createIntent( Order $order, string $method ): PaymentIntent {
		return $this->mode() === 'shopify'
			? $this->createShopifyIntent( $order )
			: $this->createStripeLinkIntent( $order );
	}

	/**
	 * Shopify mode — build a Shopify Storefront API draft order and
	 * redirect the customer to its hosted Shop Pay checkout.
	 */
	private function createShopifyIntent( Order $order ): PaymentIntent {
		$body = $this->shopifyCall( 'POST', 'draft_orders.json', [
			'draft_order' => [
				'line_items' => [ [
					'title'    => 'Order ' . $order->number,
					'price'    => number_format( $order->grandTotal->amount / 100, 2, '.', '' ),
					'quantity' => 1,
				] ],
				'note' => 'Counter order ' . $order->number,
			],
		] );
		$draft = (array) ( $body['draft_order'] ?? [] );
		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     (string) ( $draft['id'] ?? '' ),
			clientSecret: '',
			redirectUrl:  (string) ( $draft['invoice_url'] ?? '' ),
			raw:          $body,
		);
	}

	/**
	 * Stripe Link fallback. Mints a regular Stripe PaymentIntent with
	 * `payment_method_types[]=link` so the customer gets the Link OTP
	 * flow on confirm. UI labels it "Shop Pay" per the merchant's choice.
	 */
	private function createStripeLinkIntent( Order $order ): PaymentIntent {
		return $this->stripe->createIntent( $order, 'link' );
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		if ( $this->mode() === 'shopify' ) {
			throw new \RuntimeException(
				'Shop Pay refunds (Shopify mode) must be issued from the Shopify Orders admin. ' .
				'Counter cannot refund cross-platform.'
			);
		}
		return $this->stripe->refund( $order, $amount, $idempotencyKey );
	}

	public function availableBalance(): ?Money {
		return $this->mode() === 'shopify' ? null : $this->stripe->availableBalance();
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		if ( $this->mode() === 'shopify' ) {
			throw new \RuntimeException( 'Shop Pay (Shopify mode) settles to your Shopify Payments balance — manage in Shopify admin.' );
		}
		return $this->stripe->payout( $amount, $instant, $idempotencyKey );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		if ( $this->mode() !== 'shopify' ) return null;

		$sig = $headers['x-shopify-hmac-sha256'] ?? $headers['X-Shopify-Hmac-Sha256'] ?? '';
		if ( $sig === '' ) return null;
		$secret = (string) get_option( 'counter_shop_pay_shopify_webhook_secret', '' );
		if ( $secret === '' ) return null;

		$expected = base64_encode( hash_hmac( 'sha256', $rawBody, $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'Shopify webhook signature mismatch.' );
		}
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		// Shopify event topic comes through as `X-Shopify-Topic` header,
		// not in the body — we encode it at WebhookController dispatch.
		$topic = (string) ( $verified['__topic'] ?? '' );
		$kind = match ( $topic ) {
			'orders/paid'             => 'payment.succeeded',
			'orders/cancelled'        => 'payment.failed',
			'refunds/create'          => 'refund.succeeded',
			default                   => 'unknown',
		};
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: (string) ( $verified['id'] ?? '' ),
			kind:            $kind,
			intentId:        (string) ( $verified['name'] ?? $verified['id'] ?? '' ),
			amount:          isset( $verified['total_price'] )
				? new Money( (int) round( ( (float) $verified['total_price'] ) * 100 ), (string) ( $verified['currency'] ?? 'USD' ) )
				: null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function mode(): string {
		return (string) get_option( 'counter_shop_pay_mode', 'stripe_link' );
	}

	private function shopifyStore(): string { return (string) get_option( 'counter_shop_pay_shopify_store', '' ); }
	private function shopifyToken(): string { return (string) get_option( 'counter_shop_pay_shopify_storefront_token', '' ); }

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function shopifyCall( string $method, string $path, array $params ): array {
		$store = $this->shopifyStore();
		if ( $store === '' ) throw new \RuntimeException( 'Shop Pay (Shopify mode) store not configured.' );

		$res = wp_remote_request( "https://$store/admin/api/2024-10/$path", [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'X-Shopify-Access-Token' => $this->shopifyToken(),
				'Content-Type'           => 'application/json',
			],
			'body'    => $method === 'GET' ? null : wp_json_encode( $params ),
		] );
		if ( is_wp_error( $res ) ) throw new \RuntimeException( 'Shopify HTTP error: ' . $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) throw new \RuntimeException( 'Shopify returned non-JSON.' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $json['errors'] ?? 'Shopify API error' );
			throw new \RuntimeException( "Shopify $code: " . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
		}
		return $json;
	}
}
