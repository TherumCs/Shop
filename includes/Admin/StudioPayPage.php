<?php
/**
 * Shop by Therum — Studio Pay admin page.
 *
 * Three tabs:
 *   - Providers  — connect / disconnect buttons per PSP, status chips
 *   - Methods    — per-method routing overrides + visibility toggles
 *   - Payouts    — cadence + balance + manual payout button + history
 *
 * No Preact — vanilla JS hydrates from /admin/studio-pay/status.
 * Keeps the admin bundle small and avoids the build step everywhere
 * except the Pure builder (which earned it).
 */

namespace Shop\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioPayPage {

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'providers';
		if ( ! in_array( $tab, [ 'providers', 'methods', 'payouts' ], true ) ) $tab = 'providers';
		?>
		<div class="wrap shop-admin">
			<h1 class="shop-admin__title">
				<span class="shop-admin__mark">T</span>
				Studio Pay
				<?php if ( isset( $_GET['connected'] ) ) : ?>
					<span class="shop-admin__chip is-active">Connected: <?php echo esc_html( (string) $_GET['connected'] ); ?></span>
				<?php endif; ?>
			</h1>
			<nav class="shop-admin__tabs">
				<?php foreach ( [ 'providers' => 'Providers', 'methods' => 'Methods', 'payouts' => 'Payouts' ] as $k => $label ) :
					$href = add_query_arg( [ 'page' => 'shop-studio-pay', 'tab' => $k ], admin_url( 'admin.php' ) );
					$cls  = 'shop-admin__tab' . ( $k === $tab ? ' is-active' : '' );
				?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div
				id="shop-studio-pay"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-tab="<?php echo esc_attr( $tab ); ?>"
			>
				<p>Loading…</p>
			</div>
		</div>
		<script>
		( function () {
			const root = document.getElementById( 'shop-studio-pay' );
			if ( ! root ) return;
			const REST  = root.getAttribute( 'data-rest' ) + 'shop/v1/';
			const NONCE = root.getAttribute( 'data-nonce' );
			const TAB   = root.getAttribute( 'data-tab' );

			const api = ( path, opts ) => fetch( REST + path, Object.assign( {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			}, opts || {} ) ).then( r => r.json() );

			const money = c => '$' + ( ( c || 0 ) / 100 ).toFixed( 2 );
			const escapeHtml = s => String( s ).replace( /[&<>"']/g, c => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ] ) );

			function render( state ) {
				switch ( TAB ) {
					case 'methods':  return methodsTab( state );
					case 'payouts':  return payoutsTab( state );
					default:         return providersTab( state );
				}
			}

			function providersTab( s ) {
				return s.providers.map( p => `
					<div class="shop-sp-card">
						<div class="shop-sp-card__head">
							<div>
								<h3>${ escapeHtml( p.name ) }</h3>
								<div class="shop-sp-card__sub">${ p.methods.join( ' · ' ) }</div>
							</div>
							<span class="shop-admin__chip ${ p.connected ? 'is-active' : '' }">
								${ p.connected ? 'Connected' : 'Not connected' }
							</span>
						</div>
						<div class="shop-sp-card__body">
							${ p.connected
								? `<div>Balance: <strong>${ p.balance !== null ? money( p.balance ) : '—' }</strong></div>`
								: '<div class="shop-sp-card__hint">Connect to enable methods that route through this provider.</div>'
							}
						</div>
						<div class="shop-sp-card__actions">
							${ p.connected
								? `<button class="button button-link-delete" data-disconnect="${ p.id }">Disconnect</button>`
								: `<button class="button button-primary" data-connect="${ p.id }">Connect ${ escapeHtml( p.name ) }</button>`
							}
						</div>
					</div>
				` ).join( '' );
			}

			function methodsTab( s ) {
				return `
					<table class="wp-list-table widefat striped shop-admin__table">
						<thead><tr><th>Method</th><th>Group</th><th>Available providers</th><th>Routes through</th></tr></thead>
						<tbody>
							${ s.methods.map( m => {
								const opts = [ '<option value="auto">Auto</option>' ].concat(
									m.providers.map( pid => {
										const sel = ( s.routes[ m.id ] || 'auto' ) === pid ? 'selected' : '';
										return `<option value="${ pid }" ${ sel }>${ pid }</option>`;
									} )
								).join( '' );
								const live = s.providers.filter( p => p.connected && m.providers.includes( p.id ) ).map( p => p.id ).join( ', ' ) || '—';
								return `
									<tr>
										<td><strong>${ escapeHtml( m.label ) }</strong><div class="shop-admin__sub">${ m.id }</div></td>
										<td>${ m.group }</td>
										<td>${ live }</td>
										<td><select data-route="${ m.id }">${ opts }</select></td>
									</tr>`;
							} ).join( '' ) }
						</tbody>
					</table>`;
			}

			function payoutsTab( s ) {
				return `
					<div class="shop-sp-card">
						<div class="shop-sp-card__head"><h3>Payout cadence</h3></div>
						<div class="shop-sp-card__body">
							<label><input type="radio" name="cadence" value="daily"   ${ s.cadence === 'daily'   ? 'checked' : '' }> Daily (free, T+1)</label>
							<label><input type="radio" name="cadence" value="instant" ${ s.cadence === 'instant' ? 'checked' : '' }> Instant per order (provider fee)</label>
							<label><input type="radio" name="cadence" value="manual"  ${ s.cadence === 'manual'  ? 'checked' : '' }> Manual</label>
						</div>
					</div>
					<div class="shop-sp-card">
						<div class="shop-sp-card__head"><h3>Pay out now</h3></div>
						<div class="shop-sp-card__body">
							Aggregates balance across connected providers and triggers a payout.
						</div>
						<div class="shop-sp-card__actions">
							<button class="button" data-payout="standard">Standard payout</button>
							<button class="button button-primary" data-payout="instant">Instant payout (fee)</button>
						</div>
					</div>`;
			}

			api( 'admin/studio-pay/status' ).then( s => {
				root.innerHTML = render( s );
				bind( s );
			} );

			function bind( s ) {
				root.addEventListener( 'click', e => {
					const c = e.target.getAttribute( 'data-connect' );
					if ( c ) {
						e.preventDefault();
						api( 'admin/studio-pay/connect/' + c + '/start' ).then( r => {
							if ( r.redirect_url ) window.location.href = r.redirect_url;
							else alert( r.error || 'Connect failed.' );
						} );
						return;
					}
					const d = e.target.getAttribute( 'data-disconnect' );
					if ( d ) {
						if ( ! confirm( 'Disconnect ' + d + '?' ) ) return;
						api( 'admin/studio-pay/connect/' + d, { method: 'DELETE' } ).then( () => window.location.reload() );
						return;
					}
					const p = e.target.getAttribute( 'data-payout' );
					if ( p ) {
						api( 'admin/studio-pay/payout', { method: 'POST', body: JSON.stringify( { instant: p === 'instant' } ) } ).then( r => {
							alert( 'Payouts: ' + JSON.stringify( r.results ) );
						} );
					}
				} );
				root.addEventListener( 'change', e => {
					if ( e.target.name === 'cadence' ) {
						api( 'admin/studio-pay/cadence', { method: 'POST', body: JSON.stringify( { value: e.target.value } ) } );
					}
					const r = e.target.getAttribute( 'data-route' );
					if ( r ) {
						api( 'admin/studio-pay/route', { method: 'POST', body: JSON.stringify( { method: r, provider: e.target.value } ) } );
					}
				} );
			}
		} )();
		</script>
		<style>
		.shop-sp-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; margin-bottom: 14px; }
		.shop-sp-card__head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #f0f0f1; }
		.shop-sp-card__head h3 { margin: 0; font-size: 14px; }
		.shop-sp-card__sub { font-size: 11px; color: #8c8f94; margin-top: 2px; }
		.shop-sp-card__body { padding: 14px 18px; font-size: 13px; color: #50575e; }
		.shop-sp-card__body label { display: block; margin: 6px 0; }
		.shop-sp-card__hint { color: #8c8f94; }
		.shop-sp-card__actions { padding: 12px 18px; background: #f6f7f7; border-top: 1px solid #f0f0f1; display: flex; gap: 8px; }
		</style>
		<?php
	}
}
