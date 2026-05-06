# BOS Entity — Equipment Fuel Transaction

Entity Type ID: `equipment_fuel_transaction`
Storage: ECK

## Purpose

One record per fuel purchase imported from a WEX fleet card transaction export. Captures the operational data point (vehicle, driver, gallons, cost, odometer) PLUS a raw audit copy of the WEX-side identifiers so resolution decisions made at import time are auditable later. Records are immutable in normal operation; corrections happen via standalone edit, never bulk overwrite.

Imports are idempotent — a transaction with a `field_wex_transaction_id` already in BOS is skipped silently rather than re-created. See `__BOS_AI/Modules/bos_wex_import.md` for the import service that maintains these records.

## Bundles (1)

| Bundle | Label | Description |
|---|---|---|
| `standard` | Standard | Standard fuel transaction record imported from WEX. |

## Required Relationships

Both relationships are **optional at the field level** (cardinality 1, required: false) so unmatched transactions can still be saved with a status flag for manual review.

| Field | Targets | Resolved how |
|---|---|---|
| `field_equipment` | `equipment` (vehicles bundle only) | At import: WEX `Custom Vehicle/Asset ID` → `equipment.vehicles.field_vehicle_number` |
| `field_driver` | `user` | At import: WEX `Driver Prompt ID` (zero-padded 4 chars) → `teammate_profile.field_wex_driver_prompt_id` → owning user |

When either lookup fails, the field stays empty and `field_match_status` reflects which side failed.

## Key Fields (30 custom + standard ECK base fields)

### Identity & Deduplication

| Field | Type | Label | Required |
|---|---|---|---|
| `field_wex_transaction_id` | string (32) | WEX Transaction ID | **Yes** — uniqueness enforced via presave hook in `bos_wex_import` |

### Transaction Timing

| Field | Type | Label | Required |
|---|---|---|---|
| `field_transaction_date` | datetime (date+time) | Transaction Date | **Yes** — combined from WEX `Transaction Date` + `Transaction Time` |
| `field_posted_date` | datetime (date) | Posted Date | No |

### Card Linkage

| Field | Type | Label |
|---|---|---|
| `field_card_number_masked` | string (16) | Card Number (Masked) — stored as-imported (e.g., `****91071`) |

### Vehicle Linkage

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_equipment` | entity_reference → equipment (vehicles) | Vehicle | Resolved value; empty when WEX asset ID didn't match |
| `field_vehicle_asset_id_raw` | string (32) | WEX Vehicle Asset ID (Raw) | Audit copy of the as-imported value |
| `field_vin_raw` | string (32) | VIN (Raw from WEX) | Audit copy of the as-imported value |

### Driver Linkage

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_driver` | entity_reference → user | Driver | Resolved value; empty when prompt ID didn't match |
| `field_driver_prompt_id_raw` | string (4) | WEX Driver Prompt ID (Raw) | Stored zero-padded to 4 chars |
| `field_driver_first_name_raw` | string (64) | Driver First Name (Raw from WEX) | Snapshot at transaction time |
| `field_driver_last_name_raw` | string (64) | Driver Last Name (Raw from WEX) | Snapshot at transaction time |
| `field_driver_department_snapshot` | string (64) | Driver Department (Snapshot) | Snapshot — may differ from current BOS department |

### Merchant

| Field | Type | Label |
|---|---|---|
| `field_merchant_name` | string (128) | Merchant Name |
| `field_merchant_brand` | string (64) | Merchant Brand |
| `field_merchant_city` | string (64) | Merchant City |
| `field_merchant_state` | string (4) | Merchant State |
| `field_merchant_postal_code` | string (16) | Merchant Postal Code |

### Product

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_product_code` | string (16) | Product Code | Fuel grade code (UNL, DSL, ETH, UN+, SUP, …) |
| `field_product_class` | string (32) | Product Class |
| `field_product_description` | string (128) | Product Description |

### Cost & Quantity

All decimal fields are precision 12, scale 4 — preserves the WEX export's 4-decimal pricing precision. Currency is implicit USD; there is no currency field.

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_units` | decimal | Units (Gallons) | Quantity purchased in gallons |
| `field_unit_cost` | decimal | Unit Cost ($/gal) |
| `field_total_fuel_cost` | decimal | Total Fuel Cost |
| `field_net_cost` | decimal | Net Cost — final cost after discounts/taxes |

### Vehicle Telemetry

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_current_odometer` | integer | Current Odometer | Reading entered by driver at the pump |
| `field_adjusted_odometer` | integer | Adjusted Odometer | WEX-corrected when driver entry was wrong; preferred over current_odometer when populated |
| `field_previous_odometer` | integer | Previous Odometer | Reading from prior fill-up per WEX |
| `field_distance_driven` | integer | Distance Driven | Miles since last fill-up, calculated by WEX |
| `field_fuel_economy` | decimal (8,2) | Fuel Economy (MPG) | Calculated by WEX |

### Match Status

| Field | Type | Label | Required | Default |
|---|---|---|---|---|
| `field_match_status` | list_string | Match Status | **Yes** | `matched` |

Allowed values:

| Key | Label | When set |
|---|---|---|
| `matched` | Matched | Both driver and vehicle resolved at import time |
| `unmatched_driver` | Unmatched Driver | Vehicle resolved, driver did not |
| `unmatched_vehicle` | Unmatched Vehicle | Driver resolved, vehicle did not |
| `unmatched_both` | Unmatched Driver & Vehicle | Neither resolved |
| `manually_resolved` | Manually Resolved | Office staff fixed an unmatched record by setting field_driver / field_equipment manually |

## Auto-label

```
[equipment_fuel_transaction:field_equipment:entity:title] - [equipment_fuel_transaction:field_transaction_date:date:custom:Y-m-d]
```

Renders as e.g. `2001 Ford F250 4WD Truck - #12 - 2026-04-22`. Set via `auto_entitylabel.settings.equipment_fuel_transaction.standard`.

## Pathauto

```
[equipment_fuel_transaction:field_equipment:entity:url:path]/fuel/[equipment_fuel_transaction:field_transaction_date:date:custom:Y-m-d]
```

Renders as e.g. `/about-us/our-equipment/truck/2001-ford-f250-4wd-truck-12/fuel/2026-04-22`. Set via `pathauto.pattern.equipment_fuel_transaction`. The entity type is registered in `pathauto.settings.enabled_entity_types` to enable the path base field — without that, no alias generates (see `__BOS_AI/Governance/drupal_bos_gotchas.md` → "ECK + pathauto: enabled_entity_types registration is mandatory").

## Companion field on `equipment.vehicles`

The mileage-update side effect of imports writes to `equipment.vehicles.field_current_mileage_updated_on` (datetime) — that field lives on the parent equipment entity, NOT on this transaction entity. See [equipment.md](equipment.md) for that field's documentation. The audit-trail relationship is one-way: equipment_fuel_transaction is the source of mileage updates, but a transaction entity has no back-reference to "did this transaction's odometer become the vehicle's current mileage."

## Invariants (non-negotiable)

- **`field_wex_transaction_id` is unique across the entity type.** Enforced via `bos_wex_import_entity_presave()` (in the `bos_wex_import` module). Re-imports of the same WEX export are no-ops at the entity level — duplicates are detected before save and skipped.
- **`field_match_status` is required and defaults to `matched`.** No transaction can be saved with an empty status — the import service computes the value before save.
- **Raw audit fields (`field_*_raw`) preserve as-imported WEX values.** Resolved entity references (`field_equipment`, `field_driver`) may change post-import via manual resolution; the raw values do not.
- **Driver Prompt ID is always stored 4-character zero-padded** (`0625` not `625`). This matches the `teammate_profile.field_wex_driver_prompt_id` storage convention so resolution lookups are exact-match on canonical values.
- **`field_transaction_date` is required** — a transaction without a known date can't be ordered, audited, or grouped chronologically.
- **Pricing snapshots are immutable per the BOS architectural rule.** Edits to cost/units after import should be exceptional and audit-noted.

## Reporting Expectations

Three Views ship with this entity (see `views.view.equipment_fuel_transactions_*.yml`):

| View | Path / Attachment | Purpose |
|---|---|---|
| `equipment_fuel_transactions_eva` | EVA on each equipment entity view page | Per-vehicle fuel history; date column links to the transaction record |
| `equipment_fuel_transactions_admin` | `/admin/operations/equipment/fuel-transactions` | Master list across the fleet, exposed filters for vehicle / driver / status / date range / product code |
| `equipment_fuel_transactions_unmatched` | `/admin/operations/equipment/fuel-transactions/unmatched` | Review queue filtered to non-matched / non-resolved statuses; raw audit fields shown for manual resolution |

Operations staff resolve unmatched records by editing them on the standalone edit form, populating `field_driver` and/or `field_equipment` manually, then setting `field_match_status` to `manually_resolved`.

## Deletion / Archival Policy

Transaction records are **append-only** in normal operation. The import service:

- Skips records with an existing `field_wex_transaction_id` (idempotent re-imports)
- Never overwrites existing records
- Never deletes records

Deletion is allowed via standard entity-edit access for users with `administer eck entities` permission, but should be exceptional — re-importing the same WEX export will not bring a deleted transaction back, since the import path always checks for duplicates first. If a transaction needs to be removed (test data, accidental import), the standard entity delete flow applies and is logged as a Drupal `ENTITY_DELETE` action.

There is no archival flag at the entity level. Long-term storage scaling is not a concern at projected volumes (a few hundred transactions per month).

## Related Entities

- [equipment.md](equipment.md) — `field_equipment` targets the vehicles bundle. Mileage updates write to `equipment.vehicles.field_current_mileage` and `field_current_mileage_updated_on`.
- `user` — `field_driver` targets a real BOS user with a populated `teammate_profile.field_wex_driver_prompt_id`.
- See `__BOS_AI/Entities/users.md` (or the equivalent teammate_profile section) for the WEX driver prompt ID field that anchors driver resolution.

## Related Module

`__BOS_AI/Modules/bos_wex_import.md` — the import service, batch processor, upload form, and uniqueness validation hook that maintain these records.

## Status

- Created: 2026-05-04 (commit `885eb452` — entity + 30 fields + displays + auto-label + pathauto)
- Views added 2026-05-04 (commit `5120b90f`)
- Import module operationalized 2026-05-05 (commit `bfa697fc` + form-fix `114afa70`)
- EVA date-link added 2026-05-06 (commit `bacf10d2`)
- First production import: ~209 transactions covering 2025-12-30 → 2026-04-30
