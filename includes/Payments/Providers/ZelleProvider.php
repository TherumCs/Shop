<?php
/**
 * Counter by Therum — ZelleProvider.
 *
 * Zelle has no merchant API — it's consumer bank-to-bank P2P. The
 * checkout flow is:
 *
 *   1. Customer picks Zelle at checkout.
 *   2. We show them the merchant's Zelle handle (email or phone) and
 *      the exact amount + order number to put in the memo.
 *   3. Customer sends from their banking app.
 *   4. Funds arrive in the merchant's bank within minutes (Zelle is
 *      real-time RTP underneath for most banks).
 *   5. Merchant manually marks the order paid in the Counter admin
 *      after they see the deposit hit — OR we auto-detect via Plaid
 *      reading the merchant's bank if Plaid Auth is wired up.
 *
 * This provider therefore implements `createIntent()` by returning the
 * payment instructions as the redirect payload, and `verifyWebhook()`
 * as a no-op (no upstream to call).
 *
 * Auth: just the merchant's Zelle handle (email or phone) in
 * `counter_zelle_handle` + display name in `counter_zelle_display_name`.
 * No keys.
 */

namespace Counter\Payments\Providers;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ZelleProvider implements PaymentProvider {

	public function id(): string          { return 'zelle'; }
	public function displayName(): string { return 'Zelle'; }

	public function supportedMethods(): array { return [ 'zelle' ]; }

	public function isConnected(): bool { return $this->handle() !== ''; }

	public function createIntent( Order $order, string $method ): PaymentIntent {
		// We don't talk to a backend — we hand the checkout UI the data
		// it needs to render the instructions card.
		$raw = [
			'handle'       => $this->handle(),
			'display_name' => $this->displayName2(),
			'amount'       => $order->grandTotal->amount,   // cents
			'currency'     => $order->grandTotal->currency,
			'memo'         => 'Order ' . $order->number,
			'instructions' => sprintf(
				'Send %s to %s via Zelle. Include "Order %s" in the memo. Order ships once payment is confirmed.',
				$this->formatMoney( $order->grandTotal ),
				$this->handle(),
				$order->number,
			),
		];
		return new PaymentIntent(
			providerId:   $this->id(),
			intentId:     'zelle-' . $order->number,
			clientSecret: wp_json_encode( $raw ),  // JS reads this to render the panel
			redirectUrl:  null,
			raw:          $raw,
		);
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		// Same problem as inbound — no API. Merchant sends Zelle back to
		// the customer manually. We log the intent for audit and return a
		// synthetic id so the rest of the refund pipeline keeps moving.
		$id = 'zelle-refund-' . $order->number . '-' . substr( md5( $idempotencyKey ), 0, 8 );
		do_action( 'counter_zelle_manual_refund_requested', $order, $amount, $id );
		return $id;
	}

	public function availableBalance(): ?Money {
		// Zelle deposits directly to your bank — no platform balance.
		return null;
	}

	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string {
		throw new \RuntimeException( 'Zelle has no payout API — funds settle direct to your bank in real time.' );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		// No upstream → no webhooks. Confirmations come from the merchant
		// manually clicking "Mark paid" in the admin (or from Plaid Auth
		// detecting the deposit on the merchant's bank if configured).
		return null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		return new WebhookEvent(
			providerId:      $this->id(),
			providerEventId: '',
			kind:            'unknown',
			intentId:        '',
			amount:          null,
			raw:             $verified,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function handle(): string {
		return (string) get_option( 'counter_zelle_handle', '' );
	}

	private function displayName2(): string {
		return (string) get_option( 'counter_zelle_display_name', '' ) ?: get_bloginfo( 'name' );
	}

	private function formatMoney( Money $m ): string {
		return '$' . number_format( $m->amount / 100, 2 );
	}
}
