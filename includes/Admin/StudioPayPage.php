<?php
/**
 * Counter by Therum — Studio Pay admin page.
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

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioPayPage {

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'providers';
		if ( ! in_array( $tab, [ 'providers', 'methods', 'payouts' ], true ) ) $tab = 'providers';
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				Payments
				<?php if ( isset( $_GET['connected'] ) ) : ?>
					<span class="counter-admin__chip is-active">Connected: <?php echo esc_html( sanitize_key( (string) $_GET['connected'] ) ); ?></span>
				<?php endif; ?>
			</h1>
			<?php SectionTabs::render( 'counter-studio-pay' ); ?>
			<nav class="counter-admin__tabs counter-admin__tabs--sub">
				<?php foreach ( [ 'providers' => 'Providers', 'methods' => 'Methods', 'payouts' => 'Payouts' ] as $k => $label ) :
					$href = add_query_arg( [ 'page' => 'counter-studio-pay', 'tab' => $k ], admin_url( 'admin.php' ) );
					$cls  = 'counter-admin__tab' . ( $k === $tab ? ' is-active' : '' );
				?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div
				id="counter-studio-pay"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-tab="<?php echo esc_attr( $tab ); ?>"
				data-nexus-url="<?php echo esc_url( admin_url( 'admin.php?page=nexus' ) ); ?>"
			>
				<p>Loading…</p>
			</div>
		</div>
		<script>
			window.CounterNexusUrl = document.getElementById( 'counter-studio-pay' )?.dataset.nexusUrl
				|| '/wp-admin/admin.php?page=nexus';
		</script>
		<script>
		( function () {
			const root = document.getElementById( 'counter-studio-pay' );
			if ( ! root ) return;
			const REST  = root.getAttribute( 'data-rest' ) + 'counter/v1/';
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
				const studio = s.providers.filter( p => p.source !== 'nexus' );
				const nexus  = s.providers.filter( p => p.source === 'nexus' );

				const card = p => `
					<div class="counter-sp-card">
						<div class="counter-sp-card__head">
							<div>
								<h3>${ escapeHtml( p.name ) }</h3>
								<div class="counter-sp-card__sub">${ ( p.methods || [] ).join( ' · ' ) || ( p.source === 'nexus' ? 'Managed by Nexus' : '' ) }</div>
							</div>
							<span class="counter-admin__chip ${ p.connected ? 'is-active' : '' }">
								${ p.connected ? 'Connected' : 'Not connected' }
							</span>
						</div>
						<div class="counter-sp-card__body">
							${ p.connected
								? ( p.source === 'nexus'
									? '<div class="counter-sp-card__hint">Credentials live in Nexus. Counter routes payments through them.</div>'
									: `<div>Balance: <strong>${ p.balance !== null ? money( p.balance ) : '—' }</strong></div>` )
								: '<div class="counter-sp-card__hint">Connect to enable methods that route through this provider.</div>'
							}
						</div>
						<div class="counter-sp-card__actions">
							${ p.source === 'nexus'
								? `<a class="button" href="${ window.CounterNexusUrl || '/wp-admin/admin.php?page=nexus' }">Manage in Nexus ↗</a>`
								: p.connected
									? `<button class="button button-link-delete" data-disconnect="${ p.id }">Disconnect</button>`
									: `<a class="button button-primary" href="?page=counter-settings#provider-${ p.id }" data-setup="${ p.id }">Connect ${ escapeHtml( p.name ) }</a>`
							}
						</div>
					</div>
				`;

				let out = studio.map( card ).join( '' );
				if ( nexus.length ) {
					out += `<h2 class="counter-sp-section-h">Connected via Nexus</h2>` + nexus.map( card ).join( '' );
				}
				return out;
			}

			// Single-card methods tab: one switch per method, grouped exactly
			// like the checkout strip (Card / Wallets / Pay later / Bank /
			// Crypto / P2P). Routing happens silently in the background —
			// the merchant just enables what they want at checkout.
			function methodsTab( s ) {
				return `
					<div class="counter-sp-card">
						<div class="counter-sp-card__head">
							<h3>Visible at checkout</h3>
						</div>
						<div class="counter-sp-card__body">
							<div class="counter-sp-card__hint" style="margin-bottom:14px">
								Toggle methods on or off. Routing is handled automatically — Square handles what Square handles best, Stripe fills BNPL, PayPal carries PayPal-family, and so on. "Set up" links walk you through anything that needs credentials.
							</div>
							<div data-methods><em>Loading methods…</em></div>
						</div>
					</div>`;
			}

			// Per-provider setup location. OAuth-capable rails go to the
			// Providers sub-tab (Connect button there); key-paste rails
			// go to the Settings page, scrolled to the right section via
			// hash anchor. Used for the "Needs connection" deep links so
			// one click lands the merchant exactly where they configure.
			const PROVIDER_SETUP = {
				stripe:   { url: '?page=counter-settings#provider-stripe',  label: 'Settings → Stripe' },
				square:   { url: '?page=counter-studio-pay&tab=providers',  label: 'Providers → Square' },
				paypal:   { url: '?page=counter-studio-pay&tab=providers',  label: 'Providers → PayPal' },
				plaid:    { url: '?page=counter-studio-pay&tab=providers',  label: 'Providers → Plaid' },
				sezzle:   { url: '?page=counter-settings#provider-sezzle',  label: 'Settings → Sezzle' },
				zip:      { url: '?page=counter-settings#provider-zip',     label: 'Settings → Zip' },
				crypto:   { url: '?page=counter-settings#provider-crypto',  label: 'Settings → Crypto' },
				zelle:    { url: '?page=counter-settings#provider-zelle',   label: 'Settings → Zelle' },
				shop_pay: { url: '?page=counter-settings#provider-shop_pay',label: 'Settings → Shop Pay' },
			};

			// Group display labels — mirror the checkout strip's section
			// titles so the admin and customer surfaces match.
			const GROUP_LABELS = {
				card:    'Card',
				wallets: 'Wallets',
				bnpl:    'Pay later',
				bank:    'Bank',
				crypto:  'Crypto',
				p2p:     'P2P',
			};
			const GROUP_ORDER = [ 'card', 'wallets', 'bnpl', 'bank', 'crypto', 'p2p' ];

			// Methods tab boot — fetches the overview and renders the
			// toggle grid. Routing is invisible to the merchant; they only
			// see "is this method visible at checkout?" + "is its provider
			// connected?" with a one-click Set-up link when not.
			function bootMethods() {
				const wrap = root.querySelector( '[data-methods]' );
				if ( ! wrap ) return;

				function render( data ) {
					const groups = data.groups || {};
					wrap.innerHTML = GROUP_ORDER
						.filter( g => groups[ g ] && groups[ g ].length )
						.map( g => `
							<div class="counter-sp-mg">
								<div class="counter-sp-mg__title">${ escapeHtml( GROUP_LABELS[ g ] || g ) }</div>
								<div class="counter-sp-mg__rows">
									${ groups[ g ].map( m => renderRow( m ) ).join( '' ) }
								</div>
							</div>
						` ).join( '' );
				}

				function renderRow( m ) {
					const connected = m.provider_connected;
					const setup     = PROVIDER_SETUP[ m.provider ];
					const status    = connected
						? '<span class="counter-sp-mg__status counter-sp-mg__status--ok">✓ Live</span>'
						: setup
							? `<a class="counter-sp-mg__status counter-sp-mg__status--warn" href="${ setup.url }">Set up ${ escapeHtml( m.provider ) } →</a>`
							: '<span class="counter-sp-mg__status counter-sp-mg__status--warn">Not configurable</span>';
					return `
						<label class="counter-sp-mg__row ${ m.enabled ? '' : 'is-off' }">
							<span class="counter-sp-toggle">
								<input type="checkbox" data-toggle="${ m.id }" ${ m.enabled ? 'checked' : '' }>
								<span class="counter-sp-toggle__track"></span>
							</span>
							<span class="counter-sp-mg__label">${ escapeHtml( m.label ) }</span>
							<span class="counter-sp-mg__via">via <code>${ escapeHtml( m.provider ) }</code></span>
							${ status }
						</label>
					`;
				}

				api( 'admin/studio-pay/methods-overview' ).then( render );

				wrap.addEventListener( 'change', e => {
					const id = e.target.getAttribute( 'data-toggle' );
					if ( ! id ) return;
					api( 'admin/studio-pay/method/toggle', {
						method: 'POST',
						body: JSON.stringify( { method: id, enabled: e.target.checked } ),
					} ).then( () => {
						const row = e.target.closest( '.counter-sp-mg__row' );
						if ( row ) row.classList.toggle( 'is-off', ! e.target.checked );
					} );
				} );
			}

			// Legacy boots kept for tab compatibility — unused on the simplified Methods tab.
			function bootMoneyFlow() {
				const wrap = root.querySelector( '#counter-sp-flow [data-flow]' );
				if ( ! wrap ) return;

				const BUCKETS = {
					square: {
						label:  'Square balance',
						sub:    'Native, instant, free',
						color:  '#10b981',
						order:  1,
					},
					square_via_stripe: {
						label:  'Square balance (via Stripe instant payout)',
						sub:    'Capture → Stripe → instant payout to Square Debit Card · 1.5% fee',
						color:  '#f59e0b',
						order:  2,
					},
					bank: {
						label:  'Your bank account',
						sub:    'ACH settlement, T+1 typical, free',
						color:  '#3b82f6',
						order:  3,
					},
					external: {
						label:  'External processor',
						sub:    'Funds stay in a third-party wallet you manage outside Counter',
						color:  '#8b5cf6',
						order:  4,
					},
					none: {
						label:  'Not routed',
						sub:    'No provider picked for these methods',
						color:  '#9ca3af',
						order:  5,
					},
				};

				api( 'admin/studio-pay/routing/flow' ).then( r => {
					const cnx = r.connections || {};

					// Group lines by destination bucket.
					const groups = {};
					( r.lines || [] ).forEach( line => {
						const b = ( line.destination && line.destination.bucket ) || 'none';
						( groups[ b ] = groups[ b ] || [] ).push( line );
					} );

					const orderedKeys = Object.keys( groups ).sort( ( a, b ) =>
						( ( BUCKETS[ a ] || BUCKETS.none ).order ) - ( ( BUCKETS[ b ] || BUCKETS.none ).order )
					);

					if ( ! orderedKeys.length ) {
						wrap.innerHTML = '<em>No methods routed yet. Pick a preset above to get started.</em>';
						return;
					}

					const sections = orderedKeys.map( bucketKey => {
						const meta  = BUCKETS[ bucketKey ] || BUCKETS.none;
						const lines = groups[ bucketKey ];
						const connectedCount    = lines.filter( l => cnx[ l.provider ] ).length;
						const disconnectedCount = lines.length - connectedCount;

						const rows = lines.map( line => {
							const connected = cnx[ line.provider ];
							const provider  = line.provider || '—';
							const setup     = PROVIDER_SETUP[ provider ];
							const statusHtml = connected
								? '<span class="counter-sp-mf__status counter-sp-mf__status--ok">✓ Live</span>'
								: setup
									? `<a class="counter-sp-mf__status counter-sp-mf__status--warn" href="${ setup.url }" title="Configure in ${ escapeHtml( setup.label ) }">Set up ${ escapeHtml( provider ) } →</a>`
									: '<span class="counter-sp-mf__status counter-sp-mf__status--warn">Not configurable</span>';
							return `
								<div class="counter-sp-mf__row ${ connected ? '' : 'is-pending' }">
									<span class="counter-sp-mf__method">${ escapeHtml( line.label ) }</span>
									<span class="counter-sp-mf__via">via <code>${ escapeHtml( provider ) }</code></span>
									${ statusHtml }
								</div>
							`;
						} ).join( '' );

						return `
							<div class="counter-sp-mf__section" style="border-left-color:${ meta.color }">
								<div class="counter-sp-mf__head">
									<div class="counter-sp-mf__head-l">
										<div class="counter-sp-mf__title">${ escapeHtml( meta.label ) }</div>
										<div class="counter-sp-mf__sub">${ escapeHtml( meta.sub ) }</div>
									</div>
									<div class="counter-sp-mf__head-r">
										<div class="counter-sp-mf__count">${ lines.length }</div>
										<div class="counter-sp-mf__count-label">${ lines.length === 1 ? 'method' : 'methods' }</div>
									</div>
								</div>
								${ disconnectedCount > 0 && connectedCount > 0
									? `<div class="counter-sp-mf__warn">${ connectedCount } live · ${ disconnectedCount } needs setup (click below to configure)</div>`
									: disconnectedCount > 0
										? `<div class="counter-sp-mf__warn">${ disconnectedCount } method${ disconnectedCount === 1 ? '' : 's' } need${ disconnectedCount === 1 ? 's' : '' } provider setup — click any row's "Set up" link to configure</div>`
										: `<div class="counter-sp-mf__ok">All ${ connectedCount } method${ connectedCount === 1 ? '' : 's' } live</div>`
								}
								<div class="counter-sp-mf__rows">${ rows }</div>
							</div>
						`;
					} ).join( '' );

					wrap.innerHTML = sections;
				} );
			}

			// ─── Routing presets renderer ─────────────────────────────────
			function bootPresets() {
				const wrap = root.querySelector( '#counter-sp-presets [data-presets]' );
				if ( ! wrap ) return;

				api( 'admin/studio-pay/routing/presets' ).then( r => {
					if ( ! r.presets || ! r.presets.length ) {
						wrap.innerHTML = '<em>No presets available.</em>';
						return;
					}
					const isCustom = r.active_preset === 'custom';
					let html = r.presets.map( p => `
						<label class="counter-sp-preset ${ r.active_preset === p.id ? 'is-active' : '' }">
							<input type="radio" name="preset" value="${ p.id }" ${ r.active_preset === p.id ? 'checked' : '' }>
							<div>
								<div class="counter-sp-preset__label">${ escapeHtml( p.label ) }</div>
								<div class="counter-sp-preset__desc">${ escapeHtml( p.description ) }</div>
							</div>
						</label>
					` ).join( '' );
					if ( isCustom ) {
						html += `
							<div class="counter-sp-preset is-active" style="border-style:dashed;cursor:default">
								<div style="font-size:18px;line-height:1">✎</div>
								<div>
									<div class="counter-sp-preset__label">Custom routing</div>
									<div class="counter-sp-preset__desc">You've changed at least one method from the last preset's defaults. Pick a preset above to reset, or keep tweaking individual rows below — Counter remembers each choice.</div>
								</div>
							</div>`;
					}
					wrap.innerHTML = html;
				} );

				wrap.addEventListener( 'change', e => {
					if ( e.target.name !== 'preset' ) return;
					const id = e.target.value;
					if ( ! confirm( "Apply the '" + id + "' preset? This overwrites every method route." ) ) {
						return;
					}
					api( 'admin/studio-pay/routing/preset', {
						method: 'POST', body: JSON.stringify( { id } ),
					} ).then( r => {
						if ( r.error ) { alert( r.error ); return; }
						window.location.reload(); // refresh routes in the table
					} );
				} );
			}

			function payoutsTab( s ) {
				return `
					<div class="counter-sp-card">
						<div class="counter-sp-card__head"><h3>Payout cadence</h3></div>
						<div class="counter-sp-card__body">
							<label><input type="radio" name="cadence" value="daily"   ${ s.cadence === 'daily'   ? 'checked' : '' }> Daily (free, T+1)</label>
							<label><input type="radio" name="cadence" value="instant" ${ s.cadence === 'instant' ? 'checked' : '' }> Instant per order (1.5% Stripe fee, lands in minutes)</label>
							<label><input type="radio" name="cadence" value="manual"  ${ s.cadence === 'manual'  ? 'checked' : '' }> Manual</label>
						</div>
					</div>
					<div class="counter-sp-card" id="counter-sp-destinations">
						<div class="counter-sp-card__head"><h3>Payout destination</h3></div>
						<div class="counter-sp-card__body">
							<div class="counter-sp-card__hint" style="margin-bottom:10px">
								Where Stripe sends your payouts. Use a debit card (e.g. your Square Debit Mastercard) for instant payouts to that account.
							</div>
							<div data-dest-list><em>Loading destinations…</em></div>
						</div>
						<div class="counter-sp-card__actions" style="flex-direction:column;align-items:stretch">
							<button class="button" type="button" data-dest-toggle>+ Add a debit card</button>
							<div data-dest-form hidden style="margin-top:10px">
								<div style="display:grid;grid-template-columns:1fr 70px 70px;gap:6px;margin-bottom:8px">
									<input type="text" inputmode="numeric" autocomplete="cc-number" placeholder="Card number" data-cc-number>
									<input type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" data-cc-exp>
									<input type="text" inputmode="numeric" autocomplete="cc-csc" placeholder="CVC" data-cc-cvc>
								</div>
								<div style="display:flex;gap:6px;margin-bottom:8px">
									<input type="text" autocomplete="cc-name" placeholder="Cardholder name" data-cc-name style="flex:1">
									<input type="text" inputmode="numeric" autocomplete="postal-code" placeholder="ZIP" data-cc-zip style="width:90px">
								</div>
								<button class="button button-primary" type="button" data-dest-save>Attach card</button>
								<span data-dest-error style="color:#c00;margin-left:8px"></span>
							</div>
						</div>
					</div>
					<div class="counter-sp-card">
						<div class="counter-sp-card__head"><h3>Pay out now</h3></div>
						<div class="counter-sp-card__body">
							Aggregates balance across connected providers and triggers a payout.
						</div>
						<div class="counter-sp-card__actions">
							<button class="button" data-payout="standard">Standard payout</button>
							<button class="button button-primary" data-payout="instant">Instant payout (fee)</button>
						</div>
					</div>`;
			}

			// ─── Destinations (Stripe external_accounts) ──────────────────
			// Loads list, renders, handles add-card via Stripe.js token,
			// and persists default-destination selection. Activates only
			// when the Payouts tab is current; lazy-loads Stripe.js once.
			function bootDestinations() {
				const wrap = root.querySelector( '#counter-sp-destinations' );
				if ( ! wrap ) return;
				const listEl  = wrap.querySelector( '[data-dest-list]' );
				const toggle  = wrap.querySelector( '[data-dest-toggle]' );
				const formEl  = wrap.querySelector( '[data-dest-form]' );
				const saveBtn = wrap.querySelector( '[data-dest-save]' );
				const errEl   = wrap.querySelector( '[data-dest-error]' );

				let state = { destinations: [], default_id: '', publishable_key: '', connected: false };
				let stripe = null;

				function renderList() {
					if ( ! state.connected ) {
						listEl.innerHTML = '<em>Connect Stripe in the Providers tab to set a payout destination.</em>';
						toggle.disabled = true;
						return;
					}
					if ( ! state.destinations.length ) {
						listEl.innerHTML = '<em>No payout destinations on file. Add a debit card to receive instant payouts.</em>';
						return;
					}
					listEl.innerHTML = state.destinations.map( d => `
						<label style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f1">
							<input type="radio" name="dest" value="${ d.id }" ${ d.id === state.default_id ? 'checked' : '' }>
							<span style="flex:1">
								<strong>${ escapeHtml( d.label ) }</strong>
								<span class="counter-admin__chip ${ d.type === 'card' ? 'is-active' : '' }" style="margin-left:6px">${ d.type === 'card' ? 'Card · instant capable' : 'Bank · standard ACH' }</span>
							</span>
						</label>
					` ).join( '' );
				}

				async function loadStripe() {
					if ( stripe ) return stripe;
					if ( ! state.publishable_key ) {
						throw new Error( 'Stripe publishable key not set. Add it in Counter → Settings.' );
					}
					if ( ! window.Stripe ) {
						await new Promise( ( res, rej ) => {
							const s = document.createElement( 'script' );
							s.src = 'https://js.stripe.com/v3';
							s.onload = res; s.onerror = () => rej( new Error( 'Failed to load Stripe.js' ) );
							document.head.appendChild( s );
						} );
					}
					stripe = window.Stripe( state.publishable_key );
					return stripe;
				}

				toggle.addEventListener( 'click', () => {
					formEl.hidden = ! formEl.hidden;
					toggle.textContent = formEl.hidden ? '+ Add a debit card' : 'Cancel';
				} );

				saveBtn.addEventListener( 'click', async () => {
					errEl.textContent = '';
					saveBtn.disabled = true;
					try {
						await loadStripe();
						const num   = wrap.querySelector( '[data-cc-number]' ).value.replace( /\s+/g, '' );
						const expV  = wrap.querySelector( '[data-cc-exp]' ).value.replace( /[^0-9]/g, '' );
						const exp_month = expV.slice( 0, 2 );
						const exp_year  = '20' + expV.slice( 2, 4 );
						const cvc       = wrap.querySelector( '[data-cc-cvc]' ).value;
						const name      = wrap.querySelector( '[data-cc-name]' ).value;
						const address_zip = wrap.querySelector( '[data-cc-zip]' ).value;
						const { token, error } = await stripe.createToken( 'card', {
							number: num, exp_month, exp_year, cvc, name, address_zip,
							currency: 'usd',
						} );
						if ( error ) throw new Error( error.message );
						const res = await api( 'admin/studio-pay/destinations/attach', {
							method: 'POST', body: JSON.stringify( { token: token.id } ),
						} );
						if ( res.error ) throw new Error( res.error );
						formEl.hidden = true;
						toggle.textContent = '+ Add a debit card';
						await refresh();
					} catch ( e ) {
						errEl.textContent = e.message || 'Could not attach card.';
					} finally {
						saveBtn.disabled = false;
					}
				} );

				wrap.addEventListener( 'change', e => {
					if ( e.target.name !== 'dest' ) return;
					api( 'admin/studio-pay/destinations/default', {
						method: 'POST', body: JSON.stringify( { id: e.target.value } ),
					} ).then( () => { state.default_id = e.target.value; } );
				} );

				async function refresh() {
					state = await api( 'admin/studio-pay/destinations' );
					renderList();
				}
				refresh();
			}

			api( 'admin/studio-pay/status' ).then( s => {
				root.innerHTML = render( s );
				bind( s );
				if ( TAB === 'payouts' ) bootDestinations();
				if ( TAB === 'methods' ) bootMethods();
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
					// Per-method route override removed — routing is silent now.
				} );
			}
		} )();
		</script>
		<style>
		.counter-sp-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; margin-bottom: 14px; }
		.counter-sp-card__head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #f0f0f1; }
		.counter-sp-card__head h3 { margin: 0; font-size: 14px; }
		.counter-sp-card__sub { font-size: 11px; color: #8c8f94; margin-top: 2px; }
		.counter-sp-card__body { padding: 14px 18px; font-size: 13px; color: #50575e; }
		.counter-sp-card__body label { display: block; margin: 6px 0; }
		.counter-sp-card__hint { color: #8c8f94; }
		.counter-sp-card__actions { padding: 12px 18px; background: #f6f7f7; border-top: 1px solid #f0f0f1; display: flex; gap: 8px; }

		/* Routing preset cards */
		.counter-sp-preset {
			display: flex; gap: 12px; align-items: flex-start;
			padding: 12px 14px; margin-bottom: 8px;
			border: 1px solid #dcdcde; border-radius: 8px;
			background: #fff; cursor: pointer;
			transition: border-color 0.15s, background 0.15s;
		}
		.counter-sp-preset:hover         { border-color: #c3c4c7; }
		.counter-sp-preset.is-active     { border-color: #e83b3b; background: rgba(232, 59, 59, 0.04); }
		.counter-sp-preset input         { margin-top: 3px; flex-shrink: 0; }
		.counter-sp-preset__label        { font-weight: 600; font-size: 14px; color: #1d2327; margin-bottom: 4px; }
		.counter-sp-preset__desc         { font-size: 12px; color: #50575e; line-height: 1.5; }

		/* Money flow: destination-bucket sections, methods listed inside */
		.counter-sp-mf__section {
			background: #fff;
			border: 1px solid #f0f0f1;
			border-left: 4px solid #9ca3af;
			border-radius: 6px;
			padding: 14px 16px;
			margin-bottom: 12px;
		}
		.counter-sp-mf__head {
			display: flex; align-items: flex-start; justify-content: space-between;
			gap: 16px;
			margin-bottom: 4px;
		}
		.counter-sp-mf__head-r { text-align: right; flex-shrink: 0; }
		.counter-sp-mf__title { font-size: 14px; font-weight: 700; color: #1d2327; }
		.counter-sp-mf__sub   { font-size: 12px; color: #50575e; margin-top: 2px; line-height: 1.4; }
		.counter-sp-mf__count       { font-size: 26px; font-weight: 800; line-height: 1; color: #1d2327; font-variant-numeric: tabular-nums; }
		.counter-sp-mf__count-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: #8c8f94; margin-top: 2px; }

		.counter-sp-mf__warn,
		.counter-sp-mf__ok {
			margin: 8px 0 4px;
			padding: 6px 10px;
			border-radius: 4px;
			font-size: 12px;
		}
		.counter-sp-mf__warn { background: rgba(245, 158, 11, 0.08); color: #92400e; border: 1px solid rgba(245, 158, 11, 0.2); }
		.counter-sp-mf__ok   { background: rgba(16, 185, 129, 0.06); color: #065f46; border: 1px solid rgba(16, 185, 129, 0.2); }
		.counter-sp-mf__warn a { color: #92400e; font-weight: 600; }

		.counter-sp-mf__rows  { margin-top: 8px; display: grid; gap: 3px; }
		.counter-sp-mf__row   {
			display: grid;
			grid-template-columns: minmax( 130px, 1fr ) minmax( 130px, 1fr ) 120px;
			align-items: center; gap: 10px;
			padding: 6px 10px;
			background: #f9f9f9;
			border-radius: 4px;
			font-size: 12px;
		}
		.counter-sp-mf__row.is-pending { background: rgba(245, 158, 11, 0.05); }
		.counter-sp-mf__method  { font-weight: 600; color: #1d2327; }
		.counter-sp-mf__via     { color: #50575e; font-size: 11px; }
		.counter-sp-mf__via code{ font-family: var(--counter-mono); background: rgba(0,0,0,0.04); padding: 1px 5px; border-radius: 3px; color: #1d2327; }
		.counter-sp-mf__status  { text-align: right; font-size: 11px; font-weight: 600; }
		.counter-sp-mf__status--ok   { color: #065f46; }
		.counter-sp-mf__status--warn { color: #92400e; }
		a.counter-sp-mf__status--warn {
			text-decoration: none;
			padding: 3px 8px;
			background: rgba(245, 158, 11, 0.12);
			border-radius: 4px;
			border: 1px solid rgba(245, 158, 11, 0.25);
			transition: background 0.15s;
		}
		a.counter-sp-mf__status--warn:hover {
			background: rgba(245, 158, 11, 0.2);
			color: #78350f;
		}

		/* Method groups — checkout-strip-mirroring toggle list */
		.counter-sp-mg            { margin-bottom: 22px; }
		.counter-sp-mg__title     {
			font: 700 11px var(--counter-mono);
			text-transform: uppercase;
			letter-spacing: 0.08em;
			color: #8c8f94;
			margin-bottom: 8px;
		}
		.counter-sp-mg__rows      { display: grid; gap: 4px; }
		.counter-sp-mg__row       {
			display: grid;
			grid-template-columns: 50px minmax( 110px, 1fr ) minmax( 130px, 0.9fr ) 140px;
			align-items: center; gap: 12px;
			padding: 10px 14px;
			background: #fff;
			border: 1px solid #f0f0f1;
			border-radius: 6px;
			cursor: pointer;
			font-size: 13px;
			transition: background 0.15s, border-color 0.15s;
		}
		.counter-sp-mg__row:hover { border-color: #dcdcde; }
		.counter-sp-mg__row.is-off { background: #fafafa; }
		.counter-sp-mg__row.is-off .counter-sp-mg__label,
		.counter-sp-mg__row.is-off .counter-sp-mg__via { opacity: 0.5; }
		.counter-sp-mg__label     { font-weight: 600; color: #1d2327; }
		.counter-sp-mg__via       { font-size: 11px; color: #50575e; }
		.counter-sp-mg__via code  { font-family: var(--counter-mono); background: rgba(0,0,0,0.04); padding: 1px 5px; border-radius: 3px; color: #1d2327; }
		.counter-sp-mg__status    { text-align: right; font-size: 11px; font-weight: 600; }
		.counter-sp-mg__status--ok   { color: #065f46; }
		.counter-sp-mg__status--warn { color: #92400e; }
		a.counter-sp-mg__status--warn {
			text-decoration: none;
			padding: 3px 8px;
			background: rgba(245, 158, 11, 0.12);
			border-radius: 4px;
			border: 1px solid rgba(245, 158, 11, 0.25);
			transition: background 0.15s;
		}
		a.counter-sp-mg__status--warn:hover { background: rgba(245, 158, 11, 0.2); color: #78350f; }

		/* iOS-style toggle switch */
		.counter-sp-toggle { position: relative; display: inline-block; width: 38px; height: 22px; }
		.counter-sp-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
		.counter-sp-toggle__track {
			position: absolute; inset: 0;
			background: #c3c4c7;
			border-radius: 999px;
			transition: background 0.18s;
		}
		.counter-sp-toggle__track::before {
			content: ''; position: absolute;
			width: 16px; height: 16px; top: 3px; left: 3px;
			background: #fff;
			border-radius: 50%;
			transition: transform 0.18s;
			box-shadow: 0 1px 2px rgba(0,0,0,0.2);
		}
		.counter-sp-toggle input:checked + .counter-sp-toggle__track            { background: #10b981; }
		.counter-sp-toggle input:checked + .counter-sp-toggle__track::before    { transform: translateX(16px); }
		</style>
		<?php
	}
}
