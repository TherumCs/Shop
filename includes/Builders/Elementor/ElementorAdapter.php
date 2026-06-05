<?php
/**
 * Shop by Therum — Elementor adapter.
 *
 * Mirrors the Bricks pattern: when Elementor is active we walk the
 * ElementRegistry once at boot and runtime-generate an Elementor
 * Widget_Base subclass for each Shop element. Each generated widget
 * delegates back to the Shop element's `render()` so there's a single
 * source of truth.
 *
 * Auto-registers when `ELEMENTOR_VERSION` is defined. Elementor's
 * widget category for Shop elements is "shop-by-therum".
 *
 * Controls translate from our schema to Elementor's via
 * `ElementorControlMap`. Same control types (text, number, toggle,
 * select, image, color) map cleanly.
 */

namespace Shop\Builders\Elementor;

use Shop\Elements\ElementContext;
use Shop\Elements\ElementRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ElementorAdapter {

	public function __construct( private readonly ElementRegistry $elements ) {}

	public function register(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) return;
		add_action( 'elementor/elements/categories_registered', [ $this, 'category' ] );
		add_action( 'elementor/widgets/register',               [ $this, 'widgets' ] );
	}

	public function category( $elements_manager ): void {
		$elements_manager->add_category( 'shop-by-therum', [
			'title' => __( 'Shop by Therum', 'shop' ),
			'icon'  => 'eicon-store',
		] );
	}

	public function widgets( $widgets_manager ): void {
		foreach ( $this->elements->all() as $el ) {
			$widgetClass = ElementorWidgetFactory::generate( $el );
			$widgets_manager->register( new $widgetClass() );
		}
	}
}
