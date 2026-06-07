<?php
/**
 * Counter by Therum — Comprehensive WooCommerce importer.
 *
 * One-click import of everything from WooCommerce:
 * - Products (with variants, attributes, pricing, images)
 * - Customers
 * - Orders (with items)
 *
 * After import, you can safely delete WooCommerce.
 */

namespace Counter\Services;

use Counter\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ComprehensiveWooImporter {

	public function importEverything(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [
				'success' => false,
				'message' => 'WooCommerce not detected',
			];
		}

		try {
			$pdo = DB::pdo();
			$pdo->beginTransaction();

			$stats = [
				'products'   => 0,
				'variants'   => 0,
				'attributes' => 0,
				'customers'  => 0,
				'orders'     => 0,
				'order_items' => 0,
			];

			// Import in dependency order
			$stats['customers'] = $this->importCustomers( $pdo );
			$stats['attributes'] = $this->importAttributes( $pdo );
			$stats['products'] = $this->importProducts( $pdo );
			$stats['variants'] = $this->importVariants( $pdo );
			$stats['orders'] = $this->importOrders( $pdo );
			$stats['order_items'] = $this->importOrderItems( $pdo );

			$pdo->commit();

			return [
				'success' => true,
				'message' => "Imported {$stats['products']} products, {$stats['customers']} customers, {$stats['orders']} orders",
				'stats'   => $stats,
			];
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	private function importCustomers( \PDO $pdo ): int {
		// Get all WordPress users (they're also customers in WooCommerce)
		$users = get_users( [ 'number' => -1 ] );
		$count = 0;

		foreach ( $users as $user ) {
			// Check if already imported
			$stmt = $pdo->prepare( 'SELECT id FROM customers WHERE wp_user_id = :uid' );
			$stmt->execute( [ ':uid' => $user->ID ] );
			if ( $stmt->fetch() ) continue;

			// Get WooCommerce customer data if available
			$wc_customer = function_exists( 'wc_get_customer' ) ? wc_get_customer( $user->ID ) : null;

			$phone = '';
			$addr1 = '';
			$addr2 = '';
			$city = '';
			$state = '';
			$postal = '';
			$country = '';

			if ( $wc_customer ) {
				$phone = $wc_customer->get_billing_phone() ?: '';
				$addr1 = $wc_customer->get_billing_address_1() ?: '';
				$addr2 = $wc_customer->get_billing_address_2() ?: '';
				$city = $wc_customer->get_billing_city() ?: '';
				$state = $wc_customer->get_billing_state() ?: '';
				$postal = $wc_customer->get_billing_postcode() ?: '';
				$country = $wc_customer->get_billing_country() ?: '';
			}

			// Generate UUID for customer
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::generateUUID();

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO customers (
					uuid, wp_user_id, email, first_name, last_name,
					phone, accepts_marketing,
					address_line1, address_line2, city, state, postal_code, country,
					tags, orders_count, total_spent_cents, last_order_at,
					created_at, updated_at
				) VALUES (
					:uuid, :uid, :email, :first, :last,
					:phone, :marketing,
					:addr1, :addr2, :city, :state, :postal, :country,
					:tags, 0, 0, NULL,
					:created, :updated
				)
			SQL );

			$stmt->execute( [
				':uuid'      => $uuid,
				':uid'       => $user->ID,
				':email'     => $user->user_email,
				':first'     => $user->first_name ?: '',
				':last'      => $user->last_name ?: '',
				':phone'     => $phone,
				':marketing' => ( $wc_customer && $wc_customer->is_paying_customer() ? 1 : 0 ),
				':addr1'     => $addr1,
				':addr2'     => $addr2,
				':city'      => $city,
				':state'     => $state,
				':postal'    => $postal,
				':country'   => $country,
				':tags'      => '[]',
				':created'   => time(),
				':updated'   => time(),
			] );

			$count++;
		}

		return $count;
	}

	private function importAttributes( \PDO $pdo ): int {
		$wc_attrs = wc_get_attribute_taxonomies();
		$count = 0;

		foreach ( $wc_attrs as $attr ) {
			// Check if already imported
			$stmt = $pdo->prepare( 'SELECT id FROM attributes WHERE slug = :slug' );
			$stmt->execute( [ ':slug' => $attr->attribute_name ] );
			if ( $stmt->fetch() ) continue;

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO attributes (slug, name, type, created_at, updated_at)
				VALUES (:slug, :name, :type, :created, :updated)
			SQL );

			$stmt->execute( [
				':slug'    => $attr->attribute_name,
				':name'    => $attr->attribute_label,
				':type'    => $attr->attribute_type ?: 'select',
				':created' => time(),
				':updated' => time(),
			] );

			$attr_id = $pdo->lastInsertId();

			// Import attribute terms/values
			if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
				$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name );
				$terms = get_terms( [
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				] );

				if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						// Check if already imported
						$check = $pdo->prepare( 'SELECT id FROM attribute_values WHERE attribute_id = :attr_id AND value = :value' );
						$check->execute( [ ':attr_id' => $attr_id, ':value' => $term->name ] );
						if ( $check->fetch() ) continue;

						$val_stmt = $pdo->prepare( <<<SQL
							INSERT INTO attribute_values (attribute_id, value, created_at, updated_at)
							VALUES (:attr_id, :value, :created, :updated)
						SQL );

						$val_stmt->execute( [
							':attr_id'  => $attr_id,
							':value'    => $term->name,
							':created'  => time(),
							':updated'  => time(),
						] );
					}
				}
			}

			$count++;
		}

		return $count;
	}

	private function importProducts( \PDO $pdo ): int {
		$wc_products = wc_get_products( [
			'limit'  => -1,
			'type'   => [ 'simple', 'variable' ],
		] );

		$count = 0;

		foreach ( $wc_products as $wc_prod ) {
			// Skip if already imported
			$stmt = $pdo->prepare( 'SELECT id FROM products WHERE woo_id = :woo_id' );
			$stmt->execute( [ ':woo_id' => $wc_prod->get_id() ] );
			if ( $stmt->fetch() ) continue;

			$price_cents = (int) ( $wc_prod->get_price() * 100 );
			$cost_cents = (int) ( $wc_prod->get_cost() * 100 );

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO products (
					woo_id, title, slug, description, sku,
					price_cents, cost_cents, status, type,
					created_at, updated_at
				) VALUES (
					:woo_id, :title, :slug, :desc, :sku,
					:price, :cost, :status, :type,
					:created, :updated
				)
			SQL );

			$stmt->execute( [
				':woo_id'   => $wc_prod->get_id(),
				':title'    => $wc_prod->get_name(),
				':slug'     => $wc_prod->get_slug(),
				':desc'     => $wc_prod->get_description() ?: '',
				':sku'      => $wc_prod->get_sku() ?: '',
				':price'    => $price_cents,
				':cost'     => $cost_cents,
				':status'   => $wc_prod->get_status() === 'publish' ? 'active' : 'draft',
				':type'     => $wc_prod->is_type( 'variable' ) ? 'variable' : 'simple',
				':created'  => time(),
				':updated'  => time(),
			] );

			$count++;
		}

		return $count;
	}

	private function importVariants( \PDO $pdo ): int {
		$wc_products = wc_get_products( [
			'limit' => -1,
			'type'  => 'variable',
		] );

		$count = 0;

		foreach ( $wc_products as $wc_var_prod ) {
			// Get Counter product ID
			$stmt = $pdo->prepare( 'SELECT id FROM products WHERE woo_id = :woo_id' );
			$stmt->execute( [ ':woo_id' => $wc_var_prod->get_id() ] );
			$prod_row = $stmt->fetch();
			if ( ! $prod_row ) continue;

			$prod_id = $prod_row['id'];

			foreach ( $wc_var_prod->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) continue;

				// Check if already imported
				$v_stmt = $pdo->prepare( 'SELECT id FROM product_variants WHERE woo_id = :woo_id' );
				$v_stmt->execute( [ ':woo_id' => $child_id ] );
				if ( $v_stmt->fetch() ) continue;

				$var_price = (int) ( $child->get_price() * 100 );

				$insert = $pdo->prepare( <<<SQL
					INSERT INTO product_variants (
						product_id, woo_id, price_cents, stock_qty, status,
						created_at, updated_at
					) VALUES (
						:prod_id, :woo_id, :price, :stock, :status,
						:created, :updated
					)
				SQL );

				$insert->execute( [
					':prod_id'  => $prod_id,
					':woo_id'   => $child_id,
					':price'    => $var_price,
					':stock'    => max( 0, (int) $child->get_stock_quantity() ),
					':status'   => $child->get_status() === 'publish' ? 'active' : 'draft',
					':created'  => time(),
					':updated'  => time(),
				] );

				$count++;
			}
		}

		return $count;
	}

	private function importOrders( \PDO $pdo ): int {
		$wc_orders = wc_get_orders( [ 'limit' => -1 ] );
		$count = 0;

		$status_map = [
			'pending'    => 'pending',
			'processing' => 'processing',
			'completed'  => 'completed',
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
			'failed'     => 'failed',
		];

		foreach ( $wc_orders as $wc_order ) {
			// Skip if already imported
			$stmt = $pdo->prepare( 'SELECT id FROM orders WHERE woo_id = :woo_id' );
			$stmt->execute( [ ':woo_id' => $wc_order->get_id() ] );
			if ( $stmt->fetch() ) continue;

			$wc_status = $wc_order->get_status();
			$status = $status_map[ $wc_status ] ?? 'processing';

			$subtotal = (int) ( $wc_order->get_subtotal() * 100 );
			$shipping = (int) ( $wc_order->get_shipping_total() * 100 );
			$tax = (int) ( $wc_order->get_total_tax() * 100 );
			$discount = (int) ( $wc_order->get_discount_total() * 100 );
			$total = (int) ( $wc_order->get_total() * 100 );
			$refunded = (int) ( $wc_order->get_total_refunded() * 100 );

			$paid_at = null;
			if ( $wc_order->is_paid() ) {
				$paid_date = $wc_order->get_date_paid();
				$paid_at = $paid_date ? $paid_date->getTimestamp() : time();
			}

			$bill_addr = [
				'name'    => trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ),
				'line1'   => $wc_order->get_billing_address_1(),
				'line2'   => $wc_order->get_billing_address_2(),
				'city'    => $wc_order->get_billing_city(),
				'state'   => $wc_order->get_billing_state(),
				'postal'  => $wc_order->get_billing_postcode(),
				'country' => $wc_order->get_billing_country(),
			];

			$ship_addr = [
				'name'    => trim( $wc_order->get_shipping_first_name() . ' ' . $wc_order->get_shipping_last_name() ),
				'line1'   => $wc_order->get_shipping_address_1(),
				'line2'   => $wc_order->get_shipping_address_2(),
				'city'    => $wc_order->get_shipping_city(),
				'state'   => $wc_order->get_shipping_state(),
				'postal'  => $wc_order->get_shipping_postcode(),
				'country' => $wc_order->get_shipping_country(),
			];

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO orders (
					woo_id, number, user_id, email, currency, status,
					subtotal, shipping_total, tax_total, discount_total, grand_total, refunded_total,
					bill_address, ship_address,
					payment_provider, payment_method, paid_at,
					created_at, updated_at
				) VALUES (
					:woo_id, :number, :user_id, :email, :currency, :status,
					:subtotal, :shipping, :tax, :discount, :total, :refunded,
					:bill_addr, :ship_addr,
					:provider, :method, :paid_at,
					:created, :updated
				)
			SQL );

			$stmt->execute( [
				':woo_id'    => $wc_order->get_id(),
				':number'    => (string) $wc_order->get_id(),
				':user_id'   => $wc_order->get_customer_id() ?: null,
				':email'     => $wc_order->get_billing_email(),
				':currency'  => $wc_order->get_currency(),
				':status'    => $status,
				':subtotal'  => $subtotal,
				':shipping'  => $shipping,
				':tax'       => $tax,
				':discount'  => $discount,
				':total'     => $total,
				':refunded'  => $refunded,
				':bill_addr' => wp_json_encode( array_filter( $bill_addr ) ),
				':ship_addr' => wp_json_encode( array_filter( $ship_addr ) ),
				':provider'  => 'woocommerce',
				':method'    => $wc_order->get_payment_method() ?: 'woocommerce',
				':paid_at'   => $paid_at,
				':created'   => time(),
				':updated'   => time(),
			] );

			$count++;
		}

		return $count;
	}

	private function importOrderItems( \PDO $pdo ): int {
		$wc_orders = wc_get_orders( [ 'limit' => -1 ] );
		$count = 0;

		foreach ( $wc_orders as $wc_order ) {
			// Get Counter order ID
			$stmt = $pdo->prepare( 'SELECT id FROM orders WHERE woo_id = :woo_id' );
			$stmt->execute( [ ':woo_id' => $wc_order->get_id() ] );
			$order_row = $stmt->fetch();
			if ( ! $order_row ) continue;

			$counter_order_id = $order_row['id'];

			foreach ( $wc_order->get_items() as $item ) {
				if ( ! ( $item instanceof \WC_Order_Item_Product ) ) continue;

				$product = $item->get_product();
				if ( ! $product ) continue;

				$qty = (int) $item->get_quantity();
				if ( $qty < 1 ) continue; // Skip zero-quantity items

				// Get Counter product ID
				$p_stmt = $pdo->prepare( 'SELECT id FROM products WHERE woo_id = :woo_id' );
				$p_stmt->execute( [ ':woo_id' => $product->get_id() ] );
				$prod_row = $p_stmt->fetch();
				$prod_id = $prod_row ? $prod_row['id'] : null;

				$unit_price = (int) ( ( $item->get_total() / $qty ) * 100 );
				$line_total = (int) ( $item->get_total() * 100 );

				$i_stmt = $pdo->prepare( <<<SQL
					INSERT INTO order_items (
						order_id, product_id, product_title, sku,
						quantity, unit_price, line_total,
						created_at, updated_at
					) VALUES (
						:order_id, :product_id, :title, :sku,
						:qty, :unit_price, :total,
						:created, :updated
					)
				SQL );

				$i_stmt->execute( [
					':order_id'   => $counter_order_id,
					':product_id' => $prod_id,
					':title'      => $item->get_name(),
					':sku'        => $product->get_sku() ?: '',
					':qty'        => $qty,
					':unit_price' => $unit_price,
					':total'      => $line_total,
					':created'    => time(),
					':updated'    => time(),
				] );

				$count++;
			}
		}

		return $count;
	}

	/**
	 * Generate a UUID v4 if wp_generate_uuid4() is not available.
	 */
	private static function generateUUID(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}
