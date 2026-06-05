<?php
/**
 * Shop by Therum — admin Importer wizard.
 *
 * Three-stage UI:
 *
 *   1. Source — pick file/URL/text + optional importer pin
 *   2. Preview grid — every detected product as a card with editable
 *      fields, confidence badge, source ref, issues
 *   3. Commit — bulk insert with progress count
 *
 * Stages 2 & 3 are driven by assets/admin/importer.js calling the
 * /shop/v1/import/* REST endpoints. The PHP page only renders stage 1
 * + the mount points the JS hydrates.
 */

namespace Shop\Admin;

use Shop\Services\ImporterRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImporterPage {

	public function __construct(
		private readonly ImporterRegistry $registry,
	) {}

	public function render(): void {
		$importers = $this->registry->all();
		?>
		<div class="wrap shop-admin">
			<h1 class="shop-admin__title">
				<span class="shop-admin__mark">T</span>
				<?php esc_html_e( 'Import Catalog', 'shop' ); ?>
			</h1>

			<p class="shop-admin__lede">
				<?php esc_html_e( 'Drop a CSV, paste a URL, or upload a PDF/Markdown/image. Shop detects products, you review, then confirm. Nothing writes to the database until you click Commit.', 'shop' ); ?>
			</p>

			<div class="shop-admin__importer" data-shop-importer>

				<!-- ─── Stage 1 — Source ─────────────────────────────────────── -->
				<section class="shop-admin__stage" data-stage="source" data-active="1">
					<header>
						<h2><?php esc_html_e( 'Source', 'shop' ); ?></h2>
						<p><?php esc_html_e( 'Pick one. The importer auto-detects from filename or URL — you can pin it manually if needed.', 'shop' ); ?></p>
					</header>

					<form data-shop-importer-form>
						<div class="shop-admin__row">
							<label><?php esc_html_e( 'Upload file', 'shop' ); ?></label>
							<div>
								<input type="file" name="file" data-shop-importer-file
									accept=".csv,.tsv,.txt,.md,.markdown,.pdf,.jpg,.jpeg,.png,.webp" />
								<p class="description">
									<?php esc_html_e( 'CSV, TSV, Markdown work today. PDF / image / Figma need the Anthropic API key (define SHOP_ANTHROPIC_API_KEY in wp-config.php).', 'shop' ); ?>
								</p>
							</div>
						</div>

						<div class="shop-admin__row shop-admin__row--or"><?php esc_html_e( '— or —', 'shop' ); ?></div>

						<div class="shop-admin__row">
							<label><?php esc_html_e( 'Paste URL', 'shop' ); ?></label>
							<div>
								<input type="url" name="url" data-shop-importer-url
									placeholder="https://shop.example.com/products/best-shirt" />
								<p class="description">
									<?php esc_html_e( 'Works with Shopify, Squarespace, BigCommerce, modern WooCommerce, Webflow — anything with JSON-LD or Open Graph product schema.', 'shop' ); ?>
								</p>
							</div>
						</div>

						<div class="shop-admin__row">
							<label><?php esc_html_e( 'Importer', 'shop' ); ?></label>
							<div>
								<select data-shop-importer-pick>
									<option value="">
										<?php esc_html_e( 'Auto-detect', 'shop' ); ?>
									</option>
									<?php foreach ( $importers as $imp ) : ?>
										<option value="<?php echo esc_attr( $imp->id() ); ?>">
											<?php echo esc_html( $imp->displayName() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="shop-admin__actions">
							<button type="submit" class="button button-primary button-large">
								<?php esc_html_e( 'Detect products', 'shop' ); ?>
							</button>
						</div>
					</form>
				</section>

				<!-- ─── Stage 2 — Preview ─────────────────────────────────────── -->
				<section class="shop-admin__stage" data-stage="preview" data-active="0" hidden>
					<header>
						<h2><?php esc_html_e( 'Review', 'shop' ); ?></h2>
						<p data-shop-importer-summary></p>
					</header>

					<div class="shop-admin__preview-grid" data-shop-importer-grid></div>

					<div class="shop-admin__actions">
						<button type="button" class="button" data-shop-importer-back>
							<?php esc_html_e( 'Back to source', 'shop' ); ?>
						</button>
						<button type="button" class="button button-primary button-large" data-shop-importer-commit>
							<?php esc_html_e( 'Commit selected', 'shop' ); ?>
						</button>
					</div>
				</section>

				<!-- ─── Stage 3 — Done ─────────────────────────────────────────── -->
				<section class="shop-admin__stage shop-admin__stage--done" data-stage="done" data-active="0" hidden>
					<header>
						<h2 data-shop-importer-done-title></h2>
						<p data-shop-importer-done-sub></p>
					</header>
					<div class="shop-admin__actions">
						<button type="button" class="button" data-shop-importer-restart>
							<?php esc_html_e( 'Import another', 'shop' ); ?>
						</button>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=shop' ) ); ?>">
							<?php esc_html_e( 'Back to Shop', 'shop' ); ?>
						</a>
					</div>
				</section>

			</div>
		</div>
		<?php
	}
}
