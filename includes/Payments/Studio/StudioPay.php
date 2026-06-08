<?php
/**
 * Counter by Therum — Studio Pay.
 *
 * The unified gateway. Implements `PSPGateway` so the rest of the
 * plugin (CheckoutService, RefundService, WebhookController) keeps
 * treating "payment" as a single thing, while underneath we route to
 * whichever PaymentProvider best fits the requested method.
 *
 * Routing rules (in order):
 *   1. Per-method override in the merchant's settings
 *      ('counter_studio_pay_method_routes' => [ 'card' => 'square', ... ])
 *   2. First connected provider in the method's `providers` array
 *   3. Throw — no provider available
 *
 * Why we wrap providers behind one gateway:
 *   - One refund() entry point regardless of which provider captured
 *   - One webhook endpoint that fans out to the right verifier
 *   - One settings UI surface; merchant doesn't think about "Stripe vs
 *     Square" — they think "Card payments"
 */

namespace Counter\Payments\Studio;

use Counter\Models\Order;
use Counter\Money;
use Counter\Payments\PaymentIntent;
use Counter\Payments\Providers\PaymentProvider;
use Counter\Payments\PSPGateway;
use Counter\Payments\WebhookEvent;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioPay implements PSPGateway {

	/**
	 * @param array<string, PaymentProvider> $providers  keyed by provider id
	 */
	public function __construct( private readonly array $providers ) {}

	public function id(): string          { return 'studio_pay'; }
	public function displayName(): string { return 'Studio Pay'; }

	public function supports( string $capability ): bool {
		// Aggregated capability — if any connected provider supports it.
		return match ( $capability ) {
			'refunds', 'partial_refunds', 'webhooks' => true,
			'card', 'wallet_apple', 'wallet_google', 'bnpl' => $this->anyMethodAvailable(),
			default => false,
		};
	}

	/**
	 * Methods currently available at checkout. A method is available iff:
	 *   - the merchant has it enabled in Payments → Methods, AND
	 *   - at least one of its providers has credentials saved.
	 *
	 * When the enabled-flag option isn't set yet (fresh install) we
	 * fall back to "everything on" — same as the legacy behavior — so
	 * upgrading doesn't silently hide methods.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function availableMethods(): array {
		$enabled = get_option( 'counter_studio_pay_methods_enabled' );
		$out = [];
		foreach ( MethodRegistry::all() as $m ) {
			if ( is_array( $enabled ) && ! ( $enabled[ $m['id'] ] ?? true ) ) continue;
			if ( $this->providerForMethod( $m['id'] ) !== null ) $out[] = $m;
		}
		return $out;
	}

	private function anyMethodAvailable(): bool {
		return ! empty( $this->availableMethods() );
	}

	public function createIntent( Order $order ): PaymentIntent {
		// Method is selected client-side and surfaced on the order
		// (CheckoutService sets it before calling). Default to card.
		$method   = (string) ( $order->payment_method ?? 'card' );
		$provider = $this->providerForMethod( $method );
		if ( $provider === null ) throw new \RuntimeException( "No provider available for method '$method'." );
		return $provider->createIntent( $order, $method );
	}

	public function refund( Order $order, Money $amount, string $idempotencyKey ): string {
		// The order was captured by exactly one provider — use that one,
		// not whichever happens to be "first." Order rows already track
		// `payment_provider`.
		$id = (string) ( $order->payment_provider ?? '' );
		$provider = $this->providers[ $id ] ?? null;
		if ( $provider === null ) throw new \RuntimeException( "Refund: original provider '$id' not connected." );
		return $provider->refund( $order, $amount, $idempotencyKey );
	}

	public function verifyWebhook( string $rawBody, array $headers ): ?array {
		// Each provider has its own signature scheme — let them all try
		// once. First one that returns a verified body wins; outright
		// forgery throws inside the provider.
		foreach ( $this->providers as $p ) {
			try {
				$verified = $p->verifyWebhook( $rawBody, $headers );
				if ( $verified !== null ) {
					// Stash the resolving provider id on the verified
					// body so parseEvent() routes correctly.
					$verified['__provider'] = $p->id();
					return $verified;
				}
			} catch ( \Throwable $e ) {
				// A definite forgery from one provider bubbles up — we
				// don't want to fall through to another and accidentally
				// accept a malformed body.
				throw $e;
			}
		}
		return null;
	}

	public function parseEvent( array $verified ): WebhookEvent {
		$id = (string) ( $verified['__provider'] ?? '' );
		$provider = $this->providers[ $id ] ?? null;
		if ( $provider === null ) throw new \RuntimeException( "parseEvent: provider '$id' unknown." );
		return $provider->parseEvent( $verified );
	}

	// ─── Routing ─────────────────────────────────────────────────────────

	public function providerForMethod( string $method ): ?PaymentProvider {
		$meta = MethodRegistry::find( $method );
		if ( $meta === null ) return null;

		// 1. Per-method routing override
		$routes = (array) get_option( 'counter_studio_pay_method_routes', [] );
		if ( isset( $routes[ $method ] ) ) {
			$p = $this->providers[ (string) $routes[ $method ] ] ?? null;
			if ( $p && $p->isConnected() ) return $p;
		}

		// 2. First connected from the method's preferred provider list
		foreach ( $meta['providers'] as $pid ) {
			$p = $this->providers[ $pid ] ?? null;
			if ( $p && $p->isConnected() ) return $p;
		}
		return null;
	}

	/** @return array<string, PaymentProvider> */
	public function providers(): array { return $this->providers; }
}
