<?php
/**
 * Shop by Therum — ControlBuilder.
 *
 * Fluent helper for declaring element control schemas. Every control
 * row has at minimum: { id, type, label, default }. Types align with
 * the Pure editor's render switches and map cleanly to Bricks /
 * Elementor / Gutenberg control kinds.
 *
 * Standard types:
 *   text, textarea, number, slider, toggle, select, radio, color,
 *   image, gallery, dimensions, alignment, productPicker, variantPicker,
 *   ai (description-from-prompt input)
 *
 * Group + tab give the editor UI structure. Pure renders a single
 * scrolling panel (no tabs) but groups visually.
 */

namespace Shop\Elements;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ControlBuilder {

	/** @var array<int, array<string,mixed>> */
	private array $controls = [];
	private ?string $group  = null;

	public function group( string $label ): self {
		$this->group = $label;
		return $this;
	}

	public function add( string $id, string $type, string $label, mixed $default = null, array $extra = [] ): self {
		$this->controls[] = array_merge( [
			'id'      => $id,
			'type'    => $type,
			'label'   => $label,
			'default' => $default,
			'group'   => $this->group,
		], $extra );
		return $this;
	}

	public function text( string $id, string $label, string $default = '' ): self    { return $this->add( $id, 'text',     $label, $default ); }
	public function textarea( string $id, string $label, string $default = '' ): self{ return $this->add( $id, 'textarea', $label, $default ); }
	public function number( string $id, string $label, int|float $default = 0, array $extra = [] ): self {
		return $this->add( $id, 'number', $label, $default, $extra );
	}
	public function toggle( string $id, string $label, bool $default = false ): self { return $this->add( $id, 'toggle',   $label, $default ); }
	public function color( string $id, string $label, string $default = '' ): self   { return $this->add( $id, 'color',    $label, $default ); }
	public function select( string $id, string $label, array $options, mixed $default = null ): self {
		return $this->add( $id, 'select', $label, $default, [ 'options' => $options ] );
	}
	public function alignment( string $id, string $label, string $default = 'left' ): self {
		return $this->add( $id, 'alignment', $label, $default, [
			'options' => [ 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ],
		] );
	}
	public function image( string $id, string $label, ?int $default = null ): self   { return $this->add( $id, 'image',    $label, $default ); }
	public function productPicker( string $id, string $label, ?int $default = null ): self {
		return $this->add( $id, 'productPicker', $label, $default );
	}

	/** @return array<int, array<string,mixed>> */
	public function build(): array {
		return $this->controls;
	}

	public static function make(): self {
		return new self();
	}
}
