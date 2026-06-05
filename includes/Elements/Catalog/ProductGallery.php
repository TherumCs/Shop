<?php
/**
 * Shop — Product Gallery element.
 *
 * Renders primary image + gallery thumbnails. Layout options:
 *   - stacked        thumbs below main image
 *   - side-thumbs    thumbs in a side rail (default)
 *   - carousel       single carousel with arrows (needsJs = true)
 *   - grid           grid of all images
 *
 * The first three layouts render server-side with minimal JS (just
 * click-to-swap). Carousel needs full JS.
 */

namespace Shop\Elements\Catalog;

use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductGallery implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'product-gallery'; }
	public function name(): string     { return 'Gallery'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'images'; }

	public function needsJs(): bool { return true; } // for click-to-swap and carousel

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Layout' )
				->select( 'layout', 'Layout', [
					'side-thumbs' => 'Side thumbnails',
					'stacked'     => 'Stacked thumbs below',
					'carousel'    => 'Carousel',
					'grid'        => 'Grid',
				], 'side-thumbs' )
				->select( 'aspect', 'Image aspect ratio', [
					'1/1' => 'Square',
					'4/5' => 'Portrait 4:5',
					'3/4' => 'Portrait 3:4',
					'16/9'=> 'Landscape 16:9',
					'auto'=> 'Original',
				], '4/5' )
			->group( 'Style' )
				->number( 'radius', 'Corner radius (px)', 10, [ 'min' => 0, 'max' => 32 ] )
				->toggle( 'zoom', 'Hover-to-zoom on main', true )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null ) return '';

		$layout = $settings['layout'] ?? 'side-thumbs';
		$aspect = $settings['aspect'] ?? '4/5';
		$radius = (int) ( $settings['radius'] ?? 10 );
		$zoom   = ! empty( $settings['zoom'] );

		$image_ids = array_filter( array_merge(
			[ $product->primaryImageId ],
			$product->galleryImageIds,
		) );
		if ( ! $image_ids ) return '';

		$primary = (int) $image_ids[0];

		$class = sprintf(
			'shop-el shop-el-gallery shop-el-gallery--%s%s',
			esc_attr( $layout ),
			$zoom ? ' shop-el-gallery--zoom' : '',
		);
		$style = sprintf( '--shop-aspect:%s; --shop-radius:%dpx;', esc_attr( $aspect ), $radius );

		$out = '<div class="' . $class . '" style="' . $style . '" data-shop-gallery>';

		$primary_url = (string) wp_get_attachment_image_url( $primary, 'large' );
		$out .= '<div class="shop-el-gallery__main" data-shop-gallery-main>';
		$out .= '<img src="' . esc_url( $primary_url ) . '" alt="' . esc_attr( $product->title ) . '" />';
		$out .= '</div>';

		if ( count( $image_ids ) > 1 ) {
			$out .= '<div class="shop-el-gallery__thumbs">';
			foreach ( $image_ids as $i => $img_id ) {
				$thumb = (string) wp_get_attachment_image_url( (int) $img_id, 'thumbnail' );
				$out  .= sprintf(
					'<button class="shop-el-gallery__thumb%s" data-shop-gallery-thumb data-src="%s">'
					. '<img src="%s" alt="" loading="lazy" />'
					. '</button>',
					$i === 0 ? ' is-active' : '',
					esc_url( (string) wp_get_attachment_image_url( (int) $img_id, 'large' ) ),
					esc_url( $thumb ),
				);
			}
			$out .= '</div>';
		}

		$out .= '</div>';
		return $out;
	}
}
