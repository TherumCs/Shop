<?php
/**
 * Sequence checkout (stub). Falls through to Classic for v0.3.0; full port
 * from preview/sequence.html lands next chunk.
 *
 * @var \Shop\Models\Cart $cart
 * @var string $mode
 */
if ( ! defined( 'ABSPATH' ) ) exit;

include __DIR__ . '/../classic/index.php';
