# wo_profit — Work Order Cost & Profit (Stage 1)

**Added 2026-08-03. Live.** Supervisor/admin-only **cost & gross-profit per work
order**: stores + freezes `field_wo_cost` / `field_wo_profit` at completion and
shows a role-gated **Cost & Profit** panel on the WO page (live estimate while
in progress, frozen figures once recorded). Stage 1 of the cost/profit roadmap
item; Stage 2 (profit-by-service-line dashboard) is tracked separately.

## The cost model

Revenue is already computed by the `wo_*` billing modules (`field_wo_total` +
component totals). This module adds the **cost** side, mirroring the existing
billing rollup queries but summing the **cost** columns instead of the charged
ones:

| Cost line | Source |
|---|---|
| **Labor** | **Σ `wo_time_clock.field_total_time`** (live sum of the WO's clock entries) × **blended labor cost/hr** (`business_setting.field_blended_labor_cost`). Uses the clock entries directly, **not** the WO's `field_total_time` roll-up — that roll-up is only filled at sign-off, so reading it showed 0 labor for an in-progress WO that already had hours logged. |
| **Materials** | Σ `wo_material_list_item.field_subtotal` (cost basis = unit cost × qty; the *charged* side uses `field_subtotal_w_markup`, so the difference is the markup margin) |
| **Chemicals** | Σ `wo_chemicals_used.field_subtotal` (no markup field → charged at cost, nets out) |
| **Rentals** | Σ `COALESCE(field_receipt_total_cost, field_hourly_rate × field_hours)` (pass-through) |
| **Dump** | `field_dump_fee_total` (pass-through) |

`profit = field_wo_total (revenue) − total cost`. Margin = profit / revenue.

**This is JOB-LEVEL GROSS MARGIN** — it deliberately excludes fuel/vehicle wear
(the trip fee sits in revenue with no cost line), company overhead, and equipment
depreciation. The panel says so. It answers "is this job making money," not
accounting net profit.

The margin comes from **materials** (markup) and **labor** (billing rate > the
$27 blended cost). Chemicals/rentals/dump are pass-through (~0 margin).

## Components

- **`WoProfitCalculator`** service (`wo_profit.calculator`) — `calculate($wo)`
  returns the full breakdown; `blendedLaborRate()` reads the business setting.
  Reused by both the freeze (presave) and the panel (view), so they never drift.
- **`hook_entity_presave`** (generic, **not** `hook_ENTITY_TYPE_presave`) —
  freezes `field_wo_cost`/`field_wo_profit` while status is **Complete (1097)**,
  matching the billing-total freeze. Uses the generic hook + **module weight
  100** (set in `hook_install`) because the `wo_*` bundle modules set
  `field_wo_total` in *generic* `entity_presave`, which fires **after** the
  type-specific batch — a type-specific hook here would read a stale (0) revenue.
- **`hook_entity_view`** — role-gated panel on the full WO page. Three states:
  - **Recorded** (1097/1281/1504/1283 with a stored value): frozen Revenue /
    Total cost / Profit / Margin — "Cost & Profit — recorded at completion".
  - **In progress with revenue** (rare — `field_wo_total` set pre-record): live
    Revenue / Profit / Margin.
  - **In progress with a revenue projection** (`liveRevenue()` returns a value —
    see below): **"Cost & Profit — projected"** with **Revenue (projected)** /
    Profit / Margin and a note saying figures finalize at sign-off.
  - **In progress, no projection** (bundle not yet supported + no estimate): a
    **"Cost so far — live"** breakdown (Labor from live clock hours + Materials +
    Chemicals + Rentals + Dump) with **no Profit/Margin** and a note that revenue
    & profit finalize at completion. Avoids a misleading "loss" against $0 revenue.
  - `max-age 0` (live + permission-sensitive).

## Live revenue projection (per-bundle) — `liveRevenue()`

So open jobs show **projected profit**, not just cost. `field_wo_total` (revenue)
is only computed at sign-off, so before then `liveRevenue($wo)` projects it:

1. **Estimate first (any bundle):** if the WO has `field_estimated_price` > 0, or
   a `field_estimate` reference whose estimate has `field_estimate_total` > 0, use
   that (source `estimate`).
2. **Else, the bundle's own formula** (source `computed`), for bundles listed in
   `WoProfitCalculator::LIVE_REVENUE_BUNDLES` — a standard **hours × billable
   rate + materials (marked up) + rentals**, replicating the `wo_*` sign-off math
   (increment rounding + minimum floor) so the projection matches completion.
   - **`landscaping`** implemented — rate `field_maintenance_crew_labor` (\$65),
     `field_hour_billing_increment`, `field_general_minimum_time`.
   - **Extending:** add a bundle to `LIVE_REVENUE_BUNDLES` with its rate/
     increment/minimum fields once verified to fit the hours×rate shape.
     Structurally different services (snow per-push, spray per-gallon) need their
     own method. Bundles not listed (and without an estimate) fall back to
     cost-so-far. **Doing this per-bundle is deliberate** — it avoids duplicating
     all ~30 billing formulas at once and lets each be verified against the real
     `wo_*` math as it's added.

The trip fee is excluded from the computed projection (it's added at sign-off).
- **Permission** `view wo cost profit` (`restrict access`) → granted at install
  to `supervisor`, `administration`, `site_admin`, `administrator`. Crew +
  clients never see cost/profit.
- **Setup:** `web/scripts/setup_wo_profit_fields.php` (idempotent, entity-API —
  ECK/config_pages field configs cim-skip). Adds the blended-rate field (seeded
  **$27.00**, only if empty), and `field_wo_cost`/`field_wo_profit` to all 35
  real WO bundles (**legacy `estimate` bundle excluded** per policy).

## Notes / follow-ups

- **Blended rate = $27/hr** (wage + payroll tax + workers' comp + benefits, per
  owner 2026-08-03). Office adjusts it on the Business Settings page; labor cost
  is skipped while the rate is empty. Interim until real TimeTrax punch-cost
  (see the TimeTrax roadmap row).
- **Existing/historical WOs** only get frozen values when next saved while
  Complete; until then the panel shows a live estimate at the current rate.
  Full historical capture is a Stage 2 concern (dashboard).
- **Stage 2:** a supervisor profit-by-service-line dashboard on the stored
  fields (group by service/bundle/crew/season) — "are fertilizing WOs actually
  profitable overall?". Same pattern as Daily Recap / Teammate Ops. Roadmap:
  `ROADMAP.md` NEXT row.
