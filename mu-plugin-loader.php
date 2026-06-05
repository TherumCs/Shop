<?php
/**
 * Shop by Therum — MU plugin loader.
 *
 * ============================================================================
 *  INSTALLATION
 * ============================================================================
 *
 * Copy or symlink THIS FILE to:
 *
 *     wp-content/mu-plugins/shop-loader.php
 *
 * And place (or symlink) the plugin directory at:
 *
 *     wp-content/mu-plugins/shop/
 *
 * Final layout:
 *
 *     wp-content/mu-plugins/
 *         shop-loader.php     ← this file (loaded automatically by WP)
 *         shop/               ← the plugin directory
 *             shop.php
 *             includes/
 *             …
 *
 * Why a loader: WordPress auto-loads top-level PHP files in mu-plugins/, but
 * NOT files inside subdirectories. The one-line loader bridges that.
 *
 * Once installed, the plugin is always active. No admin UI to enable/disable,
 * no activation step, no client foot-gun. Schema migrations run on the first
 * admin page load via the `admin_init` catch-up in shop.php.
 *
 * For local dev you can symlink instead of copying:
 *
 *     cd wp-content/mu-plugins
 *     ln -s ../../path/to/source/shop/mu-plugin-loader.php shop-loader.php
 *     ln -s ../../path/to/source/shop shop
 *
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$shop_main = WPMU_PLUGIN_DIR . '/shop/shop.php';
if ( is_file( $shop_main ) ) {
	require_once $shop_main;
}
