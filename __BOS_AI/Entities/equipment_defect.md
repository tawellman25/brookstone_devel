# BOS Entity — Equipment Defect

Entity Type ID: `equipment_defect`
Storage: ECK

## Purpose
Track actionable equipment defects until resolved. Defects are created
from inspection findings or reported directly. A defect is not resolved
until a verified maintenance event closes it.

## Bundle
`standard` — Standard defect record

## Fields (15)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_equipment` | entity_reference → equipment (all bundles) | Equipment | Yes |
| `field_source_inspection` | entity_reference → equipment_inspection | Source Inspection | No |
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
leak, abs, hydraulic, blades_deck, spray_system, paperwork,
trailer, safety, other

### field_defect_severity values
monitor, repair_soon, urgent, out_of_service

### field_defect_status values
open, scheduled, in_repair, resolved, deferred, retired_with_equipment

## Views
- Open Defects Board: `/admin/operations/fleet/open-defects`
- Equipment Defects EVA: on all equipment entity pages

## Invariants
- A defect must only be closed when a verified maintenance event references it
- field_equipment targets ALL equipment bundles
- Defect merge: same equipment + category + open within 30 days = repeat flag

Created: April 2026
