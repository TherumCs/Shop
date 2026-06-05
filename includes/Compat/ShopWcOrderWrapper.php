<?php
/**
 * Shop by Therum — WC_Order wrapper for HPOS export.
 *
 * Extends WC_Order so Woo extensions that type-hint `WC_Order $order`
 * accept it. Reads our SQLite order via OrderRepository on demand;
 * writes are silently dropped (Therum is the system of record).
 *
 * Class only exists when WC_Order does — guarded at load via a stub
 * declaration below that PHP swaps for the real class when Woo loads.
 */

namespace Shop\Compat;

if ( ! defined( 'ABSPATH' ) ) exit;

// Only declare the wrapper when WC_Order is available. Otherwise create
// a stub so type-hints don't blow up if something references the class
// before Woo loads.
if ( class_exists( \WC_Order::class ) ) {

	final class ShopWcOrderWrapper extends \WC_Order {

		/**
		 * Block writes — Therum is the source of truth.
		 *
		 * @param string $context
		 */
		public function save_meta_data() { /* no-op */ }

		public function save() { return $this->get_id(); }

		public function delete( $force_delete = false ) {
			// Refuse to delete from this surface — admins must use Shop's
			// own admin to delete.
			return false;
		}
	}

} else {

	// Stub for environments where Woo hasn't loaded yet. Real class
	// definition arrives after Woo loads and re-includes this file.
	final class ShopWcOrderWrapper {}

}
