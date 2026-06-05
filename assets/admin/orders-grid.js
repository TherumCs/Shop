/**
 * Shop by Therum — orders grid (admin).
 *
 * Mirror of products-grid.js with order-specific render + edit config.
 * Orders are mostly immutable — only `status` is inline-editable. Bulk
 * "Delete" maps to "cancel" so the audit trail stays intact.
 */

( function () {
	'use strict';

	var cfg   = window.ShopAdminGridConfig || {};
	var REST  = ( cfg.rest || '/wp-json/' ) + 'shop/v1/admin/';
	var NONCE = cfg.nonce || '';

	var root = document.querySelector( '[data-shop-grid="orders"]' );
	if ( ! root ) return;

	var state = {
		page: 1, perPage: 50, total: 0,
		q: '', status: '',
		sort: 'created_at', order: 'desc',
		rows: [], selected: new Set(), lastIdx: null,
	};

	var EDITABLE = {
		status: { type: 'enum', options: [
			'pending', 'processing', 'on-hold', 'completed',
			'cancelled', 'refunded', 'failed',
		] },
	};

	var tbody     = root.querySelector( '[data-shop-grid-tbody]' );
	var qInput    = root.querySelector( '[data-shop-grid-q]' );
	var statusSel = root.querySelector( '[data-shop-grid-status]' );
	var totalEl   = root.querySelector( '[data-shop-grid-total]' );
	var pageEl    = root.querySelector( '[data-shop-grid-page]' );
	var pagesEl   = root.querySelector( '[data-shop-grid-pages]' );
	var prevBtn   = root.querySelector( '[data-shop-grid-prev]' );
	var nextBtn   = root.querySelector( '[data-shop-grid-next]' );
	var toggleAll = root.querySelector( '[data-shop-grid-toggle-all]' );
	var bulkBar   = root.querySelector( '[data-shop-grid-bulk]' );
	var selCount  = root.querySelector( '[data-shop-grid-selected]' );
	var bulkAct   = root.querySelector( '[data-shop-grid-bulk-action]' );
	var bulkRun   = root.querySelector( '[data-shop-grid-bulk-run]' );

	function load() {
		var params = new URLSearchParams( {
			page: state.page, per_page: state.perPage,
			q: state.q, status: state.status,
			sort: state.sort, order: state.order,
		} );
		return fetch( REST + 'orders?' + params.toString(), {
			credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE },
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			state.total = res.total || 0;
			state.rows  = res.rows  || [];
			render();
		} );
	}

	function render() {
		totalEl.textContent = state.total;
		pageEl.textContent  = state.page;
		pagesEl.textContent = Math.max( 1, Math.ceil( state.total / state.perPage ) );

		if ( state.rows.length === 0 ) {
			tbody.innerHTML = '<tr class="shop-grid__empty"><td colspan="9">No orders yet. Test the checkout to seed one.</td></tr>';
			return;
		}

		tbody.innerHTML = state.rows.map( function ( r ) {
			var isSel = state.selected.has( r.id );
			var total = '$' + ( ( r.grand_total || 0 ) / 100 ).toFixed( 2 );
			var refunded = r.refunded_total > 0 ? ' (−$' + ( r.refunded_total / 100 ).toFixed( 2 ) + ')' : '';
			var payment = r.payment_provider
				? esc( r.payment_provider ) + ( r.payment_method ? ' · ' + esc( r.payment_method ) : '' )
				: '<span class="shop-grid__td--meta">unpaid</span>';

			return (
				'<tr data-id="' + r.id + '" class="' + ( isSel ? 'is-selected' : '' ) + '">' +
					'<td class="shop-grid__td shop-grid__td--check">' +
						'<input type="checkbox" data-toggle="' + r.id + '" ' + ( isSel ? 'checked' : '' ) + ' />' +
					'</td>' +
					'<td class="shop-grid__td"><strong>' + esc( r.number || '' ) + '</strong></td>' +
					'<td class="shop-grid__td shop-grid__td--meta">' + ago( r.created_at ) + '</td>' +
					'<td class="shop-grid__td">' + esc( r.email || '—' ) + '</td>' +
					cellEditable( 'status', statusPill( r.status ), r ) +
					'<td class="shop-grid__td"><span class="shop-grid__tag">' + ( r.item_count || 0 ) + '</span></td>' +
					'<td class="shop-grid__td"><strong>' + esc( total ) + '</strong>' +
						( refunded ? '<span class="shop-grid__td--meta">' + esc( refunded ) + '</span>' : '' ) +
					'</td>' +
					'<td class="shop-grid__td">' + payment + '</td>' +
					'<td class="shop-grid__td shop-grid__td--meta">' + ( r.paid_at ? ago( r.paid_at ) : '—' ) + '</td>' +
				'</tr>'
			);
		} ).join( '' );

		var visible = state.rows.map( function ( r ) { return r.id; } );
		toggleAll.checked = visible.length > 0 && visible.every( function ( id ) { return state.selected.has( id ); } );
		updateBulkBar();
	}

	function cellEditable( field, display, row ) {
		return '<td class="shop-grid__td shop-grid__td--editable" data-field="' + field + '" data-id="' + row.id + '" tabindex="0">' +
			'<span class="shop-grid__cell-display">' + display + '</span>' +
		'</td>';
	}

	function statusPill( s ) {
		var s2 = s || 'pending';
		var labels = {
			'pending':    'Pending',     'processing': 'Processing', 'on-hold':   'On hold',
			'completed':  'Completed',   'cancelled':  'Cancelled',  'refunded':  'Refunded',
			'failed':     'Failed',
		};
		// Map order statuses to existing pill classes (active/draft/archived)
		// plus dedicated colors for order-specific states.
		var classMap = {
			'pending':    'draft',
			'processing': 'active',
			'on-hold':    'archived',
			'completed':  'active',
			'cancelled':  'archived',
			'refunded':   'archived',
			'failed':     'archived',
		};
		return '<span class="shop-grid__pill shop-grid__pill--' + esc( classMap[ s2 ] || 'draft' ) + '">' +
			esc( labels[ s2 ] || s2 ) +
		'</span>';
	}

	function ago( ts ) {
		if ( ! ts ) return '';
		var now = Math.floor( Date.now() / 1000 );
		var d   = now - parseInt( ts, 10 );
		if ( d < 60 )      return d + 's ago';
		if ( d < 3600 )    return Math.floor( d / 60 )     + 'm ago';
		if ( d < 86400 )   return Math.floor( d / 3600 )   + 'h ago';
		if ( d < 2592000 ) return Math.floor( d / 86400 )  + 'd ago';
		return new Date( ts * 1000 ).toLocaleDateString();
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
		} );
	}

	// ─── Search + filter + sort ─────────────────────────────────────────────
	var qTimer;
	qInput.addEventListener( 'input', function () {
		clearTimeout( qTimer );
		qTimer = setTimeout( function () {
			state.q    = qInput.value.trim();
			state.page = 1;
			load();
		}, 250 );
	} );

	statusSel.addEventListener( 'change', function () {
		state.status = statusSel.value;
		state.page   = 1;
		load();
	} );

	root.querySelectorAll( 'th[data-sort]' ).forEach( function ( th ) {
		th.addEventListener( 'click', function () {
			var key = th.getAttribute( 'data-sort' );
			if ( state.sort === key ) {
				state.order = state.order === 'asc' ? 'desc' : 'asc';
			} else {
				state.sort  = key;
				state.order = 'desc';
			}
			root.querySelectorAll( 'th[data-sort] .shop-grid__sort-arrow' ).forEach( function ( a ) { a.textContent = ''; } );
			th.querySelector( '.shop-grid__sort-arrow' ).textContent = state.order === 'asc' ? '▲' : '▼';
			load();
		} );
	} );

	prevBtn.addEventListener( 'click', function () { if ( state.page > 1 ) { state.page--; load(); } } );
	nextBtn.addEventListener( 'click', function () {
		if ( state.page < Math.ceil( state.total / state.perPage ) ) { state.page++; load(); }
	} );

	// ─── Selection ──────────────────────────────────────────────────────────
	tbody.addEventListener( 'click', function ( e ) {
		var cb = e.target.closest( '[data-toggle]' );
		if ( ! cb ) return;
		var id  = parseInt( cb.getAttribute( 'data-toggle' ), 10 );
		var idx = state.rows.findIndex( function ( r ) { return r.id === id; } );

		if ( e.shiftKey && state.lastIdx !== null ) {
			var lo = Math.min( idx, state.lastIdx ), hi = Math.max( idx, state.lastIdx );
			for ( var i = lo; i <= hi; i++ ) state.selected.add( state.rows[ i ].id );
		} else if ( cb.checked ) {
			state.selected.add( id );
		} else {
			state.selected.delete( id );
		}
		state.lastIdx = idx;
		render();
	} );

	toggleAll.addEventListener( 'change', function () {
		if ( toggleAll.checked ) state.rows.forEach( function ( r ) { state.selected.add( r.id ); } );
		else                     state.rows.forEach( function ( r ) { state.selected.delete( r.id ); } );
		render();
	} );

	function updateBulkBar() {
		var n = state.selected.size;
		selCount.textContent = n;
		bulkBar.hidden = n === 0;
	}

	// ─── Bulk actions ───────────────────────────────────────────────────────
	bulkRun.addEventListener( 'click', function () {
		var raw = bulkAct.value;
		if ( ! raw ) return;
		var ids = Array.from( state.selected );
		if ( ids.length === 0 ) return;

		var body;
		if ( raw.indexOf( 'status:' ) === 0 ) {
			body = { action: 'status', ids: ids, value: raw.split( ':' )[1] };
		}

		fetch( REST + 'orders/bulk', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function () {
			state.selected.clear();
			bulkAct.value = '';
			load();
		} );
	} );

	// ─── Inline edit (status only on orders) ────────────────────────────────
	tbody.addEventListener( 'dblclick', function ( e ) {
		var td = e.target.closest( 'td.shop-grid__td--editable' );
		if ( td ) startEdit( td );
	} );

	tbody.addEventListener( 'keydown', function ( e ) {
		var td = e.target.closest( 'td.shop-grid__td--editable' );
		if ( ! td ) return;
		if ( e.key === 'Enter' || e.key === 'F2' ) {
			e.preventDefault();
			startEdit( td );
		}
	} );

	function startEdit( td ) {
		var field = td.getAttribute( 'data-field' );
		var id    = parseInt( td.getAttribute( 'data-id' ), 10 );
		var row   = state.rows.find( function ( r ) { return r.id === id; } );
		if ( ! row || ! EDITABLE[ field ] ) return;
		if ( td.querySelector( 'input, select' ) ) return;

		var cfg = EDITABLE[ field ];
		var current = row[ field ];

		var editor = document.createElement( 'select' );
		cfg.options.forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o;
			opt.textContent = o.charAt( 0 ).toUpperCase() + o.slice( 1 ).replace( '-', ' ' );
			if ( o === current ) opt.selected = true;
			editor.appendChild( opt );
		} );

		var prev = td.querySelector( '.shop-grid__cell-display' ).innerHTML;
		td.innerHTML = '';
		td.appendChild( editor );
		editor.focus();

		function commit() {
			var newVal = editor.value;
			td.innerHTML = '<span class="shop-grid__cell-display">' + prev + '</span>';
			td.classList.add( 'is-saving' );

			var body = {}; body[ field ] = newVal;
			fetch( REST + 'orders/' + id, {
				method: 'PATCH',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
			} )
			.then( function ( r ) { return r.json().then( function ( j ) { return { status: r.status, body: j }; } ); } )
			.then( function ( res ) {
				td.classList.remove( 'is-saving' );
				if ( res.status >= 400 ) {
					alert( ( res.body.error && res.body.error.message ) || 'Save failed.' );
					return;
				}
				row[ field ] = newVal;
				render();
			} );
		}

		function cancel() {
			td.innerHTML = '<span class="shop-grid__cell-display">' + prev + '</span>';
		}

		editor.addEventListener( 'blur', commit );
		editor.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' )  { e.preventDefault(); editor.blur(); }
			if ( e.key === 'Escape' ) { e.preventDefault(); editor.removeEventListener( 'blur', commit ); cancel(); }
		} );
	}

	load();
} )();
