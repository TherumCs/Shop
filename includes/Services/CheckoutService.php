<?php
/**
 * Shop by Therum — CheckoutService.
 *
 * Orchestrates the cart → checkout → payment-intent → order handoff. Sits
 * above CartService and OrderService.
 *
 * v1 flow (simple physical product, no variants, no real shipping/tax):
 *
 *   1. setAddress(token, kind, address) — writes address JSON to the session,
 *      transitions session.status to 'checkout' if still 'cart'.
 *   2. setEmail(token, email)            — persists customer email on session.
 *   3. selectGateway(token, gatewayId)   — records which provider will charge.
 *   4. startPayment(token)               — finalizes a draft Order from the
 *      cart, asks the gateway for a PaymentIntent, persists the intent_id
 *      on the order, returns intent + order to the client. Order is
 *      `pending` at this point; webhook flips it to `processing`.
 *
 * Shipping/tax quotes (per-vendor) are stubbed for v1 — the totals
 * pipeline produces zero for both. Real quotes plug in at milestone #2.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Models\Cart;
use Shop\Models\Order;
use Shop\Payments\PSPGateway;
use Shop\Payments\PaymentIntent;
use Shop\Repositories\OrderRepository;
use Shop\Repositories\PaymentGatewayRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CheckoutService {

	public function __construct(
		private readonly CartService $cart,
		private readonly OrderService $orders,
		private readonly OrderRepository $orderRepo,
		private readonly PaymentGatewayRegistry $gateways,
	) {}

	public function setAddress( string $token, string $kind, array $address ): Cart {
		if ( ! in_array( $kind, [ 'ship', 'bill' ], true ) ) {
			throw new \InvalidArgumentException( "Address kind must be 'ship' or 'bill', got: {$kind}" );
		}
		$cart = $this->cart->getOrCreate( $token );

		$column = $kind === 'ship' ? 'ship_address' : 'bill_address';
		DB::pdo()->prepare(
			"UPDATE sessions
			    SET {$column} = :a,
			        status    = CASE WHEN status = 'cart' THEN 'checkout' ELSE status END,
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':a' => wp_json_encode( $address ),
			':i' => $cart->id,
		] );

		return $this->cart->getOrCreate( $token );
	}

	public function setEmail( string $token, string $email ): Cart {
		$cart = $this->cart->getOrCreate( $token );
		DB::pdo()->prepare(
			"UPDATE sessions SET email = :e, updated_at = unixepoch() WHERE id = :i"
		)->execute( [ ':e' => $email, ':i' => $cart->id ] );

		return $this->cart->getOrCreate( $token );
	}

	public function selectGateway( string $token, string $gatewayId, ?string $method = null ): Cart {
		$cart = $this->cart->getOrCreate( $token );
		// Validate up-front: gateway must be registered.
		$this->gateways->get( $gatewayId );

		DB::pdo()->prepare(
			"UPDATE sessions
			    SET payment_provider = :p, payment_method = :m, updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':p' => $gatewayId,
			':m' => $method,
			':i' => $cart->id,
		] );

		return $this->cart->getOrCreate( $token );
	}

	/**
	 * Finalize the cart into a draft Order and ask the chosen gateway for a
	 * PaymentIntent. Returns the (order, intent) pair so the channel can
	 * either give the client a clientSecret (for SDK-driven payment) or a
	 * redirect URL (for hosted checkout).
	 *
	 * @return array{order: Order, intent: PaymentIntent}
	 */
	public function startPayment( string $token ): array {
		return DB::tx( function () use ( $token ): array {
			$cart = $this->cart->getOrCreate( $token );

			if ( $cart->isEmpty() ) {
				throw new \DomainException( 'Cart is empty' );
			}
			if ( $cart->email === null || $cart->email === '' ) {
				throw new \DomainException( 'Email required before payment' );
			}
			if ( $cart->paymentProvider === null ) {
				throw new \DomainException( 'Payment method required before payment' );
			}

			$gateway = $this->gateways->get( $cart->paymentProvider );

			$order  = $this->orders->createFromCart( $cart, $cart->email );
			$intent = $gateway->createIntent( $order );

			$this->orderRepo->setPaymentIntent(
				orderId:  $order->id,
				provider: $gateway->id(),
				intentId: $intent->intentId,
				method:   $cart->paymentMethod,
			);

			// Refresh order so caller sees the intent_id persisted.
			$order = $this->orderRepo->findById( $order->id, $order->currency )
				?? throw new \RuntimeException( 'Order vanished mid-startPayment' );

			// Move session to 'pending' so the cart is "in payment" — the
			// webhook receiver moves it to 'completed' on success.
			DB::pdo()->prepare(
				"UPDATE sessions
				    SET status = 'pending',
				        payment_intent_id = :i,
				        updated_at = unixepoch()
				  WHERE id = :s"
			)->execute( [
				':i' => $intent->intentId,
				':s' => $cart->id,
			] );

			return [ 'order' => $order, 'intent' => $intent ];
		} );
	}

	/**
	 * Mark the cart's session 'completed' once the order is paid. Called
	 * from the webhook receiver after OrderService::markPaid runs.
	 */
	public function completeSession( int $sessionId ): void {
		DB::pdo()->prepare(
			"UPDATE sessions SET status = 'completed', updated_at = unixepoch() WHERE id = :s"
		)->execute( [ ':s' => $sessionId ] );
	}
}
