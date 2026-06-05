<?php
/**
 * Shop — RichText element. Block of formatted text.
 */

namespace Shop\Elements\Content;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class RichText implements Element {

	public function id(): string       { return 'rich-text'; }
	public function name(): string     { return 'Rich text'; }
	public function category(): string { return 'content'; }
	public function icon(): string     { return 'pilcrow'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->textarea( 'html', 'HTML content', '<p>Edit this text…</p>' )
			->group( 'Style' )
				->alignment( 'alignment', 'Alignment', 'left' )
				->color( 'color', 'Color', '' )
				->select( 'size', 'Size', [
					'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large',
				], 'md' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$html      = (string) ( $settings['html'] ?? '' );
		if ( trim( $html ) === '' ) return '';
		$alignment = $settings['alignment'] ?? 'left';
		$color     = (string) ( $settings['color'] ?? '' );
		$size      = (string) ( $settings['size'] ?? 'md' );

		$style = $color !== '' ? sprintf( ' style="color:%s"', esc_attr( $color ) ) : '';
		return sprintf(
			'<div class="shop-el shop-el-rich shop-el-rich--%s shop-el--align-%s"%s>%s</div>',
			esc_attr( $size ),
			esc_attr( $alignment ),
			$style,
			wp_kses_post( $html ),
		);
	}
}
