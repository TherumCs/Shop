/**
 * Counter by Therum — PayPal Smart Buttons mount.
 *
 * One PayPal connector backs three visible buttons at checkout:
 * PayPal, Venmo, PayPal Credit. The PayPal SDK is loaded once with
 * `enable-funding=venmo,paylater`; we then call paypal.Buttons({
 * fundingSource }) for each visible funding source against the mount
 * points the checkout template lays out.
 *
 * Mount points are any element with `data-paypal-funding="<source>"`.
 * Wire mode: this script is enqueued on the checkout page. It hits
 * /counter/v1/studio-pay/paypal-config on load; if PayPal isn't
 * connected, every PayPal-funding mount point is hidden and we exit.
 *
 * Order creation flow:
 *   click → paypal.Buttons.createOrder() → POST /counter/v1/checkout/intent
 *     body: { method: 'paypal' | 'venmo' | 'paypal_credit' }
 *     response: { intent_id, redirect_url }
 *   onApprove → SDK captures → server webhook → order paid
 *
 * The createOrder result returns PayPal's order id (the intent_id we
 * stored); SDK uses it transparently.
 */

( function () {
	'use strict';

	const cfg   = window.CounterCheckoutConfig || {};
	const REST  = ( cfg.rest || '/wp-json/' ) + 'counter/v1/';
	const NONCE = cfg.nonce || '';

	// SDK funding-source id → our canonical method id
	const FUNDING_TO_METHOD = {
		paypal:   'paypal',
		venmo:    'venmo',
		paylater: 'paypal_credit',
	};

	function api( path, opts ) {
		return fetch( REST + path, Object.assign( {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
		}, opts || {} ) ).then( r => r.json() );
	}

	function loadSdk( url ) {
		return new Promise( ( resolve, reject ) => {
			if ( window.paypal ) { resolve( window.paypal ); return; }
			const s = document.createElement( 'script' );
			s.src = url;
			s.async = true;
			s.onload  = () => window.paypal ? resolve( window.paypal ) : reject( new Error( 'PayPal SDK loaded but window.paypal missing.' ) );
			s.onerror = () => reject( new Error( 'PayPal SDK failed to load.' ) );
			document.head.appendChild( s );
		} );
	}

	function hideMounts() {
		document.querySelectorAll( '[data-paypal-funding]' ).forEach( el => { el.hidden = true; } );
	}

	function mountFor( paypal, mountEl, fundingId ) {
		const method = FUNDING_TO_METHOD[ fundingId ] || 'paypal';
		const FUND   = paypal.FUNDING[ fundingId.toUpperCase() ];
		if ( ! FUND ) { mountEl.hidden = true; return; }

		// Eligibility — PayPal SDK can short-circuit if the buyer's
		// device/region can't render this funding source (Venmo on
		// desktop, for example). Hide quietly when ineligible.
		const buttons = paypal.Buttons( {
			fundingSource: FUND,
			style: { layout: 'horizontal', height: 40, tagline: false, label: 'paypal' },

			createOrder: () =>
				api( 'checkout/intent', {
					method: 'POST',
					body: JSON.stringify( { method } ),
				} ).then( r => {
					if ( r.error ) throw new Error( r.error.message || 'Intent creation failed.' );
					return r.intent_id;
				} ),

			onApprove: ( data, actions ) => actions.order.capture().then( details => {
				// Forward to our success route; server webhook will reconcile.
				window.location.href = cfg.success_url || '/checkout/thanks';
			} ),

			onError: err => {
				console.error( '[Counter PayPal]', err );
				if ( cfg.on_error ) cfg.on_error( err );
			},
		} );

		if ( ! buttons.isEligible() ) {
			mountEl.hidden = true;
			return;
		}
		buttons.render( mountEl );
	}

	function init() {
		api( 'studio-pay/paypal-config' )
			.then( c => {
				if ( ! c.connected ) { hideMounts(); return; }
				return loadSdk( c.sdk_url ).then( paypal => {
					document.querySelectorAll( '[data-paypal-funding]' ).forEach( el => {
						const fundingId = el.getAttribute( 'data-paypal-funding' );
						// Replace the static button label with an empty
						// container the SDK will fill.
						el.textContent = '';
						mountFor( paypal, el, fundingId );
					} );
				} );
			} )
			.catch( e => {
				console.error( '[Counter PayPal]', e );
				hideMounts();
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
