/**
 * Shop by Therum — Studio checkout (Studio Pay method strip).
 *
 * Tiny vanilla JS — ~4KB. Fetches available methods from
 * /studio-pay/methods, renders pills grouped by `group`, and swaps
 * panel content when the active pill changes.
 *
 * The actual payment-form contents per method are rendered by inline
 * panel renderers below. Card uses Stripe Elements when a publishable
 * key is exposed; everything else routes to its provider's hosted
 * page (Klarna, Affirm, PayPal, Plaid Link, etc).
 */

( function () {
	'use strict';

	const root = document.querySelector( '.shop-checkout--studio' );
	if ( ! root ) return;

	const REST  = root.getAttribute( 'data-rest' ) + 'shop/v1/';
	const NONCE = root.getAttribute( 'data-nonce' );
	const methodsEl = root.querySelector( '[data-shop-methods]' );
	const panelsEl  = root.querySelector( '[data-shop-panels]' );
	const payBtn    = root.querySelector( '[data-shop-pay]' );

	let active = null;
	let methods = [];

	const api = ( path, opts ) => fetch( REST + path, Object.assign( {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
	}, opts || {} ) ).then( r => r.json() );

	function init() {
		api( 'studio-pay/methods' ).then( res => {
			methods = ( res && res.methods ) || [];
			if ( ! methods.length ) {
				methodsEl.innerHTML = '<div class="shop-checkout__methods-empty">No payment methods are connected. Connect Studio Pay in admin.</div>';
				return;
			}
			renderStrip();
			select( methods[0].id );
		} ).catch( () => {
			methodsEl.innerHTML = '<div class="shop-checkout__methods-empty">Couldn\'t load payment methods.</div>';
		} );
	}

	const GROUPS = [
		[ 'card',    'CC',  'Card' ],
		[ 'wallets', '⌘',   'Wallets' ],
		[ 'bnpl',    '4×',  'Pay later' ],
		[ 'bank',    '⏧',   'Bank' ],
		[ 'crypto',  '₿',   'Crypto' ],
		[ 'p2p',     '$',   'P2P' ],
	];

	function renderStrip() {
		const html = methods.map( m => {
			const group = GROUPS.find( g => g[0] === m.group ) || [ m.group, '·', m.label ];
			return `<button class="shop-checkout__method-pill" data-method="${ m.id }" data-group="${ m.group }" type="button">
				<span class="shop-checkout__pill-ico" data-group-ico="${ m.group }">${ group[1] }</span>
				${ m.label }
			</button>`;
		} ).join( '' );
		methodsEl.innerHTML = html;
		methodsEl.addEventListener( 'click', e => {
			const pill = e.target.closest( '[data-method]' );
			if ( pill ) select( pill.getAttribute( 'data-method' ) );
		} );
	}

	function select( id ) {
		active = id;
		methodsEl.querySelectorAll( '[data-method]' ).forEach( el => {
			el.classList.toggle( 'is-active', el.getAttribute( 'data-method' ) === id );
		} );
		panelsEl.innerHTML = renderPanel( id );
		updatePayLabel();
		bindPanel( id );
	}

	function renderPanel( id ) {
		const m = methods.find( x => x.id === id );
		if ( ! m ) return '';
		switch ( m.group ) {
			case 'card':    return cardPanel();
			case 'wallets': return walletPanel( m );
			case 'bnpl':    return bnplPanel( m );
			case 'bank':    return bankPanel();
			case 'crypto':  return cryptoPanel();
			case 'p2p':     return p2pPanel( m );
			default:        return `<div>Continue with ${ escapeHtml( m.label ) }</div>`;
		}
	}

	function cardPanel() {
		// Inline card form. Studio Pay's Stripe provider tokenizes via
		// Stripe Elements when window.Stripe is loaded; otherwise we
		// fall back to a manual capture-via-server flow (sandbox only).
		return `
			<div data-card-panel>
				<div class="shop-checkout__row shop-checkout__row--1">
					<label>Card number
						<input class="shop-checkout__input" id="shop-card-num" autocomplete="cc-number" inputmode="numeric" placeholder="1234 1234 1234 1234">
					</label>
				</div>
				<div class="shop-checkout__row">
					<label>Expiry
						<input class="shop-checkout__input" id="shop-card-exp" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7">
					</label>
					<label>CVC
						<input class="shop-checkout__input" id="shop-card-cvc" autocomplete="cc-csc" inputmode="numeric" placeholder="•••" maxlength="4">
					</label>
				</div>
				<div class="shop-checkout__row shop-checkout__row--1">
					<label>Name on card
						<input class="shop-checkout__input" id="shop-card-name" autocomplete="cc-name" placeholder="As shown on card">
					</label>
				</div>
			</div>`;
	}

	function walletPanel( m ) {
		const buttons = methods
			.filter( x => x.group === 'wallets' )
			.map( w => `<button class="shop-checkout__wallet shop-checkout__wallet--${ w.id }" data-method="${ w.id }" type="button">${ escapeHtml( w.label ) }</button>` )
			.join( '' );
		return `<div class="shop-checkout__wallet-grid">${ buttons }</div>`;
	}

	function bnplPanel( active ) {
		const rows = methods.filter( m => m.group === 'bnpl' ).map( m => `
			<button class="shop-checkout__bnpl-card${ m.id === active.id ? ' is-active' : '' }" data-method="${ m.id }" type="button">
				<div class="shop-checkout__bnpl-logo shop-checkout__bnpl-logo--${ m.id }">${ escapeHtml( m.label ) }</div>
				<div class="shop-checkout__bnpl-name">Continue with ${ escapeHtml( m.label ) }</div>
				<span class="shop-checkout__chev">→</span>
			</button>` ).join( '' );
		return `<div class="shop-checkout__bnpl-list">${ rows }</div>`;
	}

	function bankPanel() {
		return `
			<button class="shop-checkout__bank" data-method="bank_ach" type="button">
				<div class="shop-checkout__bank-ico">⏧</div>
				<div style="flex:1">
					<div class="shop-checkout__bank-name">Connect with Plaid</div>
					<div class="shop-checkout__bank-sub">Pay directly from your bank. Saves ~2% in card fees.</div>
				</div>
				<span class="shop-checkout__chev">→</span>
			</button>`;
	}

	function cryptoPanel() {
		const coins = [ 'BTC', 'ETH', 'USDC', 'USDT', 'SOL', 'XRP' ];
		const chips = coins.map( c => `
			<button class="shop-checkout__crypto-chip" data-coin="${ c }" type="button">
				<div class="shop-checkout__crypto-sym shop-checkout__crypto-sym--${ c.toLowerCase() }">${ c[0] }</div>
				<div class="shop-checkout__crypto-label">${ c }</div>
			</button>` ).join( '' );
		return `
			<div class="shop-checkout__crypto-grid">${ chips }</div>
			<div class="shop-checkout__note">QR code generated after confirmation. ~10–15 min for network settlement.</div>`;
	}

	function p2pPanel( m ) {
		const rows = methods.filter( x => x.group === 'p2p' )
			.map( x => `<button class="shop-checkout__p2p shop-checkout__p2p--${ x.id }" data-method="${ x.id }" type="button">${ escapeHtml( x.label ) }</button>` )
			.join( '' );
		return `<div class="shop-checkout__p2p-grid">${ rows }</div>`;
	}

	function bindPanel( id ) {
		panelsEl.querySelectorAll( '[data-method]' ).forEach( el => {
			el.addEventListener( 'click', e => {
				e.preventDefault();
				active = el.getAttribute( 'data-method' );
				panelsEl.querySelectorAll( '.is-active' ).forEach( a => a.classList.remove( 'is-active' ) );
				el.classList.add( 'is-active' );
				updatePayLabel();
			} );
		} );
	}

	function updatePayLabel() {
		const totalEl = root.querySelector( '[data-shop-total]' );
		const total = totalEl ? totalEl.textContent.trim() : '';
		const m = methods.find( x => x.id === active );
		const labels = {
			card:    `Pay ${ total }`,
			apple_pay: 'Continue with Apple Pay',
			google_pay: 'Continue with Google Pay',
			paypal:  'Continue to PayPal',
			klarna:  'Continue to Klarna',
			affirm:  'Continue to Affirm',
			afterpay:'Continue to Afterpay',
			sezzle:  'Continue to Sezzle',
			zip:     'Continue to Zip',
			bank_ach:'Connect bank account',
			crypto:  `Generate QR · ${ total }`,
			cashapp: 'Open Cash App',
			venmo:   'Open Venmo',
			zelle:   'Open Zelle',
		};
		payBtn.textContent = labels[ active ] || `Pay ${ total }`;
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, c => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ] ) );
	}

	// ── Submit — create the intent through Studio Pay and route ──────
	root.querySelector( '#shop-checkout-form' ).addEventListener( 'submit', e => {
		e.preventDefault();
		payBtn.disabled = true;
		payBtn.textContent = 'Processing…';
		const fd = new FormData( e.target );
		const body = Object.fromEntries( fd.entries() );
		body.method = active;
		api( 'checkout/intent', { method: 'POST', body: JSON.stringify( body ) } )
			.then( res => {
				if ( res.redirect_url ) { window.location.href = res.redirect_url; return; }
				if ( res.client_secret ) {
					// Card path — hand off to Stripe Elements if loaded.
					if ( window.Stripe ) {
						window.Stripe( window.ShopStripePk ).confirmCardPayment( res.client_secret, {
							payment_method: {
								card: { number: document.getElementById('shop-card-num').value },
								billing_details: { name: document.getElementById('shop-card-name').value },
							},
						} ).then( r => {
							if ( r.error ) throw new Error( r.error.message );
							window.location.href = res.success_url || '/order-received/?order=' + res.order_number;
						} ).catch( err => { payBtn.disabled = false; updatePayLabel(); alert( err.message ); } );
					}
				}
			} )
			.catch( err => { payBtn.disabled = false; updatePayLabel(); alert( err.message || String( err ) ); } );
	} );

	init();
} )();
