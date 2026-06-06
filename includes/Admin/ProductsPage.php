<?php
/**
 * Shop by Therum — admin Products grid.
 *
 * Spreadsheet-style manager: inline edit, bulk select, bulk actions
 * (delete, duplicate, status change, set-any-field), search, sort,
 * pagination. JS-driven against /shop/v1/admin/products.
 *
 * This page is mostly an empty shell — assets/admin/products-grid.js
 * fetches data and renders rows. The PHP only emits the column config
 * and the bulk-action menu, so adding columns later doesn't require JS
 * edits.
 */

namespace Shop\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductsPage {

	public function render(): void {
		?>
		<div class="wrap shop-admin">
			<h1 class="shop-admin__title">
				<span class="shop-admin__mark">T</span>
				<?php esc_html_e( 'Products', 'shop' ); ?>
			</h1>

			<div class="shop-grid" data-shop-grid="products">

				<!-- Toolbar -->
				<header class="shop-grid__toolbar">
					<div class="shop-grid__search">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16" y2="16"/></svg>
						<input type="search" data-shop-grid-q placeholder="<?php esc_attr_e( 'Search title, SKU, slug…', 'shop' ); ?>" />
					</div>

					<select data-shop-grid-status class="shop-grid__filter">
						<option value=""><?php esc_html_e( 'All statuses', 'shop' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'shop' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'shop' ); ?></option>
						<option value="archived"><?php esc_html_e( 'Archived', 'shop' ); ?></option>
					</select>

					<div class="shop-grid__bulk" data-shop-grid-bulk hidden>
						<span class="shop-grid__bulk-count"><span data-shop-grid-selected>0</span> <?php esc_html_e( 'selected', 'shop' ); ?></span>
						<select data-shop-grid-bulk-action>
							<option value=""><?php esc_html_e( 'Bulk actions…', 'shop' ); ?></option>
							<option value="status:active"><?php esc_html_e( 'Set status → Active', 'shop' ); ?></option>
							<option value="status:draft"><?php esc_html_e( 'Set status → Draft', 'shop' ); ?></option>
							<option value="status:archived"><?php esc_html_e( 'Set status → Archived', 'shop' ); ?></option>
							<option value="duplicate"><?php esc_html_e( 'Duplicate', 'shop' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'shop' ); ?></option>
						</select>
						<button type="button" class="button" data-shop-grid-bulk-run><?php esc_html_e( 'Apply', 'shop' ); ?></button>
					</div>

					<div class="shop-grid__count">
						<span data-shop-grid-total>0</span> <?php esc_html_e( 'products', 'shop' ); ?>
					</div>
				</header>

				<!-- Spreadsheet -->
				<div class="shop-grid__scroll">
					<table class="shop-grid__table" data-shop-grid-table>
						<thead>
							<tr>
								<th class="shop-grid__th shop-grid__th--check">
									<input type="checkbox" data-shop-grid-toggle-all aria-label="<?php esc_attr_e( 'Toggle all', 'shop' ); ?>" />
								</th>
								<th class="shop-grid__th shop-grid__th--img"></th>
								<th class="shop-grid__th"   data-sort="title"><?php esc_html_e( 'Title', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"   data-sort="status"><?php esc_html_e( 'Status', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"   data-sort="price"><?php esc_html_e( 'Price', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"   data-sort="stock_qty"><?php esc_html_e( 'Stock', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"><?php esc_html_e( 'SKU', 'shop' ); ?></th>
								<th class="shop-grid__th"><?php esc_html_e( 'Type', 'shop' ); ?></th>
								<th class="shop-grid__th"   data-sort="updated_at"><?php esc_html_e( 'Updated', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th shop-grid__th--action"></th>
							</tr>
						</thead>
						<tbody data-shop-grid-tbody>
							<tr class="shop-grid__loading"><td colspan="10"><?php esc_html_e( 'Loading…', 'shop' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<!-- Pagination -->
				<footer class="shop-grid__pager">
					<button type="button" class="button" data-shop-grid-prev>&laquo;</button>
					<span class="shop-grid__pager-info">
						<?php esc_html_e( 'Page', 'shop' ); ?>
						<span data-shop-grid-page>1</span>
						/
						<span data-shop-grid-pages>1</span>
					</span>
					<button type="button" class="button" data-shop-grid-next>&raquo;</button>
				</footer>

			</div>
		</div>
		<?php
	}
}
