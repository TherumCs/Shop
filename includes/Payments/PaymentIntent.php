<?php
/**
 * Shop by Therum — payment intent value object.
 *
 * What a gateway returns from createIntent(). The client uses these fields
 * to complete payment — either by collecting card data via a provider SDK
 * (Square, Stripe) or by redirecting to a hosted page (PayPal, BNPL providers).
 */

namespace Shop\Payments;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class PaymentIntent {

	public function __construct(
		public string $providerId,
		public string $intentId,
		public string $status,            // 'requires_action' | 'processing' | 'succeeded' | …
		public ?string $clientSecret = null,
		public ?string $redirectUrl  = null,
		/** @var array<string,mixed> */
		public array $extra = [],
	) {}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'provider_id'   => $this->providerId,
			'intent_id'     => $this->intentId,
			'status'        => $this->status,
			'client_secret' => $this->clientSecret,
			'redirect_url'  => $this->redirectUrl,
			'extra'         => $this->extra,
		];
	}
}
