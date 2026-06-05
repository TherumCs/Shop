<?php
/**
 * Shop by Therum — Mock payment gateway.
 *
 * Lets us run checkout end-to-end without wiring a real PSP. createIntent()
 * always returns a fresh fake intent. refund() always succeeds.
 *
 * Webhook flow for local dev: there's no actual webhook source, so the
 * MockController exposes a /shop/v1/mock/succeed/{intent_id} REST endpoint
 * that constructs a synthetic payment.succeeded WebhookEvent and feeds it
 * through the same WebhookReceiver path Square would use. That way, the
 * whole post-payment chain (status flip, OrderPaid event, fulfillment
 * handoff stubs) runs in real code paths, not bypassed for testing.
 *
 * Never bind in production. PSP gateway resolution should swap this for
 * SquareGateway in the container based on settings.
 */

namespace Shop\Payments;

use Shop\Models\Order;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MockGateway implements PSPGateway {

	public const ID = 'mock';

	public function id(): string          { return self::ID; }
	public function displayName(): string { return 'Mock (development)'; }

	public function supports( string $capability ): bool {
		return in_array( $capability, [
			'refunds', 'partial_refunds', 'webhooks', 'card',
		], true );
	}

	public function createIntent( Order $order ): PaymentIntent {
		$intentId = 'mock_pi_' . bin2hex( random_bytes( 8 ) );
		return new PaymentIntent(
			providerId:   self::ID,
			intentId:     $intentId,
			status:       'requires_action',
			clientSecret: 'mock_cs_' . bin2hex( random_bytes( 8 ) ),
			extra:        [
				'note' => 'Mock gateway — call POST /shop/v1/mock/succeed/' . $intentId . ' to simulate payment.succeeded webhook.',
			],
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		return 'mock_re_' . substr( hash( 'sha256', $idempotencyKey ), 0, 16 );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		// Mock accepts anything; the synthetic webhook constructed by
		// MockController already arrives parsed.
		$decoded = json_decode( $rawBody, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		return new WebhookEvent(
			providerId:      self::ID,
			providerEventId: (string) ( $verified['event_id'] ?? ( 'mock_ev_' . bin2hex( random_bytes( 8 ) ) ) ),
			kind:            (string) ( $verified['kind'] ?? 'payment.succeeded' ),
			paymentIntentId: $verified['intent_id'] ?? null,
			refundId:        $verified['refund_id'] ?? null,
			payload:         $verified,
		);
	}
}
