/**
 * Shop by Therum — admin importer wizard.
 *
 * Vanilla JS. Drives the three-stage flow against the /shop/v1/import/*
 * REST endpoints printed by ImporterPage.
 *
 * Stage transitions:
 *   submit form        → preview()  → render grid → show stage 2
 *   click "Commit"     → commit()   → show stage 3
 *   click "Back"       → show stage 1
 *   click "Import another" → reset, show stage 1
 *
 * No framework. ~250 lines.
 */

( function () {
	'use strict';

	var cfg   = window.ShopImporterConfig || {};
	var REST  = ( cfg.rest || '/wp-json/' ) + 'shop/v1/';
	var NONCE = cfg.nonce || '';

	var root = document.querySelector( '[data-shop-importer]' );
	if ( ! root ) return;

	var form        = root.querySelector( '[data-shop-importer-form]' );
	var stages      = root.querySelectorAll( '[data-stage]' );
	var grid        = root.querySelector( '[data-shop-importer-grid]' );
	var summary     = root.querySelector( '[data-shop-importer-summary]' );
	var doneTitle   = root.querySelector( '[data-shop-importer-done-title]' );
	var doneSub     = root.querySelector( '[data-shop-importer-done-sub]' );

	var current = []; // preview product list — round-tripped to commit

	// ─── Stage helpers ──────────────────────────────────────────────────────
	function show( name ) {
		stages.forEach( function ( s ) {
			var match = s.getAttribute( 'data-stage' ) === name;
			s.setAttribute( 'data-active', match ? '1' : '0' );
			if ( match ) s.removeAttribute( 'hidden' );
			else         s.setAttribute( 'hidden', '' );
		} );
	}

	// ─── Source → Preview ───────────────────────────────────────────────────
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var fileEl  = form.querySelector( '[data-shop-importer-file]' );
		var urlEl   = form.querySelector( '[data-shop-importer-url]' );
		var pickEl  = form.querySelector( '[data-shop-importer-pick]' );

		var body = new FormData();
		if ( pickEl && pickEl.value ) body.append( 'importer', pickEl.value );
		if ( fileEl && fileEl.files && fileEl.files[0] ) {
			body.append( 'file', fileEl.files[0] );
		} else if ( urlEl && urlEl.value ) {
			body.append( 'url', urlEl.value );
		} else {
			alert( 'Pick a file or paste a URL.' );
			return;
		}

		setBusy( form, true );

		fetch( REST + 'import/preview', {
			method: 'POST',
			headers: { 'X-WP-Nonce': NONCE },
			credentials: 'same-origin',
			body: body,
		} )
		.then( function ( r ) { return r.json().then( function ( j ) { return { status: r.status, body: j }; } ); } )
		.then( function ( res ) {
			setBusy( form, false );
			if ( res.status >= 400 ) {
				var msg = ( res.body.error && res.body.error.message ) || 'Preview failed.';
				alert( msg );
				return;
			}
			current = ( res.body.products || [] ).map( function ( p ) {
				p._selected = true; // default-select all
				return p;
			} );
			summary.textContent = res.body.summary || '';
			renderGrid();
			show( 'preview' );
		} )
		.catch( function ( err ) {
			setBusy( form, false );
			alert( 'Preview crashed: ' + err.message );
		} );
	} );

	// ─── Preview → Commit ───────────────────────────────────────────────────
	var commitBtn = root.querySelector( '[data-shop-importer-commit]' );
	commitBtn.addEventListener( 'click', function () {
		var selected = current.filter( function ( p ) { return p._selected; } );
		if ( selected.length === 0 ) {
			alert( 'Select at least one product to import.' );
			return;
		}

		commitBtn.disabled = true;
		commitBtn.textContent = 'Committing…';

		fetch( REST + 'import/commit', {
			method: 'POST',
			headers: {
				'X-WP-Nonce':   NONCE,
				'Content-Type': 'application/json',
			},
			credentials: 'same-origin',
			body: JSON.stringify( { products: selected } ),
		} )
		.then( function ( r ) { return r.json().then( function ( j ) { return { status: r.status, body: j }; } ); } )
		.then( function ( res ) {
			commitBtn.disabled = false;
			commitBtn.textContent = 'Commit selected';
			if ( res.status >= 400 ) {
				alert( ( res.body.error && res.body.error.message ) || 'Commit failed.' );
				return;
			}
			doneTitle.textContent = 'Imported ' + res.body.count + ' product' + ( res.body.count === 1 ? '' : 's' ) + '.';
			doneSub.textContent   = res.body.failures > 0
				? res.body.failures + ' row' + ( res.body.failures === 1 ? '' : 's' ) + ' failed (see server log).'
				: 'All clean. Find them under Shop → Products (coming v0.6).';
			show( 'done' );
		} );
	} );

	root.querySelector( '[data-shop-importer-back]' ).addEventListener( 'click', function () {
		show( 'source' );
	} );

	root.querySelector( '[data-shop-importer-restart]' ).addEventListener( 'click', function () {
		form.reset();
		current = [];
		grid.innerHTML = '';
		show( 'source' );
	} );

	// ─── Grid render ────────────────────────────────────────────────────────
	function renderGrid() {
		grid.innerHTML = current.map( function ( p, i ) {
			var conf = Math.max( 0, Math.min( 100, Math.round( ( p.confidence || 0 ) * 100 ) ) );
			var low  = conf < 60;
			var price = p.price_fmt
				|| ( typeof p.price === 'number' ? '$' + ( p.price / 100 ).toFixed( 2 ) : '—' );
			var issues = ( p.issues || [] ).map( function ( s ) {
				return '<li>' + esc( s ) + '</li>';
			} ).join( '' );
			var variants = ( p.variants && p.variants.length )
				? '<div class="shop-pcard__variants">' + p.variants.length + ' variant' + ( p.variants.length === 1 ? '' : 's' ) + '</div>'
				: '';
			return (
				'<article class="shop-pcard' + ( low ? ' shop-pcard--low' : '' ) + '" data-i="' + i + '">' +
					'<input type="checkbox" class="shop-pcard__check" data-toggle="' + i + '" ' + ( p._selected ? 'checked' : '' ) + ' aria-label="Include">' +
					'<div class="shop-pcard__title">' + esc( p.title || 'Untitled' ) + '</div>' +
					'<div class="shop-pcard__meta">' + esc( p.sku || 'No SKU' ) + '</div>' +
					'<div class="shop-pcard__row shop-pcard__price"><span>Price</span><span>' + esc( price ) + '</span></div>' +
					'<div class="shop-pcard__confidence" title="Confidence: ' + conf + '%">' +
						'<div class="shop-pcard__confidence-bar" style="width:' + conf + '%"></div>' +
					'</div>' +
					variants +
					( issues ? '<div class="shop-pcard__issues"><ul>' + issues + '</ul></div>' : '' ) +
					'<div class="shop-pcard__source">' + esc( p.source_ref || '' ) + '</div>' +
				'</article>'
			);
		} ).join( '' );

		// Wire checkbox toggles
		grid.querySelectorAll( '[data-toggle]' ).forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var i = parseInt( cb.getAttribute( 'data-toggle' ), 10 );
				current[ i ]._selected = cb.checked;
			} );
		} );
	}

	// ─── Utils ──────────────────────────────────────────────────────────────
	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ c ];
		} );
	}

	function setBusy( el, busy ) {
		var btn = el.querySelector( 'button[type="submit"]' );
		if ( ! btn ) return;
		btn.disabled = busy;
		btn.textContent = busy ? 'Detecting…' : 'Detect products';
	}
} )();
