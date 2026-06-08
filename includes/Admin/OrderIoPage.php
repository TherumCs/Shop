<?php
/**
 * Counter by Therum — admin Order Import / Export page.
 *
 * Wraps OrderIoController. Roundtrip-compatible CSV (the importer +
 * exporter share the same column layout) so admins can export from
 * one store, edit in Excel, re-import to another.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderIoPage {

	public function render(): void {
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span> Order Import / Export
			</h1>
			<?php SectionTabs::render( 'counter-import' ); ?>
			<?php $this->renderBody(); ?>
		</div>
		<?php
	}

	/**
	 * Inner body only — page chrome is the caller's responsibility.
	 * Used by ImportExportPage to embed this view as a sub-tab.
	 */
	public function renderBody(): void {
		?>
			<div
				id="counter-order-io"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			></div>
		<script>
		( function () {
			const root = document.getElementById( 'counter-order-io' );
			if ( ! root ) return;
			const REST  = root.getAttribute( 'data-rest' ) + 'counter/v1/';
			const NONCE = root.getAttribute( 'data-nonce' );
			const api = ( path, opts ) => fetch( REST + path, Object.assign( {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			}, opts || {} ) ).then( r => r.json() );

			root.innerHTML = `
				<div class="counter-sp-card">
					<div class="counter-sp-card__head"><h3>Export orders</h3></div>
					<div class="counter-sp-card__body">
						<p>One row per line item; order columns repeat. Matches WebToffee / Shopify export format.</p>
						<p>
							<label>Status: <input type="text" id="status" placeholder="(any)"></label>
							&nbsp;<label>From: <input type="date" id="from"></label>
							&nbsp;<label>To: <input type="date" id="to"></label>
						</p>
					</div>
					<div class="counter-sp-card__actions">
						<button class="button" id="csv">Download CSV</button>
						<button class="button" id="json">Download JSON</button>
					</div>
				</div>
				<div class="counter-sp-card">
					<div class="counter-sp-card__head"><h3>Import orders</h3></div>
					<div class="counter-sp-card__body">
						<p>Paste the CSV. Multiple rows with the same <code>order_number</code> are merged into one order with its line items.</p>
						<textarea id="csvin" style="width:100%;height:200px;font-family:JetBrains Mono,monospace;font-size:11px"></textarea>
						<p>
							<label>Conflict:
								<select id="conflict">
									<option value="update" selected>Update (merge, replace items)</option>
									<option value="skip">Skip (keep existing)</option>
									<option value="replace">Replace (overwrite)</option>
								</select>
							</label>
						</p>
						<div id="result" style="font-family:JetBrains Mono,monospace;font-size:11px;color:#50575e"></div>
					</div>
					<div class="counter-sp-card__actions">
						<button class="button button-primary" id="run">Run import</button>
					</div>
				</div>`;

			function exportUrl( fmt ) {
				const q = new URLSearchParams();
				q.set( 'format', fmt );
				if ( document.getElementById( 'status' ).value ) q.set( 'status', document.getElementById( 'status' ).value );
				if ( document.getElementById( 'from' ).value )   q.set( 'from',   document.getElementById( 'from' ).value );
				if ( document.getElementById( 'to' ).value )     q.set( 'to',     document.getElementById( 'to' ).value );
				q.set( '_wpnonce', NONCE );
				return REST + 'admin/orders/export?' + q.toString();
			}
			document.getElementById( 'csv' ).addEventListener( 'click', () => window.open( exportUrl( 'csv' ), '_blank' ) );
			document.getElementById( 'json' ).addEventListener( 'click', () => window.open( exportUrl( 'json' ), '_blank' ) );

			document.getElementById( 'run' ).addEventListener( 'click', () => {
				document.getElementById( 'result' ).textContent = 'Importing…';
				api( 'admin/orders/import', { method: 'POST', body: JSON.stringify( {
					csv:      document.getElementById( 'csvin' ).value,
					conflict: document.getElementById( 'conflict' ).value,
				} ) } ).then( r => {
					document.getElementById( 'result' ).textContent = JSON.stringify( r, null, 2 );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
