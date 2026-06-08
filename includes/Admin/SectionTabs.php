<?php
/**
 * Counter by Therum — section-level tab strip.
 *
 * Mirrors the sidebar grouping (Commerce / Manage Data / Organization /
 * Integrations / Settings) as horizontal tabs at the top of each page.
 * The active page's section determines which set of tabs to render.
 *
 * Pages call `SectionTabs::render( $current_slug );` once, right under
 * the `<h1>` of the wrap. The active tab is auto-detected from the slug.
 *
 * Single source of truth for the structure: change the array here and
 * every page picks it up.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SectionTabs {

	/**
	 * Section → list of [ slug, label ] tabs.
	 * Slug matches the admin page's `?page=` value.
	 */
	private const SECTIONS = [
		'commerce' => [
			'label' => 'Commerce',
			'tabs'  => [
				[ 'counter-products',   'Products' ],
				[ 'counter-orders',     'Orders' ],
				[ 'counter-customers',  'Customers' ],
				[ 'counter-categories', 'Categories' ],
			],
		],
		'manage' => [
			'label' => 'Manage Data',
			'tabs'  => [
				[ 'counter-import',  'Import / Export' ],
				[ 'counter-updates', 'Updates' ],
			],
		],
		'organization' => [
			'label' => 'Organization',
			'tabs'  => [
				[ 'counter-categories-order', 'Category Order' ],
				[ 'counter-variants-order',   'Variant Order' ],
				[ 'counter-taxonomies',       'Taxonomy Order' ],
			],
		],
		'payments' => [
			'label' => 'Payments',
			'tabs'  => [
				[ 'counter-studio-pay', 'Payments' ],
			],
		],
		'settings' => [
			'label' => 'Settings',
			'tabs'  => [
				[ 'counter-settings', 'Settings' ],
			],
		],
	];

	/** Slug → section key. Built once per request. */
	private static array $slug_to_section = [];

	/** Lazy-build the reverse index. */
	private static function index(): array {
		if ( self::$slug_to_section ) return self::$slug_to_section;
		foreach ( self::SECTIONS as $section_key => $section ) {
			foreach ( $section['tabs'] as [ $slug, ] ) {
				self::$slug_to_section[ $slug ] = $section_key;
			}
		}
		return self::$slug_to_section;
	}

	/**
	 * Render the tab strip for the section containing $current_slug.
	 * Echoes directly so it can drop into a render() flow.
	 */
	public static function render( string $current_slug ): void {
		$index = self::index();
		$section_key = $index[ $current_slug ] ?? null;
		if ( $section_key === null ) return;

		$section = self::SECTIONS[ $section_key ];
		?>
		<nav class="counter-admin__tabs" aria-label="<?php echo esc_attr( $section['label'] ); ?>">
			<?php foreach ( $section['tabs'] as [ $slug, $label ] ):
				$is_active = $slug === $current_slug;
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
