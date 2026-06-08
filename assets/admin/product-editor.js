/**
 * Counter by Therum — Product editor drawer.
 *
 * Preact-based side drawer (720px from the right) that mounts onto
 * the products grid. Listens for a custom event
 * (`shop:open-product`, detail = { id }) so the grid stays decoupled
 * from this module — `products-grid.js` only has to dispatch.
 *
 * All 7 tabs are wired end-to-end against /counter/v1/admin/*:
 *   - General, Pricing, Inventory, Shipping, SEO → product PATCH
 *     (autosave debounced 800 ms, dirty-tracked per group)
 *   - Variants → per-row PATCH on /admin/variants/{id}
 *   - Media → primary + gallery PATCH on /admin/products/{id}/images
 *     via the WP media-library picker (wp.media)
 *   - "Saved 3s ago" pill in header
 *   - Esc / overlay click closes; warns if there are unsaved changes
 *
 * Preact + htm from esm.sh, no build step.
 *
 * Mount point: `<div id="counter-product-editor-root"></div>` added to
 * the products page via PHP. The script auto-creates it if missing.
 */

import { h, render }                                              from 'https://esm.sh/preact@10.22.0';
import htm                                                        from 'https://esm.sh/htm@3.1.1';
import { useState, useEffect, useRef, useCallback, useMemo }      from 'https://esm.sh/preact@10.22.0/hooks';

const html = htm.bind( h );

const cfg  = window.CounterAdminGridConfig || {};
const REST = ( cfg.rest || '/wp-json/' ) + 'counter/v1/admin/';
const NONCE = cfg.nonce || '';

function api( path, opts ) {
	return fetch( REST + path, Object.assign( {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
	}, opts || {} ) ).then( async r => {
		// Always try to parse JSON, but if the server returned non-2xx
		// surface that as a thrown error so the drawer's `Loading…` state
		// resolves into a visible error instead of hanging forever.
		const text = await r.text();
		let body;
		try { body = text ? JSON.parse( text ) : {}; }
		catch ( _ ) { throw new Error( `Server returned ${ r.status } with non-JSON body.` ); }
		if ( ! r.ok ) {
			const msg = body && body.message ? body.message
				: body && body.error && body.error.message ? body.error.message
				: `Server returned ${ r.status }.`;
			throw new Error( msg );
		}
		return body;
	} );
}

const TABS = [
	{ id: 'general',   label: 'General' },
	{ id: 'pricing',   label: 'Pricing' },
	{ id: 'variants',  label: 'Variants' },
	{ id: 'inventory', label: 'Inventory' },
	{ id: 'shipping',  label: 'Shipping' },
	{ id: 'media',     label: 'Media' },
	{ id: 'seo',       label: 'SEO' },
];

function fmtAgo( ts ) {
	if ( ! ts ) return '';
	const s = Math.round( ( Date.now() - ts ) / 1000 );
	if ( s < 5 )    return 'just now';
	if ( s < 60 )   return s + 's ago';
	if ( s < 3600 ) return Math.floor( s / 60 ) + 'm ago';
	return Math.floor( s / 3600 ) + 'h ago';
}

function Drawer() {
	const [ openId,   setOpenId   ] = useState( null );
	const [ product,  setProduct  ] = useState( null );
	const [ tab,      setTab      ] = useState( 'general' );
	const [ dirty,    setDirty    ] = useState( {} );  // { group: true } per touched group
	const [ saving,   setSaving   ] = useState( false );
	const [ savedAt,  setSavedAt  ] = useState( null );
	const [ error,    setError    ] = useState( '' );
	const [ now,      setNow      ] = useState( Date.now() );

	// Tick savedAt label every 5 s for "3s ago" → "1m ago" rollover
	useEffect( () => {
		const t = setInterval( () => setNow( Date.now() ), 5000 );
		return () => clearInterval( t );
	}, [] );

	// Listen for grid → drawer open events
	useEffect( () => {
		const onOpen = e => {
			const id = e?.detail?.id;
			if ( ! id ) return;
			setOpenId( id );
			setProduct( null );
			setTab( 'general' );
			setDirty( {} );
			setSavedAt( null );
			setError( '' );
			api( 'products/' + id )
				.then( p => {
					if ( p.error ) setError( p.error.message || 'Could not load product.' );
					else setProduct( p );
				} )
				.catch( e => setError( e.message || 'Could not load product.' ) );
		};
		document.addEventListener( 'counter:open-product', onOpen );
		return () => document.removeEventListener( 'counter:open-product', onOpen );
	}, [] );

	// Esc / overlay click closes
	useEffect( () => {
		if ( ! openId ) return;
		const onKey = e => { if ( e.key === 'Escape' ) close(); };
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ openId, dirty ] );

	const close = useCallback( () => {
		if ( Object.keys( dirty ).length && saving ) return;
		if ( Object.keys( dirty ).length ) {
			if ( ! confirm( 'Unsaved changes — close anyway?' ) ) return;
		}
		setOpenId( null );
	}, [ dirty, saving ] );

	// Mutate-then-mark-dirty per group. Path is dotted ("price.regular").
	const update = useCallback( ( path, value ) => {
		if ( ! product ) return;
		const [ group, field ] = path.split( '.' );
		setProduct( p => {
			const next = { ...p };
			if ( field ) {
				next[ group ] = { ...( p[ group ] || {} ), [ field ]: value };
			} else {
				next[ group ] = value;
			}
			return next;
		} );
		setDirty( d => ( { ...d, [ field ? group : path ]: true } ) );
	}, [ product ] );

	// Debounced autosave — fires 800ms after the last edit. Only PATCHes
	// the groups that are dirty, not the whole product.
	useEffect( () => {
		if ( ! product || ! Object.keys( dirty ).length ) return;
		const t = setTimeout( () => {
			const payload = {};
			for ( const k of Object.keys( dirty ) ) payload[ k ] = product[ k ];
			setSaving( true );
			api( 'products/' + product.id, {
				method: 'PATCH',
				body:   JSON.stringify( payload ),
			} ).then( r => {
				if ( r.error ) setError( r.error.message || 'Save failed.' );
				else {
					setSavedAt( Date.now() );
					setDirty( {} );
					setError( '' );
				}
			} ).finally( () => setSaving( false ) );
		}, 800 );
		return () => clearTimeout( t );
	}, [ product, dirty ] );

	if ( ! openId ) return null;

	return html`
		<div class="counter-pe-overlay" onClick=${ close } />
		<aside class="counter-pe" onClick=${ e => e.stopPropagation() }>
			<header class="counter-pe__head">
				<div class="counter-pe__head-l">
					<button class="counter-pe__close" onClick=${ close } title="Close (Esc)">✕</button>
					<div>
						<div class="counter-pe__title">
							${ product?.title || ( error ? 'Error' : 'Loading…' ) }
						</div>
						<div class="counter-pe__sub">
							${ product ? html`
								<span class="counter-pe__chip counter-pe__chip--${ product.status }">${ product.status }</span>
								<span class="counter-pe__chip counter-pe__chip--ghost">${ product.type }</span>
								<span class="counter-pe__chip counter-pe__chip--ghost">#${ product.id }</span>
							` : '' }
						</div>
					</div>
				</div>
				<div class="counter-pe__head-r">
					${ error ? html`<span class="counter-pe__pill counter-pe__pill--err">${ error }</span>` : null }
					${ saving ? html`<span class="counter-pe__pill">Saving…</span>`
						: savedAt ? html`<span class="counter-pe__pill counter-pe__pill--ok">Saved ${ fmtAgo( savedAt ) }</span>`
						: null }
					${ product?.urls?.edit_in_woo ? html`<a class="counter-pe__woo" href=${ product.urls.edit_in_woo } target="_blank">Edit in Woo ↗</a>` : '' }
					${ product?.urls?.view ? html`<a class="counter-pe__view" href=${ product.urls.view } target="_blank">View ↗</a>` : '' }
				</div>
			</header>

			<nav class="counter-pe__tabs">
				${ TABS.map( t => html`
					<button
						key=${ t.id }
						class=${ "counter-pe__tab" + ( tab === t.id ? " is-active" : "" ) }
						onClick=${ () => setTab( t.id ) }
					>${ t.label }</button>
				` ) }
			</nav>

			<div class="counter-pe__body">
				${ product
					? renderTab( tab, product, update )
					: error
						? html`<div class="counter-pe__loading counter-pe__loading--err">${ error }</div>`
						: html`<div class="counter-pe__loading">Loading…</div>` }
			</div>

			<footer class="counter-pe__foot">
				<button type="button" class="counter-pe__btn counter-pe__btn--ghost" onClick=${ close }>Cancel</button>
				<button type="button" class="counter-pe__btn counter-pe__btn--primary" onClick=${ close } disabled=${ saving || ! product }>
					${ saving ? 'Saving…' : 'Done' }
				</button>
			</footer>
		</aside>
	`;
}

function renderTab( tab, p, update ) {
	switch ( tab ) {
		case 'general':   return html`<${ Tab_General }   p=${ p } update=${ update } />`;
		case 'pricing':   return html`<${ Tab_Pricing }   p=${ p } update=${ update } />`;
		case 'inventory': return html`<${ Tab_Inventory } p=${ p } update=${ update } />`;
		case 'variants':  return html`<${ Tab_Variants }  p=${ p } update=${ update } />`;
		case 'shipping':  return html`<${ Tab_Shipping }  p=${ p } update=${ update } />`;
		case 'media':     return html`<${ Tab_Media }     p=${ p } update=${ update } />`;
		case 'seo':       return html`<${ Tab_SEO }       p=${ p } update=${ update } />`;
		default:          return null;
	}
}

// ─── Form primitives ────────────────────────────────────────────────────
function Field( { label, hint, children, span } ) {
	return html`
		<div class=${ "counter-pe-f" + ( span ? " counter-pe-f--" + span : "" ) }>
			${ label ? html`<label class="counter-pe-f__l">${ label }</label>` : null }
			${ children }
			${ hint ? html`<div class="counter-pe-f__hint">${ hint }</div>` : null }
		</div>
	`;
}

function Input( { value, onInput, type='text', placeholder, mono } ) {
	return html`<input
		class=${ "counter-pe-in" + ( mono ? " counter-pe-in--mono" : "" ) }
		type=${ type }
		placeholder=${ placeholder || '' }
		value=${ value ?? '' }
		onInput=${ e => onInput( e.target.value ) }
	/>`;
}

function Textarea( { value, onInput, rows=6 } ) {
	return html`<textarea
		class="counter-pe-in counter-pe-in--ta"
		rows=${ rows }
		onInput=${ e => onInput( e.target.value ) }
	>${ value ?? '' }</textarea>`;
}

function Select( { value, onInput, options } ) {
	return html`<select class="counter-pe-in" onChange=${ e => onInput( e.target.value ) }>
		${ Object.entries( options ).map( ( [ v, l ] ) => html`<option value=${ v } selected=${ v === String( value ) }>${ l }</option>` ) }
	</select>`;
}

function Toggle( { value, onInput, label } ) {
	return html`<label class="counter-pe-tog">
		<input type="checkbox" checked=${ !! value } onChange=${ e => onInput( e.target.checked ) } />
		<span class="counter-pe-tog__sw"></span>
		<span>${ label }</span>
	</label>`;
}

// Money input — UI is in dollars, state is cents. Two-way conversion.
function MoneyInput( { cents, onInput, placeholder } ) {
	const display = ( cents == null || cents === '' ) ? '' : ( cents / 100 ).toFixed( 2 );
	return html`
		<div class="counter-pe-money">
			<span class="counter-pe-money__sym">$</span>
			<input
				class="counter-pe-in counter-pe-in--mono"
				type="text"
				inputmode="decimal"
				placeholder=${ placeholder || '0.00' }
				value=${ display }
				onInput=${ e => {
					const v = e.target.value.trim();
					if ( v === '' ) return onInput( null );
					const n = parseFloat( v );
					if ( ! isNaN( n ) ) onInput( Math.round( n * 100 ) );
				} }
			/>
		</div>
	`;
}

// ─── Tabs ───────────────────────────────────────────────────────────────
function Tab_General( { p, update } ) {
	return html`
		<div class="counter-pe-rows">
			<${ Field } label="Title">
				<${ Input } value=${ p.title } onInput=${ v => update( 'title', v ) } />
			</Field>
			<${ Field } label="Slug" hint="The URL handle">
				<${ Input } mono=${ true } value=${ p.slug } onInput=${ v => update( 'slug', v ) } />
			</Field>
			<${ Field } label="Status">
				<${ Select } value=${ p.status } onInput=${ v => update( 'status', v ) } options=${ {
					publish: 'Published',
					draft:   'Draft',
					pending: 'Pending review',
					private: 'Private',
				} } />
			</Field>
			<${ Field } label="Short description" hint="Shown in cart, search, archives">
				<${ Textarea } rows=${ 3 } value=${ p.short_description } onInput=${ v => update( 'short_description', v ) } />
			</Field>
			<${ Field } label="Description" hint="Full description for the product page">
				<${ Textarea } rows=${ 10 } value=${ p.description } onInput=${ v => update( 'description', v ) } />
			</Field>
		</div>
	`;
}

function Tab_Pricing( { p, update } ) {
	return html`
		<div class="counter-pe-rows">
			<${ Field } label="Regular price">
				<${ MoneyInput } cents=${ p.price.regular } onInput=${ v => update( 'price.regular', v ) } />
			</Field>
			<${ Field } label="Sale price" hint="Leave blank for no sale">
				<${ MoneyInput } cents=${ p.price.sale } onInput=${ v => update( 'price.sale', v ) } />
			</Field>
			<${ Field } label="Cost (private)" hint="For margin calculations. Not shown to customers.">
				<${ MoneyInput } cents=${ p.price.cost } onInput=${ v => update( 'price.cost', v ) } />
			</Field>
		</div>
	`;
}

function Tab_Inventory( { p, update } ) {
	return html`
		<div class="counter-pe-rows">
			<${ Field } label="SKU">
				<${ Input } mono=${ true } value=${ p.inventory.sku } onInput=${ v => update( 'inventory.sku', v ) } />
			</Field>
			<${ Field }>
				<${ Toggle } label="Track inventory" value=${ p.inventory.manage_stock } onInput=${ v => update( 'inventory.manage_stock', v ) } />
			</Field>
			${ p.inventory.manage_stock ? html`
				<${ Field } label="Stock quantity">
					<${ Input } type="number" mono=${ true } value=${ p.inventory.stock_qty } onInput=${ v => update( 'inventory.stock_qty', v === '' ? null : parseInt( v, 10 ) ) } />
				</Field>
				<${ Field } label="Low-stock threshold" hint="Alert when stock falls below this">
					<${ Input } type="number" mono=${ true } value=${ p.inventory.low_stock_amount } onInput=${ v => update( 'inventory.low_stock_amount', v === '' ? null : parseInt( v, 10 ) ) } />
				</Field>
				<${ Field } label="Backorders">
					<${ Select } value=${ p.inventory.backorder || 'no' } onInput=${ v => update( 'inventory.backorder', v ) } options=${ {
						no:      'Not allowed',
						notify:  'Allowed, notify customer',
						yes:     'Allowed',
					} } />
				</Field>
			` : null }
			<${ Field } label="Stock status">
				<${ Select } value=${ p.inventory.stock_status || 'instock' } onInput=${ v => update( 'inventory.stock_status', v ) } options=${ {
					instock:     'In stock',
					outofstock:  'Out of stock',
					onbackorder: 'On backorder',
				} } />
			</Field>
		</div>
	`;
}

// Inline-editable variant row. Each cell is a controlled input that
// PATCHes /admin/variants/{id} on blur. Prices are typed in dollars but
// stored in cents on the server.
function VariantRow( { v } ) {
	const [ row,    setRow    ] = useState( v );
	const [ saving, setSaving ] = useState( null ); // field name being saved
	const [ err,    setErr    ] = useState( '' );

	useEffect( () => { setRow( v ); }, [ v.id ] );

	function save( field, value ) {
		setSaving( field );
		setErr( '' );
		const payload = { [ field ]: value };
		api( 'variants/' + row.id, {
			method: 'PATCH',
			body: JSON.stringify( payload ),
		} ).then( r => {
			if ( r.error ) setErr( r.error.message || 'Save failed.' );
			else if ( r.variant ) setRow( r.variant );
		} ).finally( () => setSaving( null ) );
	}

	const dollarsToCents = s => {
		const n = parseFloat( String( s ).replace( /[^\d.\-]/g, '' ) );
		return isNaN( n ) ? null : Math.round( n * 100 );
	};
	const centsToDollars = c => ( c === null || c === undefined ) ? '' : ( c / 100 ).toFixed( 2 );

	return html`
		<tr>
			<td>
				<input class="counter-pe-vt-in" value=${ row.sku || '' }
					onInput=${ e => setRow( { ...row, sku: e.target.value } ) }
					onBlur=${ e => e.target.value !== ( v.sku || '' ) && save( 'sku', e.target.value ) }
					placeholder="—" />
			</td>
			<td>${ Object.values( row.attributes || {} ).join( ' / ' ) || '—' }</td>
			<td>
				<input class="counter-pe-vt-in counter-pe-vt-in--mono" value=${ centsToDollars( row.regular_price ) }
					onBlur=${ e => save( 'price', dollarsToCents( e.target.value ) ) }
					placeholder="0.00" />
			</td>
			<td>
				<input class="counter-pe-vt-in counter-pe-vt-in--mono" value=${ centsToDollars( row.sale_price ) }
					onBlur=${ e => save( 'sale_price', dollarsToCents( e.target.value ) ) }
					placeholder="0.00" />
			</td>
			<td>
				<input class="counter-pe-vt-in counter-pe-vt-in--mono" type="number" value=${ row.stock_qty ?? '' }
					onBlur=${ e => save( 'stock_qty', e.target.value === '' ? null : parseInt( e.target.value, 10 ) ) }
					placeholder="—" />
			</td>
			<td class="counter-pe-vt-status">
				${ saving ? html`<span class="counter-pe-pill">Saving…</span>` : null }
				${ err    ? html`<span class="counter-pe-pill counter-pe-pill--err">${ err }</span>` : null }
			</td>
		</tr>
	`;
}

function Tab_Variants ( { p } ) {
	if ( ! p.variants || p.variants.length === 0 ) {
		return html`<div class="counter-pe-empty"><p>This product has no variants. Toggle <strong>Has variants</strong> in the General tab to enable variant management.</p></div>`;
	}
	return html`
		<div class="counter-pe-rows">
			<p class="counter-pe-vt-hint">${ p.variants.length } variant${ p.variants.length === 1 ? '' : 's' }. Click any cell to edit; changes save when you tab out.</p>
			<table class="counter-pe-vt">
				<thead><tr><th>SKU</th><th>Attributes</th><th>Price</th><th>Sale</th><th>Stock</th><th></th></tr></thead>
				<tbody>
					${ p.variants.map( v => html`<${ VariantRow } key=${ v.id } v=${ v } />` ) }
				</tbody>
			</table>
		</div>
	`;
}

function Tab_Shipping ( { p, update } ) {
	return html`
		<div class="counter-pe-rows">
			<${ Field }>
				<${ Toggle } label="Virtual (no shipping)"   value=${ p.shipping.virtual }      onInput=${ v => update( 'shipping.virtual', v ) } />
			</Field>
			<${ Field }>
				<${ Toggle } label="Downloadable (digital)"  value=${ p.shipping.downloadable } onInput=${ v => update( 'shipping.downloadable', v ) } />
			</Field>
			${ ! p.shipping.virtual ? html`
				<${ Field } label="Weight">
					<${ Input } mono=${ true } value=${ p.shipping.weight } onInput=${ v => update( 'shipping.weight', v ) } placeholder="0.5" />
				</Field>
				<div class="counter-pe-grid3">
					<${ Field } label="Length">
						<${ Input } mono=${ true } value=${ p.shipping.length } onInput=${ v => update( 'shipping.length', v ) } />
					</Field>
					<${ Field } label="Width">
						<${ Input } mono=${ true } value=${ p.shipping.width  } onInput=${ v => update( 'shipping.width',  v ) } />
					</Field>
					<${ Field } label="Height">
						<${ Input } mono=${ true } value=${ p.shipping.height } onInput=${ v => update( 'shipping.height', v ) } />
					</Field>
				</div>
			` : null }
		</div>
	`;
}

// Media tab — real WordPress media-library picker for primary + gallery.
// wp.media is enqueued by AdminMenu via wp_enqueue_media() on the
// products page, so it's already available globally as window.wp.media
// when this component renders. Each saved selection PATCHes the new
// /admin/products/{id}/images endpoint, which atomically rewrites the
// product_images rowset and updates products.primary_image_id.
function Tab_Media( { p, update } ) {
	const [ primary, setPrimary ] = useState( p.images.primary );
	const [ gallery, setGallery ] = useState( p.images.gallery || [] );
	const [ saving, setSaving   ] = useState( false );
	const [ err,    setErr      ] = useState( '' );

	useEffect( () => { setPrimary( p.images.primary ); setGallery( p.images.gallery || [] ); }, [ p.id ] );

	function persist( nextPrimary, nextGallery ) {
		setSaving( true );
		setErr( '' );
		api( 'products/' + p.id + '/images', {
			method: 'PATCH',
			body: JSON.stringify( {
				primary_id:  nextPrimary ? nextPrimary.id : null,
				gallery_ids: nextGallery.map( g => g.attachment_id || g.id ),
			} ),
		} ).then( r => {
			if ( r.error ) {
				setErr( r.error.message || 'Save failed.' );
			} else if ( r.images ) {
				setPrimary( r.images.primary );
				setGallery( r.images.gallery || [] );
				if ( update ) update( 'images', r.images );
			}
		} ).finally( () => setSaving( false ) );
	}

	function openPicker( multiple, onSelect ) {
		if ( ! window.wp || ! window.wp.media ) {
			setErr( 'WordPress media library unavailable. Reload the page.' );
			return;
		}
		const frame = window.wp.media( {
			title:    multiple ? 'Pick gallery images' : 'Pick a primary image',
			button:   { text: 'Use selection' },
			multiple,
			library:  { type: 'image' },
		} );
		frame.on( 'select', () => {
			const sel = frame.state().get( 'selection' );
			onSelect( multiple
				? sel.map( a => ( { id: a.id, attachment_id: a.id, url: a.attributes.url } ) )
				: ( () => { const a = sel.first(); return { id: a.id, url: a.attributes.url }; } )()
			);
		} );
		frame.open();
	}

	function pickPrimary() {
		openPicker( false, sel => {
			setPrimary( sel );
			persist( sel, gallery );
		} );
	}

	function pickGallery() {
		openPicker( true, picked => {
			// Merge — skip dupes already in the gallery.
			const existing = new Set( gallery.map( g => g.attachment_id || g.id ) );
			const merged   = gallery.concat( picked.filter( g => ! existing.has( g.attachment_id || g.id ) ) );
			setGallery( merged );
			persist( primary, merged );
		} );
	}

	function removeFromGallery( id ) {
		const next = gallery.filter( g => ( g.attachment_id || g.id ) !== id );
		setGallery( next );
		persist( primary, next );
	}

	function clearPrimary() {
		setPrimary( null );
		persist( null, gallery );
	}

	return html`
		<div class="counter-pe-rows">
			<div class="counter-pe-media-block">
				<div class="counter-pe-media-block__head">
					<h3>Primary image</h3>
					${ saving ? html`<span class="counter-pe-pill">Saving…</span>` : null }
					${ err    ? html`<span class="counter-pe-pill counter-pe-pill--err">${ err }</span>` : null }
				</div>
				${ primary
					? html`
						<div class="counter-pe-media-primary">
							<img src=${ primary.url } alt="" />
							<div class="counter-pe-media-actions">
								<button type="button" class="button" onClick=${ pickPrimary }>Replace</button>
								<button type="button" class="button button-link-delete" onClick=${ clearPrimary }>Remove</button>
							</div>
						</div>
					`
					: html`
						<div class="counter-pe-media-empty">
							<button type="button" class="button button-primary" onClick=${ pickPrimary }>Choose image</button>
						</div>
					`
				}
			</div>

			<div class="counter-pe-media-block">
				<div class="counter-pe-media-block__head">
					<h3>Gallery <span class="counter-pe-media-count">${ gallery.length }</span></h3>
					<button type="button" class="button" onClick=${ pickGallery }>Add images</button>
				</div>
				${ gallery.length
					? html`
						<div class="counter-pe-gallery">
							${ gallery.map( g => html`
								<div class="counter-pe-gallery__item" key=${ g.id || g.attachment_id }>
									<img src=${ g.url } alt=${ g.alt || '' } />
									<button type="button" class="counter-pe-gallery__rm" title="Remove"
										onClick=${ () => removeFromGallery( g.attachment_id || g.id ) }>×</button>
								</div>
							` ) }
						</div>
					`
					: html`<p class="counter-pe-media-hint">No gallery images yet.</p>`
				}
			</div>
		</div>
	`;
}

function Tab_SEO( { p, update } ) {
	return html`
		<div class="counter-pe-rows">
			<${ Field } label="Meta title" hint="Shown in search engine results">
				<${ Input } value=${ p.seo.meta_title } onInput=${ v => update( 'seo.meta_title', v ) } />
			</Field>
			<${ Field } label="Meta description" hint="155-160 chars max">
				<${ Textarea } rows=${ 3 } value=${ p.seo.meta_description } onInput=${ v => update( 'seo.meta_description', v ) } />
			</Field>
		</div>
	`;
}

// ─── Mount ──────────────────────────────────────────────────────────────
let root = document.getElementById( 'counter-product-editor-root' );
if ( ! root ) {
	root = document.createElement( 'div' );
	root.id = 'counter-product-editor-root';
	document.body.appendChild( root );
}
render( h( Drawer ), root );
