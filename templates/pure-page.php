<?php
/**
 * Shop by Therum — Pure-mode theme template.
 *
 * The fallback theme template used when no theme override is found.
 * Just opens get_header() / get_footer() around the rendered page so
 * the theme's nav + footer still wrap our content.
 *
 * Themes wanting different chrome can copy this file to
 *   <theme>/shop/pure-page.php
 * and tweak.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$router = \Shop\Container::instance()->get( \Shop\Services\PageRouter::class );
echo $router->renderCurrent(); // phpcs:ignore — element renders already escape

get_footer();
