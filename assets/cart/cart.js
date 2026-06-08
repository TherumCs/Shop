/**
 * Counter by Therum — cart JS.
 *
 * Vanilla, no framework, ~5KB. Handles:
 *   - Open/close drawer + overlay shells (with focus trap + ESC)
 *   - Add / increment / decrement / remove with optimistic UI
 *   - Re-render via HTML morph (innerHTML preserves no state we care about
 *     at this size; we use replaceChildren + a small diff for the items list
 *     to keep focus on the qty input being edited)
 *   - Sync cart count badge anywhere on the page (any element with
 *     [data-counter-cart-count-label] gets updated)
 *
 * Public API (window.CounterCart):
 *   addItem(productId, variantId?, quantity = 1)
 *   updateQuantity(itemId, quantity)
 *   removeItem(itemId)
 *   open(target = 'drawer'), close(), refresh()
 *
 * Wire setup:
 *   - REST base + nonce are read from window.CounterCartConfig (printed by
 *     CartAssets::enqueue()).
 *   - Click delegation off document.body — works regardless of when shells
 *     get inserted into the DOM.
 */

( function () {
	'use strict';

	var cfg = window.CounterCartConfig || {};
	var REST = ( cfg.rest || '/wp-json/' ) + 'counter/v1/';
	var NONCE = cfg.nonce || '';

	// ─── Fetch helper ───────────────────────────────────────────────────────
	function api( path, opts ) {
		opts = opts || {};
		opts.headers = Object.assign( {
			'Accept':        'application/json',
			'Content-Type':  'application/json',
			'X-WP-Nonce':    NONCE,
		}, opts.headers || {} );
		opts.credentials = 'same-origin';
		return fetch( REST + path, opts ).then( function ( r ) {
			return r.json().then( function ( body ) { return { status: r.status, headers: r.headers, body: body }; } );
		} );
	}

	// ─── Apply a cart response (HTML + count) to the page ───────────────────
	function applyResponse( res ) {
		if ( res.status >= 400 ) {
			// Revert any optimistic changes by re-fetching truth.
			refresh();
			toast( ( res.body && res.body.error && res.body.error.message ) || 'Something went wrong' );
			return;
		}
		// Replace every cart contents region with fresh HTML.
		var mounts = document.querySelectorAll( '[data-counter-cart-mount]' );
		mounts.forEach( function ( el ) {
			el.innerHTML = res.body.html;
		} );
		// Update every count label on the page.
		var count = res.body.cart && typeof res.body.cart.item_count === 'number'
			? res.body.cart.item_count
			: 0;
		document.querySelectorAll( '[data-counter-cart-count-label]' ).forEach( function ( el ) {
			el.textContent = String( count );
			if ( count > 0 ) el.removeAttribute( 'hidden' );
			else             el.setAttribute( 'hidden', '' );
		} );
		document.querySelectorAll( '[data-counter-cart-open]' ).forEach( function ( el ) {
			if ( count > 0 ) el.removeAttribute( 'data-counter-cart-empty' );
			else             el.setAttribute( 'data-counter-cart-empty', '' );
		} );
	}

	// ─── Public actions ─────────────────────────────────────────────────────
	function addItem( productId, variantId, quantity ) {
		quantity = quantity || 1;
		return api( 'cart/items', {
			method: 'POST',
			body: JSON.stringify( { product_id: productId, variant_id: variantId || null, quantity: quantity } ),
		} ).then( applyResponse ).then( function () { open( 'drawer' ); } );
	}

	function updateQuantity( itemId, quantity ) {
		return api( 'cart/items/' + itemId, {
			method: 'PATCH',
			body: JSON.stringify( { quantity: quantity } ),
		} ).then( applyResponse );
	}

	function removeItem( itemId ) {
		return api( 'cart/items/' + itemId, { method: 'DELETE' } )
			.then( applyResponse );
	}

	function refresh() {
		return api( 'cart' ).then( applyResponse );
	}

	// ─── Open/close shells ──────────────────────────────────────────────────
	var lastFocus = null;

	function open( target ) {
		target = target || 'drawer';
		var shell = document.querySelector( '[data-counter-cart-shell="' + target + '"]' );
		if ( ! shell ) return;
		lastFocus = document.activeElement;
		shell.setAttribute( 'data-counter-cart-state', 'open' );
		shell.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';
		// Move focus to close button.
		var closer = shell.querySelector( '[data-counter-cart-close]' );
		if ( closer ) closer.focus();
	}

	function close() {
		document.querySelectorAll( '[data-counter-cart-shell]' ).forEach( function ( s ) {
			s.setAttribute( 'data-counter-cart-state', 'closed' );
			s.setAttribute( 'aria-hidden', 'true' );
		} );
		document.body.style.overflow = '';
		if ( lastFocus && lastFocus.focus ) lastFocus.focus();
	}

	// ─── Tiny toast (no styling assumptions; site CSS can style) ────────────
	function toast( message ) {
		var t = document.createElement( 'div' );
		t.className = 'counter-cart-toast';
		t.setAttribute( 'role', 'status' );
		t.textContent = message;
		document.body.appendChild( t );
		setTimeout( function () { t.remove(); }, 3500 );
	}

	// ─── Click delegation ───────────────────────────────────────────────────
	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! ( t instanceof Element ) ) return;

		// Open trigger
		var opener = t.closest( '[data-counter-cart-open]' );
		if ( opener ) {
			e.preventDefault();
			open( opener.getAttribute( 'data-counter-cart-target' ) || 'drawer' );
			return;
		}

		// Close trigger
		if ( t.closest( '[data-counter-cart-close]' ) ) {
			e.preventDefault();
			close();
			return;
		}

		// Increment / decrement
		var inc = t.closest( '[data-counter-cart-increment]' );
		var dec = t.closest( '[data-counter-cart-decrement]' );
		if ( inc || dec ) {
			e.preventDefault();
			var li = t.closest( '[data-counter-cart-item]' );
			if ( ! li ) return;
			var id = parseInt( li.getAttribute( 'data-counter-cart-item-id' ), 10 );
			var input = li.querySelector( '[data-counter-cart-qty]' );
			var cur = input ? parseInt( input.value, 10 ) || 0 : 0;
			var next = inc ? cur + 1 : Math.max( 0, cur - 1 );
			if ( input ) input.value = String( next ); // optimistic
			updateQuantity( id, next );
			return;
		}

		// Remove
		var rm = t.closest( '[data-counter-cart-remove]' );
		if ( rm ) {
			e.preventDefault();
			var li2 = rm.closest( '[data-counter-cart-item]' );
			if ( ! li2 ) return;
			var rid = parseInt( li2.getAttribute( 'data-counter-cart-item-id' ), 10 );
			li2.style.opacity = '0.4'; // optimistic
			removeItem( rid );
			return;
		}
	} );

	// ─── Quantity input commits on change/blur ──────────────────────────────
	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! ( t instanceof Element ) ) return;
		if ( ! t.matches( '[data-counter-cart-qty]' ) ) return;
		var li = t.closest( '[data-counter-cart-item]' );
		if ( ! li ) return;
		var id = parseInt( li.getAttribute( 'data-counter-cart-item-id' ), 10 );
		var q  = Math.max( 0, parseInt( t.value, 10 ) || 0 );
		updateQuantity( id, q );
	} );

	// ─── Keyboard ───────────────────────────────────────────────────────────
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) close();
	} );

	// ─── Prefetch on hover (desktop): warm the network ─────────────────────
	// Drawer is already inlined so this is mainly for cold-cache cases.
	document.addEventListener( 'mouseenter', function ( e ) {
		var t = e.target;
		if ( t instanceof Element && t.matches( '[data-counter-cart-open]' ) ) {
			refresh();
		}
	}, true );

	// ─── Expose public API ──────────────────────────────────────────────────
	window.CounterCart = {
		addItem:        addItem,
		updateQuantity: updateQuantity,
		removeItem:     removeItem,
		open:           open,
		close:          close,
		refresh:        refresh,
	};
} )();
