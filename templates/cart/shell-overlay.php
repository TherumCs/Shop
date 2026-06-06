<?php
/**
 * Counter by Therum — overlay shell (legacy fallback).
 *
 * Alias for shell-drawer.php. Used when MODE_OVERLAY is configured
 * (0.2.x legacy back-compat). New installs should use MODE_STUDIO or MODE_VITRINE.
 *
 * Variables in scope:
 *   $cart     : Counter\Models\Cart
 *   $contents : string  (already-rendered contents.php HTML)
 */

/** @var \Counter\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;

include COUNTER_DIR . 'templates/cart/shell-drawer.php';
