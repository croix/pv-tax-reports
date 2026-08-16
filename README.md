# Poor Vida Tax Reports

A WooCommerce plugin that answers two questions that currently take manual work
at year end:

1. **What was in stock on a given date, and what was it worth** — costed from
   real cost of goods rather than a hand-maintained number.
2. **What were taxable sales for a period** — WooCommerce reports tax
   *collected*, not the taxable sales figure the return asks for.

Cost of goods ultimately comes from BOM, a separate Cloudflare Workers + D1
application that computes per-container cost from real recipes, ingredient
prices and packaging.

The full scope and design rationale, and the specification for BOM's cost
endpoint, are kept as internal working documents rather than published here.

## Status

| Phase | Deliverable | State |
|---|---|---|
| 0 | Plugin skeleton, settings, GitHub release updater, PHPCS/PHPStan/PHPUnit | **done** |
| 1 | BOM-side read-only cost API + key auth (*other repo*) | **done** |
| 2 | Cost sync, product↔BOM mapping, dry-run preview, unmapped warnings | **done** |
| 3 | Nightly stock snapshots + manual "snapshot now" | **done** |
| 4 | Order COGS capture at sale | **done** |
| 5 | Report 1 — inventory valuation as of a date | not started |
| 6 | Report 2 — taxable sales | not started |
| 7 | Docs | not started |

Phases 3 and 4 were built ahead of 1 and 2 on purpose: **they only produce data
from the day they ship.** Every week they are not running is a week of history
that cannot be reconstructed later. They read WooCommerce's own Cost of Goods
Sold field, which was hand-maintained until the sync in Phase 2 took over
writing it — Phase 2 changes where that number comes from, not the shape of
the history already recorded.

## Install

Download the zip from [Releases](https://github.com/croix/pv-tax-reports/releases)
and install it through **Plugins → Add New → Upload Plugin**. Subsequent updates
are offered in place through the normal WordPress update screen.

Then check **WooCommerce → Tax Reports**. It should say a nightly snapshot is
scheduled. If it does not, nothing is being recorded.

### Configuration

**WooCommerce → Tax Reports Settings.**

- **Daily snapshot time** — site local time. Late evening, after the day's
  sales.
- **BOM URL and API key** — the key is issued from BOM itself: BOM → Settings
  → API keys → Create key. The raw key is shown once, at creation; if it is
  lost, issue a new one and revoke the old one from the same screen. Prefer
  defining the key in `wp-config.php` so it never lands in the database:

  ```php
  define( 'PVTAX_BOM_API_KEY', '…' );
  ```

- **Cost meta key** — only consulted when WooCommerce's own Cost of Goods Sold
  API is unavailable or disabled on the installed version.

Once both are set, **WooCommerce → Sync Costs** pulls current costs from BOM.
See "Syncing costs from BOM" below.

## How it works

### Stock history starts at install

WooCommerce keeps no stock ledger — `_stock` is a single current value with no
history — so "what was in stock on 12/31" cannot be answered retroactively. The
plugin records a daily row per stock-managed product in
`{prefix}pvtax_stock_snapshots`, via Action Scheduler.

A daily snapshot is deliberate, rather than replaying a movement ledger
backwards: it answers the actual question directly, it is trivially auditable,
and at ~25 products it is under 10k rows a year.

`unit_cost` is copied in at snapshot time, so a later cost change cannot restate
a past valuation. Re-running a day overwrites that day and leaves every other
day alone.

### The COGS drift rule

> The product-level COGS field holds **current cost, for future sales**.
> The order line holds **what it cost when that unit actually sold**.
> A cost change updates the former and never the latter.

Without this, an ingredient price rise in March silently restates January's
profit, and last year's filed numbers move underneath you.

Order costs are frozen into `{prefix}pvtax_order_cogs` when an order first
reaches `processing` or `completed` — whichever comes first, since both count as
a real sale. Capture is idempotent against a unique key on the order item, so
the first transition wins and later ones are no-ops.

Practical consequence: syncing costs from BOM is safe to run as often as you
like. It only ever affects sales that have not happened yet.

### Syncing costs from BOM

**WooCommerce → Sync Costs.** WooCommerce's Cost of Goods Sold field was
hand-maintained before this existed — a year of that work is real, so every
sync, not just the first, is preview then apply, never a silent write:

1. **Preview** pulls current costs from BOM and shows every product's current
   value next to what it would become, plus two lists that are worth reading
   rather than skimming: **BOM options with no matching product** (expected
   for anything made for on-site service rather than sold packaged — check
   anything else) and **products with no BOM match** (their SKU matched
   neither an MPN nor a UPC in BOM; if it's a UPC that BOM hasn't recorded
   yet, add it there rather than loosening how this plugin matches).
2. **Apply** writes exactly what the preview showed. It does not re-check BOM,
   so nothing changes that was not already on screen — even if BOM's prices
   moved in the meantime. The preview expires after 10 minutes; preview again
   to sync a later state.

Matching is by SKU against **both** MPN and UPC — a WooCommerce SKU is
sometimes either — with no fuzzy matching; a wrong cost is worse than an
obvious gap.

**Products with no match can be mapped by hand, right on the preview.** Each
unmatched product gets a dropdown of every unclaimed BOM option, labelled with
enough to tell near-identical options apart — recipe name, size and unit,
container type, and MPN or UPC (e.g. "Verde Ghost Salsa — 16 ounces — bottles
— PV-SALSA-VERDE-16"). Picking one and saving sets the product's
`_pvtax_bom_package_option_id` meta to BOM's `packageOptionId`, which the
matcher checks as the last resort after MPN and UPC. A mapping made this way
invalidates the current preview — preview again to confirm it took and see the
resulting cost. A mapping made in error can be removed with **Clear mapping**
next to any row matched via override, with no need to touch product meta by
hand either way.

Every pulled option is archived to `{prefix}pvtax_costs` on apply, matched or
not, so an option unmapped today still has cost history once it is mapped
later.

**Discontinued recipes are pulled too, and flagged.** A recipe marked
discontinued in BOM doesn't stop having inventory on the shelf the same day —
it needs a cost until it sells out. The sync always asks BOM for inactive
options as well as active ones, and any that are discontinued show
"(discontinued)" wherever they appear, including next to a product that
matched one automatically by SKU.

**Some products are never worth checking against BOM at all.** Grouped and
bundle products are always left out — they compose already-mapped simple
products rather than carrying their own BOM cost, so the base products get
mapped individually and the parent doesn't need to be. A store's non-food
categories can be left out too, with the **Excluded categories** field on the
settings screen (comma-separated category slugs, e.g. `clothing`) — otherwise
they'd just clutter the unmapped-products list every sync with nothing to map
them to.

### Uncosted is not zero

A product with no cost on file records `NULL`, never `0.00`, and is counted and
surfaced on the status screen. A silently missing line is exactly the error a
tax report must not make.

## Data

Three custom tables, created with `dbDelta` on activation:

| Table | Holds |
|---|---|
| `{prefix}pvtax_costs` | Append-only cost cache from BOM |
| `{prefix}pvtax_stock_snapshots` | Daily stock per product, with the cost that day |
| `{prefix}pvtax_order_cogs` | Cost frozen at the moment of sale |

Uninstalling removes the plugin's **settings only**. The tables hold history
that cannot be reconstructed and may be needed to support a filed return;
dropping them is a manual decision.

## Development

```bash
composer install
```

```bash
composer run check
```

That runs PHPCS (WordPress standard), PHPStan level 6, and PHPUnit. Tests are
unit-level, using Brain Monkey — WordPress is not loaded.

### Releasing

Bump the `Version:` header in `pv-tax-reports.php` and the `VERSION` constant,
then push a matching tag:

```bash
git tag v0.5.0 && git push origin v0.5.0
```

CI verifies the tag matches the header, builds a correctly-foldered zip, and
publishes the release. Sites pick it up through the normal update screen.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pvtax_product_unit_cost` | filter | Override the resolved unit cost. The BOM sync does not need this — it writes the same field directly — so this is the escape hatch for anything costed some other way. |
| `pvtax_cogs_capture_statuses` | filter | Order statuses that freeze costs. Default `[ 'processing', 'completed' ]`. |
| `pvtax_excluded_product_types` | filter | Product types left out of the cost sync entirely. Default `[ 'grouped', 'bundle' ]`. |
| `pvtax_snapshot_captured` | action | Fires after a daily snapshot, with the run summary. |
| `pvtax_order_cogs_captured` | action | Fires after an order's costs are frozen. |

## Not accounting advice

The plugin produces figures from your own data. The costing method, and its
consistency between years, is a question for whoever files the return.
