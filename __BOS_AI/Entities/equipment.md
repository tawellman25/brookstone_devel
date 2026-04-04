# BOS Entity — Equipment

Entity Type ID:
- equipment

Storage:
- ECK entity type

---

## Purpose

Equipment entities represent Brookstone assets used to perform work, including:
- vehicles
- trailers
- heavy equipment
- small engines
- power tools
- sprayers
- snow plows
- attachments

Equipment supports:
- asset inventory and identification
- operational status tracking
- cost/rate tracking (internal + billable)
- Work Order equipment usage (directly or via child entities such as wo_rental_equipment)

Equipment is not “consumed” like Materials/Chemicals; it is an asset.

---

## Bundles (Machine Name | Label)

attachements | Attachements  
heavy_equipment | Heavy Equipment  
power_tools | Power Tools  
small_engine | Small Engine  
snow_plows | Snow Plows  
sprayers | Sprayers  
trailers | Trailers  
vehicles | Vehicles  

---

## Global Fields (Present in all bundles)

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Type
- title | string | Title/Name
- uid | entity_reference | Authored by / Entered by
- created | created | Authored/Entered on
- changed | changed | Changed/Updated
- default_langcode | boolean | Default translation
- path | path | URL alias

Invariant:
- Equipment records must be uniquely identifiable by internal ID and stable naming/numbering strategy.

---

## Common Operational Fields (Used in multiple bundles)

Status & visibility:
- field_status | entity_reference | Status
- field_public_description | text_long | Public Description
- field_pictures | image | Pictures of Equipment
- field_documents | file | Documents
- field_comments | comment | Notes

Financial:
- field_purchase_price | decimal | Purchase Price
- field_depriciated_value | decimal | Depreciated Value
- field_date_purchased | datetime | Date Purchased
- field_date_sold | datetime | Date Sold

Costing & rates:
- field_billable | boolean | Billable
- field_rate | decimal | Hourly Work Order Rate
- field_internal_cost_rate | decimal | Internal Cost Rate
- field_operating_cost_per_hour | decimal | Operating Cost per Hour

Identifiers:
- field_serial_code_number | string | Serial Number / Serial (CODE) Number
- field_equipment_type | entity_reference | Equipment Type (bundle-specific label)
- field_equipment_make | string | Equipment Make / Manufacturer
- field_model | string | Model
- field_manufactured_year | integer | Manufactured Year

Vehicle/trailer identifiers:
- field_vin | string | VIN
- field_license_plate | string | License Plate

Invariant:
- If equipment is used for WO costing, rates/cost fields must be populated and stable for reporting.
- If equipment has a VIN/plate/serial number, it must be unique within its bundle (prefer globally unique).

---

## Bundle Definitions (Fields by Bundle)

### attachements (Attachements)
Currently only base fields.
Note:
- Likely intended to model implements that attach to other equipment.
- Consider adding relationship fields (attaches_to) if needed later (already exists on snow_plows).

### heavy_equipment (Heavy Equipment)
Key fields:
- field_billable
- field_color
- field_date_purchased
- field_date_sold
- field_depriciated_value
- field_documents
- field_engine_size
- field_engine_type
- field_equipment_make
- field_equipment_type
- field_internal_cost_rate
- field_manufactured_year
- field_model
- field_operating_cost_per_hour
- field_pictures
- field_public_description
- field_purchase_price
- field_rate
- field_serial_code_number
- field_size
- field_status
- field_vin

Notes:
- VIN present here implies some “heavy equipment” overlaps with vehicles. Keep consistent identifier rules.

### power_tools (Power Tools)
Key fields:
- field_date_purchased
- field_equipment_make
- field_equipment_type
- field_model
- field_pictures
- field_purchase_price
- field_status

Notes:
- Minimal costing/rates; likely fine unless you want billable tool tracking.

### small_engine (Small Engine)
Key fields:
- field_billable
- field_date_of_manufacture (DOM)
- field_date_purchased
- field_depriciated_value
- field_documents
- field_engine_size
- field_equipment_make (Make)
- field_equipment_number (Equipment ID Number)
- field_equipment_type (Small Engine Equipment Type)
- field_internal_cost_rate
- field_manufactured_year
- field_manufacturer_website_item (link)
- field_model
- field_oil_type
- field_operating_cost_per_hour
- field_pictures
- field_public_description
- field_purchase_price
- field_rate
- field_serial_code_number
- field_small_engine_type
- field_spec_number
- field_status

Invariant:
- field_equipment_number should be unique within small engines (and ideally globally unique).

### snow_plows (Snow Plows)
Key fields:
- field_attaches_to (entity_reference)
- field_color
- field_comments (Notes)
- field_date_of_manufacture (DOM)
- field_date_purchased
- field_date_sold
- field_depriciated_value
- field_end (list_integer) — unclear semantics; document intended meaning later
- field_equipment_make (Manufacturer)
- field_equipment_number (Plow Number)
- field_equipment_type (Equipment Category)
- field_model
- field_pictures
- field_purchase_price
- field_serial_code_number
- field_status

Invariant:
- field_attaches_to must reference a compatible equipment record (typically vehicles).

### sprayers (Sprayers)
Key fields:
- field_billable
- field_date_purchased
- field_pictures

Notes:
- Currently minimal. If sprayers are regulated/inspected, consider adding maintenance/inspection fields later.

### trailers (Trailers)
Key fields:
- field_color
- field_date_purchased
- field_depriciated_value
- field_equipment_type
- field_license_plate
- field_manufactured_year
- field_model
- field_pictures
- field_public_description
- field_purchase_price
- field_renewal_month
- field_size
- field_status
- field_trailer_name (Trailer Name/Description)
- field_trailer_number
- field_vin

Invariant:
- field_trailer_number should be unique and stable.

### vehicles (Vehicles)
Key fields:
- field_billable
- field_color
- field_comments (Notes)
- field_date_purchased
- field_date_sold
- field_depriciated_value
- field_documents
- field_doors
- field_drivetrain
- field_engine_size
- field_engine_type
- field_equipment_type
- field_inspection_required
- field_internal_cost_rate
- field_last_inspection_date
- field_license_plate
- field_manufactured_year
- field_model
- field_operating_cost_per_hour
- field_pictures
- field_public_description
- field_purchase_price
- field_renewal_month
- field_size
- field_status
- field_vehicle_make
- field_vehicle_number (Truck Number)
- field_vin

#### Fleet Management Fields (added April 2026)
- field_current_mileage (integer) — Current Mileage
- field_condition_score (integer) — Condition Score (1-10)
- field_engine_health (list_string) — good, monitor, at_risk, failing
- field_transmission_health (list_string) — good, monitor, at_risk, failing
- field_breakdown_risk_score (integer) — Breakdown Risk Score (1-10)
- field_fleet_decision_status (list_string) — keep, monitor, repair, replace, liquidate
- field_utilization_class (list_string) — core, secondary, backup
- field_estimated_resale_value (decimal) — Estimated Resale Value
- field_last_major_failure_date (datetime) — Last Major Failure Date
- field_open_defect_count (integer) — Open Defect Count

These fields are management-owned. Crews must not update them directly.

Invariants:
- field_vehicle_number must be unique and stable.
- If field_inspection_required is TRUE, field_last_inspection_date must be maintained.

#### Related Fleet Entities
- `fleet_inspection` — weekly inspection records (via field_vehicle)
- `fleet_defect` — actionable defect tracking (via field_vehicle)
- `fleet_maintenance_event` — service/repair records (via field_vehicle)

EVA views show inspection history, defect history, and maintenance
history on the vehicle entity page.

See: `fleet_inspection.md`, `fleet_defect.md`, `fleet_maintenance_event.md`

---

## Integration With Work Orders

Known related Work Order child entities:
- wo_rental_equipment (records equipment/rental equipment used on Work Orders)

Rules:
- Equipment usage on Work Orders should reference Equipment entities (not free-text).
- Work Orders may snapshot rates/costs if historical costing must remain stable.

Recommended (future governance):
- If WO costing uses equipment rates, define whether:
  - WO reads equipment.field_rate live, or
  - snapshots into WO usage records (preferred for historical accuracy).

---

## Deletion / Archival

Default:
- Do not delete equipment records.

Preferred:
- Mark as sold/inactive via:
  - field_status
  - field_date_sold
  - archival rules in UI

Hard delete:
- Only allowed if not referenced by Work Orders, attachments, or logs.

---

## Issues / Notes (Observed)

- Bundle name typo: `attachements` should be `attachments` (machine name typo is permanent; label can be corrected).
- Some bundles use `uid` label variations (Authored vs Entered); cosmetic.
- Some fields overlap across bundles; consistency should be enforced by policy rather than creating new fields.
