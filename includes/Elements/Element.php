<?php
/**
 * Shop by Therum — Element contract.
 *
 * Every element ships two halves:
 *
 *   - controls()   declares the configurable inputs the element accepts
 *                  (title, color, alignment, product_id, etc.). Pure
 *                  editor, Bricks adapter, Elementor adapter, and the
 *                  Gutenberg adapter all read the same control schema
 *                  and translate it into their builder's native control
 *                  format.
 *
 *   - render( settings, context ): string
 *                  produces the HTML for the element given a settings
 *                  map (filled by the editor's controls) and a render
 *                  context (current product, archive query, cart, etc.).
 *                  Pure HTML/CSS — no client JS unless the element is
 *                  inherently interactive (cart, variant picker, etc.).
 *
 * Element classes are intentionally stateless beyond their declarations.
 * State lives in the page's saved layout JSON (Pure mode) or the page
 * builder's own DB (Bricks/Elementor/Gutenberg).
 */

namespace Shop\Elements;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Element {

	/** Short id used in saved layouts and as the cache key. */
	public function id(): string;

	/** Display name shown in builder palettes. */
	public function name(): string;

	/** Category for grouping in the palette: 'catalog', 'layout', 'content', 'commerce'. */
	public function category(): string;

	/** Icon name (Lucide icon set) for the palette. */
	public function icon(): string;

	/**
	 * The control schema. Builder adapters translate this into their
	 * native control format. See ControlBuilder for the helper API.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function controls(): array;

	/**
	 * Server-render the element to HTML.
	 *
	 * @param array<string,mixed> $settings  values set by the editor
	 * @param ElementContext      $context   render-time data
	 */
	public function render( array $settings, ElementContext $context ): string;

	/**
	 * Whether this element needs client JS to function. The Pure
	 * renderer reads this to decide which scripts to enqueue per page —
	 * pages without interactive elements ship zero JS.
	 */
	public function needsJs(): bool;
}
