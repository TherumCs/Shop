<?php
/**
 * Shop — Image element.
 *
 * Static image (not product gallery). Picker selects from media library.
 */

namespace Shop\Elements\Layout;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Image implements Element {

	public function id(): string       { return 'image'; }
	public function name(): string     { return 'Image'; }
	public function category(): string { return 'content'; }
	public function icon(): string     { return 'image'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Content' )
				->image( 'image_id', 'Image', null )
				->text( 'alt', 'Alt text', '' )
				->text( 'link', 'Link URL (optional)', '' )
			->group( 'Style' )
				->select( 'fit', 'Object fit', [
					'cover'    => 'Cover (crop)',
					'contain'  => 'Contain',
					'fill'     => 'Fill',
					'none'     => 'Original size',
				], 'cover' )
				->select( 'aspect', 'Aspect', [
					'auto' => 'Original',
					'1/1'  => 'Square',
					'4/3'  => '4:3',
					'3/4'  => '3:4',
					'16/9' => '16:9',
					'21/9' => 'Cinemascope',
				], 'auto' )
				->number( 'radius', 'Corner radius (px)', 0, [ 'min' => 0, 'max' => 32 ] )
				->alignment( 'alignment', 'Alignment', 'left' )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$image_id = (int) ( $settings['image_id'] ?? 0 );
		if ( $image_id <= 0 ) return '';

		$alt    = (string) ( $settings['alt'] ?? '' );
		$link   = (string) ( $settings['link'] ?? '' );
		$fit    = $settings['fit']    ?? 'cover';
		$aspect = $settings['aspect'] ?? 'auto';
		$radius = (int) ( $settings['radius'] ?? 0 );
		$align  = $settings['alignment'] ?? 'left';

		$url = (string) wp_get_attachment_image_url( $image_id, 'large' );
		if ( $url === '' ) return '';

		$styles = [
			'--shop-fit: '    . $fit,
			'--shop-radius: ' . $radius . 'px',
		];
		if ( $aspect !== 'auto' ) $styles[] = '--shop-aspect: ' . $aspect;

		$img = sprintf(
			'<img src="%s" alt="%s" loading="lazy" />',
			esc_url( $url ),
			esc_attr( $alt !== '' ? $alt : (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
		);

		if ( $link !== '' ) {
			$img = sprintf( '<a href="%s">%s</a>', esc_url( $link ), $img );
		}

		return sprintf(
			'<figure class="shop-el shop-el-image shop-el--align-%s" style="%s">%s</figure>',
			esc_attr( $align ),
			esc_attr( implode( '; ', $styles ) ),
			$img,
		);
	}
}
