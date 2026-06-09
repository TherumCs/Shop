<?php
/**
 * Counter by Therum — global tab strip.
 *
 * One horizontal strip with every Counter page, rendered identically
 * across all pages. The active tab is auto-detected from $current_slug.
 *
 * Pages call `SectionTabs::render( $current_slug );` once, right under
 * the `<h1>` of the wrap. Single source of truth — change the array
 * here and every page picks it up.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SectionTabs {

	/**
	 * Ordered list of [ slug, label ] tabs. Renders left-to-right on
	 * every page. Slug matches the admin page's `?page=` value.
	 */
	private const TABS = [
		[ 'counter-products',         'Products' ],
		[ 'counter-orders',           'Orders' ],
		[ 'counter-customers',        'Customers' ],
		[ 'counter-categories',       'Categories' ],
		[ 'counter-import',           'Import / Export' ],
		[ 'counter-updates',          'Updates' ],
		[ 'counter-categories-order', 'Order' ],
		[ 'counter-studio-pay',       'Payments' ],
		[ 'counter-settings',         'Settings' ],
	];

	/**
	 * Slugs that all live under the "Order" tab — Variants and Taxonomies
	 * are hidden from the sidebar and sit as sub-tabs inside the unified
	 * Order page. Keep the parent highlighted on all three.
	 */
	private const ORDER_ALIASES = [
		'counter-categories-order',
		'counter-variants-order',
		'counter-taxonomies',
	];

	/**
	 * Render the global tab strip. Echoes directly so it can drop into
	 * a render() flow.
	 */
	public static function render( string $current_slug ): void {
		?>
		<nav class="counter-admin__tabs" aria-label="<?php esc_attr_e( 'Counter', 'counter' ); ?>">
			<?php foreach ( self::TABS as [ $slug, $label ] ):
				$is_active = ( $slug === $current_slug )
					|| ( $slug === 'counter-categories-order' && in_array( $current_slug, self::ORDER_ALIASES, true ) );
				$url = admin_url( 'admin.php?page=' . $slug );
				?>
				<a
					class="counter-admin__tab <?php echo $is_active ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( $url ); ?>"
					aria-current="<?php echo $is_active ? 'page' : 'false'; ?>"
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
