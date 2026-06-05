/**
 * Shop by Therum — Pure page builder (Preact MVP).
 *
 * Phases shipped:
 *   - palette / canvas / inspector / autosave + live preview (0.14)
 *   - undo + redo + inline text + ±1 reorder + duplicate (0.17)
 *   - drag-to-reorder + motion-one entry transitions (0.18)
 *   - ⌘K AI command palette (0.19)
 *   - header/footer builder + chrome resolution (0.20)
 *   - multi-select + bulk ops + exit transitions (0.21)
 *   - click-in-preview + viewport toggle + copy/paste + snap guides
 *     + nested shift-select (0.22)
 *
 * Preact + htm loaded from esm.sh — zero build step. ~12 KB total
 * after the runtime.
 */

import { h, render } from 'https://esm.sh/preact@10.22.0';
import htm           from 'https://esm.sh/htm@3.1.1';
import { useState, useEffect, useRef, useCallback } from 'https://esm.sh/preact@10.22.0/hooks';
import { animate, spring } from 'https://esm.sh/motion@10.18.0';

const html = htm.bind( h );

const root = document.getElementById( 'shop-builder-root' );
if ( root ) {
	const pageId = parseInt( root.getAttribute( 'data-page-id' ), 10 );
	const REST   = root.getAttribute( 'data-rest' ) + 'shop/v1/';
	const NONCE  = root.getAttribute( 'data-nonce' );

	const api = ( path, opts = {} ) =>
		fetch( REST + path, {
			...opts,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce':   NONCE,
				'Content-Type': 'application/json',
				...( opts.headers || {} ),
			},
		} ).then( r => r.json() );

	// ─── App ─────────────────────────────────────────────────────────────
	function uuid() {
		return 'el-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function App() {
		const [ page,     setPage     ] = useState( null );
		const [ elements, setElements ] = useState( [] );
		const [ tree,     setTree     ] = useState( [] );
		// Multi-select — Set of ids. Single-select code paths read
		// `firstSelected` (first element of the set). Cmd/Ctrl-click
		// toggles, Shift-click extends from the most recent anchor.
		const [ selectedSet, setSelectedSet ] = useState( () => new Set() );
		const lastAnchorId = useRef( null );
		const [ preview,  setPreview  ] = useState( '' );
		const [ saving,   setSaving   ] = useState( false );
		const previewRef = useRef( null );

		// Single-select shims so the inspector / other code keeps reading
		// "the" selected node naturally.
		const selected     = selectedSet.size ? [ ...selectedSet ][ 0 ] : null;
		const setSelected  = ( id ) => {
			if ( id === null ) { setSelectedSet( new Set() ); lastAnchorId.current = null; return; }
			setSelectedSet( new Set( [ id ] ) );
			lastAnchorId.current = id;
		};
		const toggleSelect = ( id ) => {
			setSelectedSet( prev => {
				const next = new Set( prev );
				if ( next.has( id ) ) next.delete( id ); else next.add( id );
				return next;
			} );
			lastAnchorId.current = id;
		};
		const extendSelect = ( id ) => {
			// Shift-click selects the range between the anchor and the
			// target. If both live in the same sibling array (at any
			// depth), the range is contiguous siblings; otherwise we add
			// the target alone — selecting across parents would surprise.
			const anchor = lastAnchorId.current;
			if ( ! anchor || anchor === id ) { toggleSelect( id ); return; }
			const sibs = findSiblingArray( tree, anchor );
			if ( sibs ) {
				const ids   = sibs.map( n => n.id );
				const aIdx  = ids.indexOf( anchor );
				const bIdx  = ids.indexOf( id );
				if ( aIdx !== -1 && bIdx !== -1 ) {
					const [ lo, hi ] = aIdx < bIdx ? [ aIdx, bIdx ] : [ bIdx, aIdx ];
					setSelectedSet( prev => {
						const next = new Set( prev );
						for ( let i = lo; i <= hi; i++ ) next.add( ids[ i ] );
						return next;
					} );
					return;
				}
			}
			toggleSelect( id );
		};

		// History ring — past + future, capped at 100 each side.
		const past   = useRef( [] );
		const future = useRef( [] );
		const skipNextHistory = useRef( true ); // skip the initial load

		const pushHistory = ( prev ) => {
			past.current.push( prev );
			if ( past.current.length > 100 ) past.current.shift();
			future.current.length = 0;
		};

		// Wrapped setter — every tree mutation (except undo/redo itself
		// and the initial boot) snapshots the previous tree into history.
		const applyTree = useCallback( ( updater ) => {
			setTree( prev => {
				const next = typeof updater === 'function' ? updater( prev ) : updater;
				if ( ! skipNextHistory.current ) pushHistory( prev );
				skipNextHistory.current = false;
				return next;
			} );
		}, [] );

		const undo = useCallback( () => {
			if ( ! past.current.length ) return;
			setTree( prev => {
				future.current.push( prev );
				return past.current.pop();
			} );
		}, [] );
		const redo = useCallback( () => {
			if ( ! future.current.length ) return;
			setTree( prev => {
				past.current.push( prev );
				return future.current.pop();
			} );
		}, [] );

		// Keyboard — Cmd/Ctrl+Z (undo), Cmd/Ctrl+Shift+Z (redo),
		//           Cmd/Ctrl+D (duplicate), Delete/Backspace (remove),
		//           Cmd/Ctrl+A (select all root).
		useEffect( () => {
			const onKey = ( e ) => {
				const t = e.target?.tagName;
				if ( t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || e.target?.isContentEditable ) return;
				const mod = e.metaKey || e.ctrlKey;
				const k   = e.key.toLowerCase();
				if ( mod && k === 'z' )       { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
				if ( mod && k === 'd' )       { e.preventDefault(); duplicateSelected(); return; }
				if ( mod && k === 'a' )       { e.preventDefault(); setSelectedSet( new Set( tree.map( n => n.id ) ) ); return; }
				if ( mod && k === 'c' )       { copySelected(); return; }
				if ( mod && k === 'v' )       { e.preventDefault(); pasteClipboard(); return; }
				if ( k === 'delete' || k === 'backspace' ) {
					if ( selectedSet.size ) { e.preventDefault(); removeSelected(); }
					return;
				}
			};
			window.addEventListener( 'keydown', onKey );
			return () => window.removeEventListener( 'keydown', onKey );
		}, [ undo, redo, duplicateSelected, removeSelected, selectedSet, tree, copySelected, pasteClipboard ] );

		// Clipboard (in-memory + localStorage so it survives cross-page).
		// Stored as raw node objects; pasting regenerates ids.
		const copySelected = useCallback( () => {
			if ( ! selectedSet.size ) return;
			const nodes = [ ...selectedSet ].map( id => findNode( tree, id ) ).filter( Boolean );
			try {
				window.localStorage.setItem( 'shop:builder:clipboard', JSON.stringify( nodes ) );
			} catch ( _ ) {}
		}, [ selectedSet, tree ] );

		const pasteClipboard = useCallback( () => {
			let nodes = [];
			try { nodes = JSON.parse( window.localStorage.getItem( 'shop:builder:clipboard' ) || '[]' ); }
			catch ( _ ) { return; }
			if ( ! Array.isArray( nodes ) || ! nodes.length ) return;
			const cloned = nodes.map( n => reId( n, uuid ) );
			applyTree( t => [ ...t, ...cloned ] );
			setSelectedSet( new Set( cloned.map( n => n.id ) ) );
		}, [ applyTree ] );

		// Click-in-preview — delegate clicks on .shop-ed wrappers inside
		// the preview div to select the matching tree node. Modifier keys
		// route to the same multi-select handlers.
		useEffect( () => {
			const root = previewRef.current;
			if ( ! root ) return;
			const onClick = ( e ) => {
				const wrap = e.target.closest( '.shop-ed[data-shop-node-id]' );
				if ( ! wrap ) return;
				e.preventDefault();
				e.stopPropagation();
				const id = wrap.getAttribute( 'data-shop-node-id' );
				if ( e.metaKey || e.ctrlKey ) toggleSelect( id );
				else if ( e.shiftKey )         extendSelect( id );
				else                           setSelected( id );
			};
			root.addEventListener( 'click', onClick, true );
			return () => root.removeEventListener( 'click', onClick, true );
		}, [ preview ] );

		// Snap guides — after selection or preview re-render, measure the
		// selected element vs its siblings inside the preview and project
		// guide lines into the overlay.
		useEffect( () => {
			if ( ! previewRef.current || ! selected ) { setGuides( [] ); return; }
			const el = previewRef.current.querySelector( '[data-shop-node-id="' + cssEscape( selected ) + '"]' );
			if ( ! el ) { setGuides( [] ); return; }
			const rootRect = previewRef.current.getBoundingClientRect();
			const elRect   = el.getBoundingClientRect();
			const sibs     = Array.from( el.parentElement?.children || [] )
				.filter( c => c !== el && c.classList.contains( 'shop-ed' ) );
			const next = [];
			sibs.forEach( s => {
				const sr = s.getBoundingClientRect();
				// Vertical edges that match (within 1px) — a vertical guide line.
				[ [ elRect.left, sr.left ], [ elRect.right, sr.right ], [ elRect.left, sr.right ], [ elRect.right, sr.left ] ].forEach( ( [ a, b ] ) => {
					if ( Math.abs( a - b ) < 1.5 ) next.push( { axis: 'v', x: a - rootRect.left, y1: Math.min( elRect.top, sr.top ) - rootRect.top, y2: Math.max( elRect.bottom, sr.bottom ) - rootRect.top } );
				} );
				// Horizontal edges that match — a horizontal guide line.
				[ [ elRect.top, sr.top ], [ elRect.bottom, sr.bottom ], [ elRect.top, sr.bottom ], [ elRect.bottom, sr.top ] ].forEach( ( [ a, b ] ) => {
					if ( Math.abs( a - b ) < 1.5 ) next.push( { axis: 'h', y: a - rootRect.top, x1: Math.min( elRect.left, sr.left ) - rootRect.left, x2: Math.max( elRect.right, sr.right ) - rootRect.left } );
				} );
			} );
			setGuides( next );
		}, [ selected, preview, viewport ] );

		// Boot — fetch the page + element catalog in parallel
		useEffect( () => {
			Promise.all( [
				api( 'admin/pages/' + pageId ),
				api( 'elements' ),
			] ).then( ( [ p, e ] ) => {
				if ( p && p.id ) {
					setPage( p );
					skipNextHistory.current = true;
					setTree( p.tree || [] );
				}
				setElements( e.elements || [] );
			} );
		}, [] );

		// Re-render preview when tree changes
		useEffect( () => {
			if ( ! page ) return;
			api( 'admin/pages/' + page.id + '/render', {
				method: 'POST',
				body:   JSON.stringify( { tree } ),
			} ).then( r => setPreview( r.html || '' ) );
		}, [ tree, page ] );

		// Autosave on tree change (debounced)
		useEffect( () => {
			if ( ! page ) return;
			const t = setTimeout( () => {
				setSaving( true );
				api( 'admin/pages/' + page.id, {
					method: 'PUT',
					body:   JSON.stringify( { tree } ),
				} ).then( () => setSaving( false ) );
			}, 800 );
			return () => clearTimeout( t );
		}, [ tree, page ] );

		const addElement = useCallback( ( type ) => {
			const node = { id: uuid(), type, settings: {}, children: [] };
			applyTree( t => [ ...t, node ] );
			setSelected( node.id );
		}, [ applyTree ] );

		const updateSettings = useCallback( ( id, settings ) => {
			applyTree( t => walkAndUpdate( t, id, n => ( { ...n, settings: { ...n.settings, ...settings } } ) ) );
		}, [ applyTree ] );

		const removeNode = useCallback( async ( id ) => {
			await waitForExit( [ id ] );
			applyTree( t => walkAndRemove( t, id ) );
			setSelected( null );
		}, [ applyTree ] );

		const removeSelected = useCallback( async () => {
			if ( ! selectedSet.size ) return;
			const ids = [ ...selectedSet ];
			await waitForExit( ids );
			applyTree( t => ids.reduce( ( acc, id ) => walkAndRemove( acc, id ), t ) );
			setSelectedSet( new Set() );
		}, [ selectedSet, applyTree ] );

		const duplicateSelected = useCallback( () => {
			if ( ! selectedSet.size ) return;
			applyTree( t => [ ...selectedSet ].reduce( ( acc, id ) => walkAndDuplicate( acc, id, uuid ), t ) );
		}, [ selectedSet, applyTree ] );

		const moveNode = useCallback( ( id, dir ) => {
			applyTree( t => walkAndMove( t, id, dir ) );
		}, [ applyTree ] );

		const duplicateNode = useCallback( ( id ) => {
			applyTree( t => walkAndDuplicate( t, id, uuid ) );
		}, [ applyTree ] );

		const moveBefore = useCallback( ( srcId, dstIndex ) => {
			applyTree( t => walkAndMoveTo( t, srcId, dstIndex ) );
		}, [ applyTree ] );

		// Drag state — the id currently being dragged, plus the row index
		// the pointer hovers above (for the drop-indicator line). Kept in
		// useState so the overlay re-renders the indicator live.
		const [ dragId,    setDragId    ] = useState( null );
		const [ dropIndex, setDropIndex ] = useState( -1 );

		// ⌘K palette state
		const [ paletteOpen, setPaletteOpen ] = useState( false );
		const [ paletteBusy, setPaletteBusy ] = useState( false );
		const [ paletteErr,  setPaletteErr  ] = useState( '' );

		// Preview viewport — desktop / tablet / mobile. The canvas
		// constrains the .shop-builder__preview width so admins can see
		// how their tree responds without resizing the browser.
		const [ viewport, setViewport ] = useState( 'desktop' );

		// Snap guides — measured spacing pulled from the preview iframe
		// when a node is selected. Shown as an overlay layer.
		const [ guides, setGuides ] = useState( [] );

		// ⌘K opens / closes the AI command palette
		useEffect( () => {
			const onKey = ( e ) => {
				const mod = e.metaKey || e.ctrlKey;
				if ( mod && e.key.toLowerCase() === 'k' ) {
					e.preventDefault();
					setPaletteOpen( v => ! v );
				} else if ( e.key === 'Escape' ) {
					setPaletteOpen( false );
				}
			};
			window.addEventListener( 'keydown', onKey );
			return () => window.removeEventListener( 'keydown', onKey );
		}, [] );

		const runCommand = useCallback( async ( prompt ) => {
			setPaletteBusy( true );
			setPaletteErr( '' );
			try {
				const r = await api( 'admin/builder/command', {
					method: 'POST',
					body:   JSON.stringify( { prompt, tree } ),
				} );
				if ( r.error )           { setPaletteErr( r.error ); return; }
				if ( ! r.ops?.length )   { setPaletteErr( 'No ops returned.' ); return; }
				applyTree( t => applyOps( t, r.ops, uuid ) );
				setPaletteOpen( false );
			} catch ( e ) {
				setPaletteErr( e.message || String( e ) );
			} finally {
				setPaletteBusy( false );
			}
		}, [ tree, applyTree ] );

		const selectedNode = selected ? findNode( tree, selected ) : null;
		const selectedElement = selectedNode ? elements.find( e => e.id === selectedNode.type ) : null;

		if ( ! page ) {
			return html`<div class="shop-builder-boot"><div class="shop-builder-boot__title">Loading…</div></div>`;
		}

		return html`
			<div class="shop-builder">
				<header class="shop-builder__header">
					<div class="shop-builder__mark">T</div>
					<input
						class="shop-builder__title-input"
						value=${ page.title }
						onInput=${ e => {
							setPage( { ...page, title: e.target.value } );
							api( 'admin/pages/' + page.id, { method: 'PUT', body: JSON.stringify( { title: e.target.value } ) } );
						} }
					/>
					<button class="shop-builder__icon-btn" title="Undo (⌘Z)" disabled=${ ! past.current.length } onClick=${ undo }>↶</button>
					<button class="shop-builder__icon-btn" title="Redo (⌘⇧Z)" disabled=${ ! future.current.length } onClick=${ redo }>↷</button>
					<button class="shop-builder__icon-btn shop-builder__icon-btn--ai" title="AI command (⌘K)" onClick=${ () => setPaletteOpen( true ) }>✨</button>
					<div class="shop-builder__viewport">
						<button class=${ "shop-builder__vp" + ( viewport === 'desktop' ? ' is-active' : '' ) } title="Desktop"  onClick=${ () => setViewport( 'desktop' ) }>▭</button>
						<button class=${ "shop-builder__vp" + ( viewport === 'tablet'  ? ' is-active' : '' ) } title="Tablet"   onClick=${ () => setViewport( 'tablet' ) }>▯</button>
						<button class=${ "shop-builder__vp" + ( viewport === 'mobile'  ? ' is-active' : '' ) } title="Mobile"   onClick=${ () => setViewport( 'mobile' ) }>▫</button>
					</div>
					${ selectedSet.size > 1 ? html`
						<div class="shop-builder__bulk">
							<span class="shop-builder__bulk-count">${ selectedSet.size } selected</span>
							<button class="shop-builder__bulk-btn" onClick=${ duplicateSelected } title="Duplicate (⌘D)">Duplicate</button>
							<button class="shop-builder__bulk-btn shop-builder__bulk-btn--danger" onClick=${ removeSelected } title="Delete">Delete</button>
							<button class="shop-builder__bulk-btn" onClick=${ () => setSelectedSet( new Set() ) } title="Clear">Clear</button>
						</div>
					` : null }
					<div class="shop-builder__status">${ saving ? 'Saving…' : 'Saved' }</div>
					<a class="shop-builder__exit" href="${ window.location.search.replace(/[?&]page_id=\d+/, '').replace(/&action=edit/, '') || 'admin.php?page=shop-pages' }">Back to pages</a>
				</header>

				<div class="shop-builder__body">
					<aside class="shop-builder__palette">
						<h3>Add element</h3>
						${ groupByCategory( elements ).map( ( [ cat, list ] ) => html`
							<div class="shop-builder__palette-group" key=${ cat }>
								<div class="shop-builder__palette-group-label">${ cat }</div>
								${ list.map( el => html`
									<button class="shop-builder__palette-item" onClick=${ () => addElement( el.id ) } key=${ el.id }>
										${ el.name }
									</button>
								` ) }
							</div>
						` ) }
					</aside>

					<main class=${ "shop-builder__canvas shop-builder__canvas--vp-" + viewport }>
						<div class="shop-builder__preview-frame">
							<div class="shop-builder__preview" ref=${ previewRef } dangerouslySetInnerHTML=${ { __html: preview } } />
							<div class="shop-builder__guides">
								${ guides.map( ( g, i ) => g.axis === 'v'
									? html`<div key=${ 'gv'+i } class="shop-builder__guide shop-builder__guide--v" style=${ 'left:' + g.x + 'px;top:' + g.y1 + 'px;height:' + ( g.y2 - g.y1 ) + 'px;' } />`
									: html`<div key=${ 'gh'+i } class="shop-builder__guide shop-builder__guide--h" style=${ 'top:'  + g.y + 'px;left:' + g.x1 + 'px;width:'  + ( g.x2 - g.x1 ) + 'px;' } />`
								) }
							</div>
						</div>
						<div
							class="shop-builder__overlay"
							onDragOver=${ ( e ) => {
								if ( ! dragId ) return;
								e.preventDefault();
								// If we drop on the bare overlay (past the last
								// row) the indicator lands after the last item.
								if ( e.target === e.currentTarget ) setDropIndex( tree.length );
							} }
							onDrop=${ ( e ) => {
								if ( ! dragId ) return;
								e.preventDefault();
								if ( dropIndex >= 0 ) moveBefore( dragId, dropIndex );
								setDragId( null );
								setDropIndex( -1 );
							} }
						>
							${ tree.map( ( node, i ) => html`
								<${ NodeRow }
									key=${ node.id }
									node=${ node }
									index=${ i }
									count=${ tree.length }
									elements=${ elements }
									selected=${ selectedSet.has( node.id ) }
									isDragging=${ dragId === node.id }
									showDropBefore=${ dropIndex === i }
									onSelect=${ ( e ) => {
										if ( e?.metaKey || e?.ctrlKey ) toggleSelect( node.id );
										else if ( e?.shiftKey )         extendSelect( node.id );
										else                            setSelected( node.id );
									} }
									onRemove=${ () => removeNode( node.id ) }
									onMoveUp=${ () => moveNode( node.id, -1 ) }
									onMoveDown=${ () => moveNode( node.id, +1 ) }
									onDuplicate=${ () => duplicateNode( node.id ) }
									onText=${ ( v ) => updateSettings( node.id, primaryTextField( node.type, v ) ) }
									onDragStart=${ () => setDragId( node.id ) }
									onDragEnd=${ () => { setDragId( null ); setDropIndex( -1 ); } }
									onDragOverRow=${ ( before ) => setDropIndex( before ? i : i + 1 ) }
								/>
							` ) }
							${ dropIndex === tree.length
								? html`<div class="shop-builder__drop-line shop-builder__drop-line--tail" />`
								: null }
						</div>
					</main>

					<aside class="shop-builder__inspector">
						${ selectedNode && selectedElement
							? html`<${ Inspector } element=${ selectedElement } node=${ selectedNode } onChange=${ ( s ) => updateSettings( selectedNode.id, s ) } />`
							: html`<p class="shop-builder__inspector-empty">Select an element to edit.</p>`
						}
					</aside>
				</div>
				${ paletteOpen
					? html`<${ Palette }
						busy=${ paletteBusy }
						err=${ paletteErr }
						onClose=${ () => setPaletteOpen( false ) }
						onSubmit=${ runCommand }
					/>`
					: null }
			</div>
		`;
	}

	// ─── ⌘K command palette ─────────────────────────────────────────────
	function Palette( { busy, err, onClose, onSubmit } ) {
		const inputRef = useRef( null );
		const [ value, setValue ] = useState( '' );

		useEffect( () => { inputRef.current?.focus(); }, [] );

		const SUGGESTIONS = [
			'Add a heading "New arrivals" and a 4-column product grid below it',
			'Make the heading bigger and red',
			'Add a 2-column section with an image on the left and a button on the right',
			'Remove the product description',
		];

		return html`
			<div class="shop-builder__palette-bg" onClick=${ onClose }>
				<div class="shop-builder__palette-modal" onClick=${ e => e.stopPropagation() }>
					<form onSubmit=${ ( e ) => { e.preventDefault(); if ( value.trim() ) onSubmit( value.trim() ); } }>
						<div class="shop-builder__palette-bar">
							<span class="shop-builder__palette-icon">✨</span>
							<input
								ref=${ inputRef }
								class="shop-builder__palette-input"
								type="text"
								value=${ value }
								disabled=${ busy }
								onInput=${ e => setValue( e.target.value ) }
								placeholder="Describe the change — e.g. add a hero section"
							/>
							<span class="shop-builder__palette-kbd">${ busy ? 'Thinking…' : 'Enter' }</span>
						</div>
					</form>
					${ err
						? html`<div class="shop-builder__palette-err">${ err }</div>`
						: html`
							<div class="shop-builder__palette-sugg-label">Try</div>
							${ SUGGESTIONS.map( s => html`
								<button class="shop-builder__palette-sugg" onClick=${ () => { setValue( s ); onSubmit( s ); } } disabled=${ busy }>
									${ s }
								</button>
							` ) }
						`
					}
				</div>
			</div>
		`;
	}

	// ─── Overlay node row — inline text + reorder + duplicate + delete ──
	function NodeRow( {
		node, index, count, elements, selected, isDragging, showDropBefore,
		onSelect, onRemove, onMoveUp, onMoveDown, onDuplicate, onText,
		onDragStart, onDragEnd, onDragOverRow,
	} ) {
		const def       = elements.find( e => e.id === node.type );
		const name      = def?.name || node.type;
		const inlineKey = inlineTextKey( node.type );
		const inlineVal = inlineKey ? ( node.settings?.[ inlineKey ] ?? '' ) : '';
		const rowRef    = useRef( null );

		// motion-one entry — bounce in on mount. Cheap; the spring auto-
		// resolves and stops referencing the node when it settles.
		useEffect( () => {
			if ( ! rowRef.current ) return;
			animate(
				rowRef.current,
				{ opacity: [ 0, 1 ], transform: [ 'translateY(-4px) scale(0.98)', 'translateY(0) scale(1)' ] },
				{ duration: 0.22, easing: spring( { stiffness: 220, damping: 22 } ) },
			);
		}, [] );

		return html`
			${ showDropBefore ? html`<div class="shop-builder__drop-line" />` : null }
			<div
				ref=${ rowRef }
				draggable=${ true }
				data-node-id=${ node.id }
				class=${ "shop-builder__node"
					+ ( selected   ? " is-selected" : "" )
					+ ( isDragging ? " is-dragging" : "" ) }
				onClick=${ ( e ) => { e.stopPropagation(); onSelect( e ); } }
				onDragStart=${ ( e ) => {
					e.dataTransfer.effectAllowed = 'move';
					// Firefox needs payload to fire the drag at all.
					e.dataTransfer.setData( 'text/plain', node.id );
					onDragStart();
				} }
				onDragEnd=${ onDragEnd }
				onDragOver=${ ( e ) => {
					e.preventDefault();
					e.stopPropagation();
					const rect = e.currentTarget.getBoundingClientRect();
					onDragOverRow( ( e.clientY - rect.top ) < rect.height / 2 );
				} }
			>
				<div class="shop-builder__node-label">
					<span class="shop-builder__drag-grip" title="Drag to reorder">⋮⋮</span>
					<span class="shop-builder__node-type">${ name }</span>
					${ inlineKey
						? html`<input
							class="shop-builder__node-inline"
							value=${ inlineVal }
							placeholder=${ name }
							onClick=${ e => e.stopPropagation() }
							onMouseDown=${ e => e.stopPropagation() }
							onInput=${ e => onText( e.target.value ) }
						/>`
						: null
					}
					<div class="shop-builder__node-actions">
						<button title="Move up"   disabled=${ index === 0 }         onClick=${ e => { e.stopPropagation(); onMoveUp(); } }>↑</button>
						<button title="Move down" disabled=${ index === count - 1 } onClick=${ e => { e.stopPropagation(); onMoveDown(); } }>↓</button>
						<button title="Duplicate"                                   onClick=${ e => { e.stopPropagation(); onDuplicate(); } }>⎘</button>
						<button title="Remove"                                      onClick=${ e => { e.stopPropagation(); onRemove(); } }>×</button>
					</div>
				</div>
			</div>
		`;
	}

	// Which setting key on a given element type counts as its "primary
	// text" for the inline editor in the overlay row. Returning null
	// hides the inline input.
	function inlineTextKey( type ) {
		switch ( type ) {
			case 'heading':   return 'text';
			case 'button':    return 'label';
			case 'rich-text': return 'html';
			case 'add-to-cart': return 'label';
			default:          return null;
		}
	}
	function primaryTextField( type, value ) {
		const k = inlineTextKey( type );
		return k ? { [ k ]: value } : {};
	}

	// ─── Inspector ───────────────────────────────────────────────────────
	function Inspector( { element, node, onChange } ) {
		const groups = groupControls( element.controls );
		return html`
			<div class="shop-builder__inspector-head">
				<h3>${ element.name }</h3>
				<div class="shop-builder__inspector-sub">${ element.category }</div>
			</div>
			${ Object.entries( groups ).map( ( [ groupLabel, controls ] ) => html`
				<div class="shop-builder__inspector-group" key=${ groupLabel }>
					${ groupLabel ? html`<div class="shop-builder__inspector-group-label">${ groupLabel }</div>` : null }
					${ controls.map( c => html`<${ Control } control=${ c } value=${ node.settings[ c.id ] ?? c.default } onChange=${ ( v ) => onChange( { [ c.id ]: v } ) } />` ) }
				</div>
			` ) }
		`;
	}

	function Control( { control, value, onChange } ) {
		const id = 'c-' + control.id;
		const label = html`<label class="shop-builder__control-label" for=${ id }>${ control.label }</label>`;

		switch ( control.type ) {
			case 'text':
				return html`<div class="shop-builder__control">${ label }<input id=${ id } class="shop-builder__input" type="text" value=${ value || '' } onInput=${ e => onChange( e.target.value ) } /></div>`;
			case 'textarea':
				return html`<div class="shop-builder__control">${ label }<textarea id=${ id } class="shop-builder__input shop-builder__input--ta" onInput=${ e => onChange( e.target.value ) }>${ value || '' }</textarea></div>`;
			case 'number':
				return html`<div class="shop-builder__control">${ label }<input id=${ id } class="shop-builder__input" type="number" value=${ value ?? 0 } onInput=${ e => onChange( parseFloat( e.target.value ) || 0 ) } /></div>`;
			case 'toggle':
				return html`<div class="shop-builder__control shop-builder__control--toggle"><label><input type="checkbox" checked=${ !! value } onChange=${ e => onChange( e.target.checked ) } /> ${ control.label }</label></div>`;
			case 'color':
				return html`<div class="shop-builder__control">${ label }<input id=${ id } class="shop-builder__input shop-builder__input--color" type="color" value=${ value || '#000000' } onInput=${ e => onChange( e.target.value ) } /></div>`;
			case 'select':
			case 'alignment':
				return html`<div class="shop-builder__control">${ label }<select id=${ id } class="shop-builder__input" onChange=${ e => onChange( e.target.value ) }>${ Object.entries( control.options || {} ).map( ( [ v, l ] ) => html`<option value=${ v } selected=${ v === value }>${ l }</option>` ) }</select></div>`;
			case 'image':
				return html`<div class="shop-builder__control">${ label }<button class="shop-builder__input" onClick=${ () => openMediaPicker( onChange ) }>${ value ? 'Image #' + value : 'Choose image' }</button></div>`;
			default:
				return html`<div class="shop-builder__control">${ label }<input id=${ id } class="shop-builder__input" type="text" value=${ value ?? '' } onInput=${ e => onChange( e.target.value ) } /></div>`;
		}
	}

	function openMediaPicker( onChange ) {
		if ( ! window.wp || ! window.wp.media ) {
			alert( 'WP media library unavailable.' );
			return;
		}
		const frame = window.wp.media( { multiple: false } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onChange( att.id );
		} );
		frame.open();
	}

	// ─── Helpers ─────────────────────────────────────────────────────────
	function groupByCategory( elements ) {
		const out = {};
		elements.forEach( e => {
			( out[ e.category ] ||= [] ).push( e );
		} );
		return Object.entries( out );
	}

	function groupControls( controls ) {
		const out = {};
		( controls || [] ).forEach( c => {
			const g = c.group || '';
			( out[ g ] ||= [] ).push( c );
		} );
		return out;
	}

	// Returns the array of siblings that contains the node with `id`, or
	// null if not found. Used by shift-select to bound nested ranges.
	function findSiblingArray( tree, id ) {
		if ( tree.some( n => n.id === id ) ) return tree;
		for ( const n of tree ) {
			if ( n.children?.length ) {
				const found = findSiblingArray( n.children, id );
				if ( found ) return found;
			}
		}
		return null;
	}

	function findNode( tree, id ) {
		for ( const node of tree ) {
			if ( node.id === id ) return node;
			if ( node.children ) {
				const found = findNode( node.children, id );
				if ( found ) return found;
			}
		}
		return null;
	}

	function walkAndUpdate( tree, id, fn ) {
		return tree.map( n => {
			if ( n.id === id ) return fn( n );
			if ( n.children ) return { ...n, children: walkAndUpdate( n.children, id, fn ) };
			return n;
		} );
	}

	// Imperative "presence" — animate every overlay row whose id is in
	// the list out, then resolve once the longest exit finishes. The
	// caller then mutates the tree, and preact unmounts the rows it
	// already faded out. Capped at 180ms so a bulk delete doesn't feel
	// laggy.
	function waitForExit( ids ) {
		const els = ids
			.map( id => document.querySelector( '[data-node-id="' + cssEscape( id ) + '"]' ) )
			.filter( Boolean );
		if ( ! els.length ) return Promise.resolve();
		return Promise.all( els.map( el => animate(
			el,
			{ opacity: [ 1, 0 ], transform: [ 'translateY(0) scale(1)', 'translateY(-2px) scale(0.98)' ] },
			{ duration: 0.18, easing: 'ease-out' },
		).finished.catch( () => {} ) ) );
	}

	function cssEscape( s ) {
		// Node ids are el-xxxxxxxx — safe — but be defensive.
		return String( s ).replace( /["\\]/g, '\\$&' );
	}

	function walkAndRemove( tree, id ) {
		return tree.filter( n => n.id !== id ).map( n => ( {
			...n,
			children: n.children ? walkAndRemove( n.children, id ) : [],
		} ) );
	}

	// Move a node up or down within its parent's children array. Searches
	// the whole tree so nested nodes also reorder correctly.
	function walkAndMove( tree, id, dir ) {
		const idx = tree.findIndex( n => n.id === id );
		if ( idx !== -1 ) {
			const next = idx + dir;
			if ( next < 0 || next >= tree.length ) return tree;
			const out = tree.slice();
			[ out[ idx ], out[ next ] ] = [ out[ next ], out[ idx ] ];
			return out;
		}
		return tree.map( n => n.children
			? { ...n, children: walkAndMove( n.children, id, dir ) }
			: n );
	}

	// Duplicate a node in place (immediately after itself), recursively
	// regenerating ids so the clones stay addressable.
	function walkAndDuplicate( tree, id, makeId ) {
		const idx = tree.findIndex( n => n.id === id );
		if ( idx !== -1 ) {
			const clone = reId( tree[ idx ], makeId );
			const out = tree.slice();
			out.splice( idx + 1, 0, clone );
			return out;
		}
		return tree.map( n => n.children
			? { ...n, children: walkAndDuplicate( n.children, id, makeId ) }
			: n );
	}
	// Apply a list of ops returned by the AI palette to the tree. Ops:
	//   add     — push a new node (parentId/index optional)
	//   update  — merge settings into an existing node
	//   remove  — drop a node anywhere in the tree
	//   replace — wholesale tree swap (use sparingly)
	// Unknown ops are ignored — the schema guards us but the model may
	// still surprise us.
	function applyOps( tree, ops, makeId ) {
		let next = tree;
		for ( const op of ops ) {
			switch ( op.op ) {
				case 'add': {
					const node = {
						id:       makeId(),
						type:     op.type,
						settings: op.settings || {},
						children: [],
					};
					next = insertNode( next, node, op.parentId ?? null, op.index ?? null );
					break;
				}
				case 'update':
					if ( op.id ) next = walkAndUpdate( next, op.id, n => ( { ...n, settings: { ...n.settings, ...( op.settings || {} ) } } ) );
					break;
				case 'remove':
					if ( op.id ) next = walkAndRemove( next, op.id );
					break;
				case 'replace':
					if ( Array.isArray( op.tree ) ) next = op.tree.map( n => reId( n, makeId ) );
					break;
			}
		}
		return next;
	}

	// Insert `node` into the tree. Null parentId = root append (or at
	// index if provided). Otherwise finds the parent and appends to its
	// children at the given index.
	function insertNode( tree, node, parentId, index ) {
		if ( parentId === null ) {
			if ( index === null || index >= tree.length ) return [ ...tree, node ];
			const out = tree.slice();
			out.splice( Math.max( 0, index ), 0, node );
			return out;
		}
		return tree.map( n => {
			if ( n.id === parentId ) {
				const kids = n.children || [];
				const idx  = ( index === null || index > kids.length ) ? kids.length : Math.max( 0, index );
				const next = kids.slice();
				next.splice( idx, 0, node );
				return { ...n, children: next };
			}
			return n.children ? { ...n, children: insertNode( n.children, node, parentId, index ) } : n;
		} );
	}

	function reId( node, makeId ) {
		return {
			...node,
			id:       makeId(),
			children: ( node.children || [] ).map( c => reId( c, makeId ) ),
		};
	}

	// Move `srcId` so it ends up at `dstIndex` in its sibling array. Used
	// by drag-drop where the cursor lands between two rows. Walks the
	// tree so siblings at any depth reorder correctly. Returns the input
	// untouched if src isn't found at this level or below.
	function walkAndMoveTo( tree, srcId, dstIndex ) {
		const srcIdx = tree.findIndex( n => n.id === srcId );
		if ( srcIdx !== -1 ) {
			if ( srcIdx === dstIndex || srcIdx === dstIndex - 1 ) return tree;
			const out  = tree.slice();
			const [ node ] = out.splice( srcIdx, 1 );
			const target = dstIndex > srcIdx ? dstIndex - 1 : dstIndex;
			out.splice( target, 0, node );
			return out;
		}
		return tree.map( n => n.children
			? { ...n, children: walkAndMoveTo( n.children, srcId, dstIndex ) }
			: n );
	}

	render( h( App ), root );
}
