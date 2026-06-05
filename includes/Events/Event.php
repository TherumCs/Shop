<?php
/**
 * Shop by Therum — Event base.
 *
 * Marker interface. Every event class implements this. Carries typed
 * properties — subscribers read them off the instance, no array digging.
 *
 * Why a marker (no abstract base): events should have no inherited state
 * and PHP doesn't need it. Keeping it an interface means each event can
 * be a `final readonly class` with the cleanest constructor possible.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Event {
	/**
	 * Stable string identifier — used for queued dispatch and logging.
	 * Convention: dot-separated lowercase, e.g. "cart.item_added".
	 */
	public static function name(): string;
}
