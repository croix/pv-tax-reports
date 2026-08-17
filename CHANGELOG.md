# Changelog

All notable changes to this plugin are documented here. Versions correspond
to [GitHub releases](https://github.com/croix/pv-tax-reports/releases), whose
own notes carry the full commit-level detail — this is the short version.

## v0.6.0 — 2026-08-16

- Built Report 1, **Inventory Valuation**: pick a date, get quantity, unit
  cost, and extended value per product, plus a total and CSV export. Uses
  each day's frozen snapshot cost, so a later cost change never restates a
  past valuation. A date before recording started, or a day the snapshotter
  missed, says so rather than showing a total that looks real.
- Built Report 2, **Taxable Sales**: gross sales, taxable sales, and tax
  collected for a date range, broken out by rate, with CSV export. Built on
  WooCommerce's order CRUD (HPOS-safe), refunds net out in the period the
  refund happened, and a sale taxed under two stacked rates counts its full
  base under each without double-counting the overall total.

## v0.5.3 — 2026-08-16

- Fixed a broken "Variation of 0" link for orphaned variations (a variation
  with no real parent product, typically left over from a broken
  print-on-demand catalog sync) — now shown as an honest, explained state
  instead of a dead link.

## v0.5.2 — 2026-08-16

- Added **Check for updates now** (Tax Reports Settings → Updates), since a
  release just published on GitHub could otherwise sit uncached for hours
  behind two stacked caches (this plugin's own 6-hour release-lookup cache,
  and WordPress's own plugin-update cache).

## v0.5.1 — 2026-08-16

- The "products with no BOM match" list now names a variation's parent
  product and links straight to its edit screen — WordPress's own product
  search can't find a variation by name or SKU, and a variation's category
  lives on the parent, not on itself.

## v0.5.0 — 2026-08-16

- Added a one-time (but permanent, reusable) **migration from a pre-native
  COGS plugin** — SkyVerge's or YITH's "Cost of Goods," both storing under
  `_wc_cog_cost` — into WooCommerce's own native Cost of Goods Sold field.
  Never overwrites a product that already has a native value.
- Removed the temporary diagnostics added in v0.4.2 now that they'd served
  their purpose (finding the above).

## v0.4.2 — 2026-08-16

- Added a temporary diagnostics table to the status screen to track down a
  report of costed products showing as uncosted on the sync preview.

## v0.4.1 — 2026-08-16

- Fixed the wrong fallback Cost of Goods Sold meta key: `_cogs_value` is
  WooCommerce's *order item* meta key, not the product key
  (`_cogs_total_value`). Any install that had already saved the settings
  screen had the wrong value baked in; a one-time migration corrects it.

## v0.4.0 — 2026-08-16

- The cost sync now always asks BOM for discontinued options too
  (`includeInactive=1`), since a recipe marked discontinued doesn't stop
  having inventory on the shelf that same day. Discontinued options are
  labelled everywhere they appear.
- Grouped and bundle products are now always excluded from the cost sync —
  they compose already-mapped simple products rather than carrying their own
  BOM cost. A store's non-food categories (e.g. clothing) can be excluded too,
  via the new **Excluded categories** setting.

## v0.3.0 — 2026-08-16

- BOM options with no matching product now show size, unit, container, and
  any operator label, so near-identical options can be told apart.
- Added a manual mapping picker: an unmatched product can be pinned to a
  specific BOM option from a dropdown, right on the sync preview, with a
  **Clear mapping** control to undo it — no more hand-editing product meta.

## v0.2.1 — 2026-08-16

- Added the `Update URI` plugin header so WordPress skips an unnecessary
  wordpress.org lookup on every update check.

## v0.2.0 — 2026-08-16

First tagged release, bundling Phase 0 (skeleton, settings, GitHub release
updater, PHPCS/PHPStan/PHPUnit), Phase 3 (nightly stock snapshots), and
Phase 4 (order COGS capture at sale) from the initial commit, plus:

- Built Phase 2, the **cost sync from BOM**: pull current costs, match by SKU
  against both MPN and UPC, preview every change before writing, and archive
  every pulled option (matched or not) to the cost cache.

Phases 3 and 4 were built ahead of 1 and 2 on purpose — they only produce
data from the day they ship, so every week not running is a week of history
that can't be reconstructed later.
