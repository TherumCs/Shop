<?php
/**
 * Shop — Spacer element. Pure vertical space.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Spacer implements Element {

	public function id(): string       { return 'spacer'; }
	public function name(): string     { return 'Spacer'; }
	public function category(): string { return 'layout'; }
	public function icon(): string     { return 'move-vertical'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->number( 'height', 'Height (px)', 24, [ 'min' => 1, 'max' => 480 ] )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$h = (int) ( $settings['height'] ?? 24 );
		return sprintf( '<div class="shop-el shop-el-spacer" style="height:%dpx"></div>', $h );
	}
}
