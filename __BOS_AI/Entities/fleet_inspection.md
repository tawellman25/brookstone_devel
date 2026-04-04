# BOS Entity — Fleet Inspection

Entity Type ID: `fleet_inspection`
Storage: ECK

## Purpose
One record per truck/trailer inspection event. Weekly inspections are source
observations, not repair records. Failed safety, legal, or drivability checks
must create or queue defect records.

Paper forms remain valid intake, but BOS structured records are the
operational system of record.

## Bundle
`standard` — Standard weekly inspection

## Auto-Label Pattern
`Truck [vehicle number] - [inspection date]`

---

## Fields (53)

### Identity / Linkage
| Field | Type | Label | Required |
|---|---|---|---|
| `field_vehicle` | entity_reference → equipment:vehicles | Vehicle | Yes |
| `field_trailer` | entity_reference → equipment:trailers | Trailer | No |
| `field_source_type` | list_string | Source Type | Yes |
| `field_review_status` | list_string | Review Status | Yes (default: pending) |

`field_source_type` values: paper_scan, bos_manual, mobile_form, imported
`field_review_status` values: pending, approved, rejected

### Inspection Header
| Field | Type | Label | Required |
|---|---|---|---|
| `field_inspection_date` | datetime (date only) | Inspection Date | Yes |
| `field_inspector_name` | string | Inspector Name | Yes |
| `field_odometer` | integer | Odometer Reading | No |
| `field_service_reading` | integer | Service Reading | No |
| `field_trailer_used` | boolean | Trailer Used | No (default: 0) |

### Critical Operating Decision
| Field | Type | Label | Required |
|---|---|---|---|
| `field_safe_to_operate` | list_string | Safe to Operate | Yes |
| `field_warning_light_status` | list_string | Warning Lights | Yes |
| `field_followup_required` | boolean | Follow-Up Required | No (default: 0) |
| `field_critical_issue_flag` | boolean | Critical Issue Flag | No (default: 0) |

`field_safe_to_operate` values: yes, limited_use, no
`field_warning_light_status` values: none, present

### Truck Inspection — Fluids (5 fields)
`field_engine_oil_status`, `field_coolant_status`, `field_trans_fluid_status`,
`field_brake_fluid_status`, `field_power_steering_status`

### Truck Inspection — Mechanical (7 fields)
`field_visible_leak_status`, `field_belts_status`, `field_tires_status`,
`field_tread_status`, `field_windshield_status`, `field_wipers_status`,
`field_seatbelt_status`

### Truck Inspection — Lights (6 fields)
`field_headlights_status`, `field_taillights_status`, `field_brake_lights_status`,
`field_backup_lights_status`, `field_turn_signals_status`, `field_horn_status`

### Trailer Inspection (17 fields)
`field_trailer_hitch_status`, `field_trailer_chains_status`,
`field_trailer_breakaway_status`, `field_trlr_coupling_pin_status`,
`field_trailer_jack_status`, `field_trailer_lights_status`,
`field_trlr_brake_lights_status`, `field_trlr_marker_lights_status`,
`field_trlr_turn_signals_status`, `field_trailer_tires_status`,
`field_trailer_tread_status`, `field_trailer_spare_status`,
`field_trailer_suspension_status`, `field_trailer_damage_status`,
`field_trailer_load_secure_status`, `field_trailer_plate_status`,
`field_trailer_cleanliness_status`

### Issue Codes and Notes
| Field | Type | Label |
|---|---|---|
| `field_issue_codes` | list_string (multi-value) | Issue Codes |
| `field_notes_short` | string | Short Note |
| `field_notes_detailed` | text_long | Detailed Review Notes |

`field_issue_codes` values: engine, trans, brakes, steering, tire, light,
glass, leak, abs, paperwork, trailer, other

### Review Fields
| Field | Type | Label |
|---|---|---|
| `field_reviewed_by` | entity_reference → user | Reviewed By |
| `field_reviewed_on` | datetime | Reviewed On |

### Shortened Field Names
6 fields shortened to stay under Drupal's 32-character limit:
- `field_power_steering_status` (was field_power_steering_fluid_status)
- `field_trans_fluid_status` (was field_transmission_fluid_status)
- `field_trlr_brake_lights_status` (was field_trailer_brake_lights_status)
- `field_trlr_coupling_pin_status` (was field_trailer_coupling_pin_status)
- `field_trlr_marker_lights_status` (was field_trailer_marker_lights_status)
- `field_trlr_turn_signals_status` (was field_trailer_turn_signals_status)

---

## Views

### Inspection Review Queue
Path: `/admin/operations/fleet/inspection-review`
Menu: Operations > Fleet > Inspection Review
Filter: field_review_status = pending
Default sort: Truck Number ASC

### Vehicle Inspections EVA
Attached to: equipment:vehicles entity display
Shows inspection history per vehicle, sorted newest first

---

## Invariants
- One inspection record per inspection event — not a running log
- Inspections are observations, not repair records
- Trailer fields only completed when field_trailer_used = TRUE
- field_review_status must be set to approved before defect rules fire

Created: April 2026
