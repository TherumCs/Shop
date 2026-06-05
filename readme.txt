=== Shop by Therum ===
Contributors: therumstudios
Tags: ecommerce, woocommerce alternative, store, checkout
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A native commerce engine built for speed. One product entity with capability toggles, purpose-built database schema, unified cart/checkout session.

== Description ==

**Shop** is a from-scratch commerce engine for WordPress. Not a WooCommerce fork — a clean rebuild on a purpose-built database schema, designed to be fast by default. Built and maintained by **Therum Creative Studios**.

= What's different from WooCommerce =

* **One product entity, not five types.** No "Simple / Variable / External / Grouped / Downloadable" menu. A product just exists, and you toggle the capabilities it needs: variants, shipping, digital delivery, POD routing, inventory tracking. Mix freely.
* **Custom DB tables, not WP posts + postmeta.** Single-query product loads. Real indexes on price, SKU, stock. SQLite-friendly. No 5-JOIN catalog queries.
* **Unified cart/checkout session.** Cart and checkout are one state container, two render modes — not two separate subsystems. Sessions only spin up on first add-to-cart so anonymous browsing has zero overhead.
* **Pluggable providers via Nexus.** Payment, tax, shipping, and fulfillment are interfaces. The active provider is whatever the merchant connected through Nexus by Therum. Swap providers without touching commerce code.
* **No plugin sprawl.** Subscriptions, bundles, group buy, BNPL, crypto — these arrive as toggles on the core engine, not as separate paid add-ons.

= v0.1 scope (foundation) =

This release ships the database schema and bootstrap only. Active development is layering on top:

* Catalog admin (product list, edit form with capability toggles, variant matrix builder)
* Frontend product page (Bricks-first, theme-overridable)
* Cart drawer + unified checkout flow
* Stripe payment provider (via Nexus)
* Stripe Tax integration
* Order admin + line item fulfillment status
* REST API surface for headless storefronts

= Requirements =

* WordPress 6.4+
* PHP 8.0+
* **Nexus by Therum** (recommended) — for managing payment / tax / shipping / fulfillment provider credentials. Shop will work without it, but provider configuration would live in Shop's own admin instead.

= Coexisting with WooCommerce =

Shop's tables are entirely separate from WooCommerce's. Both can run on the same site. Migration tools are not in v0.1 — they're on the roadmap.

== Installation ==

1. Upload `shop-by-therum.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate. The plugin will create its database tables automatically.
3. (Recommended) Install and activate **Nexus by Therum** for connection management.

== Changelog ==

= 0.1.0 =
* Initial release: bootstrap, plugin header, database migrations (11 tables), uninstall cleanup.
