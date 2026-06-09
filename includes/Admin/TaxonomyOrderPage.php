<?php
/**
 * Counter by Therum — Taxonomy ordering admin page.
 *
 * Base class for drag-drop term reordering pages. Subclassed for
 * categories, variants, custom taxonomies.
 */

namespace Counter\Admin;

use Counter\Repositories\TaxonomyOrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class TaxonomyOrderPage {

	public function __construct(
		protected readonly TaxonomyOrderRepository $orders,
	) {}

	/**
	 * Return the taxonomy slug (e.g., 'product_categories', 'vendors').
	 */
	abstract protected function getTaxonomy(): string;

	/**
	 * Return the page title (e.g., 'Product Categories').
	 */
	abstract protected function getPageTitle(): string;

	/**
	 * Return the description shown at the top of the page.
	 */
	abstract protected function getDescription(): string;

	/**
	 * Fetch all available terms for this taxonomy.
	 * Should return array of [ 'id' => int, 'name' => string, ...extra fields ].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract protected function getTerms(): array;

	/**
	 * Shared sub-tab strip across the three Order pages. Categories /
	 * Variants live on their own slugs; Vendors and Collections are two
	 * modes of the custom-taxonomy page (?taxonomy=…).
	 */
	protected static function renderOrderSubTabs(): void {
		$current_page     = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		$current_taxonomy = sanitize_key( (string) ( $_GET['taxonomy'] ?? '' ) );
		$tabs = [
			[ 'Categories',  'counter-categories-order', '' ],
			[ 'Variants',    'counter-variants-order',   '' ],
			[ 'Vendors',     'counter-taxonomies',       'vendors' ],
			[ 'Collections', 'counter-taxonomies',       'collections' ],
		];
		?>
		<nav class="counter-admin__tabs counter-admin__tabs--sub" aria-label="<?php esc_attr_e( 'Order', 'counter' ); ?>">
			<?php foreach ( $tabs as [ $label, $slug, $taxonomy ] ):
				$is_active = ( $slug === $current_page )
					&& ( $taxonomy === '' || $taxonomy === $current_taxonomy
						|| ( $taxonomy === 'vendors' && $current_taxonomy === '' ) );
				$args = [ 'page' => $slug ];
				if ( $taxonomy !== '' ) $args['taxonomy'] = $taxonomy;
				$url = add_query_arg( $args, admin_url( 'admin.php' ) );
				?>
				<a
					class="counter-admin__tab <?php echo $is_active ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( $url ); ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render the admin page.
	 */
	public function render(): void {
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
			<?php SectionTabs::render( sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ); ?>
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

	/**
	 * Build hierarchical tree from flat terms and ordering.
	 *
	 * @param array<int, array<string, mixed>> $terms
	 * @param array<int, \Counter\Models\TaxonomyOrder> $orderMap
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildTree( array $terms, array $orderMap ): array {
		$byParent = [ null => [] ];

		// First pass: organize by parent
		foreach ( $terms as $term ) {
			$order = $orderMap[ $term['id'] ] ?? null;
			$parentId = $order?->parentId;

			if ( ! isset( $byParent[ $parentId ] ) ) {
				$byParent[ $parentId ] = [];
			}

			$byParent[ $parentId ][] = [
				'term'  => $term,
				'order' => $order,
			];
		}

		// Sort each parent's children by position
		foreach ( $byParent as &$group ) {
			usort( $group, fn( $a, $b ) =>
				( $a['order']?->position ?? 0 ) <=> ( $b['order']?->position ?? 0 )
			);
		}

		return $byParent[ null ] ?? [];
	}

	/**
	 * Recursively render the tree as nested lists.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, \Counter\Models\TaxonomyOrder> $orderMap
	 * @param array<int, array<string, mixed>> $termMap
	 */
	protected function renderTree( array $items, array $orderMap, array $termMap, int $depth = 0 ): void {
		foreach ( $items as $item ) {
			$term = $item['term'];
			$order = $item['order'];
			$termId = (int) $term['id'];

			?>
			<li class="counter-taxonomy-item" data-term-id="<?php echo esc_attr( (string) $termId ); ?>">
				<div class="counter-taxonomy-item__drag-handle">
					<span class="dashicons dashicons-menu"></span>
				</div>
				<div class="counter-taxonomy-item__info">
					<?php echo esc_html( $term['name'] ?? 'Untitled' ); ?>
					<span class="counter-taxonomy-item__id">#<?php echo esc_html( (string) $termId ); ?></span>
				</div>

				<?php
				// Render children if any
				$children = [];
				foreach ( $orderMap as $o ) {
					if ( $o->parentId === $termId && isset( $termMap[ $o->termId ] ) ) {
						$children[] = [
							'term'  => $termMap[ $o->termId ],
							'order' => $o,
						];
					}
				}

				if ( $children ) {
					// Sort children by position
					usort( $children, fn( $a, $b ) =>
						( $a['order']?->position ?? 0 ) <=> ( $b['order']?->position ?? 0 )
					);

					?>
					<ul class="counter-taxonomy-item__children">
						<?php $this->renderTree( $children, $orderMap, $termMap, $depth + 1 ); ?>
					</ul>
					<?php
				}
				?>
			</li>
			<?php
		}
	}
}
