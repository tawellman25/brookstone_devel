# BOS Module — bos_spray_route_ui

Module: bos_spray_route_ui
Package: BOS Custom

Purpose:
- Adds a computed "Days Since Last Applied" Views field with color status indicator to weed spray route views
- Provides client-side JavaScript sort so most overdue properties appear first
- Attaches CSS library to spray route views

---

## Files

```
bos_spray_route_ui/
  bos_spray_route_ui.info.yml
  bos_spray_route_ui.module
  bos_spray_route_ui.libraries.yml
  bos_spray_route_ui.views.inc
  css/
    spray-route.css
  js/
    spray-route-sort.js
  src/
    Plugin/views/field/
      WeedSprayDaysField.php
```

---

## WeedSprayDaysField Views Plugin

Plugin ID: weed_spray_days_field
Registered via: bos_spray_route_ui.views.inc hook_views_data()
Base table: property_spraying_info_field_data

### Frequency Thresholds

| TID | Frequency | Threshold | Due Soon Buffer |
|---|---|---|---|
| 1104 | Monthly | 35 days | 5 days (warn at 30) |
| 1105 | Biweekly | 18 days | 5 days (warn at 13) |
| 1106 | On Call | No threshold | N/A |

### Status Classes and Colors

| Class | Color | Meaning |
|---|---|---|
| spray-status--ok | Green | On schedule |
| spray-status--due | Yellow | Due soon (within 5 days of threshold) |
| spray-status--overdue | Red | Past threshold |
| spray-status--on-call | Gray | On Call frequency — no schedule |
| spray-status--never | Gray | Never applied |

### Frequency Field Priority

Checks field_beds_spraying_frequency first, falls back to
field_misc_spraying_frequency if beds is empty.

### Render Output

Returns render array with #markup containing:
```html
<span class="spray-status spray-status--{status}">{days} days (OVERDUE)</span>
```

---

## hook_views_pre_render

Attaches bos_spray_route_ui/spray_route library to:
- teammate_weed_spraying_route
- admin_weed_spray_route
- admin_weed_spray_reconciliation

---

## spray-route-sort.js

Client-side sort that runs on page load via Drupal.behaviors.sprayRouteSort.

Sort order (highest priority first):
1. Overdue — sorted by days descending (most overdue first)
2. Due Soon — sorted by days descending
3. Never Applied
4. OK — sorted by days descending (closest to threshold first)
5. On Call — last

Uses data-spray-sorted attribute on table to prevent double-sort.

---

## Views Using This Field

Both views have the field added to their page_1 display (not default display):
- teammate_weed_spraying_route — page_1 display at /teammates/work-orders/spraying/weeds/route
- admin_weed_spray_route — page_1 display

Field label: "Status"
Placed after: Last Amount Applied column

---

## Days counted from last VISIT, not last spray (June 2026, branch `feature/spray-route-guard`)

> Status: built + verified locally, commit `7c8c2334`, **not yet deployed**.

`WeedSprayDaysField::render()` originally measured days from `field_last_applied_date`
only. But the weed-spray sign-off stamps `field_last_checked` on **every** visit — and
on a "checked, no spray needed" visit it sets only `field_last_checked` (leaving Last
Applied on the prior real spray). Result: a no-spray visit never reset the overdue clock,
so checked-but-not-sprayed properties read as increasingly overdue (and stuck/uncompleted
WOs whose `field_last_applied_date` never advanced looked stale forever).

Fix: count from the **most recent visit** = `max(field_last_applied_date,
field_last_checked)`. The field now iterates both, takes the latest, and shows
"Never Visited" only when neither is set. Example impact: a property checked 2026-04-27
but last sprayed 2025-09-29 dropped from **268 days → 59 days** overdue. (The capture
side — `field_last_checked` on every sign-off — was already live; this is the read side.)

The companion create-trap fix + abandoned-WO housekeeping live in `wo_weed_spraying`
(see `wo_weed_spraying_updates.md`).

---

## Status

Created: March 2026
Note: Field must be added to page_1 display specifically — not default display.
Adding to default display only will NOT show on the actual page route.
