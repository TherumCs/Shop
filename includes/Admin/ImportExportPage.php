<?php
/**
 * Counter by Therum — unified Import / Export page.
 *
 * Replaces the two separate menu entries (Import + Order I/O) with one
 * page that has internal sub-tabs:
 *
 *   ?tab=products   — catalog import wizard (ImporterPage)
 *   ?tab=orders     — order import/export (OrderIoPage)
 *   ?tab=customers  — customer CSV import/export (CustomersPage with
 *                     its built-in `tab=io` view)
 *
 * Each sub-tab delegates rendering to the existing page class — no
 * content duplication. The wrapper only owns the header, section nav,
 * and sub-tab strip.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImportExportPage {

	public function __construct(
		private readonly ImporterPage  $importer,
		private readonly OrderIoPage   $orders,
		private readonly CustomersPage $customers,
	) {}

	public function render(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'products';
		if ( ! in_array( $tab, [ 'products', 'orders', 'customers' ], true ) ) $tab = 'products';

		$page  = 'counter-import';
		$href  = fn( string $t ) => add_query_arg( [ 'page' => $page, 'tab' => $t ], admin_url( 'admin.php' ) );
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Import / Export', 'counter' ); ?>
			</h1>
			<?php SectionTabs::render( 'counter-import' ); ?>

			<nav class="counter-admin__tabs counter-admin__tabs--sub">
				<a class="counter-admin__tab <?php echo $tab === 'products'  ? 'is-active' : ''; ?>" href="<?php echo esc_url( $href( 'products' ) ); ?>"><?php esc_html_e( 'Products', 'counter' ); ?></a>
				<a class="counter-admin__tab <?php echo $tab === 'orders'    ? 'is-active' : ''; ?>" href="<?php echo esc_url( $href( 'orders' ) ); ?>"><?php esc_html_e( 'Orders', 'counter' ); ?></a>
				<a class="counter-admin__tab <?php echo $tab === 'customers' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $href( 'customers' ) ); ?>"><?php esc_html_e( 'Customers', 'counter' ); ?></a>
			</nav>

			<?php
			// Route to the inner body of the source page so the wrapper's
			// chrome (title + section tabs + sub-tabs) isn't duplicated.
			match ( $tab ) {
				'products'  => $this->importer->renderBody(),
				'orders'    => $this->orders->renderBody(),
				'customers' => $this->customers->renderBody( 'io' ),
			};
			?>
		</div>
		<?php
	}
}
