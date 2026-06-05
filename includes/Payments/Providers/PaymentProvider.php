<?php
/**
 * Shop by Therum — PaymentProvider contract.
 *
 * Wraps a single PSP (Stripe, Square, PayPal, ...). Studio Pay routes
 * specific payment methods (card, Klarna, Apple Pay, ...) through one
 * of these providers based on availability, fees, and merchant pref.
 *
 * Why this exists alongside PSPGateway:
 *   PSPGateway was the v1 single-provider contract. PaymentProvider is
 *   the multi-provider variant — same idea, but adds:
 *     - capability flags per *method* (not just generic 'card')
 *     - balance/payout endpoints for the unified Payouts service
 *     - connect-flow primitives so Studio Pay can OAuth a merchant in
 *
 * A `PaymentProvider` implementation should be cheap to instantiate
 * (no network calls in __construct) and idempotent across calls — the
 * Studio Pay router may create multiple instances per request.
 */

namespace Shop\Payments\Providers;

use Shop\Models\Order;
use Shop\Money;
use Shop\Payments\PaymentIntent;
use Shop\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

interface PaymentProvider {

	public function id(): string;          // 'stripe' | 'square' | 'paypal' | ...
	public function displayName(): string; // 'Stripe' | 'Square' | 'PayPal'

	/**
	 * Methods this provider can fulfil. Drives the StudioPay method
	 * registry — a method is "available" if at least one connected
	 * provider supports it.
	 *
	 * Canonical method ids: 'card', 'apple_pay', 'google_pay', 'link',
	 * 'shop_pay', 'paypal', 'paypal_credit', 'venmo', 'klarna',
	 * 'affirm', 'afterpay', 'sezzle', 'zip', 'bank_ach', 'cashapp',
	 * 'zelle', 'crypto'.
	 *
	 * @return string[]
	 */
	public function supportedMethods(): array;

	/**
	 * Whether the merchant has finished Connect / OAuth for this
	 * provider and a working credential is on file.
	 */
	public function isConnected(): bool;

	/**
	 * Begin a payment intent for a specific method. `method` MUST be in
	 * `supportedMethods()`. Provider implementations are responsible for
	 * translating the canonical id to whatever the underlying API
	 * expects (e.g. Stripe's `payment_method_types`).
	 */
	public function createIntent( Order $order, string $method ): PaymentIntent;

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string;

	/**
	 * Available balance in the platform / connected account. Reported
	 * in minor units (cents). For unified providers like Stripe Connect
	 * this is the merchant's available balance — not the platform's.
	 *
	 * Implementations that don't expose a balance (e.g. crypto rails)
	 * return null.
	 */
	public function availableBalance(): ?Money;

	/**
	 * Request a payout. `instant=true` uses the provider's instant rail
	 * (Stripe Instant Payouts, Square Instant Deposit, …) at the
	 * provider's typical fee; `false` joins the standard daily ACH
	 * cycle. Returns the provider's payout id.
	 */
	public function payout( Money $amount, bool $instant, string $idempotencyKey ): string;

	/**
	 * Webhook verification + canonical event extraction. Same shape as
	 * PSPGateway so existing WebhookController doesn't need to change.
	 *
	 * @param array<string,string> $headers
	 * @return array<string,mixed>|null
	 */
	public function verifyWebhook( string $rawBody, array $headers ): ?array;

	/**
	 * @param array<string,mixed> $verified
	 */
	public function parseEvent( array $verified ): WebhookEvent;
}
