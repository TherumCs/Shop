<?php
/**
 * Shop — Product Grid element.
 *
 * Renders a grid of product cards. Each card links to /product/{slug}/.
 * Used on the /shop/ archive template and anywhere a designer wants
 * a curated grid (related products, featured set).
 *
 * Filter options: status (default active), pod_provider (vendor),
 * explicit ids list, sort order, max items.
 *
 * Each card optionally shows: thumbnail, title, price, vendor badge,
 * compare-at strikethrough, stock badge, quick add-to-cart.
 */

namespace Shop\Elements\Catalog;

use Shop\DB;
use Shop\Elements\ControlBuilder;
use Shop\Elements\Element;
use Shop\Elements\ElementContext;
use Shop\Mode;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductGrid implements Element {

	public function __construct( private readonly ProductRepository $products ) {}

	public function id(): string       { return 'product-grid'; }
	public function name(): string     { return 'Product grid'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'grid'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Source' )
				->select( 'sort', 'Sort by', [
					'newest'        => 'Newest',
					'oldest'        => 'Oldest',
					'price_asc'     => 'Price: low → high',
					'price_desc'    => 'Price: high → low',
					'title_asc'     => 'Title A → Z',
				], 'newest' )
				->number( 'limit', 'Max items', 12, [ 'min' => 1, 'max' => 100 ] )
				->text( 'pod_provider', 'Filter by vendor slug (optional)', '' )
				->text( 'ids', 'Explicit product IDs (comma-separated, optional)', '' )
			->group( 'Layout' )
				->select( 'columns', 'Columns (desktop)', [
					'2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6',
				], '4' )
				->select( 'aspect', 'Card image aspect', [
					'1/1'  => 'Square',
					'4/5'  => 'Portrait 4:5',
					'3/4'  => 'Portrait 3:4',
					'16/9' => 'Landscape 16:9',
				], '4/5' )
				->number( 'gap', 'Gap (px)', 16, [ 'min' => 4, 'max' => 80 ] )
			->group( 'Card content' )
				->toggle( 'show_vendor',     'Show vendor badge',          true )
				->toggle( 'show_price',      'Show price',                 true )
				->toggle( 'show_compare',    'Show compare-at strikethrough', true )
				->toggle( 'show_stock',      'Show stock badge',           false )
				->toggle( 'show_quick_add',  'Inline "Add to cart" button',false )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$sort         = (string) ( $settings['sort'] ?? 'newest' );
		$limit        = max( 1, min( 100, (int) ( $settings['limit'] ?? 12 ) ) );
		$pod_provider = trim( (string) ( $settings['pod_provider'] ?? '' ) );
		$ids_raw      = trim( (string) ( $settings['ids'] ?? '' ) );

		$columns      = (int) ( $settings['columns'] ?? 4 );
		$aspect       = (string) ( $settings['aspect'] ?? '4/5' );
		$gap          = (int) ( $settings['gap'] ?? 16 );

		$show_vendor  = ! empty( $settings['show_vendor'] );
		$show_price   = ! empty( $settings['show_price'] );
		$show_compare = ! empty( $settings['show_compare'] );
		$show_stock   = ! empty( $settings['show_stock'] );
		$show_quick   = ! empty( $settings['show_quick_add'] );

		$products = $this->fetchProducts( $sort, $limit, $pod_provider, $ids_raw );
		if ( ! $products ) {
			return '<div class="shop-el shop-el-grid shop-el-grid--empty"><p>No products yet.</p></div>';
		}

		$style = sprintf(
			'--shop-grid-cols:%d; --shop-grid-aspect:%s; --shop-grid-gap:%dpx;',
			$columns, esc_attr( $aspect ), $gap,
		);

		$out  = '<div class="shop-el shop-el-grid" style="' . $style . '">';
		foreach ( $products as $p ) {
			$image_url = $p->primaryImageId !== null
				? (string) wp_get_attachment_image_url( $p->primaryImageId, 'medium_large' )
				: '';
			$href = $this->productUrl( $p->slug );

			$out .= '<article class="shop-el-grid__card">';

			$out .= '<a class="shop-el-grid__image" href="' . esc_url( $href ) . '" aria-label="' . esc_attr( $p->title ) . '">';
			$out .= $image_url !== ''
				? '<img src="' . esc_url( $image_url ) . '" alt="" loading="lazy" />'
				: '<div class="shop-el-grid__image-placeholder"></div>';
			if ( $show_stock && $p->trackInventory && ( $p->stockQty ?? 0 ) <= 0 ) {
				$out .= '<span class="shop-el-grid__badge shop-el-grid__badge--oos">Sold out</span>';
			}
			$out .= '</a>';

			$out .= '<div class="shop-el-grid__body">';
			$out .= '<a class="shop-el-grid__title" href="' . esc_url( $href ) . '">' . esc_html( $p->title ) . '</a>';

			if ( $show_vendor ) {
				// Vendor badge from primary variant — cheap heuristic
				$badge = $this->vendorBadgeFor( $p->id );
				if ( $badge ) {
					$out .= '<span class="shop-el-grid__vendor">' . esc_html( $badge ) . '</span>';
				}
			}

			if ( $show_price && $p->price !== null ) {
				$out .= '<div class="shop-el-grid__price">';
				$out .= '<span class="shop-el-grid__price-amount">' . esc_html( $p->price->format() ) . '</span>';
				if ( $show_compare && $p->compareAtPrice !== null && $p->compareAtPrice->greaterThan( $p->price ) ) {
					$out .= ' <span class="shop-el-grid__price-compare">' . esc_html( $p->compareAtPrice->format() ) . '</span>';
				}
				$out .= '</div>';
			}

			if ( $show_quick ) {
				$out .= '<button class="shop-el-grid__quick-add" data-shop-add-to-cart-btn data-shop-product-id="' . esc_attr( (string) $p->id ) . '">Add</button>';
			}

			$out .= '</div></article>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * @return \Shop\Models\Product[]
	 */
	private function fetchProducts( string $sort, int $limit, string $pod_provider, string $ids_raw ): array {
		// Explicit ID list short-circuits everything else.
		if ( $ids_raw !== '' ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) ) );
			$out = [];
			foreach ( $ids as $id ) {
				$p = $this->products->findById( $id );
				if ( $p !== null ) $out[] = $p;
			}
			return $out;
		}

		// SQL path for the native catalog — Mode-aware. In Woo mode we
		// fall back to wc_get_products() for the id list.
		if ( Mode::catalogSource() === 'woo' && function_exists( 'wc_get_products' ) ) {
			$order_clause = match ( $sort ) {
				'oldest'     => [ 'orderby' => 'date',  'order' => 'ASC' ],
				'price_asc'  => [ 'orderby' => 'price', 'order' => 'ASC' ],
				'price_desc' => [ 'orderby' => 'price', 'order' => 'DESC' ],
				'title_asc'  => [ 'orderby' => 'title', 'order' => 'ASC' ],
				default      => [ 'orderby' => 'date',  'order' => 'DESC' ],
			};
			$args = array_merge( [
				'status' => 'publish',
				'limit'  => $limit,
				'return' => 'ids',
			], $order_clause );
			$ids = wc_get_products( $args );
			$out = [];
			foreach ( $ids as $id ) {
				$p = $this->products->findById( (int) $id );
				if ( $p !== null ) $out[] = $p;
			}
			return $out;
		}

		// Native (SQLite) path
		$order = match ( $sort ) {
			'oldest'     => 'created_at ASC',
			'price_asc'  => 'price ASC',
			'price_desc' => 'price DESC',
			'title_asc'  => 'title ASC',
			default      => 'created_at DESC',
		};

		$where = [ "status = 'active'" ];
		$bind  = [];
		if ( $pod_provider !== '' ) {
			$where[] = 'is_pod = 1 AND id IN (SELECT product_id FROM product_variants WHERE pod_provider = :pp)';
			$bind[':pp'] = $pod_provider;
		}

		$sql = 'SELECT id FROM products WHERE ' . implode( ' AND ', $where ) . " ORDER BY $order LIMIT " . (int) $limit;
		$stmt = DB::pdo()->prepare( $sql );
		$stmt->execute( $bind );
		$out = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$p = $this->products->findById( (int) $row['id'] );
			if ( $p !== null ) $out[] = $p;
		}
		return $out;
	}

	private function productUrl( string $slug ): string {
		return home_url( '/product/' . $slug . '/' );
	}

	private function vendorBadgeFor( int $productId ): ?string {
		$stmt = DB::pdo()->prepare( "SELECT pod_provider FROM product_variants WHERE product_id = :p AND pod_provider IS NOT NULL LIMIT 1" );
		$stmt->execute( [ ':p' => $productId ] );
		$row = $stmt->fetch();
		return $row && isset( $row['pod_provider'] ) ? ucwords( str_replace( [ '-', '_' ], ' ', (string) $row['pod_provider'] ) ) : null;
	}
}
