<?php
/**
 * Counter by Therum — provider → destination mapping.
 *
 * For every PaymentProvider, this answers "where does the money end up?"
 * — both the human description (shown in the admin) and the bucket the
 * UI groups by ("Square balance", "Your bank", "External").
 *
 * Bucket semantics:
 *   square  — funds land natively in the merchant's Square balance.
 *   square_via_stripe — funds land in Stripe briefly, then instant-payout
 *             to the Square Debit Mastercard (only when cadence=instant
 *             and a destination is configured). Same net effect, plus 1.5%.
 *   bank    — funds land in the merchant's bank account via ACH or
 *             direct deposit (Plaid, PayPal-via-ACH, Sezzle, Zip, Zelle).
 *   external — funds stay in a third-party wallet/processor the merchant
 *             manages outside Counter (Shopify balance, crypto wallet).
 *
 * Bucket drives both color-coding and aggregation in the Money Flow
 * panel — "5 methods → Square balance, 8 methods → bank, etc."
 *
 * The "instant payout to Square Debit" path is conditional: it requires
 *   - cadence === 'instant'
 *   - counter_studio_pay_payout_destination is set
 *   - the chosen destination is a card (not bank)
 * We pass cadence + destination state in via describe() so the UI can
 * reflect the *actual* current behavior, not a theoretical one.
 */

namespace Counter\Payments\Studio;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PaymentDestinations {

	public const BUCKET_SQUARE             = 'square';
	public const BUCKET_SQUARE_VIA_STRIPE  = 'square_via_stripe';
	public const BUCKET_BANK               = 'bank';
	public const BUCKET_EXTERNAL           = 'external';
	public const BUCKET_NONE               = 'none';

	/**
	 * @return array{ bucket:string, label:string, detail:string }
	 */
	public static function describe(
		string $providerId,
		string $cadence = 'daily',
		bool $hasInstantToSquare = false
	): array {
		// Stripe is the only provider where the destination shifts based
		// on cadence + payout-destination config.
		if ( $providerId === 'stripe' ) {
			return $cadence === 'instant' && $hasInstantToSquare
				? [
					'bucket' => self::BUCKET_SQUARE_VIA_STRIPE,
					'label'  => 'Square balance (via Stripe instant payout)',
					'detail' => 'Funds capture into Stripe, then instant-payout (1.5%) to your Square Debit Card. Lands in Square balance within minutes.',
				]
				: [
					'bucket' => self::BUCKET_BANK,
					'label'  => 'Your bank (Stripe → ACH)',
					'detail' => 'Funds settle in Stripe, then ACH to your linked bank account, T+1, free. Switch Payouts cadence to Instant + pick a Square Debit Card destination to route to Square instead.',
				];
		}

		// Shop Pay inherits whatever its underlying mode is.
		if ( $providerId === 'shop_pay' ) {
			$mode = (string) get_option( 'counter_shop_pay_mode', 'stripe_link' );
			return $mode === 'shopify'
				? [
					'bucket' => self::BUCKET_EXTERNAL,
					'label'  => 'Shopify Payments balance',
					'detail' => 'Charge processes through your Shopify storefront. Funds land in Shopify Payments balance, not Counter.',
				]
				: self::describe( 'stripe', $cadence, $hasInstantToSquare );
		}

		return match ( $providerId ) {
			'square' => [
				'bucket' => self::BUCKET_SQUARE,
				'label'  => 'Square balance (native)',
				'detail' => 'Funds settle directly in your Square balance. No middleman, no fee beyond Square processing.',
			],
			'paypal' => [
				'bucket' => self::BUCKET_BANK,
				'label'  => 'Your bank (PayPal → ACH)',
				'detail' => 'Funds settle in your PayPal balance, then free ACH to your bank, T+1.',
			],
			'plaid' => [
				'bucket' => self::BUCKET_BANK,
				'label'  => 'Your bank (direct ACH via Plaid)',
				'detail' => 'Customer\'s bank pulls direct into yours — no PSP holding period. T+1.',
			],
			'sezzle' => [
				'bucket' => self::BUCKET_BANK,
				'label'  => 'Your bank (Sezzle settlement)',
				'detail' => 'Sezzle pays out to your linked bank account on T+1 ACH.',
			],
			'zip' => [
				'bucket' => self::BUCKET_BANK,
				'label'  => 'Your bank (Zip settlement)',
				'detail' => 'Zip ACHes to your linked bank account, typically T+2.',
			],
			'zelle' => [
				'bucket' => self::BUCKET_BANK,
				'label'  => 'Your bank (Zelle direct)',
				'detail' => 'Customer sends bank-to-bank via Zelle. Lands in your bank in real time. Manual confirmation in Counter Orders.',
			],
			'crypto' => [
				'bucket' => self::BUCKET_EXTERNAL,
				'label'  => 'AnyPay (fiat conversion or crypto wallet)',
				'detail' => 'Settlement target is configured in your AnyPay merchant dashboard — auto-convert to USD ACH or hold in a crypto wallet.',
			],
			default => [
				'bucket' => self::BUCKET_NONE,
				'label'  => 'Not configured',
				'detail' => 'No active route for this method.',
			],
		};
	}

	/**
	 * Aggregate the current routing into bucket counts for the Money
	 * Flow panel. Pass a `method_id => provider_id` map (the routes
	 * option) plus the cadence + payout state.
	 *
	 * @param array<string,string> $routes
	 * @return array{ buckets: array<string,int>, lines: list<array{ method:string, label:string, provider:string, destination:array }> }
	 */
	public static function summarize(
		array $routes,
		string $cadence,
		bool $hasInstantToSquare
	): array {
		$buckets = [
			self::BUCKET_SQUARE             => 0,
			self::BUCKET_SQUARE_VIA_STRIPE  => 0,
			self::BUCKET_BANK               => 0,
			self::BUCKET_EXTERNAL           => 0,
			self::BUCKET_NONE               => 0,
		];
		$lines = [];
		foreach ( MethodRegistry::all() as $m ) {
			$provider = $routes[ $m['id'] ] ?? ( $m['providers'][0] ?? '' );
			$dest = self::describe( $provider, $cadence, $hasInstantToSquare );
			$buckets[ $dest['bucket'] ]++;
			$lines[] = [
				'method'      => $m['id'],
				'label'       => $m['label'],
				'provider'    => $provider,
				'destination' => $dest,
			];
		}
		return [ 'buckets' => $buckets, 'lines' => $lines ];
	}
}
