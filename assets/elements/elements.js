/**
 * Counter by Therum — element interactivity.
 *
 * Three behaviors:
 *
 *   1. Gallery: click a thumb → main image swaps
 *   2. Variant picker: click a swatch / button / pick a dropdown →
 *      tally the selection across all attribute groups, resolve to a
 *      variant_id via the REST endpoint, then broadcast that variant_id
 *      so Price / Stock / AddToCart re-render
 *   3. Add to cart: click → CounterCart.addItem( productId, variantId, qty )
 *
 * ~5 KB minified. Loaded once per page when any interactive element is
 * on the page (server decides via Element::needsJs()).
 */

( function () {
	'use strict';

	var cfg  = window.CounterCartConfig || {};
	var REST = ( cfg.rest || '/wp-json/' ) + 'counter/v1/';

	// ─── Gallery ────────────────────────────────────────────────────────────
	document.addEventListener( 'click', function ( e ) {
		var thumb = e.target.closest( '[data-counter-gallery-thumb]' );
		if ( ! thumb ) return;

		var gallery = thumb.closest( '[data-counter-gallery]' );
		if ( ! gallery ) return;

		var src = thumb.getAttribute( 'data-src' );
		var img = gallery.querySelector( '[data-counter-gallery-main] img' );
		if ( img && src ) img.src = src;

		gallery.querySelectorAll( '[data-counter-gallery-thumb]' ).forEach( function ( t ) {
			t.classList.remove( 'is-active' );
		} );
		thumb.classList.add( 'is-active' );
	} );

	// ─── Variant picker ─────────────────────────────────────────────────────
	// Each picker holds a state object: { attrSlug: optionSlug }. Updated
	// on any selection. We POST to /counter/v1/products/match-variant to
	// resolve, then broadcast `shop:variantChange` so Price / Stock /
	// AddToCart can update without a page reload.
	function pickerState( picker ) {
		if ( ! picker._state ) picker._state = {};
		return picker._state;
	}

	function resolveVariant( picker ) {
		var productId = picker.getAttribute( 'data-counter-product-id' );
		var state     = pickerState( picker );
		var params = new URLSearchParams( { product_id: productId } );
		Object.keys( state ).forEach( function ( k ) {
			if ( state[ k ] ) params.append( 'options[' + k + ']', state[ k ] );
		} );

		fetch( REST + 'products/match-variant?' + params.toString(), {
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			var variantId = res && res.variant_id ? res.variant_id : null;
			picker.setAttribute( 'data-counter-variant-id', variantId || '' );

			// Broadcast for siblings (AddToCart, Price)
			document.dispatchEvent( new CustomEvent( 'counter:variantChange', {
				detail: { productId: productId, variantId: variantId },
			} ) );
		} );
	}

	// Swatch / button picks
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-counter-option-value]' );
		if ( ! btn ) return;
		var group = btn.closest( '[data-counter-attr-slug]' );
		if ( ! group ) return;
		var picker = group.closest( '[data-counter-variant-picker]' );
		if ( ! picker ) return;

		var slug = group.getAttribute( 'data-counter-attr-slug' );
		var val  = btn.getAttribute( 'data-counter-option-value' );

		pickerState( picker )[ slug ] = val;

		group.querySelectorAll( '[data-counter-option-value]' ).forEach( function ( b ) {
			b.classList.remove( 'is-selected' );
		} );
		btn.classList.add( 'is-selected' );

		resolveVariant( picker );
	} );

	// Dropdown picks
	document.addEventListener( 'change', function ( e ) {
		var sel = e.target.closest( '[data-counter-option-select]' );
		if ( ! sel ) return;
		var group  = sel.closest( '[data-counter-attr-slug]' );
		var picker = sel.closest( '[data-counter-variant-picker]' );
		if ( ! group || ! picker ) return;

		var slug = group.getAttribute( 'data-counter-attr-slug' );
		pickerState( picker )[ slug ] = sel.value;
		resolveVariant( picker );
	} );

	// ─── AddToCart ──────────────────────────────────────────────────────────
	// Listen to variantChange to know which variant is currently selected
	// per product. Multiple products on one page (e.g. archive) tracked
	// in a map.
	var selectedVariants = {}; // productId → variantId

	document.addEventListener( 'counter:variantChange', function ( e ) {
		selectedVariants[ e.detail.productId ] = e.detail.variantId;
	} );

	// Qty stepper
	document.addEventListener( 'click', function ( e ) {
		var inc = e.target.closest( '[data-counter-qty-inc]' );
		var dec = e.target.closest( '[data-counter-qty-dec]' );
		if ( ! inc && ! dec ) return;
		var wrap = ( inc || dec ).closest( '[data-counter-qty]' );
		if ( ! wrap ) return;
		var input = wrap.querySelector( '[data-counter-qty-input]' );
		if ( ! input ) return;
		var cur = parseInt( input.value, 10 ) || 1;
		input.value = String( Math.max( 1, inc ? cur + 1 : cur - 1 ) );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-counter-add-to-cart-btn]' );
		if ( ! btn ) return;
		if ( ! window.CounterCart || typeof window.CounterCart.addItem !== 'function' ) return;

		var productId = parseInt( btn.getAttribute( 'data-counter-product-id' ), 10 );
		var wrap      = btn.closest( '[data-counter-add-to-cart]' );
		var qtyInput  = wrap && wrap.querySelector( '[data-counter-qty-input]' );
		var qty       = qtyInput ? Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 ) : 1;
		var variantId = selectedVariants[ String( productId ) ] || null;

		// Optimistic UX — disable briefly
		btn.disabled = true;
		var origLabel = btn.querySelector( '.counter-el-add-to-cart__label' );
		var origText  = origLabel ? origLabel.textContent : '';
		if ( origLabel ) origLabel.textContent = 'Adding…';

		Promise.resolve( window.CounterCart.addItem( productId, variantId, qty ) )
			.then( function () {
				if ( origLabel ) origLabel.textContent = 'Added ✓';
				setTimeout( function () {
					if ( origLabel ) origLabel.textContent = origText;
					btn.disabled = false;
				}, 1200 );
			} )
			.catch( function () {
				if ( origLabel ) origLabel.textContent = origText;
				btn.disabled = false;
			} );
	} );

	// ─── Cart button (chrome): open + live count badge ────────────────
	// Delegated open — any [data-counter-cart-open] click asks the active
	// cart pattern to show itself. Falls back to navigating to /cart/.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-counter-cart-open]' );
		if ( ! btn ) return;
		e.preventDefault();
		if ( window.CounterCart && typeof window.CounterCart.open === 'function' ) {
			window.CounterCart.open();
		} else {
			window.location.href = '/cart/';
		}
	} );

	// Live count — initial fetch + react to shop:cartChange events.
	function paintCount( count ) {
		document.querySelectorAll( '[data-counter-cart-count]' ).forEach( function ( el ) {
			el.textContent = String( count );
			el.style.display = count > 0 ? '' : 'none';
		} );
	}
	if ( window.CounterCart && typeof window.CounterCart.snapshot === 'function' ) {
		Promise.resolve( window.CounterCart.snapshot() )
			.then( function ( s ) { paintCount( s && s.count ? s.count : 0 ); } )
			.catch( function () {} );
	} else {
		paintCount( 0 );
	}
	document.addEventListener( 'counter:cartChange', function ( e ) {
		paintCount( e.detail && typeof e.detail.count === 'number' ? e.detail.count : 0 );
	} );
} )();
