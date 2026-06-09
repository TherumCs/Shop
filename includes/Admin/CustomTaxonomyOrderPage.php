<?php
/**
 * Counter by Therum — Custom taxonomy ordering page.
 *
 * Unified page for ordering custom taxonomies (vendors, collections, etc).
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomTaxonomyOrderPage extends TaxonomyOrderPage {

	private string $activeTaxonomy = 'vendors';

	public function render(): void {
		// Allow selecting which taxonomy to manage
		if ( isset( $_GET['taxonomy'] ) ) {
			$this->activeTaxonomy = sanitize_text_field( $_GET['taxonomy'] );
		}

		if ( ! in_array( $this->activeTaxonomy, [ 'vendors', 'collections' ], true ) ) {
			$this->activeTaxonomy = 'vendors';
		}

		// Render parent with Therum design, then add tabs overlay
		$taxonomy = $this->getTaxonomy();
		$terms = $this->getTerms();
		$ordering = $this->orders->getTree( $taxonomy );

		// Build a map for quick lookups
		$orderMap = [];
		$termMap = [];
		foreach ( $ordering as $order ) {
			$orderMap[ $order->termId ] = $order;
		}
		foreach ( $terms as $term ) {
			$termMap[ $term['id'] ] = $term;
		}

		// Build hierarchical tree
		$tree = $this->buildTree( $terms, $orderMap );

		?>
		<div class="wrap counter-admin">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Order', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>
			<?php SectionTabs::render( 'counter-taxonomies' ); ?>
			<?php self::renderOrderSubTabs(); ?>

			<p class="counter-admin__description"><?php echo esc_html( $this->getDescription() ); ?></p>

			<div class="counter-taxonomy-order__controls">
				<button type="button" class="button button-primary" id="counter-save-order">
					<?php esc_html_e( 'Save Order', 'counter' ); ?>
				</button>
				<span class="spinner" id="counter-order-spinner"></span>
				<span id="counter-order-message" class="counter-order-message"></span>
			</div>

			<div class="counter-taxonomy-order__tree">
				<ul id="counter-taxonomy-tree" class="counter-taxonomy-tree sortable-list">
					<?php $this->renderTree( $tree, $orderMap, $termMap ); ?>
				</ul>
			</div>
		</div>

		<script>
		window.CounterTaxonomyOrderConfig = {
			taxonomy: <?php echo wp_json_encode( $taxonomy ); ?>,
			restUrl: <?php echo wp_json_encode( rest_url() ); ?>,
			nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
		};
		</script>
		<?php
	}

	protected function getTaxonomy(): string {
		return $this->activeTaxonomy;
	}

	protected function getPageTitle(): string {
		return __( 'Custom Taxonomy Order', 'counter' );
	}

	protected function getDescription(): string {
		$name = $this->activeTaxonomy === 'vendors' ? __( 'Vendors', 'counter' ) : __( 'Collections', 'counter' );
		return sprintf(
			__( 'Drag to reorder %s. The order is used when displaying these options in product filters and navigation.', 'counter' ),
			$name
		);
	}

	protected function getTerms(): array {
		// MVP: Show all attributes as reorderable items across all custom taxonomy tabs
		// Vendors and Collections are managed separately via extensions/filters
		try {
			$pdo = \Counter\DB::pdo();
			$stmt = $pdo->prepare(
				"SELECT id, name FROM attributes ORDER BY name"
			);
			$stmt->execute();

			$terms = [];
			foreach ( $stmt->fetchAll() as $row ) {
				$terms[] = [
					'id'   => (int) $row['id'],
					'name' => (string) $row['name'],
				];
			}
			return $terms;
		} catch ( \Throwable $e ) {
			return [];
		}
	}
}
