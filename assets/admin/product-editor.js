/**
 * Shop by Therum — Product editor drawer.
 *
 * Preact-based side drawer (720px from the right) that mounts onto
 * the products grid. Listens for a custom event
 * (`shop:open-product`, detail = { id }) so the grid stays decoupled
 * from this module — `products-grid.js` only has to dispatch.
 *
 * Phase 1 (this file):
 *   - Drawer shell with header + tab strip + body + footer
 *   - Tabs: General, Pricing, Inventory rendered fully
 *   - Variants, Shipping, Media, SEO scaffolded as "Coming soon"
 *     placeholders so the visual layout is locked in.
 *   - Autosave debounced 800 ms — dirty-tracking per-field, only
 *     PATCHes changed groups.
 *   - "Saved 3s ago" pill in header
 *   - Esc / overlay click closes; warns if there are unsaved changes
 *
 * Preact + htm from esm.sh, no build step.
 *
 * Mount point: `<div id="shop-product-editor-root"></div>` added to
 * the products page via PHP. The script auto-creates it if missing.
 */

import { h, render }                                              from 'https://esm.sh/preact@10.22.0';
import htm                                                        from 'https://esm.sh/htm@3.1.1';
import { useState, useEffect, useRef, useCallback, useMemo }      from 'https://esm.sh/preact@10.22.0/hooks';

const html = htm.bind( h );

const cfg  = window.ShopAdminGridConfig || {};
const REST = ( cfg.rest || '/wp-json/' ) + 'shop/v1/admin/';
const NONCE = cfg.nonce || '';

function api( path, opts ) {
	return fetch( REST + path, Object.assign( {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
	}, opts || {} ) ).then( r => r.json() );
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
			api( 'products/' + id ).then( p => {
				if ( p.error ) setError( p.error.message || 'Could not load product.' );
				else setProduct( p );
			} );
		};
		document.addEventListener( 'shop:open-product', onOpen );
		return () => document.removeEventListener( 'shop:open-product', onOpen );
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
		<div class="shop-pe-overlay" onClick=${ close } />
		<aside class="shop-pe" onClick=${ e => e.stopPropagation() }>
			<header class="shop-pe__head">
				<div class="shop-pe__head-l">
					<button class="shop-pe__close" onClick=${ close } title="Close (Esc)">✕</button>
					<div>
						<div class="shop-pe__title">
							${ product?.title || ( error ? 'Error' : 'Loading…' ) }
						</div>
						<div class="shop-pe__sub">
							${ product ? html`
								<span class="shop-pe__chip shop-pe__chip--${ product.status }">${ product.status }</span>
								<span class="shop-pe__chip shop-pe__chip--ghost">${ product.type }</span>
								<span class="shop-pe__chip shop-pe__chip--ghost">#${ product.id }</span>
							` : '' }
						</div>
					</div>
				</div>
				<div class="shop-pe__head-r">
					${ error ? html`<span class="shop-pe__pill shop-pe__pill--err">${ error }</span>` : null }
					${ saving ? html`<span class="shop-pe__pill">Saving…</span>`
						: savedAt ? html`<span class="shop-pe__pill shop-pe__pill--ok">Saved ${ fmtAgo( savedAt ) }</span>`
						: null }
					${ product?.urls?.edit_in_woo ? html`<a class="shop-pe__woo" href=${ product.urls.edit_in_woo } target="_blank">Edit in Woo ↗</a>` : '' }
					${ product?.urls?.view ? html`<a class="shop-pe__view" href=${ product.urls.view } target="_blank">View ↗</a>` : '' }
				</div>
			</header>

			<nav class="shop-pe__tabs">
				${ TABS.map( t => html`
					<button
						key=${ t.id }
						class=${ "shop-pe__tab" + ( tab === t.id ? " is-active" : "" ) }
						onClick=${ () => setTab( t.id ) }
					>${ t.label }</button>
				` ) }
			</nav>

			<div class="shop-pe__body">
				${ product ? renderTab( tab, product, update ) : html`<div class="shop-pe__loading">Loading…</div>` }
			</div>
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
		<div class=${ "shop-pe-f" + ( span ? " shop-pe-f--" + span : "" ) }>
			${ label ? html`<label class="shop-pe-f__l">${ label }</label>` : null }
			${ children }
			${ hint ? html`<div class="shop-pe-f__hint">${ hint }</div>` : null }
		</div>
	`;
}

function Input( { value, onInput, type='text', placeholder, mono } ) {
	return html`<input
		class=${ "shop-pe-in" + ( mono ? " shop-pe-in--mono" : "" ) }
		type=${ type }
		placeholder=${ placeholder || '' }
		value=${ value ?? '' }
		onInput=${ e => onInput( e.target.value ) }
	/>`;
}

function Textarea( { value, onInput, rows=6 } ) {
	return html`<textarea
		class="shop-pe-in shop-pe-in--ta"
		rows=${ rows }
		onInput=${ e => onInput( e.target.value ) }
	>${ value ?? '' }</textarea>`;
}

function Select( { value, onInput, options } ) {
	return html`<select class="shop-pe-in" onChange=${ e => onInput( e.target.value ) }>
		${ Object.entries( options ).map( ( [ v, l ] ) => html`<option value=${ v } selected=${ v === String( value ) }>${ l }</option>` ) }
	</select>`;
}

function Toggle( { value, onInput, label } ) {
	return html`<label class="shop-pe-tog">
		<input type="checkbox" checked=${ !! value } onChange=${ e => onInput( e.target.checked ) } />
		<span class="shop-pe-tog__sw"></span>
		<span>${ label }</span>
	</label>`;
}

// Money input — UI is in dollars, state is cents. Two-way conversion.
function MoneyInput( { cents, onInput, placeholder } ) {
	const display = ( cents == null || cents === '' ) ? '' : ( cents / 100 ).toFixed( 2 );
	return html`
		<div class="shop-pe-money">
			<span class="shop-pe-money__sym">$</span>
			<input
				class="shop-pe-in shop-pe-in--mono"
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
		<div class="shop-pe-rows">
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
		<div class="shop-pe-rows">
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
		<div class="shop-pe-rows">
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

function ComingSoon( { label, fields } ) {
	return html`
		<div class="shop-pe-coming">
			<h3>${ label }</h3>
			<p>This tab's editor isn't wired up yet — it'll land in the next pass. Until then you can edit these fields in Woo.</p>
			<ul>${ fields.map( f => html`<li>${ f }</li>` ) }</ul>
		</div>
	`;
}

function Tab_Variants ( { p } ) {
	if ( p.type !== 'variable' ) {
		return html`<div class="shop-pe-coming"><p>This is a <strong>${ p.type }</strong> product — it has no variants.</p></div>`;
	}
	return html`
		<div class="shop-pe-rows">
			<p class="shop-pe-coming-hint">${ p.variants.length } variant${ p.variants.length === 1 ? '' : 's' }. Inline edit + bulk-apply lands in the next pass.</p>
			<table class="shop-pe-vt">
				<thead><tr><th>SKU</th><th>Attributes</th><th>Price</th><th>Sale</th><th>Stock</th></tr></thead>
				<tbody>
					${ p.variants.map( v => html`
						<tr key=${ v.id }>
							<td><code>${ v.sku || '—' }</code></td>
							<td>${ Object.values( v.attributes || {} ).join( ' / ' ) || '—' }</td>
							<td>${ v.regular_price !== null ? '$' + ( v.regular_price / 100 ).toFixed( 2 ) : '—' }</td>
							<td>${ v.sale_price !== null ? '$' + ( v.sale_price / 100 ).toFixed( 2 ) : '—' }</td>
							<td>${ v.stock_qty ?? '—' }</td>
						</tr>
					` ) }
				</tbody>
			</table>
		</div>
	`;
}

function Tab_Shipping ( { p, update } ) {
	return html`
		<div class="shop-pe-rows">
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
				<div class="shop-pe-grid3">
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

function Tab_Media( { p } ) {
	return html`
		<div class="shop-pe-rows">
			${ p.images.primary ? html`
				<img class="shop-pe-img" src=${ p.images.primary.url } alt="" />
			` : html`<div class="shop-pe-coming"><p>No primary image set.</p></div>` }
			${ p.images.gallery.length ? html`
				<div class="shop-pe-gallery">
					${ p.images.gallery.map( g => html`<img key=${ g.id } src=${ g.url } alt="" />` ) }
				</div>
			` : null }
			<${ ComingSoon } label="Media uploader" fields=${ [ 'WP media-library picker for primary + gallery', 'Drag-reorder', 'Replace via paste/drop' ] } />
		</div>
	`;
}

function Tab_SEO( { p, update } ) {
	return html`
		<div class="shop-pe-rows">
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
let root = document.getElementById( 'shop-product-editor-root' );
if ( ! root ) {
	root = document.createElement( 'div' );
	root.id = 'shop-product-editor-root';
	document.body.appendChild( root );
}
render( h( Drawer ), root );
