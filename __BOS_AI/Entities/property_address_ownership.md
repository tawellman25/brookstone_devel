# BOS Entity — property

Entity Type ID: `property`
Storage: ECK

> **Note:** This entity type (`property`) is DISTINCT from `properties`. `properties` is the primary property record. `property` (bundle: `included_address`) represents an additional service address associated with a property — used for snow removal multi-driveway tracking.

## Bundles
`included_address` (single bundle)

## Required Relationships
- `field_associated_property` → `properties` (parent property)

## Key Fields
- `field_address` — street address of this included location
- `field_unit` — unit/suite if applicable

## Usage
- Referenced by `wo_tasks_list:snow_removal.field_driveways_plowed` — tracks which specific driveways/addresses were plowed in a snow removal WO.

## Invariants
- Must always reference a parent `properties` entity.
- Do not confuse with `properties` (the primary property entity type).

## Deletion / Archival
- Do not delete if referenced by completed WOs.

---

# BOS Entity — ownership_record

Entity Type ID: `ownership_record`
Storage: ECK

## Purpose
- Records the ownership relationship between a user (client/owner) and a property over time.
- Supports tracking property ownership changes — properties persist across ownership changes.

## Bundles
`record` (single bundle)

## Required Relationships
- `field_property_reference` → `properties`
- `field_property_owner` → `user`

## Key Fields
- `field_property_owner` → `user` — the owner during this record's period
- `field_property_reference` → `properties` — the property
- `created` (base) — when this ownership record was established

## Invariants
- Properties persist across ownership changes (see `properties` entity policy).
- Do not delete ownership records — they represent historical ownership history.

## Deletion / Archival
- Do not delete. Historical record.
