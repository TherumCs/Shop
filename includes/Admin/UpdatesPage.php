<?php
/**
 * Counter by Therum — admin Updates page.
 *
 * Three sections:
 *   1. Status — version + git head/branch/dirty + lock state
 *   2. Update — git pull button + zip upload + clean toggle
 *   3. History — snapshots table with rollback / delete
 *
 * Vanilla JS, inline. No framework. Hits /admin/updater/*.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class UpdatesPage {

	public function render(): void {
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span> Updates
			</h1>
			<?php SectionTabs::render( 'counter-updates' ); ?>
			<div
				id="counter-updater"
				data-rest="<?php echo esc_url( rest_url() ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			><p>Loading…</p></div>
		</div>
		<script>
		( function () {
			const root = document.getElementById( 'counter-updater' );
			if ( ! root ) return;
			const REST  = root.getAttribute( 'data-rest' ) + 'counter/v1/admin/updater/';
			const NONCE = root.getAttribute( 'data-nonce' );

			const api = ( path, opts ) => fetch( REST + path, Object.assign( {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			}, opts || {} ) ).then( r => r.json() );

			const esc = s => String( s == null ? '' : s ).replace( /[&<>"']/g, c => ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ] ) );
			const bytes = n => n < 1024 ? n + ' B' : n < 1048576 ? ( n / 1024 ).toFixed( 1 ) + ' KB' : ( n / 1048576 ).toFixed( 1 ) + ' MB';
			const date  = ts => new Date( ts * 1000 ).toLocaleString();

			function render( s ) {
				root.innerHTML = `
					<div class="counter-up-card">
						<div class="counter-up-head"><h3>Status</h3></div>
						<div class="counter-up-body">
							<div><strong>Plugin version:</strong> ${ esc( s.version || 'unknown' ) }</div>
							${ s.git ? `
								<div><strong>Git:</strong> ${ esc( s.git.branch || '?' ) } @ <code>${ esc( s.git.head || '?' ) }</code> ${ s.git.dirty ? '<span class="counter-up-pill counter-up-pill--warn">uncommitted</span>' : '<span class="counter-up-pill counter-up-pill--ok">clean</span>' }</div>
								<div class="counter-up-sub">${ esc( s.git.subject || '' ) }</div>
								<div class="counter-up-sub">remote: ${ esc( s.git.remote || '?' ) }</div>
							` : '<div class="counter-up-sub">Not a git checkout — pull-from-git is unavailable. Zip install + rollback still work.</div>' }
							${ s.locked ? '<div class="counter-up-pill counter-up-pill--warn">Another update is currently locked</div>' : '' }
						</div>
					</div>

					<div class="counter-up-card">
						<div class="counter-up-head"><h3>Update</h3></div>
						<div class="counter-up-body">
							${ s.git ? `
								<p>Pull latest from <code>origin/${ esc( s.git.branch || 'main' ) }</code>. Local working-tree changes will be discarded.</p>
								<label>Branch: <input id="branch" value="${ esc( s.git.branch || 'main' ) }" style="width:180px"></label>
								<button class="button button-primary" id="pull">Pull from git</button>
								<hr>
							` : '' }
							<p>Or upload a <code>.zip</code> of the plugin:</p>
							<input type="file" id="zip" accept=".zip">
							<label><input type="checkbox" id="clean"> Clean install (remove files not in zip)</label>
							<button class="button" id="install">Install from zip</button>
							<hr>
							<p>Take a manual snapshot without changing anything:</p>
							<button class="button" id="snap">Snapshot now</button>
							<div id="result" class="counter-up-result"></div>
						</div>
					</div>

					<div class="counter-up-card">
						<div class="counter-up-head"><h3>Snapshots <span class="counter-up-sub">(${ s.snapshots.length })</span></h3></div>
						<div class="counter-up-body">
							${ s.snapshots.length === 0
								? '<p class="counter-up-sub">No snapshots yet. One is taken automatically before every update.</p>'
								: `<table class="wp-list-table widefat striped">
									<thead><tr><th>Version</th><th>When</th><th>Size</th><th>Note</th><th></th></tr></thead>
									<tbody>${ s.snapshots.map( sn => `
										<tr>
											<td><code>${ esc( sn.version ) }</code></td>
											<td>${ date( sn.created_at ) }</td>
											<td>${ bytes( sn.size ) }</td>
											<td class="counter-up-sub">${ esc( sn.note || '' ) }</td>
											<td>
												<button class="button" data-rollback="${ esc( sn.id ) }">Rollback</button>
												<button class="button-link-delete" data-del="${ esc( sn.id ) }">Delete</button>
											</td>
										</tr>` ).join( '' ) }</tbody>
								</table>`
							}
						</div>
					</div>`;
				bind();
			}

			function bind() {
				const result = document.getElementById( 'result' );
				const say = ( msg, kind ) => {
					result.className = 'counter-up-result ' + ( kind === 'ok' ? 'is-ok' : kind === 'err' ? 'is-err' : '' );
					result.textContent = msg;
				};

				document.getElementById( 'pull' )?.addEventListener( 'click', () => {
					if ( ! confirm( 'Discard local changes and pull from origin?' ) ) return;
					say( 'Pulling…' );
					api( 'pull', { method: 'POST', body: JSON.stringify( { branch: document.getElementById( 'branch' ).value } ) } )
						.then( r => {
							if ( r.error ) return say( r.error, 'err' );
							say( `Updated ${ r.pulled.before.substr(0,7) } → ${ r.pulled.after.substr(0,7) }: ${ r.pulled.subject }`, 'ok' );
							setTimeout( load, 1500 );
						} );
				} );

				document.getElementById( 'install' )?.addEventListener( 'click', () => {
					const file = document.getElementById( 'zip' ).files[0];
					if ( ! file ) return say( 'Choose a .zip first.', 'err' );
					if ( ! confirm( 'Install this zip over the current plugin?' ) ) return;
					say( 'Uploading…' );
					const fd = new FormData();
					fd.append( 'zip', file );
					if ( document.getElementById( 'clean' ).checked ) fd.append( 'clean', '1' );
					fetch( REST + 'install-zip', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': NONCE },
						body: fd,
					} ).then( r => r.json() ).then( r => {
						if ( r.error ) return say( r.error, 'err' );
						say( `Installed: ${ r.result.files_written } written, ${ r.result.files_removed } removed.`, 'ok' );
						setTimeout( load, 1500 );
					} );
				} );

				document.getElementById( 'snap' )?.addEventListener( 'click', () => {
					const note = prompt( 'Note (optional):' );
					say( 'Snapshotting…' );
					api( 'snapshot', { method: 'POST', body: JSON.stringify( { note: note || '' } ) } )
						.then( r => {
							if ( r.error ) return say( r.error, 'err' );
							say( 'Snapshot ' + r.id + ' created.', 'ok' );
							setTimeout( load, 800 );
						} );
				} );

				root.querySelectorAll( '[data-rollback]' ).forEach( b => b.addEventListener( 'click', () => {
					const id = b.getAttribute( 'data-rollback' );
					if ( ! confirm( 'Roll back to ' + id + '? A safety snapshot of the current state will be taken first.' ) ) return;
					say( 'Rolling back…' );
					api( 'rollback', { method: 'POST', body: JSON.stringify( { id } ) } ).then( r => {
						if ( r.error ) return say( r.error, 'err' );
						say( 'Rolled back to ' + id + '. Safety snapshot: ' + r.result.safety_snapshot, 'ok' );
						setTimeout( load, 1500 );
					} );
				} ) );

				root.querySelectorAll( '[data-del]' ).forEach( b => b.addEventListener( 'click', () => {
					const id = b.getAttribute( 'data-del' );
					if ( ! confirm( 'Delete snapshot ' + id + '?' ) ) return;
					api( 'snapshot/' + encodeURIComponent( id ), { method: 'DELETE' } ).then( () => load() );
				} ) );
			}

			function load() { api( 'status' ).then( render ); }
			load();
		} )();
		</script>
		<style>
		.counter-up-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; margin-bottom: 14px; }
		.counter-up-head { padding: 14px 18px; border-bottom: 1px solid #f0f0f1; }
		.counter-up-head h3 { margin: 0; font-size: 14px; }
		.counter-up-body { padding: 14px 18px; font-size: 13px; color: #50575e; }
		.counter-up-body > * + * { margin-top: 10px; }
		.counter-up-body hr { border: 0; border-top: 1px solid #f0f0f1; margin: 12px 0; }
		.counter-up-sub { color: #8c8f94; font-size: 11px; }
		.counter-up-pill {
			display: inline-block; padding: 2px 8px;
			font: 700 10px JetBrains Mono, ui-monospace, Menlo, monospace;
			letter-spacing: .06em; text-transform: uppercase;
			border-radius: 999px;
			margin-left: 6px;
		}
		.counter-up-pill--ok   { background: #d4edda; color: #155724; }
		.counter-up-pill--warn { background: #fff3cd; color: #856404; }
		.counter-up-result { font: 12px JetBrains Mono, ui-monospace, Menlo, monospace; padding: 10px; border-radius: 6px; background: #f6f7f7; }
		.counter-up-result.is-ok  { background: #d4edda; color: #155724; }
		.counter-up-result.is-err { background: #f8d7da; color: #721c24; }
		</style>
		<?php
	}
}
