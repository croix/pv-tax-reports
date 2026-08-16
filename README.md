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
| 1 | BOM-side read-only cost API + key auth (*other repo*) | not started |
| 2 | Cost sync, product↔BOM mapping, dry-run preview, unmapped warnings | not started |
| 3 | Nightly stock snapshots + manual "snapshot now" | **done** |
| 4 | Order COGS capture at sale | **done** |
| 5 | Report 1 — inventory valuation as of a date | not started |
| 6 | Report 2 — taxable sales | not started |
| 7 | Docs | not started |

Phases 3 and 4 were built ahead of 1 and 2 on purpose: **they only produce data
from the day they ship.** Every week they are not running is a week of history
that cannot be reconstructed later. They read WooCommerce's own Cost of Goods
Sold field, which is hand-maintained today; Phase 2 changes where that number
comes from, not the shape of the history being recorded.

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
- **BOM URL and API key** — not used yet; the endpoint they point at does not
  exist. Prefer defining the key in `wp-config.php` so it never lands in the
  database:

  ```php
  define( 'PVTAX_BOM_API_KEY', '…' );
  ```

- **Cost meta key** — only consulted when WooCommerce's own Cost of Goods Sold
  API is unavailable or disabled on the installed version.

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

Practical consequence: syncing costs from BOM will be safe to run as often as
you like. It only ever affects sales that have not happened yet.

### Uncosted is not zero

A product with no cost on file records `NULL`, never `0.00`, and is counted and
surfaced on the status screen. A silently missing line is exactly the error a
tax report must not make.

## Data

Three custom tables, created with `dbDelta` on activation:

| Table | Holds |
|---|---|
| `{prefix}pvtax_costs` | Append-only cost cache from BOM (Phase 2) |
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
git tag v0.2.0 && git push origin v0.2.0
```

CI verifies the tag matches the header, builds a correctly-foldered zip, and
publishes the release. Sites pick it up through the normal update screen.

## Extension points

| Hook | Type | Purpose |
|---|---|---|
| `pvtax_product_unit_cost` | filter | Override the resolved unit cost. The seam the BOM cost cache uses in Phase 2. |
| `pvtax_cogs_capture_statuses` | filter | Order statuses that freeze costs. Default `[ 'processing', 'completed' ]`. |
| `pvtax_snapshot_captured` | action | Fires after a daily snapshot, with the run summary. |
| `pvtax_order_cogs_captured` | action | Fires after an order's costs are frozen. |

## Not accounting advice

The plugin produces figures from your own data. The costing method, and its
consistency between years, is a question for whoever files the return.
