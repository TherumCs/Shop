<?php
/**
 * Shop by Therum — uninstall cleanup.
 *
 * Destructive: deletes the SQLite database file and the wp-content/uploads/
 * therum-shop directory. Only fires on explicit plugin deletion via the
 * WordPress admin (not on deactivation), so the user is opting in.
 *
 * If you want to deactivate without losing data, do NOT click "Delete" — just
 * deactivate. The SQLite file sits untouched.
 *
 * Also drops the legacy MySQL tables from 0.1.x in case an installer
 * upgraded through that path; on a fresh 0.2.0 install these queries are
 * no-ops.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// ──────────────────────────────────────────────────────────────────────────
//  SQLite file
// ──────────────────────────────────────────────────────────────────────────

$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'therum-shop';

if ( is_dir( $dir ) ) {
	$files = [
		'shop.sqlite',
		'shop.sqlite-wal',
		'shop.sqlite-shm',
		'.htaccess',
		'index.php',
	];
	foreach ( $files as $f ) {
		$path = $dir . '/' . $f;
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}


// ──────────────────────────────────────────────────────────────────────────
//  Legacy MySQL tables (0.1.x)
// ──────────────────────────────────────────────────────────────────────────

global $wpdb;
$p = $wpdb->prefix;

$legacy_tables = [
	'therum_payment_events',
	'therum_refund_items',
	'therum_refunds',
	'therum_coupon_redemptions',
	'therum_coupons',
	'therum_order_notes',
	'therum_order_shipments',
	'therum_order_items',
	'therum_orders',
	'therum_session_items',
	'therum_sessions',
	'therum_vendor_option_terms',
	'therum_digital_files',
	'therum_product_images',
	'therum_variant_attribute_values',
	'therum_product_attributes',
	'therum_attribute_values',
	'therum_attributes',
	'therum_product_variants',
	'therum_products',
];

foreach ( $legacy_tables as $tbl ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}{$tbl}" );
}

// Settings cleanup.
delete_option( 'shop_db_version' );
delete_option( 'shop_cart_presentation' );
delete_option( 'shop_checkout_presentation' );
delete_option( 'shop_cart_button_position' );
delete_option( 'shop_cart_default_mode' );      // legacy 0.2.x key
delete_option( 'shop_product_source' );
