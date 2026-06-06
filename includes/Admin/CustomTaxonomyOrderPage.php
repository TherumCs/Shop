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

		?>
		<div class="wrap counter-taxonomy-order">
			<h1><?php echo esc_html( $this->getPageTitle() ); ?></h1>

			<div class="counter-taxonomy-order__tabs">
				<a href="?page=counter-taxonomies&taxonomy=vendors"
					class="nav-tab <?php echo $this->activeTaxonomy === 'vendors' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Vendors', 'counter' ); ?>
				</a>
				<a href="?page=counter-taxonomies&taxonomy=collections"
					class="nav-tab <?php echo $this->activeTaxonomy === 'collections' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Collections', 'counter' ); ?>
				</a>
			</div>

			<?php parent::render(); ?>
		</div>
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
		// For MVP, return empty — custom taxonomies created via filters
		// In future: integrate with custom term registration system
		return [];
	}
}
