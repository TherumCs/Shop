<?php
/**
 * Counter by Therum — admin Customers page.
 *
 * Vanilla JS hydration over /admin/customers. Two surfaces:
 *   - List view — searchable table with lifetime stats
 *   - Import / Export — paste CSV, pick conflict mode, run; download
 *     CSV from the same panel.
 *
 * Edit-in-place is intentionally minimal here — the spreadsheet
 * manager handles bulk inline editing. This page is for individual
 * lookups + bulk import/export.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomersPage {

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'list';
		if ( ! in_array( $tab, [ 'list', 'io' ], true ) ) $tab = 'list';
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span> Customers
			</h1>
			<?php SectionTabs::render( 'counter-customers' ); ?>
			<nav class="counter-admin__tabs counter-admin__tabs--sub">
				<a class="counter-admin__tab <?php echo $tab === 'list' ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-customers' ) ); ?>">List</a>
				<a class="counter-admin__tab <?php echo $tab === 'io' ? 'is-active' : ''; ?>"   href="<?php echo esc_url( admin_url( 'admin.php?page=counter-customers&tab=io' ) ); ?>">Import / Export</a>
			</nav>
			<?php $this->renderBody(); ?>
		</div>
		<?php
	}

	/**
	 * Inner body only — page chrome (wrap, title, section + sub tabs)
	 * is the caller's responsibility. Used by ImportExportPage to embed
	 * the Customers IO view as a sub-tab.
	 */
	public function renderBody( ?string $forced_tab = null ): void {
		// Tab can be forced by a wrapper (e.g. ImportExportPage embeds
		// the IO view); otherwise read from the request.
		$tab = $forced_tab !== null
			? $forced_tab
			: ( isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'list' );
		if ( ! in_array( $tab, [ 'list', 'io' ], true ) ) $tab = 'list';
		?>
			<div
				id="counter-customers"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-tab="<?php echo esc_attr( $tab ); ?>"
			><p>Loading…</p></div>
		<script>
		( function () {
			const root = document.getElementById( 'counter-customers' );
			if ( ! root ) return;
			const REST  = root.getAttribute( 'data-rest' ) + 'counter/v1/';
			const NONCE = root.getAttribute( 'data-nonce' );
			const TAB   = root.getAttribute( 'data-tab' );
			const api = ( path, opts ) => fetch( REST + path, Object.assign( {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			}, opts || {} ) ).then( r => r.json() );
			const money = c => '$' + ( ( c || 0 ) / 100 ).toFixed( 2 );
			const esc   = s => String( s == null ? '' : s ).replace( /[&<>"']/g, c => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ] ) );

			if ( TAB === 'io' ) {
				root.innerHTML = `
					<div class="counter-sp-card">
						<div class="counter-sp-card__head"><h3>Export</h3></div>
						<div class="counter-sp-card__body">
							Download every customer as CSV (UTF-8, Excel-ready) or JSON.
						</div>
						<div class="counter-sp-card__actions">
							<a class="button" href="${ REST }admin/customers/export?format=csv&_wpnonce=${ NONCE }" target="_blank">Download CSV</a>
							<a class="button" href="${ REST }admin/customers/export?format=json&_wpnonce=${ NONCE }" target="_blank">Download JSON</a>
						</div>
					</div>
					<div class="counter-sp-card">
						<div class="counter-sp-card__head"><h3>Import</h3></div>
						<div class="counter-sp-card__body">
							<p>Paste a CSV. Field names auto-map from common Shopify / codection / WebToffee exports.</p>
							<textarea id="csv" style="width:100%;height:180px;font-family:JetBrains Mono,monospace;font-size:11px"></textarea>
							<p>
								<label>Conflict:
									<select id="conflict">
										<option value="update" selected>Update (merge — blanks don't clobber)</option>
										<option value="skip">Skip (keep existing)</option>
										<option value="replace">Replace (overwrite)</option>
									</select>
								</label>
								&nbsp;<label><input type="checkbox" id="mkusers"> Create WP user accounts</label>
							</p>
							<div id="result" style="font-family:JetBrains Mono,monospace;font-size:11px;color:#50575e"></div>
						</div>
						<div class="counter-sp-card__actions">
							<button class="button button-primary" id="run">Run import</button>
						</div>
					</div>`;
				document.getElementById( 'run' ).addEventListener( 'click', () => {
					document.getElementById( 'result' ).textContent = 'Importing…';
					api( 'admin/customers/import', { method: 'POST', body: JSON.stringify( {
						csv:             document.getElementById( 'csv' ).value,
						conflict:        document.getElementById( 'conflict' ).value,
						create_wp_users: document.getElementById( 'mkusers' ).checked,
					} ) } ).then( r => {
						document.getElementById( 'result' ).textContent = JSON.stringify( r, null, 2 );
					} );
				} );
				return;
			}

			// List view
			let q = '', offset = 0, limit = 100;
			function fetchList() {
				api( `admin/customers?q=${ encodeURIComponent( q ) }&limit=${ limit }&offset=${ offset }` ).then( r => {
					root.innerHTML = `
						<p><input type="search" id="q" placeholder="Search by email or name" value="${ esc( q ) }" style="width:280px"></p>
						<table class="wp-list-table widefat striped counter-admin__table">
							<thead><tr><th>Email</th><th>Name</th><th>Orders</th><th>Total spent</th><th>Last order</th><th>Joined</th></tr></thead>
							<tbody>${ r.customers.map( c => `
								<tr>
									<td><strong>${ esc( c.email ) }</strong>${ c.accepts_marketing ? '<div class="counter-admin__sub">✓ marketing opt-in</div>' : '' }</td>
									<td>${ esc( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ) }</td>
									<td>${ c.orders_count }</td>
									<td>${ money( c.total_spent_cents ) }</td>
									<td>${ c.last_order_at ? new Date( c.last_order_at * 1000 ).toLocaleDateString() : '—' }</td>
									<td>${ new Date( c.created_at * 1000 ).toLocaleDateString() }</td>
								</tr>` ).join( '' ) }</tbody>
						</table>
						<p class="counter-admin__sub">${ r.total } total</p>`;
					document.getElementById( 'q' ).addEventListener( 'input', e => {
						q = e.target.value; offset = 0;
						clearTimeout( window._shopCQ );
						window._shopCQ = setTimeout( fetchList, 220 );
					} );
				} );
			}
			fetchList();
		} )();
		</script>
		<?php
	}
}
