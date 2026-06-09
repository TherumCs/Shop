<?php
/**
 * Counter by Therum — admin Dashboard.
 *
 * Bento grid. Cells are different sizes (1×1 / 2×1 / 2×2) on a 4-column
 * grid that collapses to 2-col → 1-col on narrower screens.
 *
 * Every cell links somewhere — the dashboard is the hub for the rest of
 * the plugin.
 */

namespace Counter\Admin;

use Counter\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DashboardPage {

	public function register(): void {
		// No-op — kept so AdminMenu's $this->dashboard->register() call
		// doesn't break while we use a straight render with no load hooks.
	}

	public function render(): void {
		$pdo = DB::pdo();

		$stats = [
			'revenue_cents' => (int) $pdo->query(
				"SELECT COALESCE(SUM(grand_total - refunded_total), 0)
				   FROM orders
				  WHERE status IN ('completed','processing','paid')"
			)->fetchColumn(),
			'orders_total'  => (int) $pdo->query( 'SELECT COUNT(*) FROM orders' )->fetchColumn(),
			'orders_pending'=> (int) $pdo->query(
				"SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing','on-hold')"
			)->fetchColumn(),
			'products'      => (int) $pdo->query( 'SELECT COUNT(*) FROM products' )->fetchColumn(),
			'low_stock'     => (int) $pdo->query(
				"SELECT COUNT(*) FROM products
				  WHERE track_inventory = 1
				    AND stock_qty IS NOT NULL
				    AND stock_qty < 10"
			)->fetchColumn(),
			'customers'     => (int) $pdo->query( 'SELECT COUNT(*) FROM customers' )->fetchColumn(),
			'repeat_buyers' => (int) $pdo->query( 'SELECT COUNT(*) FROM customers WHERE orders_count > 1' )->fetchColumn(),
			'categories'    => taxonomy_exists( 'product_cat' )
				? (int) wp_count_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] )
				: 0,
		];

		$recent = $pdo->query(
			"SELECT id, number, email, status, grand_total, created_at
			   FROM orders
			  ORDER BY created_at DESC
			  LIMIT 5"
		)->fetchAll();

		$payments_methods_enabled = (array) get_option( 'counter_studio_pay_methods_enabled', [] );
		$payments_visible_count   = count( array_filter( $payments_methods_enabled ) );
		$payments_cadence         = (string) get_option( 'counter_studio_pay_payout_cadence', 'daily' );

		$href = fn( string $slug ) => admin_url( 'admin.php?page=' . $slug );

		?>
		<div class="wrap counter-admin counter-dash">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Dashboard', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>
			<?php SectionTabs::render( 'counter' ); ?>

			<div class="counter-bento">

				<!-- Row 1: 4 stat cells, 1×1 each -->
				<a class="counter-bento__cell counter-bento__cell--stat" href="<?php echo esc_url( $href( 'counter-orders' ) ); ?>">
					<div class="counter-bento__stat-label">Revenue</div>
					<div class="counter-bento__stat-value">$<?php echo number_format( $stats['revenue_cents'] / 100, 2 ); ?></div>
					<div class="counter-bento__stat-sub">all-time net</div>
				</a>
				<a class="counter-bento__cell counter-bento__cell--stat" href="<?php echo esc_url( $href( 'counter-orders' ) ); ?>">
					<div class="counter-bento__stat-label">Orders</div>
					<div class="counter-bento__stat-value"><?php echo number_format( $stats['orders_total'] ); ?></div>
					<div class="counter-bento__stat-sub">
						<?php echo $stats['orders_pending'] > 0
							? esc_html( $stats['orders_pending'] . ' pending' )
							: esc_html__( 'all caught up', 'counter' ); ?>
					</div>
				</a>
				<a class="counter-bento__cell counter-bento__cell--stat" href="<?php echo esc_url( $href( 'counter-products' ) ); ?>">
					<div class="counter-bento__stat-label">Products</div>
					<div class="counter-bento__stat-value"><?php echo number_format( $stats['products'] ); ?></div>
					<div class="counter-bento__stat-sub">
						<?php echo $stats['low_stock'] > 0
							? esc_html( $stats['low_stock'] . ' low stock' )
							: esc_html__( 'stock healthy', 'counter' ); ?>
					</div>
				</a>
				<a class="counter-bento__cell counter-bento__cell--stat" href="<?php echo esc_url( $href( 'counter-customers' ) ); ?>">
					<div class="counter-bento__stat-label">Customers</div>
					<div class="counter-bento__stat-value"><?php echo number_format( $stats['customers'] ); ?></div>
					<div class="counter-bento__stat-sub">
						<?php echo $stats['repeat_buyers'] > 0
							? esc_html( $stats['repeat_buyers'] . ' repeat' )
							: esc_html__( 'lifetime', 'counter' ); ?>
					</div>
				</a>

				<!-- Row 2 left: Recent orders, 2×2 hero cell -->
				<div class="counter-bento__cell counter-bento__cell--hero">
					<div class="counter-bento__cell-head">
						<h2>Recent orders</h2>
						<a class="counter-bento__action" href="<?php echo esc_url( $href( 'counter-orders' ) ); ?>">View all →</a>
					</div>
					<?php if ( ! $recent ): ?>
						<p class="counter-bento__empty">No orders yet. Test the checkout to seed one.</p>
					<?php else: ?>
						<table class="counter-bento__table">
							<thead>
								<tr>
									<th>Order</th>
									<th>Customer</th>
									<th>Status</th>
									<th style="text-align:right;">Total</th>
									<th style="text-align:right;">Date</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent as $o ): ?>
									<tr>
										<td><a href="<?php echo esc_url( $href( 'counter-orders' ) ); ?>">#<?php echo esc_html( $o['number'] ); ?></a></td>
										<td><?php echo esc_html( $o['email'] ); ?></td>
										<td><span class="counter-bento__badge counter-bento__badge--<?php echo esc_attr( $o['status'] ); ?>"><?php echo esc_html( ucfirst( $o['status'] ) ); ?></span></td>
										<td style="text-align:right;font-family:var(--counter-mono);">$<?php echo number_format( $o['grand_total'] / 100, 2 ); ?></td>
										<td style="text-align:right;color:var(--counter-tx-3);font-family:var(--counter-mono);font-size:12px;"><?php echo esc_html( date( 'M j, Y', (int) $o['created_at'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Row 2 right: Payments — 2×1 -->
				<a class="counter-bento__cell counter-bento__cell--wide counter-bento__cell--accent" href="<?php echo esc_url( $href( 'counter-studio-pay' ) ); ?>">
					<div class="counter-bento__cell-head">
						<h2>Payments</h2>
						<span class="counter-bento__action">Configure →</span>
					</div>
					<div class="counter-bento__pay-grid">
						<div>
							<div class="counter-bento__stat-label">Visible</div>
							<div class="counter-bento__stat-value counter-bento__stat-value--md"><?php echo $payments_visible_count; ?></div>
							<div class="counter-bento__stat-sub">at checkout</div>
						</div>
						<div>
							<div class="counter-bento__stat-label">Cadence</div>
							<div class="counter-bento__stat-value counter-bento__stat-value--md"><?php echo esc_html( ucfirst( $payments_cadence ) ); ?></div>
							<div class="counter-bento__stat-sub">payout schedule</div>
						</div>
					</div>
				</a>

				<!-- Row 3 right: Categories — 1×1 -->
				<a class="counter-bento__cell" href="<?php echo esc_url( $href( 'counter-categories' ) ); ?>">
					<div class="counter-bento__stat-label">Categories</div>
					<div class="counter-bento__stat-value"><?php echo number_format( $stats['categories'] ); ?></div>
					<div class="counter-bento__stat-sub">taxonomy terms</div>
				</a>

				<!-- Row 3 right: Import / Export — 1×1 -->
				<a class="counter-bento__cell counter-bento__cell--quiet" href="<?php echo esc_url( $href( 'counter-import' ) ); ?>">
					<div class="counter-bento__quick-label">Import / Export</div>
					<div class="counter-bento__quick-hint">CSV in + out</div>
				</a>

				<!-- Row 4: full-width quick links — 4×1 -->
				<div class="counter-bento__cell counter-bento__cell--full">
					<div class="counter-bento__cell-head">
						<h2>Jump to</h2>
					</div>
					<div class="counter-bento__jumps">
						<?php
						$jumps = [
							[ 'counter-products',         'Products' ],
							[ 'counter-orders',           'Orders' ],
							[ 'counter-customers',        'Customers' ],
							[ 'counter-categories',       'Categories' ],
							[ 'counter-import',           'Import / Export' ],
							[ 'counter-updates',          'Updates' ],
							[ 'counter-categories-order', 'Category order' ],
							[ 'counter-variants-order',   'Variant order' ],
							[ 'counter-taxonomies',       'Taxonomy order' ],
							[ 'counter-studio-pay',       'Payments' ],
							[ 'counter-settings',         'Settings' ],
						];
						foreach ( $jumps as [ $slug, $label ] ):
							?>
							<a class="counter-bento__chip" href="<?php echo esc_url( $href( $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</div>
				</div>

			</div>

		</div>

		<style>
		.counter-dash { max-width: 1320px; }

		.counter-bento {
			display: grid;
			grid-template-columns: repeat( 4, minmax( 0, 1fr ) );
			grid-auto-rows: minmax( 130px, auto );
			gap: 14px;
		}

		.counter-bento__cell {
			background: #fff;
			border: 1px solid var(--counter-bd);
			border-radius: 14px;
			padding: 18px 20px;
			text-decoration: none;
			color: inherit;
			display: flex; flex-direction: column;
			min-width: 0;
			transition: border-color 0.15s, transform 0.15s, box-shadow 0.15s;
		}
		a.counter-bento__cell:hover {
			border-color: var(--counter-ac);
			transform: translateY(-1px);
			box-shadow: 0 2px 8px rgba(0,0,0,0.04);
		}

		/* Cell sizes */
		.counter-bento__cell--stat   { /* default 1×1 */ }
		.counter-bento__cell--wide   { grid-column: span 2; }
		.counter-bento__cell--hero   { grid-column: span 2; grid-row: span 2; }
		.counter-bento__cell--full   { grid-column: 1 / -1; }
		.counter-bento__cell--quiet  { background: var(--counter-sf-2); }
		.counter-bento__cell--accent {
			background: linear-gradient( 135deg, rgba(232,59,59,0.04), rgba(232,59,59,0.01) );
			border-color: rgba(232,59,59,0.18);
		}

		.counter-bento__cell-head {
			display: flex; align-items: center; justify-content: space-between;
			gap: 12px; margin-bottom: 12px;
		}
		.counter-bento__cell-head h2 {
			margin: 0; font: 700 15px var(--counter-f, sans-serif); color: #1d2327;
		}
		.counter-bento__action {
			font-size: 12px; color: var(--counter-ac); text-decoration: none;
			font-weight: 600; white-space: nowrap;
		}

		.counter-bento__stat-label {
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.06em;
			color: var(--counter-tx-3);
			margin-bottom: 6px;
		}
		.counter-bento__stat-value {
			font-size: 28px; font-weight: 800; letter-spacing: -0.02em;
			color: #1d2327; font-variant-numeric: tabular-nums;
			margin-bottom: 4px;
		}
		.counter-bento__stat-value--md { font-size: 22px; }
		.counter-bento__stat-sub { font-size: 12px; color: var(--counter-tx-3); }

		.counter-bento__quick-label { font-weight: 700; font-size: 14px; color: #1d2327; }
		.counter-bento__quick-hint  { font-size: 12px; color: var(--counter-tx-3); margin-top: 4px; }

		.counter-bento__empty {
			color: var(--counter-tx-3); font-size: 13px; margin: 6px 0;
		}

		.counter-bento__table { width: 100%; border-collapse: collapse; font-size: 13px; }
		.counter-bento__table th {
			text-align: left; padding: 8px 10px;
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.06em;
			color: var(--counter-tx-3);
			border-bottom: 1px solid var(--counter-bd);
		}
		.counter-bento__table td { padding: 10px; border-bottom: 1px solid var(--counter-bd); }
		.counter-bento__table tr:last-child td { border-bottom: 0; }
		.counter-bento__table a { color: var(--counter-ac); text-decoration: none; font-weight: 600; }
		.counter-bento__table a:hover { color: var(--counter-ac-h); }

		.counter-bento__badge {
			display: inline-block; padding: 3px 9px; border-radius: 999px;
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.04em;
			background: var(--counter-sf-2); color: var(--counter-tx-3);
		}
		.counter-bento__badge--completed,
		.counter-bento__badge--paid       { background: rgba(16,185,129,0.10); color: #065f46; }
		.counter-bento__badge--processing { background: rgba(59,130,246,0.10); color: #1e3a8a; }
		.counter-bento__badge--pending,
		.counter-bento__badge--on-hold    { background: rgba(245,158,11,0.10); color: #92400e; }
		.counter-bento__badge--cancelled,
		.counter-bento__badge--failed,
		.counter-bento__badge--refunded   { background: rgba(232,59,59,0.10); color: var(--counter-ac); }

		.counter-bento__pay-grid {
			display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
			flex: 1; align-content: center;
		}

		.counter-bento__jumps {
			display: flex; flex-wrap: wrap; gap: 8px;
		}
		.counter-bento__chip {
			display: inline-block;
			padding: 8px 14px;
			background: var(--counter-sf-2);
			border: 1px solid var(--counter-bd);
			border-radius: 999px;
			font-size: 13px; font-weight: 500;
			color: #1d2327; text-decoration: none;
			transition: background 0.15s, border-color 0.15s;
		}
		.counter-bento__chip:hover {
			background: var(--counter-ac-s);
			border-color: var(--counter-ac);
			color: var(--counter-ac);
		}

		/* Responsive collapse */
		@media ( max-width: 1024px ) {
			.counter-bento { grid-template-columns: repeat( 2, minmax( 0, 1fr ) ); }
			.counter-bento__cell--wide,
			.counter-bento__cell--hero  { grid-column: span 2; }
			.counter-bento__cell--hero  { grid-row: auto; }
			.counter-bento__cell--full  { grid-column: 1 / -1; }
		}
		@media ( max-width: 600px ) {
			.counter-bento { grid-template-columns: 1fr; }
			.counter-bento__cell--wide,
			.counter-bento__cell--hero,
			.counter-bento__cell--full { grid-column: 1 / -1; }
		}
		</style>
		<?php
	}
}
