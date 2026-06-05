<?php
/**
 * Shop by Therum — OrderService.
 *
 * Order state transitions + event emission. The only thing that should
 * flip an order's status. Channels (REST, webhook, admin) call into here.
 *
 * v1 covers: create-from-cart, mark-paid. Cancel/fail/complete come along
 * with milestones #5 (refunds) and #2 (fulfillment routing).
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Events\EventBus;
use Shop\Events\OrderCreated;
use Shop\Events\OrderPaid;
use Shop\Models\Cart;
use Shop\Models\Order;
use Shop\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderService {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly EventBus $events,
	) {}

	/**
	 * Convert a paid-ready cart into an order. Cart is left intact for now —
	 * CheckoutService marks the session 'completed' once payment lands.
	 *
	 * Fires OrderCreated (sync) on success.
	 */
	public function createFromCart( Cart $cart, string $email ): Order {
		if ( $cart->isEmpty() ) {
			throw new \DomainException( 'Cannot create an order from an empty cart' );
		}

		return DB::tx( function () use ( $cart, $email ): Order {
			$order = $this->orders->createFromCart( $cart, $email );

			$this->orders->note(
				orderId:        $order->id,
				content:        sprintf( 'Order %s created from cart #%d.', $order->number, $cart->id ),
				isSystemNote:   true,
			);

			$this->events->dispatch( new OrderCreated(
				orderId:         $order->id,
				orderNumber:     $order->number,
				email:           $order->email,
				grandTotalMinor: $order->grandTotal->minor,
				currency:        $order->currency,
			) );

			return $order;
		} );
	}

	/**
	 * Flip an order to `processing`. Called from the webhook receiver when
	 * the gateway confirms payment. Idempotent — already-processing orders
	 * return unchanged without re-firing events.
	 *
	 * Fires OrderPaid (sync) on transition.
	 */
	public function markPaid( Order $order ): Order {
		if ( in_array( $order->status, [ 'processing', 'completed' ], true ) ) {
			return $order;
		}

		return DB::tx( function () use ( $order ): Order {
			$this->orders->setStatus( $order->id, 'processing' );

			$this->orders->note(
				orderId:      $order->id,
				content:      sprintf(
					'Payment succeeded via %s (intent %s).',
					$order->paymentProvider ?? 'unknown',
					$order->paymentIntentId ?? '—',
				),
				isSystemNote: true,
			);

			$fresh = $this->orders->findById( $order->id, $order->currency );
			if ( $fresh === null ) {
				throw new \RuntimeException( 'Order vanished mid-markPaid' );
			}

			$this->events->dispatch( new OrderPaid(
				orderId:         $fresh->id,
				orderNumber:     $fresh->number,
				email:           $fresh->email,
				grandTotalMinor: $fresh->grandTotal->minor,
				currency:        $fresh->currency,
				paymentProvider: $fresh->paymentProvider ?? 'unknown',
				paymentIntentId: $fresh->paymentIntentId ?? '',
			) );

			return $fresh;
		} );
	}

	/**
	 * Record a failed payment. Sync, no follow-up event in v1 — caller
	 * usually wants to show the customer an error message and let them retry.
	 */
	public function markFailed( Order $order, string $reason ): Order {
		return DB::tx( function () use ( $order, $reason ): Order {
			$this->orders->setStatus( $order->id, 'failed' );
			$this->orders->note(
				orderId:      $order->id,
				content:      sprintf( 'Payment failed: %s', $reason ),
				isSystemNote: true,
			);
			return $this->orders->findById( $order->id, $order->currency )
				?? throw new \RuntimeException( 'Order vanished mid-markFailed' );
		} );
	}
}
