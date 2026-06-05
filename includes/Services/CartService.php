<?php
/**
 * Shop by Therum — CartService.
 *
 * The public API for cart operations. Every channel (REST routes, Bricks
 * elements, PHP templates, future MCP/CLI surfaces) calls into here. The
 * service is the single source of truth for "what does it mean to add
 * something to a cart" — rules, events, totals — and the only one that
 * holds them.
 *
 * Channels wrap, never re-implement.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Events\CartItemAdded;
use Shop\Events\CartItemRemoved;
use Shop\Events\CartItemUpdated;
use Shop\Events\EventBus;
use Shop\Models\Cart;
use Shop\Pipelines\CartTotalsContext;
use Shop\Pipelines\CartTotalsPipeline;
use Shop\Repositories\CartRepository;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartService {

	public function __construct(
		private readonly CartRepository $carts,
		private readonly ProductRepository $products,
		private readonly CartTotalsPipeline $totals,
		private readonly EventBus $events,
	) {}

	/**
	 * Get the cart for the given cookie token, creating one if absent.
	 * Returns the cart with totals freshly computed.
	 */
	public function getOrCreate( string $token, string $currency = 'USD' ): Cart {
		$cart = $this->carts->findByToken( $token );
		if ( $cart === null ) {
			$cart = $this->carts->create( $token, $currency );
		}
		return $this->recalc( $cart );
	}

	/**
	 * Add a (product, variant?) line to the cart. If the same line already
	 * exists, its quantity is incremented (Woo behavior). Fires
	 * CartItemAdded on success.
	 *
	 * Throws on:
	 *   - unknown product
	 *   - product not purchasable (status, capability rules)
	 *   - variant required but missing (hasVariants && variantId === null)
	 *   - insufficient stock
	 *   - product unpriced
	 *
	 * @return Cart The cart with the line included and totals re-run.
	 */
	public function addItem( string $token, int $productId, ?int $variantId, int $quantity ): Cart {
		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'CartService::addItem quantity must be >= 1' );
		}

		return DB::tx( function () use ( $token, $productId, $variantId, $quantity ): Cart {
			$cart    = $this->getOrCreate( $token );
			$product = $this->products->findById( $productId, $cart->currency );

			if ( $product === null ) {
				throw new \DomainException( "Product {$productId} not found" );
			}
			if ( ! $product->isPurchasable() ) {
				throw new \DomainException( "Product {$productId} is not purchasable" );
			}
			if ( $product->hasVariants && $variantId === null ) {
				throw new \DomainException( "Product {$productId} requires a variant selection" );
			}

			$variant = $variantId !== null
				? $this->products->findVariant( $variantId, $cart->currency )
				: null;

			if ( $variantId !== null && $variant === null ) {
				throw new \DomainException( "Variant {$variantId} not found" );
			}
			if ( $variant !== null && $variant->productId !== $product->id ) {
				throw new \DomainException( "Variant {$variantId} does not belong to product {$productId}" );
			}

			$price = $this->products->priceFor( $product, $variant );
			if ( $price === null ) {
				throw new \DomainException( "Product {$productId} has no price" );
			}

			// Check stock against the cumulative qty if a line already exists,
			// not just the incoming qty — adding 2 to an existing line of 3
			// must have stock for 5, not 2.
			$existing = $this->carts->findItem( $cart->id, $product->id, $variant?->id );
			$totalDesired = ( $existing?->quantity ?? 0 ) + $quantity;
			if ( ! $this->products->hasStock( $product, $variant, $totalDesired ) ) {
				throw new \DomainException( "Insufficient stock for product {$productId}" );
			}

			if ( $existing !== null ) {
				$this->carts->setItemQuantity( $existing->id, $totalDesired );
			} else {
				$this->carts->insertItem(
					sessionId:      $cart->id,
					productId:      $product->id,
					variantId:      $variant?->id,
					quantity:       $quantity,
					unitPriceMinor: $price->minor,
				);
			}

			$updated = $this->recalc( $this->carts->findById( $cart->id )
				?? throw new \RuntimeException( 'cart vanished mid-transaction' ) );

			$this->events->dispatch( new CartItemAdded(
				sessionId:    $cart->id,
				sessionToken: $cart->token,
				productId:    $product->id,
				variantId:    $variant?->id,
				quantity:     $quantity,
			) );

			return $updated;
		} );
	}

	/**
	 * Change a line's quantity. Quantity = 0 removes the line.
	 *
	 * @return Cart The cart with totals re-run.
	 */
	public function updateQuantity( string $token, int $itemId, int $quantity ): Cart {
		if ( $quantity < 0 ) {
			throw new \InvalidArgumentException( 'CartService::updateQuantity quantity must be >= 0' );
		}

		return DB::tx( function () use ( $token, $itemId, $quantity ): Cart {
			$cart = $this->carts->findByToken( $token );
			if ( $cart === null ) {
				throw new \DomainException( 'Cart not found' );
			}

			$line = null;
			foreach ( $cart->items as $i ) {
				if ( $i->id === $itemId ) { $line = $i; break; }
			}
			if ( $line === null ) {
				throw new \DomainException( "Cart line {$itemId} not found" );
			}

			if ( $quantity === 0 ) {
				$this->carts->deleteItem( $itemId );

				$updated = $this->recalc( $this->carts->findById( $cart->id )
					?? throw new \RuntimeException( 'cart vanished mid-transaction' ) );

				$this->events->dispatch( new CartItemRemoved(
					sessionId:    $cart->id,
					sessionToken: $cart->token,
					itemId:       $itemId,
					productId:    $line->productId,
					variantId:    $line->variantId,
					quantity:     $line->quantity,
				) );

				return $updated;
			}

			// Re-check stock for the new total.
			$product = $this->products->findById( $line->productId, $cart->currency );
			$variant = $line->variantId !== null
				? $this->products->findVariant( $line->variantId, $cart->currency )
				: null;
			if ( $product === null ) {
				throw new \DomainException( 'Product on this line no longer exists' );
			}
			if ( ! $this->products->hasStock( $product, $variant, $quantity ) ) {
				throw new \DomainException( 'Insufficient stock for requested quantity' );
			}

			$previous = $line->quantity;
			$this->carts->setItemQuantity( $itemId, $quantity );

			$updated = $this->recalc( $this->carts->findById( $cart->id )
				?? throw new \RuntimeException( 'cart vanished mid-transaction' ) );

			$this->events->dispatch( new CartItemUpdated(
				sessionId:        $cart->id,
				sessionToken:     $cart->token,
				itemId:           $itemId,
				previousQuantity: $previous,
				newQuantity:      $quantity,
			) );

			return $updated;
		} );
	}

	public function removeItem( string $token, int $itemId ): Cart {
		return $this->updateQuantity( $token, $itemId, 0 );
	}

	/**
	 * Run the totals pipeline and persist its result back to the session row.
	 * Returns the cart re-hydrated post-write so callers see the same values
	 * the DB now holds.
	 */
	public function recalc( Cart $cart ): Cart {
		$ctx = new CartTotalsContext( $cart );
		$this->totals->run( $ctx );

		$this->carts->updateTotals(
			sessionId:     $cart->id,
			subtotal:      $ctx->subtotal->minor,
			discountTotal: $ctx->discountTotal->minor,
			shippingTotal: $ctx->shippingTotal->minor,
			taxTotal:      $ctx->taxTotal->minor,
			grandTotal:    $ctx->grandTotal->minor,
		);

		return $this->carts->findById( $cart->id )
			?? throw new \RuntimeException( 'cart vanished mid-recalc' );
	}
}
