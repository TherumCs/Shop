<?php
/**
 * Shop by Therum — PageRenderer.
 *
 * Walks a Page's element tree and produces HTML. Each node:
 *
 *   { id: 'uuid', type: 'section', settings: {...}, children: [ ... ] }
 *
 * Children are passed to the parent's render() as a pre-rendered string
 * in $context->extras['children'] — so Section / Column compose their
 * inner content naturally without the renderer needing per-element
 * special-casing.
 *
 * Tracks whether any rendered element needsJs() so the caller can
 * decide whether to enqueue the interactive bundle. Pages with only
 * static elements ship zero JS.
 */

namespace Shop\Services;

use Shop\Elements\ElementContext;
use Shop\Elements\ElementRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PageRenderer {

	private bool $needsJs = false;

	public function __construct(
		private readonly ElementRegistry $elements,
	) {}

	/**
	 * Render an array of root nodes (a Page's tree).
	 *
	 * @param array<int, array<string,mixed>> $tree
	 */
	/**
	 * When true, every rendered node is wrapped in a tiny
	 * `<div class="shop-ed" data-shop-node-id="…">…</div>` envelope so
	 * the editor can hit-test clicks in the preview iframe back to the
	 * tree. Off in production — there's no class added.
	 */
	private bool $editorMode = false;

	public function withEditorMode( bool $on ): self {
		$this->editorMode = $on;
		return $this;
	}

	public function render( array $tree, ElementContext $context ): string {
		$this->needsJs = false;
		$out = '';
		foreach ( $tree as $node ) {
			if ( is_array( $node ) ) {
				$out .= $this->renderNode( $node, $context );
			}
		}
		return $out;
	}

	public function pageNeededJs(): bool {
		return $this->needsJs;
	}

	/**
	 * @param array<string,mixed> $node
	 */
	private function renderNode( array $node, ElementContext $context ): string {
		$type = (string) ( $node['type'] ?? '' );
		if ( $type === '' || ! $this->elements->has( $type ) ) return '';

		$element = $this->elements->get( $type );
		if ( $element->needsJs() ) $this->needsJs = true;

		$settings = (array) ( $node['settings'] ?? [] );

		// Render children first, pass via context.extras['children']
		$child_html = '';
		foreach ( (array) ( $node['children'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) {
				$child_html .= $this->renderNode( $child, $context );
			}
		}

		$node_context = $context->withExtras( [ 'children' => $child_html ] );
		$html         = $element->render( $settings, $node_context );

		if ( $this->editorMode ) {
			$id   = (string) ( $node['id'] ?? '' );
			$type = (string) ( $node['type'] ?? '' );
			if ( $id !== '' ) {
				$html = '<div class="shop-ed" data-shop-node-id="' . esc_attr( $id ) . '" data-shop-node-type="' . esc_attr( $type ) . '">' . $html . '</div>';
			}
		}
		return $html;
	}
}
