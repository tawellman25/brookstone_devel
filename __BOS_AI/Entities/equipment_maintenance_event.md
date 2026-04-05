# BOS Entity — Equipment Maintenance Event

Entity Type ID: `equipment_maintenance_event`
Storage: ECK

## Purpose
Track actual service, maintenance, and repairs performed on any equipment.
Maintenance events close defects (via verification) and provide cost/downtime
data for repair-vs-replace decisions.

## Bundle
`standard` — Standard maintenance event

## Fields (15)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_equipment` | entity_reference → equipment (all bundles) | Equipment | Yes |
| `field_related_defect` | entity_reference → equipment_defect | Related Defect | No |
| `field_event_date` | datetime (date only) | Event Date | Yes |
| `field_event_type` | list_string | Event Type | Yes |
| `field_vendor_or_mechanic` | string | Vendor or Mechanic | No |
| `field_service_reading` | integer | Service Reading (miles or hours) | No |
| `field_cost_parts` | decimal | Parts Cost | No |
| `field_cost_labor` | decimal | Labor Cost | No |
| `field_cost_total` | decimal | Total Cost | Yes |
| `field_downtime_days` | decimal | Downtime Days | No |
| `field_work_performed` | text_long | Work Performed | Yes |
| `field_next_service_due` | integer | Next Service Due (miles/hours) | No |
| `field_next_service_due_date` | datetime (date only) | Next Service Due Date | No |
| `field_verified_complete` | boolean | Verified Complete | No (default: 0) |
| `field_verified_by` | entity_reference → user | Verified By | No |

### field_event_type values
preventive_maintenance, repair, major_repair, inspection_service,
tire_service, oil_change, brake_service, front_end, engine_work,
transmission_work, electrical_work, body_glass, hydraulic_work,
blade_deck_service, spray_system_service, other

## Defect Resolution Flow
1. Maintenance event created with field_related_defect → defect moves to `in_repair`
2. field_verified_complete set TRUE → defect moves to `resolved`
This is the ONLY path to resolve a defect.

## Cost Auto-Calculation
Presave: `field_cost_total` auto-calculated from `field_cost_parts` +
`field_cost_labor` when parts and/or labor are set but total is empty
or zero. Handled by `fleet_inspection_workflow` module (module name is
legacy — handles all equipment types).

## Views
- Equipment Maintenance EVA: on all equipment entity pages

## Invariants
- field_equipment targets ALL equipment bundles
- field_cost_total is required
- field_service_reading works for both mileage and hours

Created: April 2026
