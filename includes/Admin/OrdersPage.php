<?php
/**
 * Counter by Therum — admin Orders grid.
 *
 * Uses the same counter-grid component as Products. The data shape and the
 * bulk actions differ — orders are mostly immutable, so editing is
 * limited to status. "Delete" archives (marks cancelled) — we never
 * destroy order rows to preserve the audit trail.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrdersPage {

	public function render(): void {
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Orders', 'counter' ); ?>
			</h1>
			<?php SectionTabs::render( 'counter-orders' ); ?>

			<div class="counter-grid" data-counter-grid="orders">

				<header class="counter-grid__toolbar">
					<div class="counter-grid__search">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16" y2="16"/></svg>
						<input type="search" data-counter-grid-q placeholder="<?php esc_attr_e( 'Search order #, email, payment intent…', 'shop' ); ?>" />
					</div>

					<select data-counter-grid-status class="counter-grid__filter">
						<option value=""><?php esc_html_e( 'All statuses', 'counter' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'counter' ); ?></option>
						<option value="processing"><?php esc_html_e( 'Processing', 'counter' ); ?></option>
						<option value="on-hold"><?php esc_html_e( 'On hold', 'counter' ); ?></option>
						<option value="completed"><?php esc_html_e( 'Completed', 'counter' ); ?></option>
						<option value="cancelled"><?php esc_html_e( 'Cancelled', 'counter' ); ?></option>
						<option value="refunded"><?php esc_html_e( 'Refunded', 'counter' ); ?></option>
						<option value="failed"><?php esc_html_e( 'Failed', 'counter' ); ?></option>
					</select>

					<div class="counter-grid__bulk" data-counter-grid-bulk hidden>
						<span class="counter-grid__bulk-count"><span data-counter-grid-selected>0</span> <?php esc_html_e( 'selected', 'counter' ); ?></span>
						<select data-counter-grid-bulk-action>
							<option value=""><?php esc_html_e( 'Bulk actions…', 'counter' ); ?></option>
							<option value="status:processing"><?php esc_html_e( 'Set status → Processing', 'counter' ); ?></option>
							<option value="status:on-hold"><?php esc_html_e( 'Set status → On hold', 'counter' ); ?></option>
							<option value="status:completed"><?php esc_html_e( 'Set status → Completed', 'counter' ); ?></option>
							<option value="status:cancelled"><?php esc_html_e( 'Set status → Cancelled', 'counter' ); ?></option>
							<option value="status:refunded"><?php esc_html_e( 'Set status → Refunded', 'counter' ); ?></option>
						</select>
						<button type="button" class="button" data-counter-grid-bulk-run><?php esc_html_e( 'Apply', 'counter' ); ?></button>
					</div>

					<div class="counter-grid__count">
						<span data-counter-grid-total>0</span> <?php esc_html_e( 'orders', 'counter' ); ?>
					</div>
				</header>

				<div class="counter-grid__scroll">
					<table class="counter-grid__table" data-counter-grid-table>
						<thead>
							<tr>
								<th class="counter-grid__th counter-grid__th--check">
									<input type="checkbox" data-counter-grid-toggle-all aria-label="<?php esc_attr_e( 'Toggle all', 'counter' ); ?>" />
								</th>
								<th class="counter-grid__th" data-sort="number"><?php esc_html_e( 'Order', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th" data-sort="created_at"><?php esc_html_e( 'Placed', 'counter' ); ?> <span class="counter-grid__sort-arrow">▼</span></th>
								<th class="counter-grid__th"><?php esc_html_e( 'Customer', 'counter' ); ?></th>
								<th class="counter-grid__th" data-sort="status"><?php esc_html_e( 'Status', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"><?php esc_html_e( 'Items', 'counter' ); ?></th>
								<th class="counter-grid__th" data-sort="grand_total"><?php esc_html_e( 'Total', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
								<th class="counter-grid__th"><?php esc_html_e( 'Payment', 'counter' ); ?></th>
								<th class="counter-grid__th" data-sort="paid_at"><?php esc_html_e( 'Paid', 'counter' ); ?> <span class="counter-grid__sort-arrow"></span></th>
							</tr>
						</thead>
						<tbody data-counter-grid-tbody>
							<tr class="counter-grid__loading"><td colspan="9"><?php esc_html_e( 'Loading…', 'counter' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<footer class="counter-grid__pager">
					<button type="button" class="button" data-counter-grid-prev>&laquo;</button>
					<span class="counter-grid__pager-info">
						<?php esc_html_e( 'Page', 'counter' ); ?>
						<span data-counter-grid-page>1</span> /
						<span data-counter-grid-pages>1</span>
					</span>
					<button type="button" class="button" data-counter-grid-next>&raquo;</button>
				</footer>

			</div>
		</div>
		<?php
	}
}
