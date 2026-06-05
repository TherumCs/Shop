<?php
/**
 * Shop by Therum — Mode helper.
 *
 * Single source of truth for the Pure vs Unlocked feature gating.
 *
 *   Pure     = Therum standalone. Native catalog, Pure page builder,
 *              Nexus-driven vendor sync. No Woo, no Bricks needed.
 *
 *   Unlocked = Therum + WordPress ecosystem. Reads Woo catalog,
 *              renders into Bricks / Elementor / Gutenberg, mirrors
 *              orders to WC_Order for POD plugin compat.
 *
 * Modes are determined automatically from what's installed and the
 * user's settings:
 *
 *   - `isPure()`       — catalog source = native AND no detected page
 *                        builder OR explicitly forced via setting
 *   - `isUnlocked()`   — opposite
 *   - `isBricks()`     — Bricks is the active builder
 *   - `isElementor()`  — Elementor is active
 *   - `isGutenberg()`  — Gutenberg only (no other builder)
 *
 * Code that needs to gate behavior reads from Mode rather than poking
 * options directly:
 *
 *   if ( Mode::isPure() ) {
 *       // Show Pure builder menu
 *   }
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Mode {

	public static function catalogSource(): string {
		return (string) get_option( 'shop_product_source', 'native' );
	}

	public static function isPure(): bool {
		// Pure when catalog is native AND no external builder is
		// driving the front-end (or user has explicitly forced Pure).
		$forced = (string) get_option( 'shop_mode', 'auto' );
		if ( $forced === 'pure' )     return true;
		if ( $forced === 'unlocked' ) return false;

		if ( self::catalogSource() !== 'native' ) return false;
		if ( self::isBricks() )     return false;
		if ( self::isElementor() )  return false;
		return true;
	}

	public static function isUnlocked(): bool { return ! self::isPure(); }

	public static function isBricks(): bool {
		return defined( 'BRICKS_VERSION' );
	}

	public static function isElementor(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public static function isWooActive(): bool {
		return defined( 'WC_VERSION' ) || function_exists( 'wc_get_product' );
	}

	/**
	 * Whether the active mode wants Shop's product editor visible.
	 * In Unlocked mode with Woo present, Woo's product editor is the
	 * source of truth — hiding ours avoids two editors for the same row.
	 */
	public static function showsNativeProductEditor(): bool {
		if ( self::isPure() )                                   return true;
		if ( ! self::isWooActive() )                            return true;
		if ( self::catalogSource() === 'native' )               return true;
		return false;
	}

	/**
	 * Whether Shop's Pure page builder admin should be registered.
	 * Pure-only — no point loading the Preact editor when Bricks /
	 * Elementor / Gutenberg are doing the building.
	 */
	public static function loadsPureBuilder(): bool {
		return self::isPure();
	}
}
