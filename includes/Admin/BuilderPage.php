<?php
/**
 * Counter by Therum — Pure builder admin page.
 *
 * The admin shell that loads the Preact editor. The actual editor lives
 * in assets/builder/builder.js — this PHP file just emits the mount
 * point and printable config.
 *
 * Only registered when Mode::loadsPureBuilder() returns true (Pure mode).
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BuilderPage {

	public function render(): void {
		$page_id = isset( $_GET['page_id'] ) ? (int) $_GET['page_id'] : 0;
		?>
		<div class="wrap counter-admin counter-builder-wrap">
			<div id="counter-builder-root"
				data-page-id="<?php echo esc_attr( (string) $page_id ); ?>"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

				<!-- Loading shell — the Preact app replaces this on boot -->
				<div class="counter-builder-boot">
					<div class="counter-builder-boot__mark">T</div>
					<div class="counter-builder-boot__title">Loading builder…</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function renderPageList(): void {
		// Active kind tab — defaults to 'page'. Headers/footers/parts
		// surface here too so admins manage them in one place instead of
		// hunting through a settings screen.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( (string) $_GET['kind'] ) : 'page';
		$allowed = [ 'page' => 'Pages', 'header' => 'Headers', 'footer' => 'Footers', 'part' => 'Parts', 'template' => 'Templates' ];
		if ( ! isset( $allowed[ $kind ] ) ) $kind = 'page';
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php echo esc_html( $allowed[ $kind ] ); ?>
				<a href="#" class="page-title-action" data-counter-new-page data-kind="<?php echo esc_attr( $kind ); ?>"><?php esc_html_e( 'Add new', 'counter' ); ?></a>
			</h1>
			<nav class="counter-admin__tabs">
				<?php foreach ( $allowed as $k => $label ) :
					$href = add_query_arg( [ 'page' => 'counter-pages', 'kind' => $k ], admin_url( 'admin.php' ) );
					$cls  = 'counter-admin__tab' . ( $k === $kind ? ' is-active' : '' );
				?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div
				id="counter-page-list"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-kind="<?php echo esc_attr( $kind ); ?>"
				data-active-header="<?php echo esc_attr( (string) (int) get_option( 'counter_active_header_id', 0 ) ); ?>"
				data-active-footer="<?php echo esc_attr( (string) (int) get_option( 'counter_active_footer_id', 0 ) ); ?>"
			>
				<p><?php esc_html_e( 'Loading…', 'counter' ); ?></p>
			</div>
		</div>
		<script>
		( function () {
			var root = document.getElementById( 'counter-page-list' );
			if ( ! root ) return;

			var REST  = root.getAttribute( 'data-rest' ) + 'counter/v1/';
			var NONCE = root.getAttribute( 'data-nonce' );
			var KIND  = root.getAttribute( 'data-kind' ) || 'page';
			var activeHeaderId = parseInt( root.getAttribute( 'data-active-header' ), 10 ) || 0;
			var activeFooterId = parseInt( root.getAttribute( 'data-active-footer' ), 10 ) || 0;

			function api( path, opts ) {
				opts = opts || {};
				return fetch( REST + path, Object.assign( {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
				}, opts ) ).then( function ( r ) { return r.json(); } );
			}

			function render( pages ) {
				if ( ! pages.length ) {
					root.innerHTML = '<p class="counter-admin__empty">Nothing here yet — click <strong>Add new</strong> to create one.</p>';
					return;
				}
				var rows = pages.map( function ( p ) {
					var editUrl = 'admin.php?page=counter-pages&action=edit&page_id=' + p.id;
					var isActiveHeader = KIND === 'header' && p.id === activeHeaderId;
					var isActiveFooter = KIND === 'footer' && p.id === activeFooterId;
					var activate = '';
					if ( KIND === 'header' || KIND === 'footer' ) {
						activate = isActiveHeader || isActiveFooter
							? '<span class="counter-admin__chip is-active">Active</span>'
							: '<button class="button" data-counter-activate="' + p.id + '">Set active</button>';
					}
					return '' +
						'<tr>' +
							'<td><a href="' + editUrl + '"><strong>' + escapeHtml( p.title ) + '</strong></a>' +
								'<div class="counter-admin__sub">' + escapeHtml( p.slug || '' ) + '</div></td>' +
							'<td>' + escapeHtml( p.status || '' ) + '</td>' +
							'<td>' + activate + '</td>' +
							'<td><button class="button-link-delete" data-counter-delete="' + p.id + '">Delete</button></td>' +
						'</tr>';
				} ).join( '' );
				root.innerHTML =
					'<table class="wp-list-table widefat striped counter-admin__table">' +
						'<thead><tr><th>Title</th><th>Status</th><th></th><th></th></tr></thead>' +
						'<tbody>' + rows + '</tbody>' +
					'</table>';
			}

			function escapeHtml( s ) {
				return String( s ).replace( /[&<>"']/g, function ( c ) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
				} );
			}

			function refresh() {
				api( 'admin/pages?kind=' + encodeURIComponent( KIND ) ).then( function ( r ) {
					render( ( r && r.pages ) || [] );
				} );
			}

			// New + activate + delete delegation
			document.addEventListener( 'click', function ( e ) {
				var newBtn = e.target.closest( '[data-counter-new-page]' );
				if ( newBtn ) {
					e.preventDefault();
					var kind = newBtn.getAttribute( 'data-kind' ) || 'page';
					var title = prompt( 'Title for the new ' + kind + ':', 'Untitled' );
					if ( title === null ) return;
					api( 'admin/pages', { method: 'POST', body: JSON.stringify( { title: title, kind: kind } ) } )
						.then( function ( p ) {
							if ( p && p.id ) {
								window.location.href = 'admin.php?page=counter-pages&action=edit&page_id=' + p.id;
							}
						} );
					return;
				}
				var actId = e.target.getAttribute && e.target.getAttribute( 'data-counter-activate' );
				if ( actId ) {
					e.preventDefault();
					api( 'admin/builder/chrome-active', { method: 'POST', body: JSON.stringify( { kind: KIND, id: parseInt( actId, 10 ) } ) } )
						.then( function () { window.location.reload(); } );
					return;
				}
				var delId = e.target.getAttribute && e.target.getAttribute( 'data-counter-delete' );
				if ( delId ) {
					e.preventDefault();
					if ( ! confirm( 'Delete this ' + KIND + '? This cannot be undone.' ) ) return;
					api( 'admin/pages/' + delId, { method: 'DELETE' } ).then( refresh );
					return;
				}
			} );

			refresh();
		} )();
		</script>
		<?php
	}
}
