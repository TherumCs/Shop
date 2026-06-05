/**
 * Shop by Therum — Gutenberg editor registration.
 *
 * Reads window.ShopGutenbergElements (emitted by GutenbergAdapter)
 * and registers each Shop element as a block. Uses ServerSideRender
 * for the editor preview so the editor sees exactly what the
 * frontend will render.
 *
 * Controls in the inspector sidebar are mapped from our schema:
 *   text     → TextControl
 *   textarea → TextareaControl
 *   number   → NumberControl
 *   toggle   → ToggleControl
 *   select   → SelectControl
 *   color    → ColorPicker
 *   image    → MediaUploadCheck + URL field
 */

( function ( blocks, element, components, serverSideRender ) {
	const list = window.ShopGutenbergElements || [];
	if ( ! list.length ) return;

	const el = element.createElement;
	const ServerSideRender = serverSideRender.default || serverSideRender;
	const { InspectorControls } = wp.blockEditor || wp.editor;
	const {
		PanelBody, TextControl, TextareaControl, ToggleControl, SelectControl, ColorPicker,
	} = components;

	list.forEach( spec => {
		blocks.registerBlockType( 'shop/' + spec.id, {
			title:      spec.name,
			category:   'shop-by-therum',
			icon:       'store',
			attributes: spec.attributes,
			edit: function ( props ) {
				const inspector = el( InspectorControls, {},
					el( PanelBody, { title: spec.name },
						( spec.controls || [] ).map( c => renderControl( c, props ) )
					)
				);
				return [
					inspector,
					el( ServerSideRender, {
						block:      'shop/' + spec.id,
						attributes: props.attributes,
					} ),
				];
			},
			save: () => null,
		} );
	} );

	// Register the category once.
	const cats = wp.blocks.getCategories ? wp.blocks.getCategories() : [];
	if ( ! cats.find( c => c.slug === 'shop-by-therum' ) ) {
		wp.blocks.setCategories( cats.concat( [ {
			slug:  'shop-by-therum',
			title: 'Shop by Therum',
			icon:  'store',
		} ] ) );
	}

	function renderControl( c, props ) {
		const id    = c.id;
		const value = props.attributes[ id ];
		const set   = v => props.setAttributes( { [ id ]: v } );
		const label = c.label || id;
		switch ( c.type ) {
			case 'textarea': return el( TextareaControl, { key: id, label, value: value || '', onChange: set } );
			case 'number':   return el( TextControl,     { key: id, label, type: 'number', value: value ?? '', onChange: v => set( Number( v ) ) } );
			case 'toggle':   return el( ToggleControl,   { key: id, label, checked: !! value, onChange: set } );
			case 'select':   return el( SelectControl,   { key: id, label, value: value || '', options: Object.entries( c.options || {} ).map( ( [ v, l ] ) => ( { value: v, label: l } ) ), onChange: set } );
			case 'color':    return el( 'div', { key: id }, el( 'label', null, label ), el( ColorPicker, { color: value || '#000000', onChangeComplete: v => set( v.hex ) } ) );
			default:         return el( TextControl, { key: id, label, value: value || '', onChange: set } );
		}
	}
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.serverSideRender );
