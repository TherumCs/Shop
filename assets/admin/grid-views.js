/**
 * Shop by Therum — grid views.
 *
 * Companion module for products-grid.js / orders-grid.js that adds:
 *
 *   - Column visibility (per-user)
 *   - Column reorder via drag handles (per-user)
 *   - Saved views — named bundles of { filters, columns, sort } that
 *     show up in a dropdown above the grid. Persisted server-side via
 *     /admin/grid-views so they survive across browsers.
 *
 * Boots itself on any element with [data-shop-grid-views="<grid-id>"].
 * Talks to the grid via a tiny event channel so products-grid /
 * orders-grid don't have to know it exists.
 *
 * Events out (from grid to views):
 *   shop:grid:state    detail = { gridId, columns, filters, sort }
 *
 * Events in (from views to grid):
 *   shop:grid:apply    detail = { columns, filters, sort }
 */

( function () {
	'use strict';

	const cfg   = window.ShopAdminGridConfig || {};
	const REST  = ( cfg.rest || '/wp-json/' ) + 'shop/v1/admin/grid-views/';
	const NONCE = cfg.nonce || '';

	const mounts = document.querySelectorAll( '[data-shop-grid-views]' );
	if ( ! mounts.length ) return;

	mounts.forEach( init );

	function init( root ) {
		const gridId = root.getAttribute( 'data-shop-grid-views' );
		let views    = [];
		let current  = { columns: null, filters: {}, sort: null };

		const api = ( path, opts ) => fetch( REST + path, Object.assign( {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
		}, opts || {} ) ).then( r => r.json() );

		api( gridId ).then( r => { views = ( r && r.views ) || []; render(); } );

		// Track grid's current state so "Save view" captures what's on screen.
		document.addEventListener( 'shop:grid:state', e => {
			if ( ! e.detail || e.detail.gridId !== gridId ) return;
			current = e.detail;
		} );

		function apply( view ) {
			document.dispatchEvent( new CustomEvent( 'shop:grid:apply', { detail: Object.assign( { gridId }, view ) } ) );
		}

		function render() {
			root.innerHTML = `
				<div class="shop-views">
					<select class="shop-views__pick">
						<option value="">— Views —</option>
						${ views.map( v => `<option value="${ v.id }">${ esc( v.name ) }</option>` ).join( '' ) }
					</select>
					<button class="button" data-action="save">Save current as view</button>
					<button class="button" data-action="columns">Columns</button>
				</div>
				<div class="shop-views__col-panel" hidden></div>`;

			root.querySelector( '.shop-views__pick' ).addEventListener( 'change', e => {
				const v = views.find( x => String( x.id ) === e.target.value );
				if ( v ) apply( v.config );
			} );
			root.querySelector( '[data-action="save"]' ).addEventListener( 'click', () => {
				const name = prompt( 'View name:' );
				if ( ! name ) return;
				api( gridId, { method: 'POST', body: JSON.stringify( { name, config: current } ) } )
					.then( r => { if ( r && r.view ) { views.push( r.view ); render(); } } );
			} );
			root.querySelector( '[data-action="columns"]' ).addEventListener( 'click', () => {
				const panel = root.querySelector( '.shop-views__col-panel' );
				panel.hidden = ! panel.hidden;
				if ( ! panel.hidden ) renderColumnPanel( panel );
			} );
		}

		function renderColumnPanel( panel ) {
			const cols = ( current.columns || [] ).slice();
			panel.innerHTML = `
				<p class="shop-views__hint">Drag to reorder. Uncheck to hide.</p>
				<ul class="shop-views__col-list">
					${ cols.map( ( c, i ) => `
						<li class="shop-views__col" draggable="true" data-i="${ i }">
							<span class="shop-views__col-grip">⋮⋮</span>
							<label><input type="checkbox" data-c="${ c.id }" ${ c.visible ? 'checked' : '' }> ${ esc( c.label ) }</label>
						</li>` ).join( '' ) }
				</ul>`;

			let dragI = null;
			panel.querySelectorAll( '.shop-views__col' ).forEach( li => {
				li.addEventListener( 'dragstart', e => { dragI = +li.getAttribute( 'data-i' ); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData( 'text/plain', String( dragI ) ); } );
				li.addEventListener( 'dragover',  e => { e.preventDefault(); } );
				li.addEventListener( 'drop',      e => {
					e.preventDefault();
					const dstI = +li.getAttribute( 'data-i' );
					if ( dragI === null || dstI === dragI ) return;
					const next = cols.slice();
					const [ moved ] = next.splice( dragI, 1 );
					next.splice( dstI, 0, moved );
					apply( { columns: next } );
					renderColumnPanel( panel );
				} );
			} );

			panel.addEventListener( 'change', e => {
				const c = e.target.getAttribute( 'data-c' );
				if ( ! c ) return;
				const next = cols.map( x => x.id === c ? Object.assign( {}, x, { visible: e.target.checked } ) : x );
				apply( { columns: next } );
			} );
		}

		function esc( s ) {
			return String( s == null ? '' : s ).replace( /[&<>"']/g, c => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ] ) );
		}
	}
} )();
