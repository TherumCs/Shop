<?php
/**
 * Shop by Therum — SQLite schema.
 *
 * The full schema lives in a single function returning an ordered array of
 * DDL statements. Run by Migrations::run() against our PDO connection.
 *
 * Conventions:
 *   - id          INTEGER PRIMARY KEY AUTOINCREMENT
 *   - timestamps  INTEGER (Unix epoch seconds), default unixepoch()
 *   - booleans    INTEGER 0/1
 *   - money       INTEGER, minor units (e.g. USD cents). This is the Stripe /
 *                 Square convention and removes all floating-point error.
 *                 EVERY monetary column in this schema is in minor units.
 *                 The PHP `Money` value object handles display formatting.
 *   - JSON blobs  TEXT (use SQLite's JSON1 functions to query when needed)
 *   - foreign keys ON. ON DELETE CASCADE on child rows where the child is
 *                 meaningless without its parent (line items, variant attrs).
 *
 * Indexes are declared after the tables they cover, so the file reads
 * top-to-bottom in dependency order.
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Schema {

	/**
	 * Bump on every schema change. Migrations::run() compares this against
	 * the stored value in the `schema_version` table and re-applies if behind.
	 *
	 * Migrations are forward-only and additive — we do not auto-downgrade.
	 */
	public const VERSION = 4;

	/**
	 * Ordered list of DDL statements. CREATE TABLE IF NOT EXISTS is idempotent;
	 * column adds on existing tables are handled by per-version migrations
	 * (not in this file — see Migrations::run()).
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		return [

			// ────────────────────────────────────────────────────────────────
			//  Internal — schema version tracking
			// ────────────────────────────────────────────────────────────────

			"CREATE TABLE IF NOT EXISTS schema_version (
				version    INTEGER NOT NULL,
				applied_at INTEGER NOT NULL DEFAULT (unixepoch())
			)",


			// ────────────────────────────────────────────────────────────────
			//  Catalog
			// ────────────────────────────────────────────────────────────────

			// One row per product. Capability flags drive UI + behavior — no
			// fixed type enum. A POD shirt = has_variants + is_shippable + is_pod.
			// An ebook = is_digital. A book in print or PDF = has_variants +
			// (per-variant) is_shippable + is_digital.
			"CREATE TABLE IF NOT EXISTS products (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				uuid                TEXT NOT NULL UNIQUE,
				slug                TEXT NOT NULL UNIQUE,
				title               TEXT NOT NULL,
				description         TEXT,
				short_description   TEXT,
				status              TEXT NOT NULL DEFAULT 'draft',
				author_id           INTEGER,
				created_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				published_at        INTEGER,

				-- Capability flags (the heart of the model)
				has_variants        INTEGER NOT NULL DEFAULT 0,
				is_shippable        INTEGER NOT NULL DEFAULT 1,
				is_digital          INTEGER NOT NULL DEFAULT 0,
				is_pod              INTEGER NOT NULL DEFAULT 0,
				track_inventory     INTEGER NOT NULL DEFAULT 0,

				-- Pricing + SKU + stock — used only when has_variants = 0
				-- Money in minor units (cents for USD).
				price               INTEGER,
				compare_at_price    INTEGER,
				cost                INTEGER,
				sku                 TEXT,
				stock_qty           INTEGER,

				-- Shipping (only relevant when is_shippable = 1)
				weight              REAL,
				length              REAL,
				width               REAL,
				height              REAL,
				weight_unit         TEXT NOT NULL DEFAULT 'g',
				dimension_unit      TEXT NOT NULL DEFAULT 'cm',

				-- Media — primary attachment ID from WP media library; gallery as JSON array.
				primary_image_id    INTEGER,
				gallery_image_ids   TEXT,

				meta                TEXT
			)",
			"CREATE INDEX IF NOT EXISTS idx_products_status ON products(status)",
			"CREATE INDEX IF NOT EXISTS idx_products_sku    ON products(sku)",


			// One row per (color × size × …) combination. Pricing + stock at
			// this level override the product-level fields. POD routing
			// (pod_provider, pod_product_id, pod_variant_id) lets a single
			// product have variants fulfilled from different sources — the
			// "1 shirt, 5 colors, 5 vendors" case.
			"CREATE TABLE IF NOT EXISTS product_variants (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				uuid                TEXT NOT NULL UNIQUE,
				product_id          INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
				sku                 TEXT,
				position            INTEGER NOT NULL DEFAULT 0,
				enabled             INTEGER NOT NULL DEFAULT 1,

				price               INTEGER,
				compare_at_price    INTEGER,
				cost                INTEGER,
				stock_qty           INTEGER,

				weight              REAL,
				length              REAL,
				width               REAL,
				height              REAL,

				image_id            INTEGER,

				-- POD routing (only relevant when parent.is_pod = 1)
				pod_provider        TEXT,
				pod_product_id      TEXT,
				pod_variant_id      TEXT,

				meta                TEXT
			)",
			"CREATE INDEX IF NOT EXISTS idx_variants_product ON product_variants(product_id)",
			"CREATE INDEX IF NOT EXISTS idx_variants_sku     ON product_variants(sku)",
			"CREATE INDEX IF NOT EXISTS idx_variants_pod     ON product_variants(pod_provider, pod_product_id)",


			// Global attribute defs (Color, Size, Material …).
			"CREATE TABLE IF NOT EXISTS attributes (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				slug        TEXT NOT NULL UNIQUE,
				name        TEXT NOT NULL,
				type        TEXT NOT NULL DEFAULT 'select',
				position    INTEGER NOT NULL DEFAULT 0,
				created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at  INTEGER NOT NULL DEFAULT (unixepoch())
			)",

			// Values for each attribute. color_hex + image_id make swatch
			// pickers first-class — no extra plugin required.
			"CREATE TABLE IF NOT EXISTS attribute_values (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				attribute_id  INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
				slug          TEXT NOT NULL,
				value         TEXT NOT NULL,
				color_hex     TEXT,
				image_id      INTEGER,
				position      INTEGER NOT NULL DEFAULT 0,
				UNIQUE(attribute_id, slug)
			)",
			"CREATE INDEX IF NOT EXISTS idx_attrvals_attr ON attribute_values(attribute_id)",

			// Which attributes a product uses. used_for_variants distinguishes
			// variant-defining attributes (Color, Size) from informational
			// metadata (Material composition, Country of origin).
			"CREATE TABLE IF NOT EXISTS product_attributes (
				id                 INTEGER PRIMARY KEY AUTOINCREMENT,
				product_id         INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
				attribute_id       INTEGER NOT NULL REFERENCES attributes(id) ON DELETE CASCADE,
				position           INTEGER NOT NULL DEFAULT 0,
				used_for_variants  INTEGER NOT NULL DEFAULT 1,
				UNIQUE(product_id, attribute_id)
			)",

			// m:m — each variant carries one value per variant-defining attribute.
			"CREATE TABLE IF NOT EXISTS variant_attribute_values (
				variant_id          INTEGER NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
				attribute_value_id  INTEGER NOT NULL REFERENCES attribute_values(id) ON DELETE CASCADE,
				PRIMARY KEY (variant_id, attribute_value_id)
			)",
			"CREATE INDEX IF NOT EXISTS idx_vav_value ON variant_attribute_values(attribute_value_id)",


			// Product images — variant_id nullable so an image can be
			// product-wide OR scoped to a specific variant (red shirt photo).
			"CREATE TABLE IF NOT EXISTS product_images (
				id             INTEGER PRIMARY KEY AUTOINCREMENT,
				product_id     INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
				variant_id     INTEGER REFERENCES product_variants(id) ON DELETE CASCADE,
				attachment_id  INTEGER NOT NULL,
				position       INTEGER NOT NULL DEFAULT 0,
				alt_text       TEXT
			)",
			"CREATE INDEX IF NOT EXISTS idx_images_product ON product_images(product_id)",
			"CREATE INDEX IF NOT EXISTS idx_images_variant ON product_images(variant_id)",


			// Downloadable files — same scoping pattern.
			"CREATE TABLE IF NOT EXISTS digital_files (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				product_id      INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
				variant_id      INTEGER REFERENCES product_variants(id) ON DELETE CASCADE,
				attachment_id   INTEGER NOT NULL,
				download_limit  INTEGER,
				expiry_days     INTEGER,
				position        INTEGER NOT NULL DEFAULT 0
			)",
			"CREATE INDEX IF NOT EXISTS idx_files_product ON digital_files(product_id)",
			"CREATE INDEX IF NOT EXISTS idx_files_variant ON digital_files(variant_id)",


			// ────────────────────────────────────────────────────────────────
			//  Vendor option dictionary
			// ────────────────────────────────────────────────────────────────

			// Per-vendor mapping of source option terms → canonical terms. Lets
			// merge / variant-creation flows recognize that Printful's "Ocean"
			// is the same as our master "Blue". Vendor identity here matches
			// product_variants.pod_provider ("printful", "aopplus", …).
			//
			// confidence: 'confirmed' (admin saved) | 'auto' (system-inferred)
			"CREATE TABLE IF NOT EXISTS vendor_option_terms (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				pod_provider    TEXT NOT NULL,
				option_type     TEXT NOT NULL,
				source_term     TEXT NOT NULL,
				canonical_term  TEXT NOT NULL,
				confidence      TEXT NOT NULL DEFAULT 'confirmed',
				created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
				UNIQUE(pod_provider, option_type, source_term)
			)",
			"CREATE INDEX IF NOT EXISTS idx_vot_canonical ON vendor_option_terms(pod_provider, option_type, canonical_term)",


			// ────────────────────────────────────────────────────────────────
			//  Commerce session
			// ────────────────────────────────────────────────────────────────

			// Unified cart+checkout state container. Cookie-keyed by `token`.
			// Lifecycle: cart → checkout → pending → completed | abandoned.
			"CREATE TABLE IF NOT EXISTS sessions (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				token               TEXT NOT NULL UNIQUE,
				user_id             INTEGER,
				email               TEXT,
				currency            TEXT NOT NULL DEFAULT 'USD',
				status              TEXT NOT NULL DEFAULT 'cart',

				subtotal            INTEGER NOT NULL DEFAULT 0,
				shipping_total      INTEGER NOT NULL DEFAULT 0,
				tax_total           INTEGER NOT NULL DEFAULT 0,
				discount_total      INTEGER NOT NULL DEFAULT 0,
				grand_total         INTEGER NOT NULL DEFAULT 0,

				ship_address        TEXT,
				bill_address        TEXT,

				shipping_method     TEXT,
				shipping_provider   TEXT,

				payment_method      TEXT,
				payment_provider    TEXT,
				payment_intent_id   TEXT,

				meta                TEXT,

				created_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				expires_at          INTEGER
			)",
			"CREATE INDEX IF NOT EXISTS idx_sessions_status   ON sessions(status)",
			"CREATE INDEX IF NOT EXISTS idx_sessions_user     ON sessions(user_id)",
			"CREATE INDEX IF NOT EXISTS idx_sessions_expires  ON sessions(expires_at)",

			"CREATE TABLE IF NOT EXISTS session_items (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				session_id   INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
				product_id   INTEGER NOT NULL REFERENCES products(id),
				variant_id   INTEGER REFERENCES product_variants(id),
				quantity     INTEGER NOT NULL DEFAULT 1,
				unit_price   INTEGER NOT NULL,
				line_total   INTEGER NOT NULL,
				meta         TEXT
			)",
			"CREATE INDEX IF NOT EXISTS idx_session_items_session ON session_items(session_id)",
			"CREATE INDEX IF NOT EXISTS idx_session_items_product ON session_items(product_id)",


			// ────────────────────────────────────────────────────────────────
			//  Orders
			// ────────────────────────────────────────────────────────────────

			// Snapshot of a paid session. Immutable. Edits happen via refunds
			// or new orders; we do not back-edit an order row.
			"CREATE TABLE IF NOT EXISTS orders (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				number              TEXT NOT NULL UNIQUE,
				session_id          INTEGER,
				user_id             INTEGER,
				email               TEXT NOT NULL,
				currency            TEXT NOT NULL DEFAULT 'USD',
				status              TEXT NOT NULL DEFAULT 'pending',

				subtotal            INTEGER NOT NULL,
				shipping_total      INTEGER NOT NULL DEFAULT 0,
				tax_total           INTEGER NOT NULL DEFAULT 0,
				discount_total      INTEGER NOT NULL DEFAULT 0,
				grand_total         INTEGER NOT NULL,
				refunded_total      INTEGER NOT NULL DEFAULT 0,

				ship_address        TEXT,
				bill_address        TEXT,

				payment_provider    TEXT,
				payment_method      TEXT,
				payment_intent_id   TEXT,
				paid_at             INTEGER,

				shipping_provider   TEXT,
				shipping_method     TEXT,
				tracking            TEXT,

				notes               TEXT,
				internal_notes      TEXT,
				meta                TEXT,

				created_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at          INTEGER NOT NULL DEFAULT (unixepoch())
			)",
			"CREATE INDEX IF NOT EXISTS idx_orders_user    ON orders(user_id)",
			"CREATE INDEX IF NOT EXISTS idx_orders_email   ON orders(email)",
			"CREATE INDEX IF NOT EXISTS idx_orders_status  ON orders(status)",
			"CREATE INDEX IF NOT EXISTS idx_orders_payint  ON orders(payment_intent_id)",


			// Per-vendor sub-shipment. Multi-vendor orders split into N rows here.
			"CREATE TABLE IF NOT EXISTS order_shipments (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id            INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				pod_provider        TEXT,
				shipping_provider   TEXT,
				shipping_method     TEXT,
				shipping_total      INTEGER NOT NULL DEFAULT 0,
				tax_total           INTEGER NOT NULL DEFAULT 0,
				tax_source          TEXT NOT NULL DEFAULT 'vendor_quote',
				quote_ref           TEXT,
				quoted_at           INTEGER,
				ship_address        TEXT,
				tracking_number     TEXT,
				tracking_carrier    TEXT,
				status              TEXT NOT NULL DEFAULT 'pending',
				shipped_at          INTEGER,
				delivered_at        INTEGER,
				meta                TEXT,
				created_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at          INTEGER NOT NULL DEFAULT (unixepoch())
			)",
			"CREATE INDEX IF NOT EXISTS idx_shipments_order  ON order_shipments(order_id)",
			"CREATE INDEX IF NOT EXISTS idx_shipments_pod    ON order_shipments(pod_provider)",
			"CREATE INDEX IF NOT EXISTS idx_shipments_status ON order_shipments(status)",


			// Order line items. Every line carries a JSON snapshot of the
			// product as of order time, so history doesn't break when products
			// are edited or deleted.
			"CREATE TABLE IF NOT EXISTS order_items (
				id                    INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id              INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				shipment_id           INTEGER REFERENCES order_shipments(id),
				product_id            INTEGER,
				variant_id            INTEGER,
				sku                   TEXT,
				title                 TEXT NOT NULL,
				product_snapshot      TEXT,
				quantity              INTEGER NOT NULL,
				unit_price            INTEGER NOT NULL,
				line_total            INTEGER NOT NULL,
				discount_total        INTEGER NOT NULL DEFAULT 0,
				vendor_unit_cost      INTEGER,
				fulfillment_status    TEXT NOT NULL DEFAULT 'pending',
				fulfillment_provider  TEXT,
				fulfillment_id        TEXT,
				tracking_number       TEXT,
				tracking_carrier      TEXT,
				fulfilled_at          INTEGER,
				meta                  TEXT
			)",
			"CREATE INDEX IF NOT EXISTS idx_items_order      ON order_items(order_id)",
			"CREATE INDEX IF NOT EXISTS idx_items_shipment   ON order_items(shipment_id)",
			"CREATE INDEX IF NOT EXISTS idx_items_product    ON order_items(product_id)",
			"CREATE INDEX IF NOT EXISTS idx_items_variant    ON order_items(variant_id)",
			"CREATE INDEX IF NOT EXISTS idx_items_fulfill    ON order_items(fulfillment_provider, fulfillment_id)",


			// Append-only event log per order.
			"CREATE TABLE IF NOT EXISTS order_notes (
				id                INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id          INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				author_id         INTEGER,
				author_name       TEXT,
				is_customer_note  INTEGER NOT NULL DEFAULT 0,
				is_system_note    INTEGER NOT NULL DEFAULT 0,
				content           TEXT NOT NULL,
				created_at        INTEGER NOT NULL DEFAULT (unixepoch())
			)",
			"CREATE INDEX IF NOT EXISTS idx_notes_order ON order_notes(order_id)",


			// ────────────────────────────────────────────────────────────────
			//  Coupons + redemptions
			// ────────────────────────────────────────────────────────────────

			// Woo-style coupon model. `code` nullable so the same table covers
			// code-entered coupons AND auto promotions.
			//
			// discount_type: percent | fixed_cart | fixed_product | free_shipping
			// scope:         cart | product | variant | vendor
			// scope_ref:     product_id | variant_id | pod_provider slug (or null for cart)
			"CREATE TABLE IF NOT EXISTS coupons (
				id                       INTEGER PRIMARY KEY AUTOINCREMENT,
				code                     TEXT UNIQUE,
				discount_type            TEXT NOT NULL,
				amount                   INTEGER NOT NULL DEFAULT 0,
				scope                    TEXT NOT NULL DEFAULT 'cart',
				scope_ref                TEXT,
				minimum_amount           INTEGER,
				maximum_amount           INTEGER,
				usage_limit              INTEGER,
				usage_limit_per_user     INTEGER,
				usage_count              INTEGER NOT NULL DEFAULT 0,
				individual_use           INTEGER NOT NULL DEFAULT 0,
				date_starts              INTEGER,
				date_expires             INTEGER,
				status                   TEXT NOT NULL DEFAULT 'active',
				description              TEXT,
				meta                     TEXT,
				created_at               INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at               INTEGER NOT NULL DEFAULT (unixepoch())
			)",
			"CREATE INDEX IF NOT EXISTS idx_coupons_status ON coupons(status)",
			"CREATE INDEX IF NOT EXISTS idx_coupons_dates  ON coupons(date_starts, date_expires)",

			"CREATE TABLE IF NOT EXISTS coupon_redemptions (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				coupon_id    INTEGER NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
				order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				user_id      INTEGER,
				email        TEXT,
				amount       INTEGER NOT NULL,
				applied_at   INTEGER NOT NULL DEFAULT (unixepoch()),
				released_at  INTEGER
			)",
			"CREATE INDEX IF NOT EXISTS idx_redemptions_coupon ON coupon_redemptions(coupon_id)",
			"CREATE INDEX IF NOT EXISTS idx_redemptions_order  ON coupon_redemptions(order_id)",
			"CREATE INDEX IF NOT EXISTS idx_redemptions_user   ON coupon_redemptions(user_id, email)",


			// ────────────────────────────────────────────────────────────────
			//  Refunds
			// ────────────────────────────────────────────────────────────────

			"CREATE TABLE IF NOT EXISTS refunds (
				id                    INTEGER PRIMARY KEY AUTOINCREMENT,
				uuid                  TEXT NOT NULL UNIQUE,
				order_id              INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				amount                INTEGER NOT NULL,
				reason                TEXT,
				status                TEXT NOT NULL DEFAULT 'pending',
				initiated_by          TEXT NOT NULL DEFAULT 'admin',
				refunded_by_user_id   INTEGER,
				payment_provider      TEXT,
				gateway_refund_id     TEXT,
				notes                 TEXT,
				failure_reason        TEXT,
				created_at            INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at            INTEGER NOT NULL DEFAULT (unixepoch()),
				completed_at          INTEGER
			)",
			"CREATE INDEX IF NOT EXISTS idx_refunds_order   ON refunds(order_id)",
			"CREATE INDEX IF NOT EXISTS idx_refunds_status  ON refunds(status)",
			"CREATE INDEX IF NOT EXISTS idx_refunds_gateway ON refunds(payment_provider, gateway_refund_id)",

			"CREATE TABLE IF NOT EXISTS refund_items (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				refund_id       INTEGER NOT NULL REFERENCES refunds(id) ON DELETE CASCADE,
				order_item_id   INTEGER NOT NULL REFERENCES order_items(id),
				shipment_id     INTEGER REFERENCES order_shipments(id),
				quantity        INTEGER NOT NULL,
				amount          INTEGER NOT NULL,
				shipping_amount INTEGER NOT NULL DEFAULT 0,
				tax_amount      INTEGER NOT NULL DEFAULT 0,
				restock         TEXT NOT NULL DEFAULT 'restock'
			)",
			"CREATE INDEX IF NOT EXISTS idx_refitems_refund   ON refund_items(refund_id)",
			"CREATE INDEX IF NOT EXISTS idx_refitems_item     ON refund_items(order_item_id)",
			"CREATE INDEX IF NOT EXISTS idx_refitems_shipment ON refund_items(shipment_id)",


			// ────────────────────────────────────────────────────────────────
			//  Payment event ledger (idempotent webhook handling)
			// ────────────────────────────────────────────────────────────────

			// UNIQUE(payment_provider, provider_event_id) is the idempotency
			// story. Webhook handler inserts BEFORE business logic. Duplicate
			// fires hit the constraint, INSERT fails, handler exits cleanly.
			"CREATE TABLE IF NOT EXISTS payment_events (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				payment_provider    TEXT NOT NULL,
				provider_event_id   TEXT NOT NULL,
				kind                TEXT NOT NULL,
				order_id            INTEGER REFERENCES orders(id),
				refund_id           INTEGER REFERENCES refunds(id),
				payment_intent_id   TEXT,
				payload             TEXT,
				status              TEXT NOT NULL DEFAULT 'received',
				processing_error    TEXT,
				received_at         INTEGER NOT NULL DEFAULT (unixepoch()),
				processed_at        INTEGER,
				UNIQUE(payment_provider, provider_event_id)
			)",
			"CREATE INDEX IF NOT EXISTS idx_payevents_order  ON payment_events(order_id)",
			"CREATE INDEX IF NOT EXISTS idx_payevents_refund ON payment_events(refund_id)",
			"CREATE INDEX IF NOT EXISTS idx_payevents_kind   ON payment_events(kind)",
			"CREATE INDEX IF NOT EXISTS idx_payevents_status ON payment_events(status)",


			// ────────────────────────────────────────────────────────────────
			//  Pure page builder — pages, templates, parts (header/footer)
			// ────────────────────────────────────────────────────────────────

			// One row per saved page or template. `kind` distinguishes:
			//   page         — a regular content page (rendered at a slug)
			//   template     — a reusable template (single product, archive, etc.)
			//   header       — site header part
			//   footer       — site footer part
			//   part         — generic reusable section
			//
			// `tree` is a JSON tree of element nodes:
			//   { "id":"uuid", "type":"section", "settings":{...}, "children":[...] }
			"CREATE TABLE IF NOT EXISTS pages (
				id              INTEGER PRIMARY KEY AUTOINCREMENT,
				uuid            TEXT NOT NULL UNIQUE,
				slug            TEXT NOT NULL,
				title           TEXT NOT NULL,
				kind            TEXT NOT NULL DEFAULT 'page',
				assigned_to     TEXT,
				status          TEXT NOT NULL DEFAULT 'draft',
				tree            TEXT NOT NULL DEFAULT '[]',
				meta            TEXT,
				author_id       INTEGER,
				created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
				published_at    INTEGER,
				UNIQUE(slug, kind)
			)",
			"CREATE INDEX IF NOT EXISTS idx_pages_kind         ON pages(kind)",
			"CREATE INDEX IF NOT EXISTS idx_pages_status       ON pages(status)",
			"CREATE INDEX IF NOT EXISTS idx_pages_assigned_to  ON pages(assigned_to)",


			// ────────────────────────────────────────────────────────────────
			//  Customers (v4)
			// ────────────────────────────────────────────────────────────────

			// One row per buyer / contact. `wp_user_id` is optional —
			// guest checkouts and newsletter signups live here too.
			// Lifetime stats are denormalized: updated on every order
			// success, reconciled nightly. Email is case-insensitive
			// unique via the COLLATE NOCASE column type.
			"CREATE TABLE IF NOT EXISTS customers (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				uuid                TEXT NOT NULL UNIQUE,
				email               TEXT NOT NULL UNIQUE COLLATE NOCASE,
				first_name          TEXT,
				last_name           TEXT,
				phone               TEXT,
				wp_user_id          INTEGER,
				accepts_marketing   INTEGER NOT NULL DEFAULT 0,

				address_line1       TEXT,
				address_line2       TEXT,
				city                TEXT,
				state               TEXT,
				postal_code         TEXT,
				country             TEXT,

				tags                TEXT NOT NULL DEFAULT '[]',

				orders_count        INTEGER NOT NULL DEFAULT 0,
				total_spent_cents   INTEGER NOT NULL DEFAULT 0,
				last_order_at       INTEGER,

				created_at          INTEGER NOT NULL DEFAULT (unixepoch()),
				updated_at          INTEGER NOT NULL DEFAULT (unixepoch())
			)",
			"CREATE INDEX IF NOT EXISTS idx_customers_wp_user_id ON customers(wp_user_id)",
			"CREATE INDEX IF NOT EXISTS idx_customers_total_spent ON customers(total_spent_cents)",
			"CREATE INDEX IF NOT EXISTS idx_customers_last_order  ON customers(last_order_at)",

		];
	}
}
