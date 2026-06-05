<?php
/**
 * Shop by Therum — REST: cart endpoints.
 *
 * Routes (namespace shop/v1):
 *
 *   GET    /shop/v1/cart           — full cart + rendered HTML
 *   GET    /shop/v1/cart/count     — { count } — header-badge cheap path
 *   POST   /shop/v1/cart/items     — add item       (product_id, variant_id?, quantity)
 *   PATCH  /shop/v1/cart/items/{id} — set quantity   (quantity)
 *   DELETE /shop/v1/cart/items/{id} — remove
 *
 * Every cart-mutating response carries the full updated cart payload AND
 * the rendered contents.php HTML, so the client morphs the DOM in one
 * round-trip — no extra "refresh" call after the mutation.
 *
 * Every response sets:
 *   X-Shop-Cart-Count: <n>   for header-badge updaters anywhere on the site
 *
 * Auth: anonymous. Identity is the shop_cart_token cookie. The cart
 * belongs to whoever holds the cookie; no user account needed.
 *
 * CSRF: WP REST nonce (?_wpnonce=) for state-changing methods. The cart
 * JS reads `wpApiSettings.nonce` from the page boot.
 */

namespace Shop\Rest;

use Shop\Models\Cart;
use Shop\Models\CartItem;
use Shop\Services\CartRenderer;
use Shop\Services\CartService;
use Shop\Services\CartTokenManager;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly CartService $cart,
		private readonly CartRenderer $renderer,
		private readonly CartTokenManager $token,
		private readonly \Shop\Services\CouponService $coupons,
	) {}

	public function register(): void {
		register_rest_route( self::NAMESPACE, '/cart', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/cart/count', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'count' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/cart/items', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'addItem' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'product_id' => [ 'type' => 'integer', 'required' => true ],
				'variant_id' => [ 'type' => 'integer', 'required' => false ],
				'quantity'   => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/cart/items/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'updateItem' ],
				'permission_callback' => '__return_true',
				'args' => [
					'quantity' => [ 'type' => 'integer', 'required' => true ],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'deleteItem' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( self::NAMESPACE, '/cart/coupons', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'applyCoupon' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'code' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/cart/coupons/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'removeCoupon' ],
			'permission_callback' => '__return_true',
		] );

		// Variant resolution for the variant-picker element. Given a
		// product + option selection, returns the matching variant_id
		// or null. Public, no auth — this is a read-only catalog query.
		register_rest_route( self::NAMESPACE, '/products/match-variant', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'matchVariant' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function matchVariant( \WP_REST_Request $req ): \WP_REST_Response {
		$product_id = (int) $req->get_param( 'product_id' );
		$options    = (array) $req->get_param( 'options' );
		if ( $product_id <= 0 || ! $options ) {
			return new \WP_REST_Response( [ 'variant_id' => null ], 200 );
		}
		$attributes = \Shop\Container::instance()->get( \Shop\Repositories\AttributeRepository::class );
		$variant_id = $attributes->matchVariant( $product_id, array_map( 'strval', $options ) );
		return new \WP_REST_Response( [ 'variant_id' => $variant_id ], 200 );
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$cart = $this->cart->getOrCreate( $this->token->current() );
		return $this->respond( $cart );
	}

	public function count( \WP_REST_Request $req ): \WP_REST_Response {
		$token = $this->token->read();
		$count = 0;
		if ( $token !== null ) {
			$cart  = $this->cart->getOrCreate( $token );
			$count = $cart->itemCount();
		}
		$res = new \WP_REST_Response( [ 'count' => $count ], 200 );
		$res->header( 'X-Shop-Cart-Count', (string) $count );
		return $res;
	}

	public function addItem( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->cart->addItem(
				token:     $this->token->current(),
				productId: (int) $req->get_param( 'product_id' ),
				variantId: $req->get_param( 'variant_id' ) !== null ? (int) $req->get_param( 'variant_id' ) : null,
				quantity:  max( 1, (int) $req->get_param( 'quantity' ) ),
			);
			return $this->respond( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( $e->getMessage(), 400 );
		}
	}

	public function updateItem( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->cart->updateQuantity(
				token:    $this->token->current(),
				itemId:   (int) $req->get_param( 'id' ),
				quantity: (int) $req->get_param( 'quantity' ),
			);
			return $this->respond( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( $e->getMessage(), 400 );
		}
	}

	public function deleteItem( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->cart->removeItem(
				token:  $this->token->current(),
				itemId: (int) $req->get_param( 'id' ),
			);
			return $this->respond( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		}
	}

	public function applyCoupon( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->cart->getOrCreate( $this->token->current() );
			$cart = $this->coupons->apply( $cart, (string) $req->get_param( 'code' ) );
			// Recalc to pick up the new discount.
			$cart = $this->cart->recalc( $cart );
			return $this->respond( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		}
	}

	public function removeCoupon( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$cart = $this->cart->getOrCreate( $this->token->current() );
			$cart = $this->coupons->remove( $cart, (int) $req->get_param( 'id' ) );
			$cart = $this->cart->recalc( $cart );
			return $this->respond( $cart );
		} catch ( \DomainException $e ) {
			return $this->error( $e->getMessage(), 422 );
		}
	}

	// ─── Response builders ───────────────────────────────────────────────

	private function respond( Cart $cart ): \WP_REST_Response {
		$res = new \WP_REST_Response( [
			'cart' => $this->serialize( $cart ),
			'html' => $this->renderer->contents( $cart ),
		], 200 );
		$res->header( 'X-Shop-Cart-Count', (string) $cart->itemCount() );
		return $res;
	}

	private function error( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'error' => [ 'message' => $message ],
		], $status );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function serialize( Cart $cart ): array {
		return [
			'id'              => $cart->id,
			'token'           => $cart->token,
			'currency'        => $cart->currency,
			'status'          => $cart->status,
			'subtotal'        => $cart->subtotal->minor,
			'subtotal_fmt'    => $cart->subtotal->format(),
			'discount_total'  => $cart->discountTotal->minor,
			'shipping_total'  => $cart->shippingTotal->minor,
			'tax_total'       => $cart->taxTotal->minor,
			'grand_total'     => $cart->grandTotal->minor,
			'grand_total_fmt' => $cart->grandTotal->format(),
			'item_count'      => $cart->itemCount(),
			'items'           => array_map( fn( CartItem $i ): array => [
				'id'         => $i->id,
				'product_id' => $i->productId,
				'variant_id' => $i->variantId,
				'quantity'   => $i->quantity,
				'unit_price' => $i->unitPrice->minor,
				'unit_price_fmt' => $i->unitPrice->format(),
				'line_total' => $i->lineTotal->minor,
				'line_total_fmt' => $i->lineTotal->format(),
			], $cart->items ),
		];
	}
}
