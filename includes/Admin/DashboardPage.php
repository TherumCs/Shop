<?php
/**
 * Counter by Therum — admin Dashboard.
 *
 * Landing page for the Counter top-level menu. Built on WordPress's
 * meta-box system (the same machinery that powers wp-admin/index.php)
 * so widgets can be dragged, collapsed, and toggled via Screen Options
 * — same logic as the main Therum OS dashboard.
 *
 * Widgets are registered once on the dashboard screen. Each is a small
 * PHP method that reads from the SQLite database and renders. Data flows
 * one-way (DB → render); user changes the layout, not the data.
 */

namespace Counter\Admin;

use Counter\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DashboardPage {

	/** Screen ID we register meta boxes against. */
	private const SCREEN = 'counter_page_counter';

	public function register(): void {
		add_action( 'load-toplevel_page_counter', [ $this, 'onLoad' ] );
	}

	/**
	 * Wire up meta-box machinery for the dashboard screen.
	 * Runs only when the user is actually on the dashboard.
	 */
	public function onLoad(): void {
		// WP's draggable/collapsible widget JS.
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );

		add_meta_box( 'counter-revenue',  __( 'Revenue', 'counter' ),         [ $this, 'widgetRevenue' ],  self::SCREEN, 'normal', 'high' );
		add_meta_box( 'counter-orders',   __( 'Orders snapshot', 'counter' ), [ $this, 'widgetOrders' ],   self::SCREEN, 'normal', 'high' );
		add_meta_box( 'counter-recent',   __( 'Recent orders', 'counter' ),   [ $this, 'widgetRecent' ],   self::SCREEN, 'normal', 'core' );

		add_meta_box( 'counter-products', __( 'Catalog', 'counter' ),         [ $this, 'widgetProducts' ], self::SCREEN, 'side',   'high' );
		add_meta_box( 'counter-customers',__( 'Customers', 'counter' ),       [ $this, 'widgetCustomers'], self::SCREEN, 'side',   'high' );
		add_meta_box( 'counter-actions',  __( 'Quick actions', 'counter' ),   [ $this, 'widgetActions' ],  self::SCREEN, 'side',   'core' );
	}

	public function render(): void {
		// Fire screen setup so add_meta_box() / current_screen() are wired
		// even on screens WP didn't auto-load (e.g. when a parent caller
		// missed the load- hook).
		do_action( 'add_meta_boxes_' . self::SCREEN, null );
		do_action( 'add_meta_boxes', self::SCREEN, null );

		$screen = get_current_screen();
		?>
		<div class="wrap counter-admin counter-dash">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Dashboard', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>

			<form name="counter-dashboard-form" method="post" action="" onsubmit="return false;">
				<?php
				wp_nonce_field( 'closedpostboxes',     'closedpostboxesnonce', false );
				wp_nonce_field( 'meta-box-order',      'meta-box-order-nonce', false );
				?>
				<div id="dashboard-widgets-wrap">
					<div id="dashboard-widgets" class="metabox-holder">
						<div id="postbox-container-1" class="postbox-container">
							<div id="normal-sortables" class="meta-box-sortables ui-sortable">
								<?php do_meta_boxes( self::SCREEN, 'normal', null ); ?>
							</div>
						</div>
						<div id="postbox-container-2" class="postbox-container">
							<div id="side-sortables" class="meta-box-sortables ui-sortable">
								<?php do_meta_boxes( self::SCREEN, 'side', null ); ?>
							</div>
						</div>
					</div>
					<div class="clear"></div>
				</div>
			</form>

		</div>

		<?php // Boot WP's postbox JS so widgets are draggable + collapsible. ?>
		<script>
		jQuery( function ( $ ) {
			if ( window.postboxes && typeof postboxes.add_postbox_toggles === 'function' ) {
				postboxes.add_postbox_toggles( <?php echo wp_json_encode( self::SCREEN ); ?>, {
					pbshow: function () {},
					pbhide: function () {}
				} );
			}
		} );
		</script>

		<style>
		.counter-dash #dashboard-widgets-wrap { max-width: 1180px; margin: 0 auto; padding: 0 4px; }
		.counter-dash .counter-admin__title  { max-width: 1180px; margin-left: auto; margin-right: auto; }
		.counter-dash #dashboard-widgets { display: flex; gap: 20px; }
		.counter-dash #postbox-container-1 { flex: 2 1 0; min-width: 0; width: auto; }
		.counter-dash #postbox-container-2 { flex: 1 1 0; min-width: 0; width: auto; }
		.counter-dash .postbox {
			border: 1px solid var(--counter-bd);
			border-radius: 10px;
			background: #fff;
			margin-bottom: 16px;
			box-shadow: none;
		}
		.counter-dash .postbox > .postbox-header {
			border-bottom: 1px solid var(--counter-bd);
			padding: 0 8px;
			cursor: move; /* signal draggability via the header */
		}
		.counter-dash .postbox.ui-sortable-helper { opacity: 0.85; }
		.counter-dash .meta-box-sortables .ui-sortable-placeholder {
			border: 2px dashed var(--counter-ac);
			border-radius: 10px;
			background: var(--counter-ac-s);
			visibility: visible !important;
			margin-bottom: 16px;
		}
		.counter-dash .postbox .hndle { font-size: 14px; font-weight: 700; padding: 14px 16px; border: 0; }
		.counter-dash .postbox .inside { padding: 20px 24px; margin: 0; }
		.counter-dash .postbox.closed .inside { display: none; }
		@media ( max-width: 900px ) {
			.counter-dash #dashboard-widgets { flex-direction: column; }
		}

		/* Widget-internal styles */
		.counter-dash__stat { display: flex; align-items: baseline; gap: 12px; }
		.counter-dash__stat-value { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; color: #1d2327; font-variant-numeric: tabular-nums; }
		.counter-dash__stat-sub   { font: 600 11px var(--counter-mono); text-transform: uppercase; letter-spacing: 0.06em; color: var(--counter-tx-3); }
		.counter-dash__row        { display: flex; justify-content: space-between; align-items: baseline; padding: 8px 0; font-size: 13px; }
		.counter-dash__row + .counter-dash__row { border-top: 1px solid var(--counter-bd); }
		.counter-dash__row span:last-child { font-family: var(--counter-mono); font-variant-numeric: tabular-nums; font-weight: 700; }
		.counter-dash__table { width: 100%; border-collapse: collapse; font-size: 13px; }
		.counter-dash__table th { text-align: left; padding: 8px 10px; font: 600 11px var(--counter-mono); text-transform: uppercase; letter-spacing: 0.06em; color: var(--counter-tx-3); border-bottom: 1px solid var(--counter-bd); }
		.counter-dash__table td { padding: 10px; border-bottom: 1px solid var(--counter-bd); }
		.counter-dash__table tr:last-child td { border-bottom: 0; }
		.counter-dash__table a { color: var(--counter-ac); text-decoration: none; font-weight: 600; }
		.counter-dash__badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font: 600 11px var(--counter-mono); text-transform: uppercase; letter-spacing: 0.04em; background: var(--counter-sf-2); color: var(--counter-tx-3); }
		.counter-dash__badge--completed,
		.counter-dash__badge--paid       { background: rgba(16,185,129,0.10); color: #065f46; }
		.counter-dash__badge--processing { background: rgba(59,130,246,0.10); color: #1e3a8a; }
		.counter-dash__badge--pending,
		.counter-dash__badge--on-hold    { background: rgba(245,158,11,0.10); color: #92400e; }
		.counter-dash__badge--cancelled,
		.counter-dash__badge--failed,
		.counter-dash__badge--refunded   { background: rgba(232,59,59,0.10); color: var(--counter-ac); }
		.counter-dash__actions { display: grid; gap: 8px; }
		.counter-dash__actions a {
			display: flex; flex-direction: column; gap: 2px;
			padding: 12px 14px;
			border: 1px solid var(--counter-bd);
			border-radius: 8px;
			background: #fff;
			text-decoration: none;
			color: #1d2327;
			transition: border-color .15s, transform .15s;
		}
		.counter-dash__actions a:hover { border-color: var(--counter-ac); transform: translateY(-1px); }
		.counter-dash__actions strong { font-size: 13px; }
		.counter-dash__actions span   { font-size: 12px; color: var(--counter-tx-3); }
		</style>
		<?php
	}

	// ─── Widgets ─────────────────────────────────────────────────────────

	public function widgetRevenue(): void {
		$pdo = DB::pdo();
		$cents = (int) $pdo->query(
			"SELECT COALESCE(SUM(grand_total - refunded_total), 0) FROM orders WHERE status IN ('completed','processing','paid')"
		)->fetchColumn();
		$pending_cents = (int) $pdo->query(
			"SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE status IN ('pending','on-hold')"
		)->fetchColumn();
		?>
		<div class="counter-dash__stat">
			<div class="counter-dash__stat-value">$<?php echo number_format( $cents / 100, 2 ); ?></div>
			<div class="counter-dash__stat-sub">All-time net</div>
		</div>
		<div class="counter-dash__row" style="margin-top:16px;">
			<span>Pending payment</span>
			<span>$<?php echo number_format( $pending_cents / 100, 2 ); ?></span>
		</div>
		<?php
	}

	public function widgetOrders(): void {
		$pdo = DB::pdo();
		$rows = $pdo->query(
			"SELECT status, COUNT(*) AS c FROM orders GROUP BY status ORDER BY c DESC"
		)->fetchAll();
		$total = (int) $pdo->query( 'SELECT COUNT(*) FROM orders' )->fetchColumn();
		?>
		<div class="counter-dash__stat">
			<div class="counter-dash__stat-value"><?php echo number_format( $total ); ?></div>
			<div class="counter-dash__stat-sub">Total orders</div>
		</div>
		<div style="margin-top:16px;">
			<?php foreach ( $rows as $r ): ?>
				<div class="counter-dash__row">
					<span><?php echo esc_html( ucfirst( $r['status'] ) ); ?></span>
					<span><?php echo number_format( (int) $r['c'] ); ?></span>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $rows ): ?>
				<p style="color:var(--counter-tx-3);font-size:13px;margin:0;">No orders yet.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function widgetRecent(): void {
		$pdo = DB::pdo();
		$rows = $pdo->query(
			"SELECT id, number, email, status, grand_total, created_at FROM orders ORDER BY created_at DESC LIMIT 6"
		)->fetchAll();

		if ( ! $rows ) {
			echo '<p style="color:var(--counter-tx-3);font-size:13px;margin:0;">No orders yet.</p>';
			return;
		}
		?>
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
				<?php foreach ( $rows as $o ): ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>">#<?php echo esc_html( $o['number'] ); ?></a></td>
						<td><?php echo esc_html( $o['email'] ); ?></td>
						<td><span class="counter-dash__badge counter-dash__badge--<?php echo esc_attr( $o['status'] ); ?>"><?php echo esc_html( ucfirst( $o['status'] ) ); ?></span></td>
						<td style="text-align:right;font-family:var(--counter-mono);">$<?php echo number_format( $o['grand_total'] / 100, 2 ); ?></td>
						<td style="text-align:right;color:var(--counter-tx-3);font-family:var(--counter-mono);font-size:12px;"><?php echo esc_html( date( 'M j, Y', $o['created_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function widgetProducts(): void {
		$pdo = DB::pdo();
		$total = (int) $pdo->query( 'SELECT COUNT(*) FROM products' )->fetchColumn();
		$low   = (int) $pdo->query( "SELECT COUNT(*) FROM products WHERE track_inventory = 1 AND stock_qty IS NOT NULL AND stock_qty < 10" )->fetchColumn();
		?>
		<div class="counter-dash__stat">
			<div class="counter-dash__stat-value"><?php echo number_format( $total ); ?></div>
			<div class="counter-dash__stat-sub">Products</div>
		</div>
		<div class="counter-dash__row" style="margin-top:16px;">
			<span>Low stock</span>
			<span><?php echo number_format( $low ); ?></span>
		</div>
		<?php
	}

	public function widgetCustomers(): void {
		$pdo = DB::pdo();
		$total = (int) $pdo->query( 'SELECT COUNT(*) FROM customers' )->fetchColumn();
		$repeat = (int) $pdo->query( "SELECT COUNT(*) FROM customers WHERE orders_count > 1" )->fetchColumn();
		?>
		<div class="counter-dash__stat">
			<div class="counter-dash__stat-value"><?php echo number_format( $total ); ?></div>
			<div class="counter-dash__stat-sub">Customers</div>
		</div>
		<div class="counter-dash__row" style="margin-top:16px;">
			<span>Repeat buyers</span>
			<span><?php echo number_format( $repeat ); ?></span>
		</div>
		<?php
	}

	public function widgetActions(): void {
		?>
		<div class="counter-dash__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-products' ) ); ?>"><strong>Products</strong><span>Manage catalog</span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-orders' ) ); ?>"><strong>Orders</strong><span>View &amp; fulfill</span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-customers' ) ); ?>"><strong>Customers</strong><span>Member list</span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-import' ) ); ?>"><strong>Import</strong><span>Catalog wizard</span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=counter-settings' ) ); ?>"><strong>Settings</strong><span>Cart &amp; checkout</span></a>
		</div>
		<?php
	}
}
