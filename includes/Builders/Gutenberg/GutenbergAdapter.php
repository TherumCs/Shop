<?php
/**
 * Shop by Therum — Gutenberg adapter.
 *
 * Registers every Shop element as a dynamic block (server-rendered),
 * so they appear in the block inserter under "Shop by Therum".
 *
 * Dynamic blocks let us reuse the Shop element render() pipeline
 * verbatim — Gutenberg passes the attributes to our PHP render
 * callback at output time. No JS edit() implementation needed beyond
 * a minimal placeholder (registered via inline script).
 *
 * Auto-boots when block editor is available.
 */

namespace Shop\Builders\Gutenberg;

use Shop\Elements\ElementContext;
use Shop\Elements\ElementRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GutenbergAdapter {

	public function __construct( private readonly ElementRegistry $elements ) {}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) return;
		add_action( 'init', [ $this, 'registerBlocks' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'editorAssets' ] );
	}

	public function registerBlocks(): void {
		foreach ( $this->elements->all() as $el ) {
			$id = $el->id();
			register_block_type( 'shop/' . $id, [
				'attributes'      => $this->attributesFor( $el ),
				'render_callback' => function ( array $atts ) use ( $id ) {
					$el = $this->elements->get( $id );
					if ( ! $el ) return '';
					return $el->render( $atts, new ElementContext() );
				},
			] );
		}
	}

	public function editorAssets(): void {
		// Minimal JS that registers each Shop block in the editor with
		// the standard ServerSideRender placeholder. One inline script,
		// no build step.
		$elements = [];
		foreach ( $this->elements->all() as $el ) {
			$elements[] = [
				'id'         => $el->id(),
				'name'       => $el->name(),
				'category'   => $el->category(),
				'icon'       => $el->icon(),
				'attributes' => $this->attributesFor( $el ),
				'controls'   => $el->controls(),
			];
		}
		wp_add_inline_script(
			'wp-blocks',
			'window.ShopGutenbergElements = ' . wp_json_encode( $elements ) . ';',
			'before'
		);
		wp_enqueue_script(
			'shop-gutenberg',
			SHOP_URL . 'assets/builders/gutenberg.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-server-side-render' ],
			SHOP_VERSION,
			true
		);
	}

	/**
	 * Build a Gutenberg attributes schema from the element's control
	 * schema. Everything is strings/numbers/booleans — Gutenberg
	 * persists them in JSON-attribute form on the block.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private function attributesFor( $el ): array {
		$attrs = [];
		foreach ( $el->controls() as $c ) {
			$id   = (string) ( $c['id']    ?? '' );
			$type = (string) ( $c['type']  ?? 'text' );
			$def  = $c['default'] ?? '';
			$attrs[ $id ] = [
				'type'    => match ( $type ) {
					'number' => 'number',
					'toggle' => 'boolean',
					default  => 'string',
				},
				'default' => $def,
			];
		}
		return $attrs;
	}
}
