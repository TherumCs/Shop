<?php
/**
 * Shop by Therum — admin Orders grid.
 *
 * Uses the same shop-grid component as Products. The data shape and the
 * bulk actions differ — orders are mostly immutable, so editing is
 * limited to status. "Delete" archives (marks cancelled) — we never
 * destroy order rows to preserve the audit trail.
 */

namespace Shop\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrdersPage {

	public function render(): void {
		?>
		<div class="wrap shop-admin">
			<h1 class="shop-admin__title">
				<span class="shop-admin__mark">T</span>
				<?php esc_html_e( 'Orders', 'shop' ); ?>
			</h1>

			<div class="shop-grid" data-shop-grid="orders">

				<header class="shop-grid__toolbar">
					<div class="shop-grid__search">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16" y2="16"/></svg>
						<input type="search" data-shop-grid-q placeholder="<?php esc_attr_e( 'Search order #, email, payment intent…', 'shop' ); ?>" />
					</div>

					<select data-shop-grid-status class="shop-grid__filter">
						<option value=""><?php esc_html_e( 'All statuses', 'shop' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'shop' ); ?></option>
						<option value="processing"><?php esc_html_e( 'Processing', 'shop' ); ?></option>
						<option value="on-hold"><?php esc_html_e( 'On hold', 'shop' ); ?></option>
						<option value="completed"><?php esc_html_e( 'Completed', 'shop' ); ?></option>
						<option value="cancelled"><?php esc_html_e( 'Cancelled', 'shop' ); ?></option>
						<option value="refunded"><?php esc_html_e( 'Refunded', 'shop' ); ?></option>
						<option value="failed"><?php esc_html_e( 'Failed', 'shop' ); ?></option>
					</select>

					<div class="shop-grid__bulk" data-shop-grid-bulk hidden>
						<span class="shop-grid__bulk-count"><span data-shop-grid-selected>0</span> <?php esc_html_e( 'selected', 'shop' ); ?></span>
						<select data-shop-grid-bulk-action>
							<option value=""><?php esc_html_e( 'Bulk actions…', 'shop' ); ?></option>
							<option value="status:processing"><?php esc_html_e( 'Set status → Processing', 'shop' ); ?></option>
							<option value="status:on-hold"><?php esc_html_e( 'Set status → On hold', 'shop' ); ?></option>
							<option value="status:completed"><?php esc_html_e( 'Set status → Completed', 'shop' ); ?></option>
							<option value="status:cancelled"><?php esc_html_e( 'Set status → Cancelled', 'shop' ); ?></option>
							<option value="status:refunded"><?php esc_html_e( 'Set status → Refunded', 'shop' ); ?></option>
						</select>
						<button type="button" class="button" data-shop-grid-bulk-run><?php esc_html_e( 'Apply', 'shop' ); ?></button>
					</div>

					<div class="shop-grid__count">
						<span data-shop-grid-total>0</span> <?php esc_html_e( 'orders', 'shop' ); ?>
					</div>
				</header>

				<div class="shop-grid__scroll">
					<table class="shop-grid__table" data-shop-grid-table>
						<thead>
							<tr>
								<th class="shop-grid__th shop-grid__th--check">
									<input type="checkbox" data-shop-grid-toggle-all aria-label="<?php esc_attr_e( 'Toggle all', 'shop' ); ?>" />
								</th>
								<th class="shop-grid__th" data-sort="number"><?php esc_html_e( 'Order', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th" data-sort="created_at"><?php esc_html_e( 'Placed', 'shop' ); ?> <span class="shop-grid__sort-arrow">▼</span></th>
								<th class="shop-grid__th"><?php esc_html_e( 'Customer', 'shop' ); ?></th>
								<th class="shop-grid__th" data-sort="status"><?php esc_html_e( 'Status', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"><?php esc_html_e( 'Items', 'shop' ); ?></th>
								<th class="shop-grid__th" data-sort="grand_total"><?php esc_html_e( 'Total', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
								<th class="shop-grid__th"><?php esc_html_e( 'Payment', 'shop' ); ?></th>
								<th class="shop-grid__th" data-sort="paid_at"><?php esc_html_e( 'Paid', 'shop' ); ?> <span class="shop-grid__sort-arrow"></span></th>
							</tr>
						</thead>
						<tbody data-shop-grid-tbody>
							<tr class="shop-grid__loading"><td colspan="9"><?php esc_html_e( 'Loading…', 'shop' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<footer class="shop-grid__pager">
					<button type="button" class="button" data-shop-grid-prev>&laquo;</button>
					<span class="shop-grid__pager-info">
						<?php esc_html_e( 'Page', 'shop' ); ?>
						<span data-shop-grid-page>1</span> /
						<span data-shop-grid-pages>1</span>
					</span>
					<button type="button" class="button" data-shop-grid-next>&raquo;</button>
				</footer>

			</div>
		</div>
		<?php
	}
}
