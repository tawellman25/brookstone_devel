# BOS Entity — Fleet Maintenance Event

Entity Type ID: `fleet_maintenance_event`
Storage: ECK

## Purpose
Track actual service, maintenance, and repairs performed on vehicles.
Maintenance events close defects and provide cost/downtime data for
repair-vs-replace decisions.

## Bundle
`standard` — Standard maintenance event

## Auto-Label Pattern
`Truck [vehicle number] - [event type] - [service date]`

---

## Fields (15)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_vehicle` | entity_reference → equipment:vehicles | Vehicle | Yes |
| `field_related_defect` | entity_reference → fleet_defect | Related Defect | No |
| `field_event_date` | datetime (date only) | Event Date | Yes |
| `field_event_type` | list_string | Event Type | Yes |
| `field_vendor_or_mechanic` | string | Vendor or Mechanic | No |
| `field_mileage_at_service` | integer | Mileage at Service | No |
| `field_cost_parts` | decimal | Parts Cost | No |
| `field_cost_labor` | decimal | Labor Cost | No |
| `field_cost_total` | decimal | Total Cost | Yes |
| `field_downtime_days` | decimal | Downtime Days | No |
| `field_work_performed` | text_long | Work Performed | Yes |
| `field_next_service_due_mileage` | integer | Next Service Due Mileage | No |
| `field_next_service_due_date` | datetime (date only) | Next Service Due Date | No |
| `field_verified_complete` | boolean | Verified Complete | No (default: 0) |
| `field_verified_by` | entity_reference → user | Verified By | No |

### field_event_type values
preventive_maintenance, repair, major_repair, inspection_service,
tire_service, oil_change, brake_service, front_end, engine_work,
transmission_work, electrical_work, body_glass, other

---

## Views

### Vehicle Maintenance EVA
Attached to: equipment:vehicles entity display
Shows maintenance history per vehicle, sorted newest first
Columns: Date, Type, Vendor, Cost, Mileage

---

## Relationship to Defects
- `field_related_defect` links the maintenance event to the defect it resolves
- On insert: linked defect transitions to `in_repair` (not resolved)
- On `field_verified_complete` = TRUE: linked defect transitions to `resolved`
- This is the ONLY path to resolve a defect — verified maintenance completion
- Cost data from maintenance events feeds the Repair vs Replace Board

## Invariants
- One event per service action — not a running log
- field_cost_total is required for all events
- field_work_performed must describe what was actually done
- Maintenance events are the authority for repair costs, not defects

Created: April 2026
