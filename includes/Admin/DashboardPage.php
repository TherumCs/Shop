<?php
/**
 * Counter by Therum — admin Dashboard.
 *
 * Landing page for the Counter top-level menu. Two jobs:
 *
 *   1. Surface the at-a-glance store numbers (revenue, orders, stock,
 *      customers, payments health) at the top.
 *   2. Give one-click jumps to every other section in the sidebar —
 *      the dashboard is the hub, the tiles are the spokes.
 *
 * Replaced the WP meta-box widget machinery with a straight layout
 * because the meta-box grid was clipping content at narrow widths and
 * the drag-to-reorder UX wasn't earning its complexity here.
 */

namespace Counter\Admin;

use Counter\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DashboardPage {

	public function register(): void {
		// No-op — kept so AdminMenu's $this->dashboard->register() call
		// doesn't need to know we don't use load hooks anymore.
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
		];

		$recent = $pdo->query(
			"SELECT id, number, email, status, grand_total, created_at
			   FROM orders
			  ORDER BY created_at DESC
			  LIMIT 8"
		)->fetchAll();

		$payments_methods_enabled = (array) get_option( 'counter_studio_pay_methods_enabled', [] );
		$payments_visible_count   = count( array_filter( $payments_methods_enabled ) );
		$payments_cadence         = (string) get_option( 'counter_studio_pay_payout_cadence', 'daily' );

		?>
		<div class="wrap counter-admin counter-dash">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Dashboard', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>

			<!-- Stats strip — the 4 numbers that matter on every visit. -->
			<div class="counter-dash__stats">
				<a class="counter-dash__stat" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>">
					<div class="counter-dash__stat-label">Revenue</div>
					<div class="counter-dash__stat-value">$<?php echo number_format( $stats['revenue_cents'] / 100, 2 ); ?></div>
					<div class="counter-dash__stat-sub">all-time net</div>
				</a>
				<a class="counter-dash__stat" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>">
					<div class="counter-dash__stat-label">Orders</div>
					<div class="counter-dash__stat-value"><?php echo number_format( $stats['orders_total'] ); ?></div>
					<div class="counter-dash__stat-sub">
						<?php echo $stats['orders_pending'] > 0
							? esc_html( $stats['orders_pending'] . ' pending' )
							: esc_html__( 'all caught up', 'counter' ); ?>
					</div>
				</a>
				<a class="counter-dash__stat" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-products' ) ); ?>">
					<div class="counter-dash__stat-label">Products</div>
					<div class="counter-dash__stat-value"><?php echo number_format( $stats['products'] ); ?></div>
					<div class="counter-dash__stat-sub">
						<?php echo $stats['low_stock'] > 0
							? esc_html( $stats['low_stock'] . ' low stock' )
							: esc_html__( 'stock healthy', 'counter' ); ?>
					</div>
				</a>
				<a class="counter-dash__stat" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-customers' ) ); ?>">
					<div class="counter-dash__stat-label">Customers</div>
					<div class="counter-dash__stat-value"><?php echo number_format( $stats['customers'] ); ?></div>
					<div class="counter-dash__stat-sub">
						<?php echo $stats['repeat_buyers'] > 0
							? esc_html( $stats['repeat_buyers'] . ' repeat' )
							: esc_html__( 'lifetime', 'counter' ); ?>
					</div>
				</a>
			</div>

			<!-- Two-column body: recent orders left, quick links right. -->
			<div class="counter-dash__body">

				<div class="counter-dash__col counter-dash__col--main">
					<section class="counter-dash__card">
						<header class="counter-dash__card-head">
							<h2>Recent orders</h2>
							<a class="counter-dash__card-action" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>">View all →</a>
						</header>
						<?php if ( ! $recent ): ?>
							<p class="counter-dash__empty">No orders yet. Test the checkout to seed one.</p>
						<?php else: ?>
							<table class="counter-dash__table">
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
											<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>">#<?php echo esc_html( $o['number'] ); ?></a></td>
											<td><?php echo esc_html( $o['email'] ); ?></td>
											<td><span class="counter-dash__badge counter-dash__badge--<?php echo esc_attr( $o['status'] ); ?>"><?php echo esc_html( ucfirst( $o['status'] ) ); ?></span></td>
											<td style="text-align:right;font-family:var(--counter-mono);">$<?php echo number_format( $o['grand_total'] / 100, 2 ); ?></td>
											<td style="text-align:right;color:var(--counter-tx-3);font-family:var(--counter-mono);font-size:12px;"><?php echo esc_html( date( 'M j, Y', (int) $o['created_at'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</section>

					<section class="counter-dash__card">
						<header class="counter-dash__card-head">
							<h2>Payments status</h2>
							<a class="counter-dash__card-action" href="<?php echo esc_url( admin_url( 'admin.php?page=counter-studio-pay' ) ); ?>">Configure →</a>
						</header>
						<div class="counter-dash__pay-row">
							<div>
								<div class="counter-dash__pay-label">Visible at checkout</div>
								<div class="counter-dash__pay-value"><?php echo $payments_visible_count; ?> methods</div>
							</div>
							<div>
								<div class="counter-dash__pay-label">Payout cadence</div>
								<div class="counter-dash__pay-value"><?php echo esc_html( ucfirst( $payments_cadence ) ); ?></div>
							</div>
						</div>
					</section>
				</div>

				<aside class="counter-dash__col counter-dash__col--side">
					<section class="counter-dash__card">
						<header class="counter-dash__card-head"><h2>Jump to</h2></header>
						<nav class="counter-dash__links">
							<?php
							// Mirrors every sidebar item — clicking Counter
							// lands here, this nav is the hub spoke for every
							// other section. Updated when the sidebar updates.
							$links = [
								[ 'counter-products',         'Products',        'Browse the catalog' ],
								[ 'counter-orders',           'Orders',          'Fulfillment + status' ],
								[ 'counter-customers',        'Customers',       'List + lifetime stats' ],
								[ 'counter-categories',       'Categories',      'Taxonomy management' ],
								[ 'counter-import',           'Import / Export', 'CSV in + out' ],
								[ 'counter-updates',          'Updates',         'Plugin version + release' ],
								[ 'counter-categories-order', 'Category order',  'Sort term display' ],
								[ 'counter-variants-order',   'Variant order',   'Sort variant display' ],
								[ 'counter-taxonomies',       'Taxonomy order',  'Custom taxonomy sort' ],
								[ 'counter-studio-pay',       'Payments',        'Methods + providers' ],
								[ 'counter-settings',         'Settings',        'Cart + checkout + keys' ],
							];
							foreach ( $links as [ $slug, $label, $hint ] ):
								?>
								<a class="counter-dash__link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
									<span class="counter-dash__link-label"><?php echo esc_html( $label ); ?></span>
									<span class="counter-dash__link-hint"><?php echo esc_html( $hint ); ?></span>
								</a>
							<?php endforeach; ?>
						</nav>
					</section>
				</aside>

			</div>

		</div>

		<style>
		.counter-dash { max-width: 1280px; }

		/* Stats row — 4 equal tiles, wraps to 2x2 on small screens. */
		.counter-dash__stats {
			display: grid;
			grid-template-columns: repeat( auto-fit, minmax( 180px, 1fr ) );
			gap: 12px;
			margin: 0 0 20px;
		}
		.counter-dash__stat {
			display: block;
			background: #fff;
			border: 1px solid var(--counter-bd);
			border-radius: 10px;
			padding: 18px 20px;
			text-decoration: none;
			color: inherit;
			transition: border-color 0.15s, transform 0.15s;
		}
		.counter-dash__stat:hover { border-color: var(--counter-ac); transform: translateY(-1px); }
		.counter-dash__stat-label {
			font: 600 11px var(--counter-mono);
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: var(--counter-tx-3);
			margin-bottom: 6px;
		}
		.counter-dash__stat-value {
			font-size: 26px; font-weight: 800; letter-spacing: -0.02em;
			color: #1d2327; font-variant-numeric: tabular-nums;
			margin-bottom: 4px;
		}
		.counter-dash__stat-sub  { font-size: 12px; color: var(--counter-tx-3); }

		/* Body — 2/3 + 1/3 split, collapses on narrow widths. */
		.counter-dash__body {
			display: grid;
			grid-template-columns: minmax( 0, 2fr ) minmax( 240px, 1fr );
			gap: 16px;
		}
		@media ( max-width: 960px ) { .counter-dash__body { grid-template-columns: 1fr; } }
		.counter-dash__col          { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
		.counter-dash__card {
			background: #fff;
			border: 1px solid var(--counter-bd);
			border-radius: 10px;
			padding: 20px 24px;
		}
		.counter-dash__card-head {
			display: flex; align-items: center; justify-content: space-between;
			gap: 12px; margin-bottom: 14px;
		}
		.counter-dash__card-head h2 { margin: 0; font: 700 15px var(--counter-f, sans-serif); color: #1d2327; }
		.counter-dash__card-action { font-size: 12px; color: var(--counter-ac); text-decoration: none; font-weight: 600; }
		.counter-dash__card-action:hover { color: var(--counter-ac-h); }

		.counter-dash__empty { color: var(--counter-tx-3); font-size: 13px; margin: 8px 0; }

		.counter-dash__table { width: 100%; border-collapse: collapse; font-size: 13px; }
		.counter-dash__table th {
			text-align: left; padding: 8px 10px;
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.06em;
			color: var(--counter-tx-3);
			border-bottom: 1px solid var(--counter-bd);
		}
		.counter-dash__table td { padding: 10px; border-bottom: 1px solid var(--counter-bd); }
		.counter-dash__table tr:last-child td { border-bottom: 0; }
		.counter-dash__table a { color: var(--counter-ac); text-decoration: none; font-weight: 600; }
		.counter-dash__table a:hover { color: var(--counter-ac-h); }

		.counter-dash__badge {
			display: inline-block; padding: 3px 9px;
			border-radius: 999px;
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.04em;
			background: var(--counter-sf-2); color: var(--counter-tx-3);
		}
		.counter-dash__badge--completed,
		.counter-dash__badge--paid       { background: rgba(16,185,129,0.10); color: #065f46; }
		.counter-dash__badge--processing { background: rgba(59,130,246,0.10); color: #1e3a8a; }
		.counter-dash__badge--pending,
		.counter-dash__badge--on-hold    { background: rgba(245,158,11,0.10); color: #92400e; }
		.counter-dash__badge--cancelled,
		.counter-dash__badge--failed,
		.counter-dash__badge--refunded   { background: rgba(232,59,59,0.10); color: var(--counter-ac); }

		.counter-dash__pay-row {
			display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
		}
		.counter-dash__pay-label {
			font: 600 11px var(--counter-mono);
			text-transform: uppercase; letter-spacing: 0.06em;
			color: var(--counter-tx-3);
			margin-bottom: 4px;
		}
		.counter-dash__pay-value { font-size: 18px; font-weight: 700; color: #1d2327; }

		/* Quick links — one row per sidebar item, always visible, no cutoff. */
		.counter-dash__links { display: flex; flex-direction: column; gap: 2px; }
		.counter-dash__link  {
			display: flex; flex-direction: column;
			padding: 10px 12px;
			border-radius: 6px;
			text-decoration: none; color: #1d2327;
			border: 1px solid transparent;
			transition: background 0.15s, border-color 0.15s;
		}
		.counter-dash__link:hover {
			background: var(--counter-sf-2);
			border-color: var(--counter-bd);
		}
		.counter-dash__link-label { font-weight: 600; font-size: 13px; }
		.counter-dash__link-hint  { font-size: 11px; color: var(--counter-tx-3); margin-top: 2px; }
		</style>
		<?php
	}
}
