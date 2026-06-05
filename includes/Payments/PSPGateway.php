<?php
/**
 * Shop by Therum — PSP gateway contract.
 *
 * Every payment provider (Square, Stripe, mock, future) implements this.
 * The plugin's checkout flow knows only this interface — to swap providers,
 * rebind the container.
 *
 * Provider identity:
 *   id()         — short slug (e.g. 'square', 'mock'); used in DB columns
 *                  and webhook routing
 *   displayName() — human label
 *   supports()   — capability check (some providers do refunds, some don't;
 *                  some do hosted-checkout, some do client-side tokenization)
 *
 * Money operations:
 *   createIntent()  — given a draft order, return a payment intent the
 *                     client can drive to completion. Sync; throws on failure.
 *   refund()        — given an order + amount + idempotency key, dispatch
 *                     a refund to the provider. Sync; throws on failure.
 *
 * Webhook:
 *   verifyWebhook() — given the raw HTTP body + headers, return null if
 *                     the signature isn't valid; throws on definite forgery.
 *   parseEvent()    — extract a canonical (event_id, kind, intent_id)
 *                     tuple from the verified body. Provider-specific.
 *
 * Why not split into multiple interfaces (PaymentGateway / RefundGateway /
 * WebhookGateway): for v1 every gateway we'd reasonably ship supports all
 * three. We can decompose later if a niche provider doesn't.
 */

namespace Shop\Payments;

use Shop\Models\Order;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

interface PSPGateway {

	public function id(): string;
	public function displayName(): string;

	/**
	 * Capability probe. Common keys: 'refunds', 'partial_refunds',
	 * 'webhooks', 'card', 'wallet_apple', 'wallet_google', 'bnpl'.
	 */
	public function supports( string $capability ): bool;

	/**
	 * Create a payment intent for an order. Returns the provider-specific
	 * data the client needs to complete payment — at minimum, the intent_id
	 * and (for hosted-checkout providers) a redirect URL.
	 *
	 * @return PaymentIntent
	 */
	public function createIntent( Order $order ): PaymentIntent;

	/**
	 * Refund all-or-some of an order. `amount` must be <= order's
	 * (grandTotal - refundedTotal). The idempotency key dedupes retries
	 * at the provider level.
	 *
	 * @return string  Provider's refund ID, stored on the Refund row.
	 */
	public function refund( Order $order, Money $amount, string $idempotencyKey ): string;

	/**
	 * Verify a webhook's signature against raw body + headers. Returns the
	 * parsed body on success; null if no signature is present (provider may
	 * not have signed); throws on definite forgery.
	 *
	 * @param array<string,string> $headers
	 * @return array<string,mixed>|null
	 */
	public function verifyWebhook( string $rawBody, array $headers ): ?array;

	/**
	 * Map a verified webhook body to canonical event fields. Implementations
	 * normalize provider-specific event types to the kinds tracked in
	 * payment_events (payment.succeeded, payment.failed, refund.succeeded, …).
	 *
	 * @param array<string,mixed> $verified
	 * @return WebhookEvent
	 */
	public function parseEvent( array $verified ): WebhookEvent;
}
