<?php
/**
 * Shop by Therum — REST: checkout endpoints.
 *
 * Routes (namespace shop/v1):
 *
 *   POST   /shop/v1/checkout/email      — set customer email
 *   POST   /shop/v1/checkout/address    — set ship/bill address
 *   POST   /shop/v1/checkout/gateway    — choose payment provider + method
 *   POST   /shop/v1/checkout/start      — create order + payment intent
 *
 *   (dev-only) POST /shop/v1/mock/succeed/{intent_id}
 *              POST /shop/v1/mock/fail/{intent_id}
 *
 * Identity: shop_cart_token cookie (same as cart endpoints).
 */

namespace Shop\Rest;

use Shop\Models\Order;
use Shop\Models\OrderItem;
use Shop\Payments\MockGateway;
use Shop\Payments\PaymentIntent;
use Shop\Services\CartTokenManager;
use Shop\Services\CheckoutService;
use Shop\Services\WebhookReceiver;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CheckoutController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly CheckoutService $checkout,
		private readonly CartTokenManager $token,
		private readonly WebhookReceiver $webhooks,
	) {}

	public function register(): void {
		$auth = '__return_true';

		register_rest_route( self::NAMESPACE, '/checkout/email', [
			'methods' => 'POST', 'callback' => [ $this, 'setEmail' ], 'permission_callback' => $auth,
			'args' => [ 'email' => [ 'type' => 'string', 'required' => true ] ],
		] );

		register_rest_route( self::NAMESPACE, '/checkout/address', [
			'methods' => 'POST', 'callback' => [ $this, 'setAddress' ], 'permission_callback' => $auth,
			'args' => [
				'kind'    => [ 'type' => 'string', 'required' => true ],
				'address' => [ 'type' => 'object', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/checkout/gateway', [
			'methods' => 'POST', 'callback' => [ $this, 'setGateway' ], 'permission_callback' => $auth,
			'args' => [
				'gateway_id' => [ 'type' => 'string', 'required' => true ],
				'method'     => [ 'type' => 'string', 'required' => false ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/checkout/start', [
			'methods' => 'POST', 'callback' => [ $this, 'startPayment' ], 'permission_callback' => $auth,
		] );

		// ─── Dev-only mock webhook trigger ─────────────────────────────
		// Lets you exercise the full webhook → markPaid → completeSession
		// path without a real PSP. Disabled when SHOP_DEV_MODE constant
		// isn't true. Always behind nonce + permission_callback.
		if ( defined( 'SHOP_DEV_MODE' ) && SHOP_DEV_MODE ) {
			register_rest_route( self::NAMESPACE, '/mock/succeed/(?P<intent>[\w-]+)', [
				'methods' => 'POST', 'callback' => [ $this, 'mockSucceed' ], 'permission_callback' => $auth,
			] );
			register_rest_route( self::NAMESPACE, '/mock/fail/(?P<intent>[\w-]+)', [
				'methods' => 'POST', 'callback' => [ $this, 'mockFail' ], 'permission_callback' => $auth,
			] );
		}
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	public function setEmail( \WP_REST_Request $req ): \WP_REST_Response {
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return $this->error( 'invalid_email', 400 );
		}
		$cart = $this->checkout->setEmail( $this->token->current(), $email );
		return $this->cartResponse( $cart );
	}

	public function setAddress( \WP_REST_Request $req ): \WP_REST_Response {
		$kind    = (string) $req->get_param( 'kind' );
		$address = (array) $req->get_param( 'address' );
		try {
			$cart = $this->checkout->setAddress( $this->token->current(), $kind, $address );
			return $this->cartResponse( $cart );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( $e->getMessage(), 400 );
		}
	}

	public function setGateway( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->checkout->selectGateway(
				$this->token->current(),
				(string) $req->get_param( 'gateway_id' ),
				$req->get_param( 'method' ) !== null ? (string) $req->get_param( 'method' ) : null,
			);
			return $this->cartResponse( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		}
	}

	public function startPayment( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$result = $this->checkout->startPayment( $this->token->current() );
			return new \WP_REST_Response( [
				'order'  => $this->serializeOrder( $result['order'] ),
				'intent' => $result['intent']->toArray(),
			], 200 );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		}
	}

	public function mockSucceed( \WP_REST_Request $req ): \WP_REST_Response {
		$intent = (string) $req->get_param( 'intent' );
		$body   = wp_json_encode( [
			'event_id'  => 'mock_ev_' . bin2hex( random_bytes( 8 ) ),
			'kind'      => 'payment.succeeded',
			'intent_id' => $intent,
		] );
		$result = $this->webhooks->handle( MockGateway::ID, $body, [] );
		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	public function mockFail( \WP_REST_Request $req ): \WP_REST_Response {
		$intent = (string) $req->get_param( 'intent' );
		$body   = wp_json_encode( [
			'event_id'  => 'mock_ev_' . bin2hex( random_bytes( 8 ) ),
			'kind'      => 'payment.failed',
			'intent_id' => $intent,
		] );
		$result = $this->webhooks->handle( MockGateway::ID, $body, [] );
		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private function cartResponse( \Shop\Models\Cart $cart ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'cart' => [
				'id'              => $cart->id,
				'currency'        => $cart->currency,
				'status'          => $cart->status,
				'email'           => $cart->email,
				'subtotal'        => $cart->subtotal->minor,
				'shipping_total'  => $cart->shippingTotal->minor,
				'tax_total'       => $cart->taxTotal->minor,
				'discount_total'  => $cart->discountTotal->minor,
				'grand_total'     => $cart->grandTotal->minor,
				'grand_total_fmt' => $cart->grandTotal->format(),
				'ship_address'    => $cart->shipAddress,
				'bill_address'    => $cart->billAddress,
				'payment_provider'=> $cart->paymentProvider,
				'payment_method'  => $cart->paymentMethod,
				'item_count'      => $cart->itemCount(),
			],
		], 200 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serializeOrder( Order $order ): array {
		return [
			'id'                => $order->id,
			'number'            => $order->number,
			'status'            => $order->status,
			'email'             => $order->email,
			'currency'          => $order->currency,
			'grand_total'       => $order->grandTotal->minor,
			'grand_total_fmt'   => $order->grandTotal->format(),
			'payment_provider'  => $order->paymentProvider,
			'payment_method'    => $order->paymentMethod,
			'payment_intent_id' => $order->paymentIntentId,
			'items'             => array_map( fn( OrderItem $i ): array => [
				'id'        => $i->id,
				'title'     => $i->title,
				'sku'       => $i->sku,
				'qty'       => $i->quantity,
				'unit'      => $i->unitPrice->minor,
				'unit_fmt'  => $i->unitPrice->format(),
				'line'      => $i->lineTotal->minor,
				'line_fmt'  => $i->lineTotal->format(),
			], $order->items ),
		];
	}

	private function error( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'error' => [ 'message' => $message ] ], $status );
	}
}
