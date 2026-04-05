# BOS Module — equipment_inspection_workflow

Module: equipment_inspection_workflow
Package: Custom

## Purpose
Automation for the fleet inspection system. Handles defect auto-creation
from approved inspections, maintenance event defect closure, and vehicle
status synchronization.

---

## Hook: hook_entity_update — equipment_inspection

**Trigger:** `field_review_status` transitions to `approved` (not already approved).

**Actions:**
1. Updates vehicle `field_last_inspection_date` from inspection date
2. Updates vehicle `field_current_mileage` from odometer (only if higher than current)
3. Evaluates 18 defect auto-create rules
4. Checks ABS issue code separately

### Defect Auto-Create Rules (18)

| Field | Trigger Values | Severity | Category |
|---|---|---|---|
| `field_safe_to_operate` | no | out_of_service | safety |
| `field_safe_to_operate` | limited_use | repair_soon | safety |
| `field_warning_light_status` | present | repair_soon | lights_electrical |
| `field_visible_leak_status` | major | urgent | leak |
| `field_brake_fluid_status` | low, issue | urgent | brakes |
| `field_tires_status` | damaged | urgent | tire_wheel |
| `field_tread_status` | unsafe | urgent | tire_wheel |
| `field_windshield_status` | severe | urgent | glass_visibility |
| `field_seatbelt_status` | issue | repair_soon | safety |
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

## Hook: hook_entity_insert — equipment_maintenance_event

**Trigger:** New maintenance event created with `field_related_defect` populated.

**Actions:**
1. Sets linked defect `field_defect_status` to `in_repair` (not resolved)
2. Copies `field_cost_total` to defect `field_actual_repair_cost`

Resolution requires explicit confirmation — maintenance event creation
does not auto-close defects. Only transitions from open/scheduled to in_repair.

## Hook: hook_entity_update — equipment_maintenance_event

**Trigger:** `field_verified_complete` flips from FALSE to TRUE.

**Actions:**
1. Sets linked defect `field_defect_status` to `resolved`
2. Sets defect `field_resolved_on` from maintenance event date

This is the only path to resolve a defect — verified maintenance completion.

---

## Hook: hook_entity_update — equipment_defect

**Trigger:** `field_out_of_service_required` flips from FALSE to TRUE.

**Actions:**
1. Sets referenced vehicle `field_status` to Needing Repairs (TID 1302)

---

## Error Handling
All automation is wrapped in try/catch. Failures are logged to
`equipment_inspection_workflow` logger channel. Automation failures
never block the parent entity save.

---

## Dependencies
- Requires equipment_inspection, equipment_defect, equipment_maintenance_event entity types
- Requires equipment:vehicles bundle with fleet management fields
- Equipment status TID from `fleet_inspection_workflow.settings` config (`status_needing_repairs_tid`)
- Default: 1302 (Needing Repairs) — configurable, not hardcoded or name-resolved

## Hook: hook_entity_presave — equipment_maintenance_event

**Actions:**
- Auto-calculates `field_cost_total` from `field_cost_parts` + `field_cost_labor`
  when parts/labor are set but total is empty or zero

---

## Configuration

Config key: `equipment_inspection_workflow.settings`

| Setting | Default | Purpose |
|---|---|---|
| `status_needing_repairs_tid` | 1302 | Equipment status TID for "Needing Repairs" |

---

## Bundle-Specific Defect Trigger Coverage

### Phase 1 — Implemented
- **vehicles**: Full coverage (18 rules — fluids, mechanical, lights, safety, ABS)
- **trailers**: Covered via trailer-specific rules (hitch, lights, tires, suspension)

### Phase 2 — Planned (not yet implemented)
- **heavy_equipment**: Needs rules for hydraulic_fluid_status, tracks_tires_status,
  bucket_attachment_status, hydraulic_hoses_status, backup_alarm_status
- **mowers**: Needs rules for blades_status (damaged), belts_status (damaged),
  deck_status (damaged), fuel_system_status (issue)
- **sprayers**: Needs rules for pump_status (issue), nozzles_status (clogged),
  pressure_status (high/low), calibration_status (overdue), visible leaks
- **standard**: Needs rules for operational_status (non_op), visual_condition (damaged)

Bundle-specific rules will follow the same pattern: field + trigger values → severity + category.
They will be added to `_equipment_inspection_defect_rules()` with bundle guards.

---

## Module Naming Note

This module is named `fleet_inspection_workflow` (legacy name from when
the system was vehicle-only). It now handles ALL equipment types. The
module name was not changed because renaming Drupal modules is disruptive
(requires uninstall/reinstall cycle). The entity types it operates on
are correctly named `equipment_*`.

---

Created: April 2026
Updated: April 2026 — generalized to all equipment, config-driven TID,
safety category, cost auto-calc, verification gate, bundle coverage plan
