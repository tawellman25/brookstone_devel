# BOS Entity — Fleet Defect

Entity Type ID: `fleet_defect`
Storage: ECK

## Purpose
Track actionable vehicle or trailer defects until resolved. Defects are
created from inspection findings or reported directly. A defect is not
resolved until a maintenance event closes it.

## Bundle
`standard` — Standard defect record

## Auto-Label Pattern
`Truck [vehicle number] - [category] - [date reported]`

---

## Fields (15)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_vehicle` | entity_reference → equipment:vehicles | Vehicle | Yes |
| `field_trailer` | entity_reference → equipment:trailers | Trailer | No |
| `field_source_inspection` | entity_reference → fleet_inspection | Source Inspection | No |
| `field_defect_date_reported` | datetime (date only) | Date Reported | Yes |
| `field_defect_category` | list_string | Defect Category | Yes |
| `field_defect_severity` | list_string | Defect Severity | Yes |
| `field_defect_summary` | string | Defect Summary | Yes |
| `field_defect_status` | list_string | Defect Status | Yes (default: open) |
| `field_repeat_issue` | boolean | Repeat Issue | No (default: 0) |
| `field_out_of_service_required` | boolean | Out of Service Required | No (default: 0) |
| `field_estimated_repair_cost` | decimal | Estimated Repair Cost | No |
| `field_actual_repair_cost` | decimal | Actual Repair Cost | No |
| `field_resolution_notes` | text_long | Resolution Notes | No |
| `field_resolved_on` | datetime (date only) | Resolved On | No |
| `field_assigned_to` | entity_reference → user | Assigned To | No |

### field_defect_category values
engine, transmission, brakes, steering_front_end, tire_wheel,
lights_electrical, glass_visibility, body, suspension, cooling,
leak, abs, paperwork, trailer, other

### field_defect_severity values
monitor, repair_soon, urgent, out_of_service

### field_defect_status values
open, scheduled, in_repair, resolved, deferred, retired_with_vehicle

---

## Views

### Open Defects Board
Path: `/admin/operations/fleet/open-defects`
Menu: Operations > Fleet > Open Defects
Filter: status IN (open, scheduled, in_repair, deferred)
Default sort: Truck Number ASC, then severity DESC

### Vehicle Defects EVA
Attached to: equipment:vehicles entity display
Shows defect history per vehicle, sorted newest first

---

## Defect Merge Rule (Phase 3)
Same category + open status within last 30 days = update repeat issue
flag instead of creating duplicate. New defect only if policy requires
historical granularity.

## Invariants
- A defect must only be closed when a fleet_maintenance_event references
  it and resolution is confirmed
- field_source_inspection links back to the inspection that found it
- Notes must never be the only place critical issues are stored

Created: April 2026
