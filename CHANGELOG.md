# Shop by Therum — Changelog

## [0.26.0] — 2026-06-05

Five batches in one drop — Studio checkout pattern, Studio Connect
OAuth flow, three admin pages, spreadsheet column reorder + saved
views, and two new builder adapters (Elementor + Gutenberg).

### Studio checkout pattern (`MODE_STUDIO`)
- `templates/checkout/studio/index.php` — distilled from
  `checkout-experience.html`. Three-section layout: Your Info →
  Shipping → Payment.
- Payment block is the method strip — pills grouped by Card / Wallets
  / BNPL / Bank / Crypto / P2P with hover preview chips. Methods
  populated at boot from `/studio-pay/methods` so only connected
  options render.
- `assets/checkout/studio.js` — ~4KB vanilla. Pill switching, per-group
  panel rendering (Card form / Wallet grid / BNPL list / Plaid bank /
  Crypto chips / P2P grid), submit → `POST /checkout/intent` with the
  selected method, Stripe Elements handoff for card.
- `assets/checkout/studio.css` — accent-red Therum tokens, JetBrains
  Mono numerics, sticky summary, glassmorphism panels.
- `CheckoutRenderer::MODE_STUDIO` added to the mode enum. Asset
  enqueue conditional on `shop_checkout_presentation === 'studio'`.

### Studio Connect (OAuth)
- `Shop\Payments\Studio\StudioConnect` — one-click connect for Stripe
  / Square / PayPal / Plaid. State is HMAC-signed (nonce + provider)
  so a callback can't be swapped between providers mid-flight.
- Stripe Connect — full OAuth handshake, auto-registers the webhook
  endpoint on the connected account with `payment_intent.*`,
  `charge.refunded`, `charge.dispute.created`, `payout.paid`. Stores
  the webhook secret automatically — the #1 cause of integration
  support tickets, eliminated.
- Square — `connect.squareup.com/oauth2/authorize` flow, token
  exchange, stores merchant_id + access_token.
- PayPal — Partner Referrals (merchant id from callback).
- Plaid — Link flow (server-side public_token → access_token
  exchange).
- `Shop\Rest\StudioPayController` gets three new routes:
    - `GET    /admin/studio-pay/connect/{provider}/start`     mints OAuth URL
    - `GET    /admin/studio-pay/connect/{provider}/callback`  finishes
    - `DELETE /admin/studio-pay/connect/{provider}`           disconnect

### Admin pages
- `Shop\Admin\StudioPayPage` — three tabs:
    - **Providers** — Connect / Disconnect per PSP, balance, status chip
    - **Methods** — per-method routing override (Auto / explicit
      provider), shows live providers per method
    - **Payouts** — cadence radio, standard + instant payout buttons
- `Shop\Admin\CustomersPage` — List + Import/Export tabs. Search,
  paginate, drag-import CSV with conflict mode + Create WP users.
- `Shop\Admin\OrderIoPage` — Status / date-range filters → CSV/JSON
  download. Paste CSV → bulk import with conflict mode.
- All three vanilla JS, no Preact — keeps admin bundle thin. Inline
  `<script>` per page so the build step doesn't grow.
- `AdminMenu` extended with all three sub-pages.

### Grid views (spreadsheet manager remainders)
- `assets/admin/grid-views.js` — companion module on any
  `[data-shop-grid-views="<grid-id>"]`. Adds:
    - **Column visibility** — checkbox list
    - **Column reorder** — HTML5 drag handles per column
    - **Saved views** — named bundles of `{ columns, filters, sort }`
- Event channel (`shop:grid:state` / `shop:grid:apply`) lets the
  existing grid + this module stay decoupled.
- `Shop\Rest\GridViewsController` — per-user, per-grid views stored
  in user_meta under `shop_grid_views_{grid}` as JSON. Full CRUD.

### Builder adapters (Elementor + Gutenberg)
- `Shop\Builders\Elementor\ElementorAdapter` — auto-boots on
  `ELEMENTOR_VERSION` defined.
- `ElementorWidgetFactory` — runtime-generates a `Widget_Base`
  subclass per Shop element via `eval()` (same trick as
  `BricksElementFactory`). Generated widgets delegate to the Shop
  element's `render()`.
- `ElementorControlMap` — Shop control schema → Elementor's
  `Controls_Manager::*` types. text / textarea / number / toggle /
  select / color / image / alignment all map cleanly.
- `Shop\Builders\Gutenberg\GutenbergAdapter` — registers each Shop
  element as a **dynamic block** (`register_block_type` with
  `render_callback` → `$el->render()`). One source of truth, zero
  JSX maintenance.
- `assets/builders/gutenberg.js` — minimal editor-side registration
  using `ServerSideRender` for the editor preview, mapped controls
  in the InspectorControls sidebar (TextControl, ToggleControl,
  SelectControl, ColorPicker, etc).
- Bound + booted in `shop.php` alongside the existing Bricks adapter.

### Container + REST hookup
- `StudioPayPage`, `CustomersPage`, `OrderIoPage` bound, threaded
  into `AdminMenu`.
- `StudioConnect` bound and injected into `StudioPayController`.
- `ElementorAdapter` + `GutenbergAdapter` bound + booted.
- `GridViewsController` bound + registered.

### Files
- `templates/checkout/studio/index.php`        (new)
- `assets/checkout/studio.{js,css}`            (new)
- `assets/admin/grid-views.js`                 (new)
- `assets/builders/gutenberg.js`               (new)
- `includes/Payments/Studio/StudioConnect.php` (new)
- `includes/Admin/StudioPayPage.php`           (new)
- `includes/Admin/CustomersPage.php`           (new)
- `includes/Admin/OrderIoPage.php`             (new)
- `includes/Rest/GridViewsController.php`      (new)
- `includes/Builders/Elementor/{ElementorAdapter,ElementorWidgetFactory,ElementorControlMap}.php` (new)
- `includes/Builders/Gutenberg/GutenbergAdapter.php` (new)
- `includes/Rest/StudioPayController.php` — Connect routes
- `includes/Services/CheckoutRenderer.php` — MODE_STUDIO + totals
- `includes/Admin/AdminMenu.php` — three new sub-pages
- `shop.php` — bindings, REST hookup, adapter boot, Studio asset enqueue

---

## [0.25.0] — 2026-06-05

Order import + export. WebToffee-style flat-per-item CSV plus a nested
JSON variant — clean roundtrip and tolerant of upstream tools'
header variations (Shopify, Woo, WebToffee all import without manual
mapping).

### Exporter
- `Shop\Exporters\OrderExporter`:
  - Flat CSV — one row per line item with order-level columns
    repeated. UTF-8 BOM for Excel autodetect.
  - Nested JSON — one entry per order with an `items[]` array and
    structured payment / address / fulfillment blocks.
  - Streamed in batches of 200 orders so a 100k-order export doesn't
    blow memory.
  - Filterable by status / date range.
  - Stable `COLUMNS` constant — exporter + importer share field
    naming so a CSV roundtrips bit-perfect.

### Importer
- `Shop\Importers\OrderImporter`:
  - Header aliasing — "Order Number", "order_number", "Order #",
    "OrderID" all map to `order_number`. Same for every other field.
  - Direct-to-PDO inserts inside a per-order `DB::tx()` so partial-row
    failures don't leave half-imported orders. Per-row errors collect
    into the result; the rest continues.
  - Conflict modes: `skip` / `update` (merge) / `replace` (wholesale
    rewrite).
  - Items are grouped by `order_number` — multiple CSV rows for one
    order accumulate into the items[] array before insert.
  - Dollars → cents via `(int) round(value * 100)`. ISO 8601 dates
    parsed via `strtotime()`.

### REST
- `Shop\Rest\OrderIoController`:
    - `POST /shop/v1/admin/orders/import`   { csv | rows, map, conflict }
    - `GET  /shop/v1/admin/orders/export`   ?format=csv|json[&status=…&from=…&to=…]
  CSV export sets `Content-Disposition: attachment` so the browser
  downloads it natively.

### Files
- `includes/Exporters/OrderExporter.php`  (new)
- `includes/Importers/OrderImporter.php`  (new)
- `includes/Rest/OrderIoController.php`   (new)
- `shop.php` — bindings + REST hookup

---

## [0.24.0] — 2026-06-05

Plaid joins Studio Pay as a provider, and the long-overdue
customers table + import/export pipeline lands.

### Plaid provider (Studio Pay)
- `Shop\Payments\Providers\PlaidProvider` — REST, no SDK. Supports the
  `bank_ach` method and routes there *before* Stripe (Plaid Auth +
  Transfer is ~30bps cheaper than Stripe Financial Connections, which
  is Plaid wrapped anyway).
- Link-token flow — `createIntent('bank_ach')` mints a Plaid
  `link_token` (passed to the client as the analogue of Stripe's
  `client_secret`), with the Transfer Intent pre-created so the
  customer-facing disclosure matches what we'll debit.
- Refunds via `transfer/refund/create`.
- Instant payouts via RTP / FedNow when `instant=true` — flat fee, ~10s.
- Webhook verification — body-hash gate against the JWT payload's
  `request_body_sha256` claim (Plaid's quick-check pattern).
- Sandbox / development / production switch via
  `shop_plaid_environment`. Credentials read from
  `shop_studio_pay_plaid_*` (Connect mode) or `shop_plaid_*` (BYO).
- `MethodRegistry` — `bank_ach` providers list updated to
  `[ 'plaid', 'stripe' ]` so Plaid wins by default when both are
  connected.

### Customers (v4 schema)
- New `customers` table — id, uuid, email (UNIQUE COLLATE NOCASE),
  name, phone, optional wp_user_id, accepts_marketing, full address,
  tags JSON, lifetime stats (orders_count, total_spent_cents,
  last_order_at). Indexed on wp_user_id, total_spent, last_order.
- Forward-only migration (v4 = pure DDL, no data move).
- `Shop\Models\Customer` DTO with `fullName()` helper.
- `Shop\Repositories\CustomerRepository`:
  - `findById / findByEmail / findByWpUserId / list / count`
  - `upsertByEmail($email, $fields, $conflict)` — three modes:
    - `skip`    — keep existing untouched
    - `update`  — merge, blanks don't clobber (default)
    - `replace` — overwrite every passed field
  - `recordOrder($id, $cents, $ts)` — increments denormalized
    lifetime stats; idempotency owned by the order layer.

### Import + Export
- `Shop\Importers\CustomerImporter`:
  - Auto-infers field mapping from common header aliases — a
    Shopify or codection-style export drops in with zero config
    (handles "First Name" / "firstname" / "givenname" all the same).
  - Per-row WP user creation when `create_wp_users=true` (or links
    to existing user if email matches).
  - Returns `{ created, updated, skipped, errors[] }`.
- `Shop\Exporters\CustomerExporter`:
  - CSV with UTF-8 BOM (Excel autodetects encoding).
  - JSON variant for API consumers.
  - Lifetime stats formatted as numbers, dates as ISO 8601 UTC.
  - Streams in batches of 500 so large exports don't OOM.

### REST
- `Shop\Rest\CustomersController`:
  - `GET    /admin/customers`              search + paginate
  - `POST   /admin/customers`              create / upsert
  - `GET    /admin/customers/{id}`         read
  - `PUT    /admin/customers/{id}`         update
  - `DELETE /admin/customers/{id}`         delete
  - `POST   /admin/customers/import`       { csv | rows, map, conflict, create_wp_users }
  - `GET    /admin/customers/export`       ?format=csv|json
- All auth-gated. The export endpoint sets `Content-Disposition` for
  CSV so the browser downloads natively.

### Files
- `includes/Schema.php` — `VERSION = 4`, customers table
- `includes/migrations.php` — v4 case (no-op data move)
- `includes/Models/Customer.php`            (new)
- `includes/Repositories/CustomerRepository.php` (new)
- `includes/Importers/CustomerImporter.php` (new)
- `includes/Exporters/CustomerExporter.php` (new)
- `includes/Rest/CustomersController.php`   (new)
- `includes/Payments/Providers/PlaidProvider.php` (new)
- `includes/Payments/Studio/MethodRegistry.php` — Plaid preferred for bank_ach
- `shop.php` — bindings, REST hookup, Plaid as Studio Pay provider

---

## [0.23.0] — 2026-06-05

**Studio Pay foundation.** WooPayments-style unified gateway —
connect-once, every checkout method aggregated under one UX. Backend
layer: providers + method registry + aggregator + payouts + REST.
Checkout UI rewrite + admin dashboard land in the next chunks.

### Provider layer
- `Shop\Payments\Providers\PaymentProvider` — multi-method contract
  with `supportedMethods()`, `isConnected()`, `availableBalance()`,
  `payout(instant)`, plus the existing PSP webhook + intent + refund
  surface.
- `StripeProvider` — REST (no SDK). Methods: card, apple_pay,
  google_pay, link, shop_pay, klarna, affirm, afterpay, zip, bank_ach,
  cashapp. Connect-mode aware — sends `Stripe-Account` header when
  the merchant is onboarded through Studio Pay. Webhook signature
  verification via HMAC-SHA256.
- `SquareProvider` — REST. Methods: card, cashapp, afterpay. Payment
  Links flow (Square's online equivalent of PaymentIntents). Square's
  URL+body HMAC signature verification.
- `PayPalProvider` — REST + OAuth2 client credentials (8-min token
  cache). Methods: paypal, paypal_credit, venmo. Round-trips
  webhook signature verification to PayPal's own verifier (avoids
  shipping their cert chain).

### Studio Pay aggregator
- `Shop\Payments\Studio\MethodRegistry` — single source of truth for
  every method in `checkout-experience.html`:
    Card / Apple Pay / Google Pay / Link / Shop Pay / PayPal
    / Klarna / Affirm / Afterpay / Sezzle / Zip / PayPal Credit
    / Bank ACH / Crypto / Cash App / Venmo / Zelle
  Each declares its UI group and the ordered list of providers that
  can fulfil it.
- `Shop\Payments\Studio\StudioPay` — implements the existing
  `PSPGateway` interface so CheckoutService/RefundService/
  WebhookController don't have to change. Routes each `createIntent`
  to the right provider per method using three-tier resolution:
    1. Per-method override (`shop_studio_pay_method_routes` option)
    2. First connected provider in the method's preferred list
    3. Throw — no provider available
  Refunds route to the *original* capturing provider, not whichever
  happens to be first. Webhooks delegate to each provider's verifier
  and stash the resolving provider id on the body for parseEvent.

### Payouts service
- `Shop\Payments\Studio\Payouts` — three cadences:
    - `daily`   — provider handles, we do nothing
    - `instant` — auto-trigger instant payout on every capture (1.5-
      1.75% provider fee absorbed transparently)
    - `manual`  — admin "Pay out now" button
- `onPaymentSucceeded(order)` — instant-payout hook called by
  CheckoutService after a capture. Failures don't break the order.
- `payoutAll(instant)` — manual payout across every connected
  provider that has a non-zero balance.
- `aggregateBalance()` — merchant sees ONE balance number in the
  admin dashboard, with a per-provider breakdown on hover.

### REST
- `Shop\Rest\StudioPayController`:
    - `GET  /shop/v1/studio-pay/methods`       public — drives the
      checkout method strip (returns only methods with a connected
      provider, grouped for UI)
    - `GET  /shop/v1/admin/studio-pay/status`  auth — connect status
      per provider + balance + cadence + method routes
    - `POST /shop/v1/admin/studio-pay/cadence` auth — set payout cadence
    - `POST /shop/v1/admin/studio-pay/payout`  auth — manual payout
    - `POST /shop/v1/admin/studio-pay/route`   auth — set / clear a
      per-method provider override
- `apply_filters( 'shop_studio_pay_providers', $providers )` lets
  third-party adapters (Sezzle, crypto, Zelle, Adyen) register
  themselves without touching shop.php.

### Container wiring
- Studio Pay registered as the *primary* gateway in
  `PaymentGatewayRegistry`. MockGateway stays for tests.
- All three core providers (Stripe / Square / PayPal) bound as
  singletons. They lazily read credentials from `shop_*_*` options or
  the Studio-Pay-Connect-prefixed variants.

### Files
- `includes/Payments/Providers/PaymentProvider.php`  (new)
- `includes/Payments/Providers/StripeProvider.php`   (new)
- `includes/Payments/Providers/SquareProvider.php`   (new)
- `includes/Payments/Providers/PayPalProvider.php`   (new)
- `includes/Payments/Studio/MethodRegistry.php`      (new)
- `includes/Payments/Studio/StudioPay.php`           (new)
- `includes/Payments/Studio/Payouts.php`             (new)
- `includes/Rest/StudioPayController.php`            (new)
- `shop.php` — bindings, gateway registration, REST hookup

### Coming next
- Checkout UI rewrite — replace `CheckoutRenderer` payment block
  with the method strip from `checkout-experience.html`, pulling
  from `/studio-pay/methods`.
- StudioConnect — OAuth flow that lets a merchant connect Stripe /
  Square / PayPal in one click each, store credentials, auto-
  register webhook endpoints.
- Admin dashboard — Preact tables for Transactions / Payouts /
  Disputes pulled from the providers' APIs.
- Sezzle + Coinbase Commerce + Zelle adapters once the interface is
  battle-tested.

---

## [0.22.0] — 2026-06-05

Phase 2 closes out — direct manipulation in the preview, viewport
toggle, copy/paste, snap guides, and nested shift-select.

### Click-in-preview selection
- `PageRenderer::withEditorMode(true)` (chainable) wraps every rendered
  node in `<div class="shop-ed" data-shop-node-id="…" data-shop-node-
  type="…">…</div>` so the editor can hit-test clicks back to the tree.
- `PagesController::render` (the /render endpoint the editor calls)
  flips this on. The public router still renders bare HTML.
- Builder's preview div listens for capture-phase clicks on `.shop-ed`,
  resolves `data-shop-node-id`, and routes the click through the same
  select / multi-select / extend handlers the overlay rows use.
- Preview-side CSS — dashed accent outline on hover, mini type-pill
  hint above the hovered element, no JS to set up.

### Viewport toggle
- `viewport` state in App — `desktop` / `tablet` / `mobile`. Header
  toolbar gets a 3-button segmented control.
- `.shop-builder__canvas--vp-{viewport}` constrains the new
  `.shop-builder__preview-frame` max-width (820 / 400). Transitions
  smoothly so toggling feels like a real device.

### Copy / paste
- `⌘C` / `⌃C` serializes the current selection to
  `localStorage['shop:builder:clipboard']` (raw JSON node objects).
- `⌘V` / `⌃V` reads it back, re-ids every node via `reId`, appends to
  the root tree, and selects the pasted set.
- Cross-page paste works — the clipboard persists in localStorage so
  you can copy from a header, switch pages, paste into the body.

### Snap guides
- After selection or preview re-render, the builder measures the
  selected element's bounding rect against each sibling's.
- Edges that line up within 1.5px (left/right/top/bottom in both
  orientations) project a thin accent guide line into a new overlay
  layer.
- Updates on `viewport` change too, so toggling devices keeps the
  guides correct.

### Nested shift-select
- `extendSelect` no longer assumes root-level — `findSiblingArray`
  walks the tree to find the container array that holds the anchor,
  then ranges within those siblings. Anchors in different parents fall
  back to single-add (a contiguous range across parents would be too
  surprising).

### Files
- `includes/Services/PageRenderer.php` — editor-mode wrapping
- `includes/Rest/PagesController.php` — flip editor mode in /render
- `assets/builder/builder.js` — viewport, copy/paste, click-in-preview,
  snap guides, nested shift-select
- `assets/builder/builder.css` — viewport pills, preview-frame widths,
  `.shop-ed` selectable wrappers, guide layer

---

## [0.21.0] — 2026-06-05

Default chrome on first install + multi-select in the builder + exit
transitions when nodes are removed.

### Default header + footer
- Schema bumped to v3 — `Migrations::v3_seedDefaultChrome()` calls a
  new `TemplateSeeder::seedChrome()`.
- `seedChrome()` creates one starter header + footer if and only if
  none exists of that kind, and sets `shop_active_{header,footer}_id`
  so `ChromeResolver` picks them up immediately.
- Default header — sticky white bar with `site-logo` (28px,
  links to `/`) + centered `site-nav` (Shop / About / Contact) +
  pill `cart-button` on the right.
- Default footer — dark section with site name as a small heading,
  link strip (Shop / Cart / Privacy / Terms), divider, and tiny
  copyright using the current site name + year.

### Multi-select in the builder
- `selectedSet` is now a `Set<id>` instead of a single id. Shimmed
  `selected` / `setSelected` keep the inspector + render code
  unchanged (single-select is just `[...selectedSet][0]`).
- Click → single-select. **⌘/⌃-click** toggles a node in/out of the
  set. **Shift-click** extends from the last anchor through the
  clicked node at the root level. (Nested ranges currently fall back
  to single-add — that's the next refinement.)
- New `removeSelected` / `duplicateSelected` operate on the full set
  in one undoable mutation.
- Header gets a bulk action bar when ≥2 nodes are selected — count
  pill, Duplicate, Delete (danger), Clear.
- New keyboard shortcuts (only fire when focus isn't in a form
  field):
  - `Delete` / `Backspace` → remove selection
  - `⌘D` / `⌃D` → duplicate selection
  - `⌘A` / `⌃A` → select all root-level nodes

### Exit transitions
- `waitForExit( ids )` queries each `[data-node-id="…"]` overlay row,
  animates opacity → 0 + a tiny lift via motion-one, then resolves.
  `removeNode` and `removeSelected` await it before mutating the
  tree so the row visibly fades instead of popping.
- Capped at 180 ms so a bulk delete still feels instant.

### Files
- `includes/Schema.php` — `VERSION = 3`
- `includes/migrations.php` — `v3_seedDefaultChrome`
- `includes/Services/TemplateSeeder.php` — `seedChrome()`,
  `defaultHeaderTree()`, `defaultFooterTree()`
- `assets/builder/builder.js` — multi-select, bulk actions, exit
  animations, new keyboard map
- `assets/builder/builder.css` — `.shop-builder__bulk*` styles

---

## [0.20.0] — 2026-06-05

Header + footer builder lands. Same editor, same elements, plus three
new chrome-specific ones. Pure pages now ship with site chrome on
every route automatically.

### Chrome resolution
- `Shop\Services\ChromeResolver` — picks the active header/footer per
  request. Rules:
  1. If `shop_active_{header,footer}_id` option is set, use that page.
  2. Otherwise newest *published* page of that kind.
  3. Else null → renderer emits no chrome wrapper.
- Memoized per request so header + footer don't double-query.
- `PageRouter::renderCurrent()` now wraps the body in
  `<div class="shop-pure-header">…</div>` /
  `<div class="shop-pure-footer">…</div>` when chrome is resolved.
- New `PageRouter::renderCurrentBody()` for themes that want bare body.

### Chrome elements
- `Shop\Elements\Chrome\SiteLogo` — image / WP custom-logo / site-name
  fallback chain. Height + link controls.
- `Shop\Elements\Chrome\SiteNav` — explicit `Label|/url` lines or a WP
  menu slug. Alignment + gap controls.
- `Shop\Elements\Chrome\CartButton` — pill / icon / text styles with
  optional live count badge.
- Registered in the global `ElementRegistry` under category `chrome`,
  so they appear in the palette automatically.

### Cart-button wiring (elements.js)
- Delegated `[data-shop-cart-open]` handler — calls
  `window.ShopCart.open()` or falls back to `/cart/`.
- `[data-shop-cart-count]` badges paint from `ShopCart.snapshot()` on
  load and live-update on every `shop:cartChange` event.

### Admin
- `BuilderPage::renderPageList()` now sports kind tabs — Pages /
  Headers / Footers / Parts / Templates — backed by a `?kind=` query
  param.
- Inline JS bootstrapper that lists the current kind, lets admins
  create + delete, and (for header/footer kinds) shows an "Active"
  chip or a "Set active" button.
- New route `POST /shop/v1/admin/builder/chrome-active` pins a header
  or footer page as site-wide.
- `assets/admin/admin.css` — tabs, active chip, empty state.

### Files
- `includes/Services/ChromeResolver.php` (new)
- `includes/Elements/Chrome/{SiteLogo,SiteNav,CartButton}.php` (new)
- `includes/Services/PageRouter.php` — chrome injection
- `includes/Rest/PagesController.php` — `setChromeActive` route
- `includes/Admin/BuilderPage.php` — kind tabs + inline list JS
- `assets/elements/elements.{css,js}` — chrome styles + cart wiring
- `assets/admin/admin.css` — admin tab styling
- `shop.php` — container bindings, element registrations

---

## [0.19.0] — 2026-06-05

AI command palette lands in the Pure builder. ⌘K → describe a change →
Claude returns ops → tree mutates through the undo-tracked setter.

### Backend
- `Shop\Services\BuilderAi` — wraps `ClaudeClient` with one method,
  `commandToOps( $tree, $prompt )`. Builds the element-catalog blob
  (id → name/category/controls), serializes the current tree, then
  asks Claude with **forced tool use** pinned to a single `apply_ops`
  tool whose schema accepts an `ops[]` array.
- Op shapes (intentionally small surface area):
  - `{ op: 'add',     type, settings, parentId, index }`
  - `{ op: 'update',  id, settings }`
  - `{ op: 'remove',  id }`
  - `{ op: 'replace', tree }`
- `Shop\Rest\PagesController::command` — new route at
  `POST /shop/v1/admin/builder/command`. Returns `{ ops }` or
  `{ error }` (200 in either case so the palette can show a friendly
  message instead of a fetch-failure toast).
- Bound `Shop\AI\ClaudeClient` + `Shop\Services\BuilderAi` in the
  container; injected `BuilderAi` into `PagesController`.

### Frontend
- `⌘K` / `⌃K` opens a centered palette modal, `Esc` closes it. Header
  toolbar gets a gradient `✨` button as a discoverable entry point.
- Submit posts `{ prompt, tree }`, receives `ops`, runs them through a
  new `applyOps( tree, ops, makeId )` reducer that handles add /
  update / remove / replace and supports nested `parentId` + `index`
  insertion via a small `insertNode` helper.
- Because `applyOps` flows through `applyTree`, AI-driven changes are
  fully captured by undo/redo — one ⌘Z reverts the whole command.
- Suggestions strip lets users one-click sample prompts.
- Errors surface inline in the palette (e.g. missing
  `SHOP_ANTHROPIC_API_KEY`).

### Styling
- `.shop-builder__icon-btn--ai` — accent gradient pill in the header.
- `.shop-builder__palette-bg/modal/bar/input/kbd/sugg/err` with
  `sb-fade-in` / `sb-modal-in` keyframes for a Raycast-flavored entrance.

---

## [0.18.0] — 2026-06-05

Drag-to-reorder + motion-one entry transitions for the Pure builder.

### Drag-drop reorder
- Every overlay row is now `draggable=true`. Picking a row up emits a
  drag with `effectAllowed='move'` and a `text/plain` payload (Firefox
  needs it).
- `onDragOver` measures the pointer position against the row's bounding
  rect — top half → drop before, bottom half → drop after — and posts
  the target index back to the overlay container.
- The overlay container handles the `drop`: calls `moveBefore`, which
  pushes a new tree through `applyTree` so undo/redo capture it.
- An animated drop-indicator line (`.shop-builder__drop-line`) pulses at
  the target slot. Dragging past the last row drops at the tail.
- `walkAndMoveTo( tree, srcId, dstIndex )` — tree-aware mover that
  handles same-array reordering with the correct index shift when the
  source is being removed from below the target.
- `is-dragging` class dims the source row and switches the grip cursor
  to `grabbing`.

### Motion-one transitions
- `motion@10.18.0` loaded from esm.sh — adds ~7 KB gz.
- Every NodeRow runs `animate( el, { opacity, transform } )` with a
  spring on mount → rows fade + lift in when added or duplicated. The
  spring auto-detaches when it settles.
- No transition on remove yet (preact unmounts immediately) — that's a
  future pass with `presence`.

### Files
- `assets/builder/builder.js` — import motion + spring; add drag state
  in App, NodeRow drag handlers, drop-line render, mount animation.
- `assets/builder/builder.css` — `.shop-builder__drag-grip`,
  `.is-dragging`, `.shop-builder__drop-line`, pulse keyframes.

---

## [0.17.0] — 2026-06-05

Pure builder gets the operations that turn an MVP into actual editing
tooling: history, inline text, reorder, duplicate.

### Undo / redo
- History ring inside the App component — `past` + `future` refs capped
  at 100 entries each side. Every tree mutation (add / settings change /
  remove / move / duplicate) flows through a wrapped `applyTree` setter
  that snapshots the previous tree.
- `⌘Z` / `⌃Z` to undo, `⌘⇧Z` / `⌃⇧Z` to redo. Bound on `window` and
  ignored when focus is inside a form field or content-editable so the
  inline text inputs keep their native browser undo.
- Header toolbar gets `↶` / `↷` icon buttons reflecting availability.

### Inline text editing
- Overlay node rows now render an inline input for the node's "primary
  text" — `heading.text`, `button.label`, `add-to-cart.label`,
  `rich-text.html`. Edits stream through `updateSettings` so the live
  preview refreshes via the existing render effect.

### Reorder + duplicate
- `↑` / `↓` move a node up or down within its siblings — recursive walk
  so nested rows reorder inside their own parent's children array.
- `⎘` duplicates a node (and its full subtree) immediately after itself,
  regenerating ids on every cloned descendant.
- `×` delete remains as before.

### Styling
- New `.shop-builder__icon-btn` (header), `.shop-builder__node-type`,
  `.shop-builder__node-inline`, `.shop-builder__node-actions` —
  same glass + accent-red language.

---

## [0.16.0] — 2026-06-05

Commerce surfaces land in the element library. The archive page now
ships a real product grid, and `/cart/`, `/checkout/`, and
`/order-received/` are real Pure routes with editable templates.

### New elements
- `Shop\Elements\Catalog\ProductGrid` — sort / limit / pod_provider /
  ids / columns / aspect / gap / show_vendor / show_price /
  show_compare / show_stock / show_quick_add. Pulls from native SQLite
  catalog or Woo depending on `Mode::catalogSource()`.
- `Shop\Elements\Commerce\CartContents` — delegates to
  `CartRenderer::contents()` for the page-level cart view.
- `Shop\Elements\Commerce\CheckoutForm` — delegates to
  `CheckoutRenderer::render()`, optional `presentation` override
  (default / classic / therum / sequence).
- `Shop\Elements\Commerce\OrderReceived` — reads `?order=SH-XXX`,
  includes `templates/order-received.php` with the resolved order in
  scope.

### Routing
- `PageRouter` adds three new rewrites:
  - `/cart/`           → assigned template 'cart-page'
  - `/checkout/`       → assigned template 'checkout-page'
  - `/order-received/` → assigned template 'order-received'
- Still no-ops in Unlocked mode.

### Default templates
- `TemplateSeeder` now seeds `cart-page`, `checkout-page`, and
  `order-received` slots alongside the existing single-product /
  archive defaults. Archive default now uses the real ProductGrid
  element (4-up, 4/5 aspect, vendor + price + compare visible).
- Idempotent — existing assigned templates are never overwritten.

### Styling
- `assets/elements/elements.css` — adds `.shop-el-grid` (responsive
  2-col fallback under 720px), `.shop-el-grid__card / media / vendor /
  title / price / compare / stock`, and pass-through wrappers for the
  three commerce elements.

---

## [0.15.0] — 2026-06-05

Frontend routing + default templates. Pure pages built in the editor
now render on the live site; first-install ships starter templates so
admins don't face a blank canvas.

### Frontend routing (Pure mode)
- `Shop\Services\PageRouter` — registers rewrite rules + a
  `template_include` filter:
  - `/p/{slug}/`              → Page (kind = 'page') with that slug
  - `/product/{slug}/`        → assigned template 'single-product'
  - `/shop/`                  → assigned template 'product-archive'
- Theme override pattern — copy `templates/pure-page.php` into your
  theme at `<theme>/shop/pure-page.php` to customize the wrapper
- Bundled `templates/pure-page.php` wraps `get_header()` /
  `get_footer()` around the rendered element tree
- Quietly does nothing in Unlocked mode — Bricks / Elementor /
  Gutenberg handle their own routes

### Default templates (auto-seeded on activation)
- v2 migration runs `TemplateSeeder::seedAll()` which idempotently
  creates the starter templates if their slot is empty:
  - **single-product** — two-column hero: gallery left, stock badge +
    title + meta + price + short description + variant picker + add
    to cart on the right; full description in a second section below
  - **product-archive** — heading + intro paragraph (real grid
    element ships in a follow-up chunk)
- Admin can edit any of these in **Shop → Pages** or replace them
  entirely. `assigned_to` is the slot; the most recently published
  template per slot wins

### Schema
- `Migrations::applyVersion()` now switches on the target version. v2
  triggers the template seeder

### Wiring
- `register_activation_hook` flushes rewrite rules so the new routes
  resolve immediately after activation — no manual permalinks save
  needed
- `PageRouter::register()` runs on `plugins_loaded` priority 25

### What's next
- Product grid / archive query loop element (drives /shop/ + Bricks
  query loops)
- Drag-and-drop positioning in the Pure editor
- Inline text editing on the canvas
- Cart / checkout / order-received templates via the seeder
- Header / footer builder + site-wide chrome
- AI command palette (⌘K)

## [0.14.0] — 2026-06-05

Pure page builder MVP. Page schema lands; Preact-based editor loads in
admin under Shop → Pages; canvas / palette / inspector all working
with live preview and autosave.

### Schema (v2)
- New `pages` table: id, uuid, slug, title, kind
  (page/template/header/footer/part), assigned_to, status, tree (JSON
  element forest), meta, author_id, timestamps
- UNIQUE on (slug, kind) so a "header" with slug "main" can coexist
  with a "page" with slug "main"
- Schema::VERSION bumped to 2 — Migrations::run() auto-applies on
  next admin load

### Page system
- `Shop\Models\Page` — DTO with KIND_* constants
- `Shop\Repositories\PageRepository` — findById, findBySlug,
  findByAssignment (templates), list, create, save, delete
- `Shop\Services\PageRenderer` — recursive walk of element tree;
  children render first and flow into parent via
  context.extras['children']; tracks whether any rendered element
  needsJs() so callers can skip enqueuing the interactive bundle on
  static-only pages

### REST surface
- `GET    /shop/v1/admin/pages` — list, optional ?kind= / ?status=
- `POST   /shop/v1/admin/pages` — create { title, kind, assigned_to }
- `GET    /shop/v1/admin/pages/{id}` — fetch including tree
- `PUT    /shop/v1/admin/pages/{id}` — save title/slug/status/tree/meta
- `DELETE /shop/v1/admin/pages/{id}` — delete
- `POST   /shop/v1/admin/pages/{id}/render` — preview render, returns
  { html, needs_js } and accepts an override tree for the live preview
- `GET    /shop/v1/elements` — element catalog for editor UI

### Admin UI
- New menu item: **Shop → Pages** (only visible in Pure mode)
- Builder canvas at admin.php?page=shop-builder&page_id=N
- `Shop\Admin\BuilderPage` — Preact mount point
- Full-bleed editor — adds `shop-builder-active` body class which
  hides the WP admin chrome via CSS

### Preact editor MVP (no build step)
- Preact + htm loaded from esm.sh — zero local build pipeline
- Three-pane layout:
  - **Palette** (left): elements grouped by category (catalog,
    layout, content) with glassmorphism panel
  - **Canvas** (center): live HTML preview + a thin node overlay for
    selection/removal
  - **Inspector** (right): control schema rendered as form fields,
    grouped by `group`. Handles text / textarea / number / toggle /
    color / select / alignment / image (via WP media library)
- Autosave on tree change, debounced 800ms
- Live render via the REST preview endpoint
- ~12 KB total app code, plus Preact runtime from CDN

### What's next
- Drag-and-drop into specific positions (currently appends to root)
- Inline text editing on the canvas
- Undo / redo
- Motion-one transitions on panel changes
- AI command palette (⌘K → "add a hero with the studio shirt")
- Header / footer builders (separate from page body)
- Default templates (single product / archive / cart / checkout)
- Front-end routing — `/page/{slug}` to render saved Pure pages on
  the storefront

## [0.13.0] — 2026-06-05

Layout primitives + Mode helper + interactive client JS + front-end
styles. The element library now has 14 elements total. Pages built
with any builder (Bricks today, Pure / Elementor / Gutenberg later)
render styled and interactive end-to-end.

### Mode helper
- `Shop\Mode` — single source of truth for Pure vs Unlocked gating.
  Computes from `shop_product_source`, detected page builders, and
  the optional `shop_mode` override setting:
  - `Mode::isPure()` / `isUnlocked()`
  - `Mode::isBricks()` / `isElementor()`
  - `Mode::showsNativeProductEditor()` — false in Unlocked + Woo mode
    so the admin doesn't show two product editors for the same row
  - `Mode::loadsPureBuilder()` — true only when Pure is the active mode

### Layout primitives (Phase 2 of task #14)
- **Section** — full-width band with constrained inner. 5 inner widths
  (sm/md/lg/xl/full). Vertical + horizontal padding, gap, background
  color/image with optional dark overlay
- **Column** — 12-column grid model. 2/3/4/6/8/9/12 span options.
  Vertical alignment (top/center/bottom/stretch). Mobile collapses to
  full width at 720px
- **Heading** — H1-H4, 6 sizes (XS-2XL), alignment, color. Supports
  dynamic data tokens `{shop_product_title}`, `{shop_product_sku}` 
- **Image** — picker + alt + link, object-fit, aspect ratio, radius,
  alignment
- **Button** — 4 variants (primary/outline/ghost/link), 3 sizes,
  full-width, custom colors, radius, alignment, optional new-tab
- **Spacer** — vertical-only spacing
- **Divider** — solid/dashed/dotted, thickness, color, vertical margin,
  width percent
- **RichText** — HTML content block with kses sanitization, 3 sizes

### Frontend assets
- `assets/elements/elements.css` (~10KB) — all `shop-el-*` classes,
  Therum tokens, JetBrains Mono numerics, accent red, no Tailwind
- `assets/elements/elements.js` (~5KB) — three behaviors:
  - Gallery: click thumb → main image swaps
  - Variant picker: any swatch/button/dropdown change → REST call to
    `/products/match-variant` → broadcasts `shop:variantChange` event
  - Add to cart: reads selected variant from broadcast, calls
    `ShopCart.addItem()`, optimistic "Adding…" / "Added ✓" UX
- Both auto-enqueued on front-end. ~15KB total.

### New REST
- `GET /shop/v1/products/match-variant?product_id=&options[color]=red&options[size]=lg`
  — returns `{ variant_id }`. Powers the variant picker JS

### What's interactive vs static
- **Static** (no JS needed): Section, Column, Heading, Image, Button,
  Spacer, Divider, RichText, ProductTitle, ProductPrice, StockStatus,
  ProductDescription, ProductMeta
- **Interactive** (needs JS): ProductGallery, VariantPicker, AddToCart

A page with only static elements ships zero JS. A page with the
interactive ones ships ~6KB (cart.js + elements.js, both already there).

## [0.12.0] — 2026-06-05

Element library foundation + Bricks Builder adapter (Phase 1). Eight
Shop-specific elements ship; layout primitives and Pure editor land
next chunk.

### Element architecture
- `Shop\Elements\Element` interface — `id`, `name`, `category`, `icon`,
  `controls()`, `render( settings, context )`, `needsJs()`
- `Shop\Elements\ControlBuilder` — fluent helper for declaring control
  schemas (text/textarea/number/toggle/select/color/alignment/image/
  productPicker)
- `Shop\Elements\ElementContext` — render-time data carrier (productId,
  variantId, cart, extras)
- `Shop\Elements\ElementRegistry` — shared registry walked by every
  page builder adapter
- Filter `shop_register_elements` lets plugins add custom elements
- Server-side render only by default — pages without interactive
  elements ship zero JS

### Catalog elements (closes Phase 1 of task #14)
- **Product Title** — h1/h2/h3 tag option, alignment, color, optional link
- **Price** — size/alignment/color/mono, strikethrough compare-at on sale
- **Gallery** — side-thumbs / stacked / carousel / grid layouts, aspect
  ratio control, hover zoom toggle
- **Variant Picker** — swatches / buttons / dropdowns / auto. Auto
  picks swatches when color_hex is set, buttons for ≤6 values, else dropdown
- **Add to Cart** — primary/outline/ghost variants, optional qty stepper,
  optional price-on-button
- **Stock Status** — In stock / Only X left / Out of stock with
  configurable thresholds and labels. Quiet but conversion-real
- **Product Description** — full or short source, HTML allowed,
  max-lines clamp
- **Product Meta** — SKU + vendor + selected-option breadcrumb in one
  configurable line

### Bricks Builder adapter (Phase 1 of task #15)
- `Shop\Builders\Bricks\BricksAdapter` — auto-registers when Bricks is
  active (detects via `BRICKS_VERSION` constant; theme or plugin both work)
- `BricksElementFactory` — generates one `\Bricks\Element` subclass
  per Shop element at runtime
- `BricksControlMap` — translates Shop control schemas to Bricks's
  native control format
- Dynamic data tags registered: `{shop_product_title}`,
  `{shop_product_price}`, `{shop_product_sku}`,
  `{shop_product_description}`, `{shop_product_stock}`,
  `{shop_product_vendor}` — usable inside any Bricks text/heading element
- `shop_products` query type registered (implementation lands when
  query control wiring ships next chunk)

### Coming next
- Layout primitives (Section, Column, Heading, Image, Button, Spacer, Divider)
- Commerce elements (Cart drawer trigger, Mini cart, Discount input)
- Pure page builder (Preact + motion-one editor, AI command palette)
- Elementor + Gutenberg adapters
- Single-product / archive / cart / checkout / received default templates

## [0.11.0] — 2026-06-05

AI integration for importers + HPOS-style order export. The PDF/Image/
Figma importers now actually work (gated on Anthropic API key), and Woo
extensions can optionally read Therum orders through Woo's HPOS surface.

### AI integration (closes task #11)
- `Shop\AI\ClaudeClient` — minimal HTTP wrapper for Anthropic Messages
  API. No SDK dependency. Tool-use forced output for structured
  extraction. ~150 lines
- `Shop\AI\ProductExtractionTool` — canonical JSON schema for
  "extract products from this image." Used by all three vision importers
- `PdfImporter` wired end-to-end:
  - Imagick renders each page to 1500px JPEG at 200dpi
  - Vision call with extract_products tool per page
  - If model returns `image_bbox`, crop the page to that region and
    sideload as the product image
  - MAX_PAGES default 50 (filterable via `shop_pdf_max_pages`) keeps
    any single import under a dollar at typical model pricing
- `ImageImporter` wired end-to-end: sideload original → vision → one
  PreviewProduct per image
- `FigmaImporter` wired end-to-end:
  - Figma REST API walks the file tree
  - Detects FRAME / COMPONENT / INSTANCE nodes named "card", "product",
    "item", or "catalog" as candidate product cards
  - `/v1/images` endpoint renders candidates to PNG
  - Each PNG flows through the same vision extraction
- Configuration:
  - `define( 'SHOP_ANTHROPIC_API_KEY', '...' )` enables PDF + Image
  - `define( 'SHOP_FIGMA_API_TOKEN', '...' )` enables Figma
  - `define( 'SHOP_ANTHROPIC_MODEL', '...' )` optionally pins model

### HPOS-style order export (closes task #6)
- `Shop\Compat\HposOrderAdapter` — opt-in adapter exposing Therum
  orders through Woo's order class system
- `Shop\Compat\ShopWcOrderWrapper` — extends WC_Order so extensions
  type-hinted against WC_Order accept our wrapped instances
- Setting: `shop_hpos_export` (default off). Registered under
  `shop_compat` option group
- Namespaced IDs (≥ 90,000,000,000) so there's never collision with
  native Woo order IDs
- Read-only by design — writes through this surface silently no-op.
  Therum is the system of record; mutations go through Shop's admin
  or REST endpoints
- Known v1 limits documented in `HposOrderAdapter`:
  - List-style order queries don't federate (Shop → Orders for ours,
    Woo's order list for Woo's). Federation is a future enhancement
  - Niche WC_Order getters return reasonable empties
- Wires up on `woocommerce_init` only when the setting is on, so it's
  free when off

## [0.10.0] — 2026-06-05

Universal exporter + product feeds. CSV / Markdown / Google Shopping
XML / Meta Catalog CSV / TikTok CSV all working. Public feed URLs
cached 15 min. Vendor-aware color/size normalization via the dictionary
so feeds pass platform validators by default.

### Exporter architecture (task #12 + #7)
- `Shop\Exporters\Exporter` interface — `id`, `displayName`, `mimeType`,
  `extension`, `export( ExportQuery )`
- `Shop\Exporters\ExportQuery` — filter spec (status, search, podProvider,
  ids, limit, offset, siteUrl)
- `Shop\Exporters\ExportResult` — in-memory body OR tmpfile path
- `Shop\Exporters\CatalogReader` — generator that yields Product DTOs;
  exporters never touch SQL or Woo APIs directly
- `Shop\Services\ExporterRegistry` — registry with pluggable
  `shop_register_exporters` filter

### Exporters shipped working
- **CSV** — generic, one row per variant for variable products,
  round-trips through CsvImporter
- **Markdown** — heading hierarchy + key:value meta block +
  embedded images, round-trips through MarkdownImporter
- **Google Shopping XML** — full RSS 2.0 with `g:` namespace, item
  per variant with item_group_id linking back to parent, vendor color
  normalization
- **Meta Catalog CSV** — Facebook + Instagram, required columns plus
  sale_price / item_group_id / color / size when present
- **TikTok Shop CSV** — full spec with age_group/gender defaults

### Public feed URLs (cached 15 min via transient)
- `GET /wp-json/shop/v1/feeds/google-shopping.xml` — Google Merchant Center
- `GET /wp-json/shop/v1/feeds/meta-catalog.csv` — Meta Commerce Manager
- `GET /wp-json/shop/v1/feeds/tiktok-feed.csv` — TikTok Shop
- No auth — these are designed to be crawled

### Admin export
- `GET  /shop/v1/admin/exporters` — list available formats
- `POST /shop/v1/admin/export` — { format, status?, search?, pod_provider?, ids? }
- Streams the result back with Content-Disposition so it downloads
- Same exporter implementations power both feeds and admin export

### Vendor normalization
- Google / Meta / TikTok feeds all route variant color + size through
  `VendorDictionaryService::translate()` before emit. Printful's
  "Ocean" / "Athletic Heather" become Google-compliant "Blue" /
  "Grey" automatically once the dictionary is populated

## [0.9.0] — 2026-06-05

Multi-vendor order routing and vendor option dictionary. The 1-shirt-
5-colors-5-vendors case now works end-to-end: a cart with lines from
different vendors creates ONE customer-facing order that splits into
N shipments routed to N vendors.

### Variants + POD routing (task #2)
- `Shop\Repositories\AttributeRepository` — reads global attributes,
  their values for a product, and provides:
  - `matchVariant( productId, [ 'color' => 'red', 'size' => 'lg' ] )` —
    grouped query that resolves an option selection to a single
    variant_id. Used by the product page when the customer picks an
    option set
  - `optionsForVariant( id )` — reverse lookup for cart line display
- `Shop\Models\OrderShipment` — DTO for the sub-shipment record
- `Shop\Repositories\OrderShipmentRepository` — read, setQuote,
  markShipped, markDelivered
- `OrderRepository::createFromCart` rewritten to GROUP by vendor:
  - One `order_shipments` row per unique `pod_provider` in the cart
  - Local-stock or unrouted lines collect under one 'local' shipment
  - Each `order_items` row carries `shipment_id` so the line's vendor
    is always one join away
- `Shop\Events\ShipmentReadyToRoute` — fired per shipment after OrderPaid
- `Shop\Services\VendorRouter` — subscribes to OrderPaid, queues one
  ShipmentReadyToRoute per shipment. Subscribers (POD plugins, Nexus,
  WooOrderMirror) listen for the routing event and call the vendor's
  fulfillment API
- Order snapshots on line items now include pod_product_id /
  pod_variant_id so vendor calls have the IDs they need without a
  re-lookup

### Vendor option dictionary (task #3)
- `Shop\Repositories\VendorOptionTermsRepository` — CRUD on
  `vendor_option_terms`. Upsert uses ON CONFLICT(provider, type, source)
  so re-confirming a mapping just updates the canonical_term
- `Shop\Services\VendorDictionaryService` — three operations:
  - `translate()` — look up canonical term for a vendor's source term
  - `confirm()` — admin-confirmed mapping (overrides any auto guess)
  - `suggest()` — the "learn over time" path. Looks for an existing
    mapping, falls back to Levenshtein + substring fuzzy match against
    known canonical terms. Matches ≥ 0.7 confidence get recorded as
    `auto` and surfaced for admin review; below threshold returns null
    and the admin hand-maps once
- The result: by product 50 with a given vendor (Printful, Printify,
  PodPartner, TapStitch, PodPluser), the merge / import flow rarely
  needs manual mapping
- REST surface for admin dictionary management:
  - `GET    /shop/v1/admin/dictionary` — list + filter by provider /
    option_type / confidence
  - `POST   /shop/v1/admin/dictionary/confirm` — confirm a mapping
  - `POST   /shop/v1/admin/dictionary/suggest` — get a suggestion
  - `DELETE /shop/v1/admin/dictionary` — remove a mapping
- All routes gated on `manage_woocommerce` / `manage_options`

## [0.8.0] — 2026-06-05

Coupons and refunds — two complete revenue-facing features. Schema landed
in 0.1.1; this release wires the services, pipeline, REST, and PSP
integration.

### Coupons (task #4)
- `Shop\Models\Coupon` — DTO with TYPE_*, SCOPE_*, STATUS_* constants
- `Shop\Repositories\CouponRepository` — findByCode, redemption ledger,
  per-customer usage count
- `Shop\Services\CouponService` — apply, remove, validate-on-recalc,
  recordRedemptionsForOrder, releaseRedemptionsForOrder
- `Shop\Pipelines\Steps\CouponStep` — runs after SubtotalStep:
  - Percent: % off the cart subtotal (or scoped subtotal)
  - Fixed cart: flat cents off subtotal, distributed proportionally
  - Fixed product: flat cents off per matching line (capped at line_total)
  - Free shipping: marker — ShippingStep zeros shipping for matching scope
  - Discount caps at subtotal so grand total never goes negative
  - Proportional distribution across lines so refund/cancel math is honest
- Scope: cart / product / variant / vendor (pod_provider slug)
- Validation enforced on apply AND every recalc:
  - active status, date window, global usage_limit, per-user limit,
    minimum_amount, maximum_amount, individual_use stacking ban
- REST:
  - `POST   /shop/v1/cart/coupons`          — { code }
  - `DELETE /shop/v1/cart/coupons/{id}`
- Pipeline now uses `resolveDefault( $container )` to wire CouponStep's
  CouponService + ProductRepository deps

### Refunds (task #5)
- `Shop\Models\Refund` — DTO
- `Shop\Repositories\RefundRepository` — create, mark complete/failed,
  attachLines, totalRefundedFor (sum of completed refunds)
- `Shop\Services\RefundService::refund()` — full or partial:
  - Validates: order in refundable status, amount ≤ remaining balance,
    PSP intent exists
  - Creates pending refund row with UUID → used as gateway idempotency key
  - Calls `PSPGateway::refund()` (MockGateway works today; Square in #8)
  - On success: marks complete, bumps `orders.refunded_total`, flips
    order status to `refunded` when fully refunded
  - On gateway failure: marks refund failed, re-raises for caller. UUID
    is the idempotency safety net — no double refund possible
  - Releases coupon redemptions on full refund (so per-customer limits
    don't permanently burn slots)
  - Audit note added to the order
- REST:
  - `POST /shop/v1/admin/orders/{id}/refund` — { amount, reason }
  - Gated on `manage_woocommerce` / `manage_options`
- v1 supports refund-by-amount (the 90% case). Refund-by-line with
  per-line restock semantics comes when admin UI exposes the picker.

## [0.7.1] — 2026-06-05

Orders grid — same spreadsheet manager component, configured for orders.
Closes the Smart Manager parity surface.

### Orders grid
- New page: **Shop → Orders**
- Columns: order number, placed-ago, customer email, status pill,
  item count, grand total (with refunded subtotal when partial),
  payment provider + method, paid-ago
- **Inline status edit** — double-click status cell → dropdown with
  all 7 order states (pending / processing / on-hold / completed /
  cancelled / refunded / failed). Enter to save, Esc to cancel
- **Search** across order number / email / payment intent ID
- **Status filter** with all 7 states
- **Bulk actions** — Set status → Processing / On hold / Completed /
  Cancelled / Refunded. No hard-delete (orders are immutable for audit)
- **Sort** by number, placed-date, status, total, paid-date
- Item count hydrated via single GROUP BY query — no N+1

### REST surface added
- `GET   /shop/v1/admin/orders` — list with same shape as products
- `PATCH /shop/v1/admin/orders/{id}` — status + internal notes only
- `POST  /shop/v1/admin/orders/bulk` — status + delete (= soft-cancel)

### Architecture
- `Shop\Admin\OrdersPage` — page shell, mirrors ProductsPage pattern
- `assets/admin/orders-grid.js` — ~270 lines, full grid mechanics
- AdminController extended with three order routes
- Same `shop-grid` CSS classes — no styling duplication

## [0.7.0] — 2026-06-05

Spreadsheet-style product manager — replaces the need for plugins like
"Smart Manager." Native to Shop, works against the native catalog
(Woo-source wiring lands next chunk).

### Products grid
- New page: **Shop → Products**
- Excel-like table with: thumbnail, title, status pill, price, stock,
  SKU, type tags (Variable / POD / Digital / Service / Simple), updated-ago
- **Inline cell edit** — double-click or focus + Enter on any
  editable cell (title, status, price, stock, sku) → input swaps in,
  Enter commits, Esc cancels. Optimistic UI with a pulsing red save dot
- **Bulk select** — row checkbox, select-all toggles the visible page,
  shift-click for range select
- **Bulk actions** — Delete (with confirm), Duplicate (copies product
  + all variants, names "(copy)" and resets to draft), Set status
  → Active / Draft / Archived
- **Search** debounced 250 ms across title / SKU / slug
- **Status filter** dropdown
- **Column sort** — click any header with a sort arrow; toggles asc/desc
- **Pagination** — 50/page; prev/next + "Page N / M"

### REST surface
- `GET  /shop/v1/admin/products` — list with search/sort/filter/paginate
- `PATCH /shop/v1/admin/products/{id}` — single-field inline edit
- `POST /shop/v1/admin/products/bulk` — { action, ids, value?, field? }
- Whitelisted editable fields (title, slug, status, price, compare_at,
  cost, sku, stock_qty, has_variants, is_shippable, is_digital, is_pod,
  track_inventory). Anything else silently ignored
- Whitelisted sort columns — no arbitrary ORDER BY injection
- All routes gated on `manage_woocommerce` / `manage_options`

### Architecture
- `Shop\Rest\AdminController` — products list/patch/bulk routes
- `Shop\Admin\ProductsPage` — page shell + column config
- `assets/admin/products-grid.js` — ~350 lines vanilla, full grid mechanics
- `assets/admin/admin.css` — grid styles appended

### Still pending in #13 / next chunks
- Orders grid (same component, different config)
- Column visibility / reorder / freeze-left
- Saved views per user
- Undo / redo
- Per-column filter pills
- CSV import/export hooks into #11 / #12
- Keyboard nav between editable cells (arrows + Tab cycle)
- Woo-mode write-through (currently PATCH only updates SQLite; Woo-sourced
  products need their PATCH to flow back to wp_postmeta)

## [0.6.0] — 2026-06-05

Admin UI lands. Native WP-admin chrome with Therum accents — no React,
no app shell. Two pages ship in this chunk; product / order list +
editor come in a follow-up.

### Admin
- New top-level "Shop" menu, position 58, dashicon cart
- `Shop\Admin\AdminMenu` — registers pages, enqueues assets per-page
- `Shop\Admin\SettingsPage` — three sections:
  - **Cart experience**: presentation dropdown (Studio / Counter /
    Vitrine / Mini / None), floating button position
  - **Checkout**: presentation dropdown (Classic / Therum / Sequence)
  - **Catalog source**: native vs WooCommerce. The Woo option is
    disabled until Woo is detected; we show an inline hint explaining
    what flipping to Woo does
- `Shop\Admin\ImporterPage` — three-stage wizard:
  - **Source**: file upload or URL paste, optional importer pin
  - **Preview**: grid of detected products with confidence bars (low
    confidence flagged amber), issues list per product, source ref,
    per-card include toggle
  - **Done**: imported-count, link to Shop home
- All admin pages gate on `manage_woocommerce` capability with
  `manage_options` as fallback

### Assets
- `assets/admin/admin.css` — section cards, preview grid, confidence
  bars, native WP form rhythm
- `assets/admin/importer.js` — ~250 lines vanilla, drives the
  preview/commit flow against the REST endpoints. No framework.

### REST hookup
- ImporterController routes are now usable through the wizard. The
  curl examples in the 0.5.0 notes still work; the wizard is just
  the friendlier surface.

## [0.5.0] — 2026-06-05

Universal catalog importer scaffold + ProductWriter. Three formats land
fully working (CSV/TSV, Markdown/plain text, URL/JSON-LD). Three more
are scaffolded behind feature flags (PDF, image, Figma) — they activate
when the AI integration ships next chunk. All importers share one
preview-then-commit flow so the admin sees what's about to land before
anything writes to the database.

### Importer architecture
- `Shop\Importers\Importer` interface — one impl per source format
- `Shop\Importers\ImportSource` — file / URL / text envelope
- `Shop\Importers\PreviewProduct` + `PreviewVariant` — proposed product
  with detection metadata (confidence, source_ref, issues)
- `Shop\Importers\ImportResult` — preview wrapper with importer-level
  summary
- `Shop\Services\ImporterRegistry` — pick(id) or route(source)
- `Shop\Services\ProductWriter` — bulk inserts PreviewProducts into our
  SQLite; sideloads images to the media library

### Importers shipped working
- **CSV / TSV** — heuristic column detection (title/desc/sku/price/stock/
  image plus color/size/material). European-vs-US decimal handling.
  Variant collapsing: same title + SKU stem across rows merges into one
  product with PreviewVariants. AI fallback hook in place for stubborn
  files (activates with Anthropic key)
- **Markdown / plain text** — heading hierarchy = products. Key:value
  meta block (SKU, Stock). Markdown images. Currency regex anywhere
  in the block
- **URL** — JSON-LD Product schema (Shopify, Squarespace, BigCommerce,
  modern Woo, Webflow). Open Graph fallback when JSON-LD absent.
  Multi-product pages emit one PreviewProduct per Product schema block

### Importers scaffolded (activate next chunk)
- **PDF** — vision-model approach: render page → Claude vision →
  bounded-box crop → PreviewProducts. Hooks behind
  `SHOP_ANTHROPIC_API_KEY`
- **Single image** — sideload → Claude vision → one PreviewProduct.
  Same key gate
- **Figma** — Figma REST API → frame detection → render → PreviewProducts.
  Behind `SHOP_FIGMA_API_TOKEN`

### REST surface
- `GET  /shop/v1/import/options` — list registered importers
- `POST /shop/v1/import/preview` — upload file / URL / text, returns
  preview (no DB writes)
- `POST /shop/v1/import/commit`  — confirm reviewed previews, writes to
  SQLite. Stateless — full preview payload round-trips back on commit
- Auth: `manage_woocommerce` or `manage_options` capability

### Admin extension point
- `shop_register_importers` action fires on registry build — plugins can
  add custom importers without touching core

### Coming next
- Admin upload UI (lives in milestone #9)
- AI integration for PDF / image / Figma (own chunk)
- WP-CLI: `wp shop:import <file>` for batch jobs
- Catalog exporter as the symmetric pair (milestone #12)

## [0.4.0] — 2026-06-05

Woo product interop. Stores already running WooCommerce can install Shop
and immediately see their catalog driving our cart/checkout — no migration,
no data copy. Paid orders mirror back to WC_Orders so Printful, Printify,
PodPartner, TapStitch, and PodPluser Woo plugins fulfill exactly as they
do today.

### Catalog
- `Shop\Repositories\ProductRepository` is now an INTERFACE. Two
  implementations behind it:
  - `NativeProductRepository` — reads our SQLite (existing behavior)
  - `WooProductRepository` — reads `wp_posts` + postmeta via `wc_get_product()`
- Container picks the impl from the `shop_product_source` setting.
  On first install, auto-defaults to `woo` if WooCommerce is detected,
  else `native`.
- POD plugin postmeta is mapped onto our pod_provider / pod_product_id /
  pod_variant_id columns. Out-of-the-box support for:
  - Printful (`_printful_*`)
  - Printify (`_printify_*`)
  - PodPartner (`_podpartner_*`)
  - TapStitch (`_tapstitch_*`)
  - PodPluser (`_podpluser_*`)
- Additional providers are pluggable via the `shop_woo_pod_providers` filter.

### Order mirror
- New `Shop\Services\WooOrderMirror` subscribes to `OrderPaid`. When the
  catalog source is `woo`, every paid Therum order spawns a matching
  `wc-processing` WC_Order with line items pointing at the original Woo
  product/variation IDs. POD plugins pick it up and fulfill normally.
- Cross-links between the two records: WC_Order carries
  `_shop_order_id` + `_shop_order_number` meta; the Therum order has a
  system note pointing at the WC order ID.
- Failure-safe: if WC_Order creation fails, the Therum order stays paid
  and a note is added. No customer refund. Operators retry via CLI
  (planned: `wp shop:mirror-order <number>`).

### Settings
- New option: `shop_product_source` (`native` | `woo`). Default computed
  on first activation by detecting Woo.
- Registered under the `shop_catalog` option group, ready for the admin
  UI in milestone #9.

### Stock handling
- In Woo mode, stock checks go through `WC_Product::has_enough_stock()`
  so we see the freshest count and don't double-book against Woo-side
  carts that may still be in play.

### WooCommerce compatibility declarations
- Declared compatibility with `custom_order_tables` (HPOS), so modern Woo
  installs (8.2+) no longer surface the incompatibility warning. We don't
  query `wp_posts` for orders; any WC_Order we create flows through the
  HPOS-aware `wc_create_order()` factory.
- Declared compatibility with `cart_checkout_blocks` — we replace those
  surfaces entirely with our templates, no conflict possible.
- Declared compatibility with `product_block_editor` — we only READ
  products via `wc_get_product()`, so we're transparent to whichever
  editor the admin uses.

### Performance
- Request-scoped product + variant cache in WooProductRepository.
  Cart render passes used to hit `wc_get_product()` 3N times for N line
  items (drawer + FAB badge + REST refresh); now once.

### Compat notes
- Woo's own cart/checkout pages stay functional but should be removed
  from your nav once Shop is installed — replaced by `[shop_cart]` /
  `[shop_checkout]`.
- This release closes the path described in the design phase: Phase 1
  (Woo source + Shop UI) ships today; Phase 2 (Nexus → SQLite direct)
  arrives when the Nexus connector layer lands.

## [0.3.0] — 2026-06-04

Presentation system landed. Four named cart patterns and three named
checkout patterns wired through the renderers and exposed via settings.
Studio cart and Classic checkout fully ported from the design previews;
other variants stubbed and fall through to their working sibling for v0.3.0,
with full ports landing chunk-by-chunk in subsequent releases.

### Cart presentation
- `Shop\Services\CartRenderer` gained named-mode constants: `MODE_STUDIO`,
  `MODE_COUNTER`, `MODE_ATELIER`, `MODE_VITRINE` (plus the existing
  legacy modes). `ALL_MODES` exposes the full list.
- Per-mode `contents()` rendering — Studio + Atelier share the thumbnail
  layout; Counter and Vitrine have dedicated stubs ready for porting.
- New option `shop_cart_presentation` (falls back to legacy
  `shop_cart_default_mode` on upgrade).
- New option `shop_cart_button_position`.
- Templates: `templates/cart/{studio,counter,atelier,vitrine}/{contents,shell}.php`.
  Studio is fully ported and reads vendor + variant attributes from the
  product/variant DTOs. Other shells render the Studio contents while their
  dedicated markup is filled in.

### Checkout presentation
- New `Shop\Services\CheckoutRenderer` mirrors CartRenderer. Modes:
  `MODE_CLASSIC`, `MODE_THERUM`, `MODE_SEQUENCE`.
- New option `shop_checkout_presentation` (default `classic`).
- Templates: `templates/checkout/{classic,therum,sequence}/index.php`.
  Classic is fully ported with all six payment rails (Card, Wallets,
  Pay later, Bank, Crypto, P2P) and renders line items + totals from the
  customer's live cart. Therum and Sequence stubs delegate to Classic
  until their dedicated markup ships.

### Shortcodes
- `[shop_cart]` now resolves to the configured cart presentation (was
  hard-coded to the page shell in 0.2.x).
- `[shop_checkout]` new — renders the configured checkout template.
- `[shop_order_received]` new — post-payment confirmation. Reads
  `?order=SH-...` from the URL and looks it up in `orders`.
- `templates/order-received.php` lands with empty-state + full-state
  rendering (line items, totals, system note acknowledgement).

### Plumbing
- `CartRenderer::defaultMode()` reworked to accept any of `ALL_MODES`,
  preserve back-compat with `shop_cart_default_mode`, and default to
  `studio` on fresh installs.
- Setting registration via `register_setting` under the `shop_appearance`
  option group, ready for the admin Settings panel in milestone #9.

## [0.2.0] — 2026-06-04

Architecture pivot. Out: MySQL via `dbDelta`, WP hook sprawl as the
extensibility model, free-function bootstrap. In: plugin-owned SQLite,
typed events + pipelines + DI container, namespaced PSR-4-lite autoload.
Schema is the same shape as 0.1.1, ported to SQLite DDL and integer minor
units for all money columns.

### Storage
- SQLite file at `wp-content/uploads/therum-shop/shop.sqlite`. WAL mode,
  foreign keys ON, 8 MB page cache, 5 s busy timeout
- `.htaccess` + `index.php` written into the directory to block HTTP access
  to the database file
- WordPress's own DB is untouched — Shop runs as a self-contained data island
- Money everywhere is INTEGER minor units (USD cents). Eliminates float
  rounding error; matches Stripe / Square convention
- Timestamps everywhere are INTEGER Unix epoch seconds with `unixepoch()`
  defaults

### Code architecture
- `Shop\DB` — singleton PDO connection with pragma setup + `tx()` helper
- `Shop\Schema` — full schema as ordered DDL list; `VERSION` const drives
  migration runs
- `Shop\Migrations` — forward-only runner. Idempotent DDL plus a `applyVersion()`
  hook for data migrations that can't be expressed as DDL
- `Shop\Container` — minimal DI container with `set()` / `singleton()` / `get()`.
  Explicit bindings, no autowiring magic. Reading the bootstrap tells you
  exactly what's wired
- PSR-4-lite autoload — `Shop\Foo\Bar` → `includes/Foo/Bar.php`. No Composer
  dependency
- Bumped PHP requirement to 8.1 (typed properties, readonly, match, named args)

### Deployment
- Intended install is as a MUST-USE plugin under `wp-content/mu-plugins/`.
  Always-active; no admin enable/disable; no client foot-gun on a critical
  commerce system
- `mu-plugin-loader.php` ships in the repo — copy or symlink it to
  `wp-content/mu-plugins/shop-loader.php`, and place the plugin directory at
  `wp-content/mu-plugins/shop/`. WP picks it up automatically
- Still compatible with regular-plugin installation under
  `wp-content/plugins/` — the `register_activation_hook` path is retained as
  a fallback for that case

### Cleanup
- Uninstaller deletes the SQLite file + directory AND drops legacy
  `wp_therum_*` tables from 0.1.x installs (no-op on fresh 0.2.0)
- `shop_run_migrations()` retained as a thin shim that delegates to
  `Shop\Migrations::run()` — keeps the 0.1.x activation hook working
- `shop_db_version` option deprecated; schema version now lives in the
  SQLite file itself (`schema_version` table)

### Coming in next chunk
- DTOs (`Shop\Models\Product`, `Order`, `Coupon` …)
- Typed event bus (`Shop\Events\*` + `Shop\EventBus`) with sync + queued dispatch
- Pipeline base + `CartTotalsPipeline`
- First services: `CartService`, `CouponService`, `WebhookReceiver`
- First REST endpoint: cart `add-item`, end-to-end through the new stack

## [0.1.1] — 2026-06-03

Schema additions for coupons, refunds, per-vendor shipments, idempotent
payment webhooks, and the vendor option dictionary. DB version bumped to `2`
— migrations run automatically on next admin load.

### Data model
- `therum_coupons` + `therum_coupon_redemptions` — Woo-style coupon model
  with percent / fixed_cart / fixed_product / free_shipping discount types,
  cart / product / variant / vendor scoping, per-user + global usage caps,
  date windows, individual_use (non-stack) flag
- `therum_order_shipments` — per-vendor sub-shipment on an order; carries
  its own shipping_total, tax_total, tax_source, quote_ref, tracking, status.
  Multi-vendor orders aggregate their order_items into N shipments
- `therum_refunds` + `therum_refund_items` — one refund row per event (an
  order can be partially refunded many times over its life); per-line
  breakdown with restock flag and shipping/tax allocation
- `therum_payment_events` — idempotent PSP webhook ledger. UNIQUE on
  (payment_provider, provider_event_id) is the entire dedupe story —
  duplicate webhook fires fail the INSERT and exit cleanly
- `therum_order_notes` — Woo-style append-only event log per order; system,
  admin, and customer-visible note kinds
- `therum_vendor_option_terms` — per-vendor mapping of source option terms
  to canonical terms (e.g. Printful "Ocean" → master "Blue"); supports
  smart variant merging that learns over time
- `therum_orders.refunded_total` — running refund total to avoid summing
  the refund ledger on every list query
- `therum_order_items` — added `shipment_id` (which sub-shipment the line
  belongs to), `discount_total`, `vendor_unit_cost` (margin tracking),
  `tracking_number`, `tracking_carrier`, `fulfilled_at`

### Status
- Schema-only release. No cart/checkout/payment logic yet — that lands in 0.2.0.
- Migration is additive: existing 0.1.0 installs upgrade in place on next
  admin load via the `shop_db_version` check.

## [0.1.0] — 2026-05-22

Initial pre-alpha release. Schema-first scaffolding for the native commerce engine.

### Data model
- Purpose-built schema (`includes/migrations.php`): single `products` table with capability columns (variants, shipping, digital delivery, POD routing) rather than the WooCommerce-style "everything in postmeta" approach
- `orders`, `order_items`, `customers`, `addresses`, `sessions` — minimal viable cart/checkout
- `uninstall.php` drops every Shop table — clean teardown

### Status
- Not yet functional end-to-end. Schema lands first; cart/checkout/payment plumbing follows in 0.2.0.
- Designed to compose with Nexus by Therum for pluggable payment / tax / shipping / fulfillment providers.
