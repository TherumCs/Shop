<?php
/**
 * Studio cart — inner contents.
 *
 * Variables in scope: $cart : Counter\Models\Cart
 *
 * Override: copy to <theme>/shop/cart/studio/contents.php
 */

/** @var \Counter\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;

$products = \Counter\Container::instance()->get( \Counter\Repositories\ProductRepository::class );
?>
<div
	class="counter-studio"
	data-counter-cart
	data-counter-cart-token="<?php echo esc_attr( $cart->token ); ?>"
	data-counter-cart-currency="<?php echo esc_attr( $cart->currency ); ?>"
	data-counter-cart-count="<?php echo esc_attr( (string) $cart->itemCount() ); ?>"
>

	<?php if ( $cart->isEmpty() ) : ?>

		<div class="counter-studio__empty" data-counter-cart-empty>
			<p class="counter-studio__empty-title"><?php esc_html_e( 'Your cart is empty.', 'counter' ); ?></p>
			<p class="counter-studio__empty-sub"><?php esc_html_e( 'Add something to get started.', 'counter' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="counter-studio__lines" data-counter-cart-items>
			<?php foreach ( $cart->items as $item ) :
				$product = $products->findById( $item->productId, $cart->currency );
				$variant = $item->variantId !== null ? $products->findVariant( $item->variantId, $cart->currency ) : null;
				$title   = $product?->title ?? sprintf( __( 'Product #%d', 'counter' ), $item->productId );
				$vendor  = $variant?->podProvider;
				$opts    = isset( $variant?->meta['options'] ) && is_array( $variant->meta['options'] )
					? implode( ' · ', array_map( 'strval', $variant->meta['options'] ) )
					: '';
				$meta_parts = array_filter( [ $vendor, $opts ] );
				$meta       = implode( ' · ', $meta_parts );
				$compare_at = $variant?->compareAtPrice ?? $product?->compareAtPrice;
			?>
				<li class="counter-studio__line" data-counter-cart-item data-counter-cart-item-id="<?php echo esc_attr( (string) $item->id ); ?>">
					<div class="counter-studio__line-image" aria-hidden="true">
						<?php
						$image_id = $product?->primaryImageId;
						if ( $image_id && function_exists( 'wp_get_attachment_image' ) ) {
							echo wp_get_attachment_image( $image_id, [ 64, 64 ], false, [
								'class'   => 'counter-studio__line-img',
								'loading' => 'lazy',
								'alt'     => $title,
							] );
						}
						?>
					</div>
					<div class="counter-studio__line-info">
						<div class="counter-studio__line-title"><?php echo esc_html( $title ); ?></div>
						<?php if ( $meta !== '' ) : ?>
							<div class="counter-studio__line-meta"><?php echo esc_html( $meta ); ?></div>
						<?php endif; ?>
					</div>
					<div class="counter-studio__line-price-col">
						<div class="counter-studio__line-price"><?php echo esc_html( $item->unitPrice->format() ); ?></div>
						<?php if ( $compare_at !== null && $compare_at->greaterThan( $item->unitPrice ) ) : ?>
							<div class="counter-studio__line-compare"><?php echo esc_html( $compare_at->format() ); ?></div>
						<?php endif; ?>
					</div>
					<div class="counter-studio__line-controls">
						<div class="counter-studio__qty" role="group" aria-label="<?php esc_attr_e( 'Quantity', 'counter' ); ?>">
							<button type="button" class="counter-studio__qty-btn" data-counter-cart-<?php echo $item->quantity === 1 ? 'remove' : 'decrement'; ?> aria-label="<?php esc_attr_e( 'Decrease', 'counter' ); ?>">
								<?php if ( $item->quantity === 1 ) : ?>
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
								<?php else : ?>
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
								<?php endif; ?>
							</button>
							<input type="number" class="counter-studio__qty-input" data-counter-cart-qty
								value="<?php echo esc_attr( (string) $item->quantity ); ?>"
								min="0" step="1" inputmode="numeric"
								aria-label="<?php esc_attr_e( 'Quantity', 'counter' ); ?>" />
							<button type="button" class="counter-studio__qty-btn" data-counter-cart-increment aria-label="<?php esc_attr_e( 'Increase', 'counter' ); ?>">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
							</button>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>

	<div class="counter-studio__foot">
		<a class="counter-studio__checkout" href="<?php echo esc_url( home_url( '/checkout/' ) ); ?>" data-counter-cart-checkout>
			<?php esc_html_e( 'Checkout', 'counter' ); ?>
			<span class="counter-studio__checkout-sep">·</span>
			<span class="counter-studio__checkout-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
		</a>
		<p class="counter-studio__foot-note">
			<?php esc_html_e( 'Shipping and taxes calculated at checkout', 'counter' ); ?>
		</p>
	</div>

</div>
