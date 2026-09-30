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
| **Move to a day** | `route_editor_reschedule` (POST) | `field_date` | Select stops → pick a date → **Move** (2026-09-29). Writes `field_date` in exactly **`ScheduleWriter`'s shape** — an all-day smart-date span at **local** midnight, `duration 1439` — so a moved stop is indistinguishable from one the bulk scheduler or the carry-forward created. Midnight is computed in the **site timezone in PHP, never in SQL** (MariaDB runs UTC on this VPS while the site is America/Denver). `wo_schedule`'s presave back-fills the legacy `field_scheduled_date_and_time` daterange and `custom_date_all_day` sets the flag — neither is written here. `wo_schedule` logs the **Rescheduled** note. |

Reorder/optimize also **auto-stamp `field_route_order_set = TRUE`** on the route's
stops (see below), shown as a green **✓ order set** badge.

Assignment and ordering are kept **separate**: drag only reorders *within* a route
(cross-column drops ignored); moving a stop to another crew is Assign, and moving
it to another **day** is Move. Date and crew are two controls and two saves, which
also means two honest audit notes.

**Selecting from the map.** Clicking a pin opens its info window, which carries a
**Select this stop** control — so a route can be picked off the map itself rather
than only from the list. The info window is built as a DOM node (not an HTML
string) so that control can carry a real listener.

**A Move does NOT touch route order.** A stop keeps its sequence number on the new
day and may collide with one already there. That is deliberate: the receiving
route's driving order is a decision about how the crew drives, so it belongs to the
office — one drag or **Optimize** away — not to a guess made during the move. The
confirm dialog says so. `field_scheduled_firm` (the "we told the customer this
day" commitment) is untouched too, so moving a promised stop never quietly
un-promises it.

## THE RULE — a finished work order is never rescheduled or reassigned

`RouteEditorController::LOCKED_STATUSES` = **Complete 1097, Warrantied 1283,
Invoiced 1281, Paid 1504, Canceled 1098**. Every write endpoint here — Move,
Assign and reorder — resolves the work order's status with `lockedReason()` and
**refuses**, naming the stop and the blocking status in the response `blocked[]`,
which the UI shows in a dialog rather than reporting a silent partial success.

Three things about how this is written are load-bearing:

- **It is stated as a rule, not a list of transitions.** The older guard in
  `_wo_schedule_handle_status_update()` names 1097 and 1098 and says nothing about
  1281, 1504 or 1283 — which is precisely why the **2026-09-29 incident** hit
  *invoiced* work orders: Complete ones were protected, invoiced ones were not.
  Enumerating the case you happen to have in mind is the bug.
- **Status is read LIVE from the field table** on every call — never a value the
  browser sent, and never the one baked into the page. During that incident six
  work orders were invoiced inside the window between two runs of a repair script,
  and a clock-time cutoff got them wrong; only a live read is safe.
- **It was not hypothetical.** `DispatchController::VISIBLE_STATUSES` **includes
  1097 and 1283**, so Complete and Warrantied stops *used to be on this map* (a
  typical week showed a couple). The existing **Assign** button could therefore
  already do to a finished work order what the incident script did: a scheduling
  save fires `wo_schedule`, which writes a "Scheduled" (1091) status record back
  onto the WO. The guard closes that, not just the new endpoint.

### Finished work: greyed in the list, never on the map

A finished stop is **listed** in its day column — greyed, 🔒, status named, no
checkbox, undraggable, skipped by select-all — so the office can see what the crew
has already knocked out. It is **not plotted**: no pin, and the route line skips it,
so the map shows only the driving still to be done. **Optimize** on a part-finished
route therefore sequences only what is left, which is what you want mid-day, and the
status line reads "N stops to run · M finished".

Everything keys off the **`locked`** flag in the data payload, computed from
`LOCKED_STATUSES`; the query still fetches on `VISIBLE_STATUSES`, because filtering
at the query would empty the list too.

**Stop numbers are positions in the FULL route**, so with #2 finished the pins read
1, 3, 4. The gap is deliberate — it says "that one's done" — and it keeps the map
agreeing with the list beside it, which renumbering would not.

**Hover pairing is keyed on scheduling id, not list position.** A route holding
finished stops has fewer markers than rows, so the old positional
`highlightRow(key, idx)` / `bounceMarker(key, idx)` would have bounced the wrong pin.
Both now resolve through `overlays.rowBySid` / `overlays.markerBySid`.

The greying is **presentation**. What actually holds is the server guard above, and
the two must not be confused: the greying decides what invites an edit, the guard
decides what may be **written**, and only the guard sees current truth. This page is
long-lived and refetches only on load or after a write, so a crew can sign a job off
while it sits on someone's screen — that stop is still drawn as editable until the
next refetch, and the POST is refused and named in the dialog.

Verification: `web/scripts/test_route_editor_reschedule.php` — reversible, 20/20
against live-synced data, covering the write shape, the legacy daterange sync, the
audit note, date validation (including `2026-02-31`), and a refusal on **all five**
closed statuses for **both** Move and Assign. Over real HTTP: anon 403,
authenticated-without-CSRF-token 403, token accepted.

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
- **Cross-column drag (not built).** Dragging a stop from one route column into
  another would set day *and* crew in a single gesture — the most map-native form
  of this. `attachDrag()` currently ignores cross-column drops on purpose; opening
  it up means one drop writing two tracked fields (two audit notes, or a combined
  write), so it wants its own pass rather than riding on the Move control.
- **⚠ Reported, not fixed — `wo_schedule` writes a spurious status record on every
  scheduling update.** `_wo_schedule_handle_status_update()` decides "did the date
  change?" from `$entity->original`, which **is not populated on update in this
  Drupal version** (a documented BOS gotcha — the fix is `loadUnchanged()`). So
  `$origDate` is NULL, `$changedDate` is always TRUE, and *any* scheduling save —
  including an order-only reorder, whose docblock claims it writes no audit note —
  emits a "Scheduled for …" `wo_status_updates` record and re-flips
  `field_scheduled`. That is the mechanism behind the 2026-09-29 incident, and it
  is why a reorder produces audit noise. The `wo_status_updates` terminal-status
  guard (2026-09-29) stops it changing a closed WO's status, so the remaining
  damage is a spurious audit row. Fixing it touches **every** scheduling save
  (dispatch drag-drop, the sprinkler bulk tool, both winterize commands), so it
  deserves its own verified pass, not a rider on a UI feature.
- **Note:** the same function's local constants are misnamed — `$STATUS_CANCELLED
  = 1097` and `$STATUS_COMPLETED = 1098` have the values **swapped** relative to
  BOS (1097 = Complete, 1098 = Canceled). The guard's *behaviour* is correct (it
  covers both ids), but the names mislead anyone reading it to extend it.
