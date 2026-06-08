<?php
/**
 * Counter by Therum — admin Importer wizard.
 *
 * Three-stage UI:
 *
 *   1. Source — pick file/URL/text + optional importer pin
 *   2. Preview grid — every detected product as a card with editable
 *      fields, confidence badge, source ref, issues
 *   3. Commit — bulk insert with progress count
 *
 * Stages 2 & 3 are driven by assets/admin/importer.js calling the
 * /counter/v1/import/* REST endpoints. The PHP page only renders stage 1
 * + the mount points the JS hydrates.
 */

namespace Counter\Admin;

use Counter\Services\ImporterRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImporterPage {

	public function __construct(
		private readonly ImporterRegistry $registry,
	) {}

	public function render(): void {
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Import / Export', 'counter' ); ?>
			</h1>
			<?php SectionTabs::render( 'counter-import' ); ?>
			<?php $this->renderBody(); ?>
		</div>
		<?php
	}

	/**
	 * Inner body only — page chrome (wrap, title, section tabs) is
	 * caller's responsibility. Lets ImportExportPage embed this view
	 * inside its own header without re-emitting duplicates.
	 */
	public function renderBody(): void {
		$importers = $this->registry->all();
		?>
			<p class="counter-admin__lede">
				<?php esc_html_e( 'Drop a CSV, paste a URL, upload a PDF/Markdown/image, or import everything from WooCommerce. Detect products, review, then confirm. Nothing writes to the database until you click Commit.', 'shop' ); ?>
			</p>

			<div class="counter-admin__importer" data-counter-importer>

				<!-- ─── Stage 1 — Source ─────────────────────────────────────── -->
				<section class="counter-admin__stage" data-stage="source" data-active="1">
					<header>
						<h2><?php esc_html_e( 'Source', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Pick one. The importer auto-detects from filename or URL — you can pin it manually if needed.', 'counter' ); ?></p>
					</header>

					<!-- WooCommerce one-click import -->
					<div class="counter-admin__row">
						<label><?php esc_html_e( 'Import from WooCommerce', 'counter' ); ?></label>
						<div>
							<button type="button" class="button button-primary" id="counter-import-woo" data-counter-import-woo>
								<?php esc_html_e( 'Import everything from WooCommerce', 'counter' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'One-click import: products, variants, customers, orders. After import, you can safely delete WooCommerce.', 'counter' ); ?>
							</p>
							<div id="counter-import-woo-result" style="margin-top: 12px; display: none;">
								<p><strong id="counter-import-woo-message"></strong></p>
							</div>
						</div>
					</div>

					<div class="counter-admin__row counter-admin__row--or"><?php esc_html_e( '— or —', 'counter' ); ?></div>

					<form data-counter-importer-form>
						<div class="counter-admin__row">
							<label><?php esc_html_e( 'Upload file', 'counter' ); ?></label>
							<div>
								<input type="file" name="file" data-counter-importer-file
									accept=".csv,.tsv,.txt,.md,.markdown,.pdf,.jpg,.jpeg,.png,.webp" />
								<p class="description">
									<?php esc_html_e( 'CSV, TSV, Markdown work today. PDF / image / Figma need the Anthropic API key (define SHOP_ANTHROPIC_API_KEY in wp-config.php).', 'shop' ); ?>
								</p>
							</div>
						</div>

						<div class="counter-admin__row counter-admin__row--or"><?php esc_html_e( '— or —', 'counter' ); ?></div>

						<div class="counter-admin__row">
							<label><?php esc_html_e( 'Paste URL', 'counter' ); ?></label>
							<div>
								<input type="url" name="url" data-counter-importer-url
									placeholder="https://shop.example.com/products/best-shirt" />
								<p class="description">
									<?php esc_html_e( 'Works with Shopify, Squarespace, BigCommerce, modern WooCommerce, Webflow — anything with JSON-LD or Open Graph product schema.', 'shop' ); ?>
								</p>
							</div>
						</div>

						<div class="counter-admin__row">
							<label><?php esc_html_e( 'Importer', 'counter' ); ?></label>
							<div>
								<select data-counter-importer-pick>
									<option value="">
										<?php esc_html_e( 'Auto-detect', 'counter' ); ?>
									</option>
									<?php foreach ( $importers as $imp ) : ?>
										<option value="<?php echo esc_attr( $imp->id() ); ?>">
											<?php echo esc_html( $imp->displayName() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="counter-admin__actions">
							<button type="submit" class="button button-primary button-large">
								<?php esc_html_e( 'Detect products', 'counter' ); ?>
							</button>
						</div>
					</form>
				</section>

				<!-- ─── Stage 2 — Preview ─────────────────────────────────────── -->
				<section class="counter-admin__stage" data-stage="preview" data-active="0" hidden>
					<header>
						<h2><?php esc_html_e( 'Review', 'counter' ); ?></h2>
						<p data-counter-importer-summary></p>
					</header>

					<div class="counter-admin__preview-grid" data-counter-importer-grid></div>

					<div class="counter-admin__actions">
						<button type="button" class="button" data-counter-importer-back>
							<?php esc_html_e( 'Back to source', 'counter' ); ?>
						</button>
						<button type="button" class="button button-primary button-large" data-counter-importer-commit>
							<?php esc_html_e( 'Commit selected', 'counter' ); ?>
						</button>
					</div>
				</section>

				<!-- ─── Stage 3 — Done ─────────────────────────────────────────── -->
				<section class="counter-admin__stage counter-admin__stage--done" data-stage="done" data-active="0" hidden>
					<header>
						<h2 data-counter-importer-done-title></h2>
						<p data-counter-importer-done-sub></p>
					</header>
					<div class="counter-admin__actions">
						<button type="button" class="button" data-counter-importer-restart>
							<?php esc_html_e( 'Import another', 'counter' ); ?>
						</button>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=counter' ) ); ?>">
							<?php esc_html_e( 'Back to Shop', 'counter' ); ?>
						</a>
					</div>
				</section>

			</div>

		<script>
		( function () {
			'use strict';

			var btn = document.getElementById( 'counter-import-woo' );
			var result = document.getElementById( 'counter-import-woo-result' );
			var msg = document.getElementById( 'counter-import-woo-message' );

			if ( ! btn ) return;

			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				if ( ! confirm( 'This will import all products, variants, customers, and orders from WooCommerce into Counter. This may take a moment. Proceed?' ) ) {
					return;
				}

				btn.disabled = true;
				btn.textContent = 'Importing…';
				result.style.display = 'none';

				fetch( '<?php echo esc_url( rest_url( 'counter/v1/import/woocommerce' ) ); ?>', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>',
						'Content-Type': 'application/json',
					},
					credentials: 'same-origin',
					body: JSON.stringify( {} ),
				} )
				.then( function ( r ) {
					return r.json().then( function ( j ) {
						return { status: r.status, body: j };
					} );
				} )
				.then( function ( res ) {
					btn.disabled = false;
					btn.textContent = 'Import everything from WooCommerce';

					if ( res.status >= 400 ) {
						msg.textContent = '❌ ' + ( res.body.message || 'Import failed.' );
						result.style.display = 'block';
						console.error( res.body );
						return;
					}

					var stats = res.body.stats || {};
					msg.innerHTML = '✅ ' +
						'Imported ' + stats.products + ' products, ' +
						stats.variants + ' variants, ' +
						stats.customers + ' customers, ' +
						stats.orders + ' orders, ' +
						stats.order_items + ' order items. ' +
						'You can now safely delete WooCommerce!';
					result.style.display = 'block';
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					btn.textContent = 'Import everything from WooCommerce';
					msg.textContent = '❌ ' + ( err.message || 'Import crashed.' );
					result.style.display = 'block';
					console.error( err );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
