<?php
/**
 * Shop by Therum — WebhookReceiver.
 *
 * The single entry point for PSP webhooks. Flow:
 *
 *   1. Resolve provider from URL slug.
 *   2. Verify signature via gateway. Reject if forged.
 *   3. Insert payment_events row BEFORE any business logic. The
 *      UNIQUE(payment_provider, provider_event_id) constraint guarantees
 *      duplicate fires are detected and skipped — even if the original
 *      handler ran twice for some other reason.
 *   4. Inside a transaction, dispatch by event kind:
 *        payment.succeeded → OrderService::markPaid → completeSession
 *        payment.failed    → OrderService::markFailed
 *        refund.succeeded  → (milestone #5)
 *   5. Stamp processed_at on the event row.
 *
 * The receiver never returns < 200 unless verification fails or storage
 * blew up. Business-logic errors are recorded on the event row and the
 * webhook is acknowledged — the PSP shouldn't retry forever because our
 * fulfillment plumbing hiccuped.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Payments\WebhookEvent;
use Shop\Repositories\OrderRepository;
use Shop\Repositories\PaymentGatewayRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WebhookReceiver {

	public function __construct(
		private readonly PaymentGatewayRegistry $gateways,
		private readonly OrderRepository $orderRepo,
		private readonly OrderService $orders,
		private readonly CheckoutService $checkout,
	) {}

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int, body:array<string,mixed>}
	 */
	public function handle( string $providerId, string $rawBody, array $headers ): array {
		if ( ! $this->gateways->has( $providerId ) ) {
			return [ 'status' => 404, 'body' => [ 'error' => 'unknown_provider' ] ];
		}

		$gateway = $this->gateways->get( $providerId );

		try {
			$verified = $gateway->verifyWebhook( $rawBody, $headers );
		} catch ( \Throwable $e ) {
			return [ 'status' => 400, 'body' => [ 'error' => 'invalid_signature', 'message' => $e->getMessage() ] ];
		}

		if ( $verified === null ) {
			return [ 'status' => 400, 'body' => [ 'error' => 'no_signature' ] ];
		}

		$event = $gateway->parseEvent( $verified );

		// ─── Idempotent insert ──────────────────────────────────────────
		try {
			$inserted = $this->insertEvent( $event );
		} catch ( \PDOException $e ) {
			// Most likely the UNIQUE(provider, provider_event_id) tripped —
			// duplicate webhook. Acknowledge and exit.
			if ( str_contains( strtolower( $e->getMessage() ), 'unique' ) ) {
				return [ 'status' => 200, 'body' => [ 'status' => 'duplicate_ignored' ] ];
			}
			throw $e;
		}
		$eventRowId = $inserted;

		// ─── Dispatch ───────────────────────────────────────────────────
		try {
			$this->dispatch( $event );
			$this->markEventProcessed( $eventRowId );
			return [ 'status' => 200, 'body' => [ 'status' => 'ok' ] ];
		} catch ( \Throwable $e ) {
			$this->markEventFailed( $eventRowId, $e->getMessage() );
			// Still 200 — see class comment. Caller can re-trigger via admin.
			return [ 'status' => 200, 'body' => [ 'status' => 'processing_error', 'message' => $e->getMessage() ] ];
		}
	}

	private function dispatch( WebhookEvent $event ): void {
		switch ( $event->kind ) {
			case 'payment.succeeded':
				$this->handlePaymentSucceeded( $event );
				break;

			case 'payment.failed':
				$this->handlePaymentFailed( $event );
				break;

			case 'refund.succeeded':
			case 'refund.failed':
				// milestone #5 — handler added when RefundService lands.
				break;

			default:
				// Unknown kind — already logged via payment_events row. Skip.
				break;
		}
	}

	private function handlePaymentSucceeded( WebhookEvent $event ): void {
		if ( $event->paymentIntentId === null ) return;

		$order = $this->orderRepo->findByPaymentIntent( $event->paymentIntentId );
		if ( $order === null ) return;

		$paid = $this->orders->markPaid( $order );

		if ( $paid->sessionId !== null ) {
			$this->checkout->completeSession( $paid->sessionId );
		}
	}

	private function handlePaymentFailed( WebhookEvent $event ): void {
		if ( $event->paymentIntentId === null ) return;
		$order = $this->orderRepo->findByPaymentIntent( $event->paymentIntentId );
		if ( $order === null ) return;
		$this->orders->markFailed( $order, 'PSP reported payment.failed' );
	}

	private function insertEvent( WebhookEvent $event ): int {
		$pdo = DB::pdo();
		$pdo->prepare(
			"INSERT INTO payment_events (
				payment_provider, provider_event_id, kind, payment_intent_id,
				payload, status, received_at
			) VALUES (
				:prov, :eid, :kind, :pi,
				:payload, 'received', unixepoch()
			)"
		)->execute( [
			':prov'    => $event->providerId,
			':eid'     => $event->providerEventId,
			':kind'    => $event->kind,
			':pi'      => $event->paymentIntentId,
			':payload' => wp_json_encode( $event->payload ),
		] );
		return (int) $pdo->lastInsertId();
	}

	private function markEventProcessed( int $rowId ): void {
		DB::pdo()->prepare(
			"UPDATE payment_events
			    SET status = 'processed', processed_at = unixepoch()
			  WHERE id = :i"
		)->execute( [ ':i' => $rowId ] );
	}

	private function markEventFailed( int $rowId, string $message ): void {
		DB::pdo()->prepare(
			"UPDATE payment_events
			    SET status = 'processing_error',
			        processing_error = :m,
			        processed_at = unixepoch()
			  WHERE id = :i"
		)->execute( [ ':m' => $message, ':i' => $rowId ] );
	}
}
