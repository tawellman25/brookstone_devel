# BOS Module — fleet_inspection_workflow

Module: fleet_inspection_workflow
Package: Custom

## Purpose
Automation for the fleet inspection system. Handles defect auto-creation
from approved inspections, maintenance event defect closure, and vehicle
status synchronization.

---

## Hook: hook_entity_update — fleet_inspection

**Trigger:** `field_review_status` transitions to `approved` (not already approved).

**Actions:**
1. Updates vehicle `field_last_inspection_date` from inspection date
2. Updates vehicle `field_current_mileage` from odometer (only if higher than current)
3. Evaluates 18 defect auto-create rules
4. Checks ABS issue code separately

### Defect Auto-Create Rules (18)

| Field | Trigger Values | Severity | Category |
|---|---|---|---|
| `field_safe_to_operate` | no | out_of_service | other |
| `field_safe_to_operate` | limited_use | repair_soon | other |
| `field_warning_light_status` | present | repair_soon | lights_electrical |
| `field_visible_leak_status` | major | urgent | leak |
| `field_brake_fluid_status` | low, issue | urgent | brakes |
| `field_tires_status` | damaged | urgent | tire_wheel |
| `field_tread_status` | unsafe | urgent | tire_wheel |
| `field_windshield_status` | severe | urgent | glass_visibility |
| `field_seatbelt_status` | issue | repair_soon | other |
| `field_headlights_status` | major_issue | urgent | lights_electrical |
| `field_taillights_status` | major_issue | urgent | lights_electrical |
| `field_brake_lights_status` | major_issue | urgent | lights_electrical |
| `field_turn_signals_status` | major_issue | urgent | lights_electrical |
| `field_trailer_hitch_status` | issue | urgent | trailer |
| `field_trlr_brake_lights_status` | major_issue | urgent | trailer |
| `field_trailer_tires_status` | damaged | urgent | trailer |
| `field_trailer_tread_status` | unsafe | urgent | trailer |
| `field_trailer_suspension_status` | unsafe | out_of_service | trailer |

Plus: `field_issue_codes` containing `abs` → repair_soon / abs

Trailer rules are skipped when `field_trailer_used` = FALSE.

### Defect Merge Rule
Same vehicle + same category + open/scheduled/in_repair/deferred status
within last 30 days = update existing defect:
- Set `field_repeat_issue` = TRUE
- Escalate severity if new finding is more severe

New defect created only when no matching open defect exists.

---

## Hook: hook_entity_insert — fleet_maintenance_event

**Trigger:** New maintenance event created with `field_related_defect` populated.

**Actions:**
1. Sets linked defect `field_defect_status` to `in_repair` (not resolved)
2. Copies `field_cost_total` to defect `field_actual_repair_cost`

Resolution requires explicit confirmation — maintenance event creation
does not auto-close defects. Only transitions from open/scheduled to in_repair.

---

## Hook: hook_entity_update — fleet_defect

**Trigger:** `field_out_of_service_required` flips from FALSE to TRUE.

**Actions:**
1. Sets referenced vehicle `field_status` to Needing Repairs (TID 1302)

---

## Error Handling
All automation is wrapped in try/catch. Failures are logged to
`fleet_inspection_workflow` logger channel. Automation failures
never block the parent entity save.

---

## Dependencies
- Requires fleet_inspection, fleet_defect, fleet_maintenance_event entity types
- Requires equipment:vehicles bundle with fleet management fields
- Equipment status "Needing Repairs" resolved by term name (not hardcoded TID)

---

Created: April 2026
