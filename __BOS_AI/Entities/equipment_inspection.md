# BOS Entity — Equipment Inspection

Entity Type ID: `equipment_inspection`
Storage: ECK

## Purpose
One record per equipment inspection event. Inspections are source
observations, not repair records. Failed safety checks must create or queue defect records via the
`equipment_inspection_workflow` module.

## Bundles (6)

| Bundle | Label | For | Key checklist areas |
|---|---|---|---|
| `vehicles` | Vehicles | Trucks | Fluids, mechanical, lights, tires, windshield, seatbelts (40+ fields) |
| `trailers` | Trailers | Trailers | Hitch, chains, lights, tires, suspension, load, plate (17 fields) |
| `heavy_equipment` | Heavy Equipment | Excavators, skid steers | Hydraulics, tracks, bucket, engine, safety guards (16 fields) |
| `mowers` | Mowers | Mowers, zero-turns | Blades, deck, belts, tires, engine oil, safety guards (12 fields) |
| `sprayers` | Sprayers | Spray rigs | Tank, pump, nozzles, hoses, pressure, calibration (10 fields) |
| `standard` | Standard | Power tools, hand tools, misc | Visual condition, operational status, safety (4 fields) |

## Common Fields (all bundles — 12 fields)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_equipment` | entity_reference → equipment (all bundles) | Equipment | Yes |
| `field_source_type` | list_string | Source Type | Yes |
| `field_review_status` | list_string | Review Status | Yes (default: pending) |
| `field_inspection_date` | datetime (date only) | Inspection Date | Yes |
| `field_inspector_name` | string | Inspector Name | Yes |
| `field_safe_to_operate` | list_string | Safe to Operate | Yes |
| `field_followup_required` | boolean | Follow-Up Required | No (default: 0) |
| `field_critical_issue_flag` | boolean | Critical Issue Flag | No (default: 0) |
| `field_notes_short` | string | Short Note | No |
| `field_notes_detailed` | text_long | Detailed Review Notes | No |
| `field_reviewed_by` | entity_reference → user | Reviewed By | No |
| `field_reviewed_on` | datetime | Reviewed On | No |

## Views
- Inspection Review Queue: `/admin/operations/equipment/inspection-review`
- Equipment Inspections EVA: on all equipment entity pages

## Invariants
- One inspection record per inspection event
- Inspections are observations, not repair records
- field_review_status must be approved before defect rules fire
- field_equipment targets ALL equipment bundles
- Inspection bundle must match equipment type — do not use standard as a shortcut
  for vehicles, mowers, sprayers, or heavy equipment

Created: April 2026
