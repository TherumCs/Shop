<?php
/**
 * Counter by Therum — Studio Pay routing presets.
 *
 * One-click "route everything sensibly" configurations. Each preset is
 * a map of `method_id => provider_id` that gets written into the
 * `counter_studio_pay_method_routes` option — the same option the
 * Methods tab's per-row dropdowns edit.
 *
 * Why presets exist:
 *   - The full strip is 16+ methods. Hand-routing each one is tedious
 *     and error-prone.
 *   - Most merchants want one of two patterns:
 *       1. Square-first  — money lands in Square balance wherever Square
 *                          can carry the method; the rest fills with the
 *                          merchant's other providers.
 *       2. Stripe-first  — one unified Stripe balance; instant-payout to
 *                          Square Debit Card on capture.
 *     Picking a preset is one click; per-method overrides still work
 *     on top.
 *
 * Presets are pure data — no API calls, no side effects beyond writing
 * the route option. Safe to run repeatedly.
 */

namespace Counter\Payments\Studio;

if ( ! defined( 'ABSPATH' ) ) exit;

final class RoutingPresets {

	/**
	 * Square handles every method Square natively supports; everything
	 * else uses the route most merchants expect. Money distribution:
	 *
	 *   → Square balance (native, instant, no fee):
	 *       card, apple_pay, google_pay, cashapp, afterpay
	 *   → Stripe balance (then instant-payout to Square Debit Card, 1.5%):
	 *       klarna, affirm, shop_pay, link
	 *   → PayPal balance (then free ACH to bank):
	 *       paypal, venmo, paypal_credit
	 *   → Direct ACH to bank (no PSP holding):
	 *       bank_ach (Plaid), sezzle, zip
	 *   → Per-provider settlement:
	 *       crypto (AnyPay), zelle (manual)
	 */
	public const SQUARE_FIRST = [
		'card'          => 'square',
		'apple_pay'     => 'square',
		'google_pay'    => 'square',
		'link'          => 'stripe',  // Stripe-owned wallet, no Square equivalent
		'cashapp'       => 'square',
		'afterpay'      => 'square',
		'shop_pay'      => 'shop_pay',
		'klarna'        => 'stripe',
		'affirm'        => 'stripe',
		'paypal'        => 'paypal',
		'venmo'         => 'paypal',
		'paypal_credit' => 'paypal',
		'sezzle'        => 'sezzle',
		'zip'           => 'zip',
		'bank_ach'      => 'plaid',
		'crypto_btc'    => 'crypto',
		'crypto_eth'    => 'crypto',
		'crypto_usdc'   => 'crypto',
		'crypto_usdt'   => 'crypto',
		'crypto_sol'    => 'crypto',
		'crypto_xrp'    => 'crypto',
		'crypto_link'   => 'crypto',
		'crypto_xlm'    => 'crypto',
		'crypto_hbar'   => 'crypto',
		'zelle'         => 'zelle',
	];

	/**
	 * Stripe is the centerpiece. Card/wallets/BNPL all funnel through
	 * Stripe; instant-payouts to the merchant's Square Debit Card mean
	 * the Square balance still lands the funds (with a 1.5% Stripe fee
	 * on each payout). PayPal-family stays on PayPal because that's the
	 * only rail that carries Venmo / PP Credit.
	 */
	public const STRIPE_FIRST = [
		'card'          => 'stripe',
		'apple_pay'     => 'stripe',
		'google_pay'    => 'stripe',
		'link'          => 'stripe',
		'cashapp'       => 'stripe',
		'afterpay'      => 'stripe',
		'shop_pay'      => 'shop_pay',
		'klarna'        => 'stripe',
		'affirm'        => 'stripe',
		'paypal'        => 'paypal',
		'venmo'         => 'paypal',
		'paypal_credit' => 'paypal',
		'sezzle'        => 'sezzle',
		'zip'           => 'zip',
		'bank_ach'      => 'plaid',  // direct Plaid is cheaper than Stripe Financial Connections wrapper
		'crypto_btc'    => 'crypto',
		'crypto_eth'    => 'crypto',
		'crypto_usdc'   => 'crypto',
		'crypto_usdt'   => 'crypto',
		'crypto_sol'    => 'crypto',
		'crypto_xrp'    => 'crypto',
		'crypto_link'   => 'crypto',
		'crypto_xlm'    => 'crypto',
		'crypto_hbar'   => 'crypto',
		'zelle'         => 'zelle',
	];

	/**
	 * @return array{ id:string, label:string, description:string, routes:array<string,string> }[]
	 */
	public static function all(): array {
		return [
			[
				'id'          => 'square_first',
				'label'       => 'Square first',
				'description' => "Square handles cards, Apple Pay, Google Pay, Cash App, and Afterpay — money lands in Square balance directly, no fee. Stripe fills Klarna/Affirm. PayPal carries Venmo + PP Credit. Best when you want maximum funds to land natively in Square.",
				'routes'      => self::SQUARE_FIRST,
			],
			[
				'id'          => 'stripe_first',
				'label'       => 'Stripe first (WooPayments-style)',
				'description' => 'Stripe is the unified rail. All card/wallet/BNPL methods route through Stripe; instant-payout (1.5%) pushes the balance to your Square Debit Card so funds still land in Square — just via Stripe in the middle. Best when you want one provider managing most of the flow.',
				'routes'      => self::STRIPE_FIRST,
			],
		];
	}

	/**
	 * Apply a preset by id. Returns the resolved route map, or null if
	 * the preset id is unknown.
	 *
	 * @return array<string,string>|null
	 */
	public static function apply( string $id ): ?array {
		foreach ( self::all() as $preset ) {
			if ( $preset['id'] === $id ) {
				update_option( 'counter_studio_pay_method_routes', $preset['routes'] );
				update_option( 'counter_studio_pay_active_preset', $id );
				return $preset['routes'];
			}
		}
		return null;
	}

	public static function activePresetId(): string {
		// Stored preset id stays valid only while every route still matches
		// what the preset would write. If the user has overridden any
		// route, we report "custom" so the UI doesn't lie about state.
		$stored = (string) get_option( 'counter_studio_pay_active_preset', '' );
		if ( $stored === '' ) return '';

		$current = (array) get_option( 'counter_studio_pay_method_routes', [] );
		foreach ( self::all() as $preset ) {
			if ( $preset['id'] !== $stored ) continue;
			foreach ( $preset['routes'] as $method => $provider ) {
				if ( ( $current[ $method ] ?? null ) !== $provider ) {
					return 'custom';
				}
			}
			return $stored;
		}
		return '';
	}
}
