<?php
/**
 * Shop by Therum — ElementRegistry.
 *
 * Single registry shared by every page builder adapter. Bootstrap
 * registers each element once; adapters iterate and wrap.
 *
 * Filter `shop_register_elements` lets plugins add custom elements.
 */

namespace Shop\Elements;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ElementRegistry {

	/** @var array<string, Element> */
	private array $elements = [];

	public function register( Element $element ): void {
		$this->elements[ $element->id() ] = $element;
	}

	public function get( string $id ): Element {
		if ( ! isset( $this->elements[ $id ] ) ) {
			throw new \DomainException( "Unknown element: {$id}" );
		}
		return $this->elements[ $id ];
	}

	public function has( string $id ): bool { return isset( $this->elements[ $id ] ); }

	/** @return Element[] */
	public function all(): array { return array_values( $this->elements ); }

	/**
	 * Group elements by category for builder palettes.
	 *
	 * @return array<string, Element[]>
	 */
	public function byCategory(): array {
		$out = [];
		foreach ( $this->elements as $el ) {
			$out[ $el->category() ][] = $el;
		}
		return $out;
	}
}
