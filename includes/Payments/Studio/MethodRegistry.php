<?php
/**
 * Shop by Therum — Studio Pay method registry.
 *
 * Source of truth for every payment method we expose in the checkout
 * UI (the method strip in checkout-experience.html). Each Method
 * declares:
 *
 *   id           — canonical slug used everywhere
 *   group        — strip group: card | wallets | bnpl | bank | crypto | p2p
 *   label        — human label shown on the pill
 *   providers    — ordered list of provider ids that can fulfil it.
 *                  Studio Pay router picks the first connected one,
 *                  with optional per-method routing override.
 *   needsRedirect — UI hint: this method opens a hosted page / QR / app
 *
 * Methods marked `needsProvider=true` only render in the checkout if at
 * least one of their providers is connected. Methods like 'zelle' that
 * don't have a connected provider are hidden until configured.
 */

namespace Shop\Payments\Studio;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MethodRegistry {

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public static function all(): array {
		return [
			// ── Card ────────────────────────────────────────────────
			[ 'id' => 'card',         'group' => 'card',    'label' => 'Card',
			  'providers' => [ 'stripe', 'square' ], 'needsRedirect' => false ],

			// ── Wallets ────────────────────────────────────────────
			[ 'id' => 'apple_pay',    'group' => 'wallets', 'label' => 'Apple Pay',
			  'providers' => [ 'stripe' ], 'needsRedirect' => false ],
			[ 'id' => 'google_pay',   'group' => 'wallets', 'label' => 'Google Pay',
			  'providers' => [ 'stripe' ], 'needsRedirect' => false ],
			[ 'id' => 'link',         'group' => 'wallets', 'label' => 'Link',
			  'providers' => [ 'stripe' ], 'needsRedirect' => false ],
			[ 'id' => 'shop_pay',     'group' => 'wallets', 'label' => 'Shop Pay',
			  'providers' => [ 'stripe' ], 'needsRedirect' => true  ],
			[ 'id' => 'paypal',       'group' => 'wallets', 'label' => 'PayPal',
			  'providers' => [ 'paypal' ], 'needsRedirect' => true  ],

			// ── BNPL ────────────────────────────────────────────────
			[ 'id' => 'klarna',        'group' => 'bnpl', 'label' => 'Klarna',
			  'providers' => [ 'stripe' ], 'needsRedirect' => true ],
			[ 'id' => 'affirm',        'group' => 'bnpl', 'label' => 'Affirm',
			  'providers' => [ 'stripe' ], 'needsRedirect' => true ],
			[ 'id' => 'afterpay',      'group' => 'bnpl', 'label' => 'Afterpay',
			  'providers' => [ 'stripe', 'square' ], 'needsRedirect' => true ],
			[ 'id' => 'sezzle',        'group' => 'bnpl', 'label' => 'Sezzle',
			  'providers' => [ 'sezzle' ], 'needsRedirect' => true ],
			[ 'id' => 'zip',           'group' => 'bnpl', 'label' => 'Zip',
			  'providers' => [ 'stripe' ], 'needsRedirect' => true ],
			[ 'id' => 'paypal_credit', 'group' => 'bnpl', 'label' => 'PayPal Credit',
			  'providers' => [ 'paypal' ], 'needsRedirect' => true ],

			// ── Bank ────────────────────────────────────────────────
			[ 'id' => 'bank_ach',      'group' => 'bank', 'label' => 'Bank (Plaid)',
			  'providers' => [ 'plaid', 'stripe' ], 'needsRedirect' => true ],

			// ── Crypto ──────────────────────────────────────────────
			[ 'id' => 'crypto',        'group' => 'crypto', 'label' => 'Crypto',
			  'providers' => [ 'crypto' ], 'needsRedirect' => true ],

			// ── P2P ─────────────────────────────────────────────────
			[ 'id' => 'cashapp',       'group' => 'p2p', 'label' => 'Cash App',
			  'providers' => [ 'square', 'stripe' ], 'needsRedirect' => true ],
			[ 'id' => 'venmo',         'group' => 'p2p', 'label' => 'Venmo',
			  'providers' => [ 'paypal' ], 'needsRedirect' => true ],
			[ 'id' => 'zelle',         'group' => 'p2p', 'label' => 'Zelle',
			  'providers' => [ 'zelle' ], 'needsRedirect' => true ],
		];
	}

	/**
	 * Methods grouped by strip group, in the order they should appear
	 * in the checkout method strip.
	 *
	 * @return array<string, array<int, array<string,mixed>>>
	 */
	public static function byGroup(): array {
		$out = [ 'card' => [], 'wallets' => [], 'bnpl' => [], 'bank' => [], 'crypto' => [], 'p2p' => [] ];
		foreach ( self::all() as $m ) {
			$out[ $m['group'] ][] = $m;
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $m ) {
			if ( $m['id'] === $id ) return $m;
		}
		return null;
	}
}
