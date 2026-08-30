# Route Editor (`bos_scheduling`)

Map-backed tool for **seeing and fixing crew routing** on scheduled work orders,
plus the **winterize carry-forward** rules that let this year's routing seed next
year. Lives in the existing `bos_scheduling` module.

- **Page:** `/teammates/calendar/route-editor` (Office → Calendar). Supervisor-gated
  (`administrator+administration+supervisor+site_admin+site_assistant`).
- **Controller:** `src/Controller/RouteEditorController.php`
- **Front end:** `js/route-editor.js`, `css/route-editor.css`,
  `templates/bos-scheduling-route-editor.html.twig` (library `route_editor`).
- **Shipped:** 2026-08-30, branch `feature/scheduling-route-editor`. Deployed by
  rsync (no cim/DB migration).

## What it shows

A Google map of scheduled **sprinkler WOs** over a date window
(Day / 3-Day / Week; Prev/Next shift by the window length). Routed bundles:
`sprinkler_winterizing`, `sprinkler_start_up`, `sprinkler_check_up`,
`sprinkler_repair`, `sprinkler_installation`, `backflow_testing`
(constant `ROUTED_BUNDLES` — WO-type-agnostic; extend there).

- One **route line per (day, tech)**, stops numbered in `field_scheduled_oder`.
- **Color by Day ↔ Crew** toolbar toggle. Day = one hue per calendar day (spot
  cross-day overlap); Crew = a distinct color per teammate (tell people apart —
  the Day range defaults to Crew).
- **No-location bucket:** stops whose property has no usable `field_geofield`
  (or coords outside the western-CO bounding box) are listed, never plotted.
- **Origin (shop):** `bos_scheduling.settings` `route_origin_property_id`
  (default property **50413**) → its `field_geofield`. Fail-loud if unusable.

**tz-safety:** `field_date` is a smartdate Unix timestamp; the controller selects
raw integers and formats in PHP (`America/Denver`) — never `FROM_UNIXTIME`
(the VPS runs MySQL in UTC). See `Governance/drupal_bos_gotchas.md`.

## Editing (write paths)

All writes go through the scheduling entity's normal save path (so `wo_schedule`
audit notes fire) and **suppress `field_notify_assigned_teammate`** (a bulk map
edit must never blast assignment emails). CSRF-guarded (`X-CSRF-Token`).

| Action | Endpoint | Writes | Notes |
|---|---|---|---|
| **Assign crew** | `route_editor_assign` (POST) | `field_assigned_to` (uid 0 = unassign) | Select stops (row checkboxes / per-route "select all") → pick a crew → Assign. Validates target against the active roster; `wo_schedule` logs "Re-assigned to …". |
| **Drag-reorder** | `route_editor_reorder` (POST) | `field_scheduled_oder` (1..N) | Native HTML5 drag within one route (grip handle); map redraws in place (viewport preserved). |
| **Optimize** | `route_editor_reorder` (POST) | `field_scheduled_oder` | Per-route button: greedy nearest-neighbor from the shop (client-side haversine, **no paid routing API**) — a starting order the office fine-tunes by dragging. |

Reorder/optimize also **auto-stamp `field_route_order_set = TRUE`** on the route's
stops (see below), shown as a green **✓ order set** badge.

Assignment and ordering are kept **separate**: drag only reorders *within* a route
(cross-column drops ignored); moving a stop to another crew is Assign.

## `field_route_order_set` — carrying an arranged route to next year

Boolean on `scheduling.work_order` (label **"Route order set"**), created by
`web/scripts/setup_route_order_set_field.php` (ECK/field configs skip cim; the sync
YAMLs carry the local UUIDs — live has its own UUIDs, which is fine).

- **Auto-stamped TRUE** whenever the office arranges a route in the Route Editor
  (drag-reorder or Optimize both post to `reorder()`).
- The **winterize carry-forward** treats a route-order-set route's **planned order
  as authoritative** (order tier 0, source `planned_set`) — so the office's
  arranging effort this year becomes next year's starting order, instead of being
  reconstructed from whatever order the truck happened to drive.

> ⚠ **Distinct from `field_scheduled_firm`** ("Firm"/"Tentative"), which is the
> **customer-commitment** flag — set when the office has told a customer a specific
> day (shown on the admin calendar "Firm only" filter, Dispatch, My Schedule).
> Never reuse `field_scheduled_firm` for routing/order concepts.

## Winterize carry-forward (`WinterizeCarryForwardCommands`)

`drush bos:winterize:plan` / `bos:winterize:apply` propose + apply next season's
`sprinkler_winterizing` schedule from prior-season history. Date rule (corrected
2026-08-30):

- **Calendar-date rule** — each stop keeps last year's **month/day** in the target
  year, so it lands **one weekday later** (the season keeps its sequence + pace and
  slides forward a weekday). Replaced the original nth-weekday-of-month mapping,
  which preserved each customer's weekday but **scrambled the route order** year to
  year (first-Wednesday customers leapfrogged to the 4th week — the bug caught on
  Gerald Reeves' route).
- **Weekend rule:** Sat & Sun roll forward to the next Monday (crews work Mon–Fri;
  flag `weekend_roll`, non-blocking). Only genuine office closures hold for review.
- **Season floor:** candidate WOs must be **created Apr 1 – Dec 31** of the target
  year. A winterize WO created Jan–Mar is a prior-season catch-up (a property
  forgotten in the fall and done in February) — excluded from the season cycle.
- **Order precedence:** route-order-set (authoritative) → sign-off ts → clock →
  status → planned → none. **Tech** = actual signer (`wo_complete_info.field_signed_off_by`),
  planned assignee fallback. **Source** = latest prior winterize (Aug 15 – Dec 31
  window per source year, so Feb catch-ups never anchor).

The one-time live correction that re-dated the already-applied 2026 records to this
rule is `web/scripts/winterize_redate_apply.php` (dry-run-gated; 443 of 459 changed;
16 new-customers w/o prior source left as-is).

## Follow-ups

- Assign crews to the ~15 unassigned new-customer winterize stops (no prior year).
- Per-route **Optimize** on "merged Mondays" (last year's Fri+Sat roll together).
- New customers have no prior history until completed this year (then they seed 2027).
