<?php
/**
 * Shop by Therum — cart contents (inner template).
 *
 * The ONLY template that knows how to render line items + totals + actions.
 * Every shell wraps this; every REST response returns the output of this
 * for client-side morph updates.
 *
 * Variables in scope:
 *   $cart : Shop\Models\Cart
 *
 * Override:
 *   Copy to <theme>/shop/cart/contents.php
 *
 * Markup notes:
 *   - Every interactive surface has a `data-shop-*` attribute that cart.js
 *     listens for. Bricks (or any styling layer) can change classes, layout,
 *     and structure freely as long as the data attributes survive.
 *   - No inline event handlers. cart.js binds via delegation on the root.
 */

/** @var \Shop\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="shop-cart"
	data-shop-cart
	data-shop-cart-token="<?php echo esc_attr( $cart->token ); ?>"
	data-shop-cart-currency="<?php echo esc_attr( $cart->currency ); ?>"
	data-shop-cart-count="<?php echo esc_attr( (string) $cart->itemCount() ); ?>"
>

	<?php if ( $cart->isEmpty() ) : ?>

		<div class="shop-cart__empty" data-shop-cart-empty>
			<p class="shop-cart__empty-title"><?php esc_html_e( 'Your cart is empty.', 'shop' ); ?></p>
			<p class="shop-cart__empty-sub"><?php esc_html_e( 'Add something to get started.', 'shop' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="shop-cart__items" data-shop-cart-items>
			<?php foreach ( $cart->items as $item ) : ?>
				<li
					class="shop-cart__item"
					data-shop-cart-item
					data-shop-cart-item-id="<?php echo esc_attr( (string) $item->id ); ?>"
				>
					<div class="shop-cart__item-info">
						<div class="shop-cart__item-title">
							<?php /* Title lookup comes when ProductRepository is exposed to templates;
							        for v1 the line carries no title yet — admin UI milestone supplies it. */ ?>
							<?php echo esc_html( sprintf(
								/* translators: %d = product ID */
								__( 'Product #%d', 'shop' ),
								$item->productId
							) ); ?>
							<?php if ( $item->variantId !== null ) : ?>
								<span class="shop-cart__item-variant">
									<?php echo esc_html( sprintf( __( 'Variant #%d', 'shop' ), $item->variantId ) ); ?>
								</span>
							<?php endif; ?>
						</div>
						<div class="shop-cart__item-price">
							<?php echo esc_html( $item->unitPrice->format() ); ?>
						</div>
					</div>

					<div class="shop-cart__item-controls">
						<div class="shop-cart__qty" role="group" aria-label="<?php esc_attr_e( 'Quantity', 'shop' ); ?>">
							<button
								type="button"
								class="shop-cart__qty-btn shop-cart__qty-dec"
								data-shop-cart-decrement
								aria-label="<?php esc_attr_e( 'Decrease quantity', 'shop' ); ?>"
							>−</button>
							<input
								type="number"
								class="shop-cart__qty-input"
								data-shop-cart-qty
								value="<?php echo esc_attr( (string) $item->quantity ); ?>"
								min="0"
								step="1"
								inputmode="numeric"
								aria-label="<?php esc_attr_e( 'Quantity', 'shop' ); ?>"
							/>
							<button
								type="button"
								class="shop-cart__qty-btn shop-cart__qty-inc"
								data-shop-cart-increment
								aria-label="<?php esc_attr_e( 'Increase quantity', 'shop' ); ?>"
							>+</button>
						</div>

						<button
							type="button"
							class="shop-cart__remove"
							data-shop-cart-remove
							aria-label="<?php esc_attr_e( 'Remove item', 'shop' ); ?>"
						>
							<?php esc_html_e( 'Remove', 'shop' ); ?>
						</button>

						<div class="shop-cart__line-total">
							<?php echo esc_html( $item->lineTotal->format() ); ?>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="shop-cart__summary" data-shop-cart-summary>
			<div class="shop-cart__row">
				<span><?php esc_html_e( 'Subtotal', 'shop' ); ?></span>
				<span data-shop-cart-subtotal><?php echo esc_html( $cart->subtotal->format() ); ?></span>
			</div>

			<?php if ( ! $cart->discountTotal->isZero() ) : ?>
				<div class="shop-cart__row shop-cart__row--discount">
					<span><?php esc_html_e( 'Discount', 'shop' ); ?></span>
					<span>−<?php echo esc_html( $cart->discountTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $cart->shippingTotal->isZero() ) : ?>
				<div class="shop-cart__row">
					<span><?php esc_html_e( 'Shipping', 'shop' ); ?></span>
					<span><?php echo esc_html( $cart->shippingTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $cart->taxTotal->isZero() ) : ?>
				<div class="shop-cart__row">
					<span><?php esc_html_e( 'Tax', 'shop' ); ?></span>
					<span><?php echo esc_html( $cart->taxTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<div class="shop-cart__row shop-cart__row--total">
				<span><?php esc_html_e( 'Total', 'shop' ); ?></span>
				<span data-shop-cart-grand><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
			</div>
		</div>

		<div class="shop-cart__actions">
			<a
				class="shop-cart__checkout"
				href="<?php echo esc_url( home_url( '/checkout/' ) ); ?>"
				data-shop-cart-checkout
			>
				<?php esc_html_e( 'Checkout', 'shop' ); ?>
				<span class="shop-cart__checkout-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
			</a>
		</div>

	<?php endif; ?>

</div>
