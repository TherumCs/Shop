<?php
/**
 * Studio cart — inner contents.
 *
 * Variables in scope: $cart : Shop\Models\Cart
 *
 * Override: copy to <theme>/shop/cart/studio/contents.php
 */

/** @var \Shop\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;

$products = \Shop\Container::instance()->get( \Shop\Repositories\ProductRepository::class );
?>
<div
	class="shop-studio"
	data-shop-cart
	data-shop-cart-token="<?php echo esc_attr( $cart->token ); ?>"
	data-shop-cart-currency="<?php echo esc_attr( $cart->currency ); ?>"
	data-shop-cart-count="<?php echo esc_attr( (string) $cart->itemCount() ); ?>"
>

	<?php if ( $cart->isEmpty() ) : ?>

		<div class="shop-studio__empty" data-shop-cart-empty>
			<p class="shop-studio__empty-title"><?php esc_html_e( 'Your cart is empty.', 'shop' ); ?></p>
			<p class="shop-studio__empty-sub"><?php esc_html_e( 'Add something to get started.', 'shop' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="shop-studio__lines" data-shop-cart-items>
			<?php foreach ( $cart->items as $item ) :
				$product = $products->findById( $item->productId, $cart->currency );
				$variant = $item->variantId !== null ? $products->findVariant( $item->variantId, $cart->currency ) : null;
				$title   = $product?->title ?? sprintf( __( 'Product #%d', 'shop' ), $item->productId );
				$vendor  = $variant?->podProvider;
				$opts    = isset( $variant?->meta['options'] ) && is_array( $variant->meta['options'] )
					? implode( ' · ', array_map( 'strval', $variant->meta['options'] ) )
					: '';
				$meta_parts = array_filter( [ $vendor, $opts ] );
				$meta       = implode( ' · ', $meta_parts );
				$compare_at = $variant?->compareAtPrice ?? $product?->compareAtPrice;
			?>
				<li class="shop-studio__line" data-shop-cart-item data-shop-cart-item-id="<?php echo esc_attr( (string) $item->id ); ?>">
					<div class="shop-studio__line-image" aria-hidden="true">
						<?php /* TODO: render <img> when product->primaryImageId is wired in admin UI */ ?>
					</div>
					<div class="shop-studio__line-info">
						<div class="shop-studio__line-title"><?php echo esc_html( $title ); ?></div>
						<?php if ( $meta !== '' ) : ?>
							<div class="shop-studio__line-meta"><?php echo esc_html( $meta ); ?></div>
						<?php endif; ?>
					</div>
					<div class="shop-studio__line-price-col">
						<div class="shop-studio__line-price"><?php echo esc_html( $item->unitPrice->format() ); ?></div>
						<?php if ( $compare_at !== null && $compare_at->greaterThan( $item->unitPrice ) ) : ?>
							<div class="shop-studio__line-compare"><?php echo esc_html( $compare_at->format() ); ?></div>
						<?php endif; ?>
					</div>
					<div class="shop-studio__line-controls">
						<div class="shop-studio__qty" role="group" aria-label="<?php esc_attr_e( 'Quantity', 'shop' ); ?>">
							<button type="button" class="shop-studio__qty-btn" data-shop-cart-<?php echo $item->quantity === 1 ? 'remove' : 'decrement'; ?> aria-label="<?php esc_attr_e( 'Decrease', 'shop' ); ?>">
								<?php if ( $item->quantity === 1 ) : ?>
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
								<?php else : ?>
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
								<?php endif; ?>
							</button>
							<input type="number" class="shop-studio__qty-input" data-shop-cart-qty
								value="<?php echo esc_attr( (string) $item->quantity ); ?>"
								min="0" step="1" inputmode="numeric"
								aria-label="<?php esc_attr_e( 'Quantity', 'shop' ); ?>" />
							<button type="button" class="shop-studio__qty-btn" data-shop-cart-increment aria-label="<?php esc_attr_e( 'Increase', 'shop' ); ?>">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
							</button>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>

	<div class="shop-studio__foot">
		<a class="shop-studio__checkout" href="<?php echo esc_url( home_url( '/checkout/' ) ); ?>" data-shop-cart-checkout>
			<?php esc_html_e( 'Checkout', 'shop' ); ?>
			<span class="shop-studio__checkout-sep">·</span>
			<span class="shop-studio__checkout-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
		</a>
		<p class="shop-studio__foot-note">
			<?php esc_html_e( 'Shipping and taxes calculated at checkout', 'shop' ); ?>
		</p>
	</div>

</div>
