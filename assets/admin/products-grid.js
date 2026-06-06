/**
 * Shop by Therum — products grid (admin).
 *
 * Vanilla, no framework. Drives the spreadsheet-style product manager:
 *
 *   - Fetch + render rows from /shop/v1/admin/products
 *   - Debounced search
 *   - Status filter
 *   - Column sort (click header)
 *   - Pagination
 *   - Per-row select (with shift-click range), select-all
 *   - Bulk actions: delete / duplicate / status change
 *   - Inline cell edit: click cell → input → Enter or blur to commit,
 *     Esc to cancel. PATCH single field. Optimistic UI with rollback
 *     on server reject.
 *
 * Roughly 350 lines. The whole "spreadsheet manager" in one file.
 */

( function () {
	'use strict';

	var cfg   = window.ShopAdminGridConfig || {};
	var REST  = ( cfg.rest || '/wp-json/' ) + 'shop/v1/admin/';
	var NONCE = cfg.nonce || '';

	var root = document.querySelector( '[data-shop-grid="products"]' );
	if ( ! root ) return;

	// ─── State ──────────────────────────────────────────────────────────────
	var state = {
		page:     1,
		perPage:  50,
		total:    0,
		q:        '',
		status:   '',
		sort:     'updated_at',
		order:    'desc',
		rows:     [],
		selected: new Set(),
		lastIdx:  null,
	};

	// Editable column config — what type of input to render, how to serialize
	var EDITABLE = {
		title:     { type: 'text' },
		sku:       { type: 'text' },
		status:    { type: 'enum', options: [ 'active', 'draft', 'archived' ] },
		price:     { type: 'cents' },
		stock_qty: { type: 'int' },
	};

	// ─── DOM refs ───────────────────────────────────────────────────────────
	var tbody       = root.querySelector( '[data-shop-grid-tbody]' );
	var qInput      = root.querySelector( '[data-shop-grid-q]' );
	var statusSel   = root.querySelector( '[data-shop-grid-status]' );
	var totalEl     = root.querySelector( '[data-shop-grid-total]' );
	var pageEl      = root.querySelector( '[data-shop-grid-page]' );
	var pagesEl     = root.querySelector( '[data-shop-grid-pages]' );
	var prevBtn     = root.querySelector( '[data-shop-grid-prev]' );
	var nextBtn     = root.querySelector( '[data-shop-grid-next]' );
	var toggleAll   = root.querySelector( '[data-shop-grid-toggle-all]' );
	var bulkBar     = root.querySelector( '[data-shop-grid-bulk]' );
	var selCountEl  = root.querySelector( '[data-shop-grid-selected]' );
	var bulkAct     = root.querySelector( '[data-shop-grid-bulk-action]' );
	var bulkRun     = root.querySelector( '[data-shop-grid-bulk-run]' );

	// ─── Fetch + render ─────────────────────────────────────────────────────
	function load() {
		var params = new URLSearchParams( {
			page:     state.page,
			per_page: state.perPage,
			q:        state.q,
			status:   state.status,
			sort:     state.sort,
			order:    state.order,
		} );
		return fetch( REST + 'products?' + params.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE },
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
			tbody.innerHTML = '<tr class="shop-grid__empty"><td colspan="10">No products. <a href="?page=shop-import">Import some.</a></td></tr>';
			return;
		}

		tbody.innerHTML = state.rows.map( function ( r ) {
			var isSel = state.selected.has( r.id );
			var price = r.price !== null && r.price !== undefined ? '$' + ( r.price / 100 ).toFixed( 2 ) : '—';
			var stock = r.stock_qty !== null && r.stock_qty !== undefined ? r.stock_qty : '—';
			var img   = r.image_url
				? '<img src="' + esc( r.image_url ) + '" alt="" />'
				: '<div class="shop-grid__no-img"></div>';
			var type  = typeBadges( r );
			return (
				'<tr data-id="' + r.id + '" class="' + ( isSel ? 'is-selected' : '' ) + '">' +
					'<td class="shop-grid__td shop-grid__td--check">' +
						'<input type="checkbox" data-toggle="' + r.id + '" ' + ( isSel ? 'checked' : '' ) + ' />' +
					'</td>' +
					'<td class="shop-grid__td shop-grid__td--img" data-shop-open-product="' + r.id + '" title="Open editor">' + img + '</td>' +
					cellEditable( 'title',    esc( r.title || '' ),    r ) +
					cellEditable( 'status',   statusPill( r.status ),  r ) +
					cellEditable( 'price',    esc( price ),            r ) +
					cellEditable( 'stock_qty',esc( String( stock ) ),  r ) +
					cellEditable( 'sku',      esc( r.sku || '' ),      r ) +
					'<td class="shop-grid__td">' + type + '</td>' +
					'<td class="shop-grid__td shop-grid__td--meta">' + ago( r.updated_at ) + '</td>' +
					'<td class="shop-grid__td shop-grid__td--action"><button class="button button-small" data-shop-open-product="' + r.id + '">Open</button></td>' +
				'</tr>'
			);
		} ).join( '' );

		// All checkbox reflects whether the visible rows are all selected
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
		var label = s === 'active' ? 'Active' : ( s === 'archived' ? 'Archived' : 'Draft' );
		return '<span class="shop-grid__pill shop-grid__pill--' + esc( s || 'draft' ) + '">' + esc( label ) + '</span>';
	}

	function typeBadges( r ) {
		var out = [];
		if ( r.has_variants ) out.push( 'Variable' );
		if ( r.is_pod )       out.push( 'POD' );
		if ( r.is_digital )   out.push( 'Digital' );
		if ( ! r.is_shippable && ! r.is_digital ) out.push( 'Service' );
		if ( out.length === 0 ) out.push( 'Simple' );
		return out.map( function ( s ) { return '<span class="shop-grid__tag">' + esc( s ) + '</span>'; } ).join( '' );
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
				state.order = 'asc';
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

	// ─── Open drawer (img cell or "Open" button) ────────────────────────────
	// Delegated to the table so newly-rendered rows pick it up automatically.
	// The product editor module listens for `shop:open-product` on document.
	tbody.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-shop-open-product]' );
		if ( ! btn ) return;
		var id = parseInt( btn.getAttribute( 'data-shop-open-product' ), 10 );
		if ( ! id ) return;
		document.dispatchEvent( new CustomEvent( 'shop:open-product', { detail: { id: id } } ) );
	} );

	// ─── Selection (with shift-range) ───────────────────────────────────────
	tbody.addEventListener( 'click', function ( e ) {
		var cb = e.target.closest( '[data-toggle]' );
		if ( ! cb ) return;
		var id = parseInt( cb.getAttribute( 'data-toggle' ), 10 );
		var idx = state.rows.findIndex( function ( r ) { return r.id === id; } );

		if ( e.shiftKey && state.lastIdx !== null ) {
			var lo = Math.min( idx, state.lastIdx );
			var hi = Math.max( idx, state.lastIdx );
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
		if ( toggleAll.checked ) {
			state.rows.forEach( function ( r ) { state.selected.add( r.id ); } );
		} else {
			state.rows.forEach( function ( r ) { state.selected.delete( r.id ); } );
		}
		render();
	} );

	function updateBulkBar() {
		var n = state.selected.size;
		selCountEl.textContent = n;
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
		} else if ( raw === 'delete' ) {
			if ( ! confirm( 'Delete ' + ids.length + ' product(s)?' ) ) return;
			body = { action: 'delete', ids: ids };
		} else if ( raw === 'duplicate' ) {
			body = { action: 'duplicate', ids: ids };
		}

		fetch( REST + 'products/bulk', {
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

	// ─── Inline cell edit ───────────────────────────────────────────────────
	tbody.addEventListener( 'dblclick', function ( e ) {
		var td = e.target.closest( '[data-shop-grid-table] td.shop-grid__td--editable' );
		if ( td ) startEdit( td );
	} );

	tbody.addEventListener( 'keydown', function ( e ) {
		var td = e.target.closest( '[data-shop-grid-table] td.shop-grid__td--editable' );
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
		if ( td.querySelector( 'input, select' ) ) return; // already editing

		var cfg = EDITABLE[ field ];
		var current = row[ field ];
		var editor;

		if ( cfg.type === 'enum' ) {
			editor = document.createElement( 'select' );
			cfg.options.forEach( function ( o ) {
				var opt = document.createElement( 'option' );
				opt.value = o; opt.textContent = o.charAt( 0 ).toUpperCase() + o.slice( 1 );
				if ( o === current ) opt.selected = true;
				editor.appendChild( opt );
			} );
		} else {
			editor = document.createElement( 'input' );
			editor.type = ( cfg.type === 'int' || cfg.type === 'cents' ) ? 'number' : 'text';
			if ( cfg.type === 'cents' ) {
				editor.step  = '0.01';
				editor.value = current !== null && current !== undefined ? ( current / 100 ).toFixed( 2 ) : '';
			} else {
				editor.value = current !== null && current !== undefined ? current : '';
			}
		}

		var display = td.querySelector( '.shop-grid__cell-display' );
		var prev    = display.innerHTML;
		td.innerHTML = '';
		td.appendChild( editor );
		editor.focus();
		if ( editor.select ) editor.select();

		function commit() {
			var raw = editor.value;
			var newVal;
			if ( cfg.type === 'cents' ) newVal = raw === '' ? null : Math.round( parseFloat( raw ) * 100 );
			else if ( cfg.type === 'int' ) newVal = raw === '' ? null : parseInt( raw, 10 );
			else newVal = raw;

			td.innerHTML = '<span class="shop-grid__cell-display">' + prev + '</span>';
			td.classList.add( 'is-saving' );

			var body = {}; body[ field ] = newVal;
			fetch( REST + 'products/' + id, {
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
			if ( e.key === 'Tab' )    { e.preventDefault(); editor.blur(); /* TODO: focus next editable */ }
		} );
	}

	// ─── Boot ──────────────────────────────────────────────────────────────
	load();
} )();
