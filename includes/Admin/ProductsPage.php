<?php
/**
 * Counter by Therum — admin Products grid.
 *
 * Spreadsheet-style manager: inline edit, bulk select, bulk actions
 * (delete, duplicate, status change, set-any-field), search, sort,
 * pagination. JS-driven against /counter/v1/admin/products.
 *
 * This page is mostly an empty shell — assets/admin/products-grid.js
 * fetches data and renders rows. The PHP only emits the column config
 * and the bulk-action menu, so adding columns later doesn't require JS
 * edits.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductsPage {

	public function render(): void {
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Products', 'counter' ); ?>
			</h1>
			<?php SectionTabs::render( 'counter-products' ); ?>

			<div class="counter-grid" data-counter-grid="products">

				<!-- Toolbar -->
				<header class="counter-grid__toolbar">
					<div class="counter-grid__search">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16" y2="16"/></svg>
						<input type="search" data-counter-grid-q placeholder="<?php esc_attr_e( 'Search title, SKU, slug…', 'shop' ); ?>" />
					</div>

					<select data-counter-grid-status class="counter-grid__filter">
						<option value=""><?php esc_html_e( 'All statuses', 'counter' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'counter' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'counter' ); ?></option>
						<option value="archived"><?php esc_html_e( 'Archived', 'counter' ); ?></option>
					</select>

					<div class="counter-grid__bulk" data-counter-grid-bulk hidden>
						<span class="counter-grid__bulk-count"><span data-counter-grid-selected>0</span> <?php esc_html_e( 'selected', 'counter' ); ?></span>
						<select data-counter-grid-bulk-action>
							<option value=""><?php esc_html_e( 'Bulk actions…', 'counter' ); ?></option>
							<option value="status:active"><?php esc_html_e( 'Set status → Active', 'counter' ); ?></option>
							<option value="status:draft"><?php esc_html_e( 'Set status → Draft', 'counter' ); ?></option>
							<option value="status:archived"><?php esc_html_e( 'Set status → Archived', 'counter' ); ?></option>
							<option value="duplicate"><?php esc_html_e( 'Duplicate', 'counter' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'counter' ); ?></option>
						</select>
						<button type="button" class="button" data-counter-grid-bulk-run><?php esc_html_e( 'Apply', 'counter' ); ?></button>
					</div>

					<div class="counter-grid__count">
						<span data-counter-grid-total>0</span> <?php esc_html_e( 'products', 'counter' ); ?>
					</div>

					<div class="counter-grid__view" role="group" aria-label="<?php esc_attr_e( 'View mode', 'counter' ); ?>">
						<button type="button" class="counter-grid__view-btn is-active" data-counter-view="list" title="<?php esc_attr_e( 'List view', 'counter' ); ?>" aria-label="<?php esc_attr_e( 'List view', 'counter' ); ?>">
							<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="2" y1="4" x2="14" y2="4"/><line x1="2" y1="8" x2="14" y2="8"/><line x1="2" y1="12" x2="14" y2="12"/></svg>
						</button>
						<button type="button" class="counter-grid__view-btn" data-counter-view="grid" title="<?php esc_attr_e( 'Grid view', 'counter' ); ?>" aria-label="<?php esc_attr_e( 'Grid view', 'counter' ); ?>">
							<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="5" height="5"/><rect x="9" y="2" width="5" height="5"/><rect x="2" y="9" width="5" height="5"/><rect x="9" y="9" width="5" height="5"/></svg>
						</button>
					</div>
				</header>

				<!-- Spreadsheet -->
				<div class="counter-grid__scroll">
					<table class="counter-grid__table" data-counter-grid-table>
						<thead>
							<tr>
								<th class="counter-grid__th counter-grid__th--check">
									<input type="checkbox" data-counter-grid-toggle-all aria-label="<?php esc_attr_e( 'Toggle all', 'counter' ); ?>" />
								</th>
								<th class="counter-grid__th counter-grid__th--img"></th>
								<th class="counter-grid__th"   data-sort="title"><?php esc_html_e( 'Title', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"   data-sort="status"><?php esc_html_e( 'Status', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"   data-sort="price"><?php esc_html_e( 'Price', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"   data-sort="stock_qty"><?php esc_html_e( 'Stock', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"><?php esc_html_e( 'SKU', 'counter' ); ?></th>
								<th class="counter-grid__th"><?php esc_html_e( 'Type', 'counter' ); ?></th>
								<th class="counter-grid__th"   data-sort="updated_at"><?php esc_html_e( 'Updated', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th counter-grid__th--action"></th>
							</tr>
						</thead>
						<tbody data-counter-grid-tbody>
							<tr class="counter-grid__loading"><td colspan="10"><?php esc_html_e( 'Loading…', 'counter' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<!-- Pagination -->
				<footer class="counter-grid__pager">
					<button type="button" class="button" data-counter-grid-prev>&laquo;</button>
					<span class="counter-grid__pager-info">
						<?php esc_html_e( 'Page', 'counter' ); ?>
						<span data-counter-grid-page>1</span>
						/
						<span data-counter-grid-pages>1</span>
					</span>
					<button type="button" class="button" data-counter-grid-next>&raquo;</button>
				</footer>

			</div>
		</div>
		<?php
	}
}
