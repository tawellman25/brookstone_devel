# BOS — Work Order Bundle Module Pattern

This document defines the `wo_*` module system as a formal BOS architectural pattern.

---

## Overview

Each Work Order bundle has a dedicated custom module named `wo_{bundle_machine_name}` (e.g., `wo_aerating`, `wo_snow_removal`). These modules implement **bundle-specific business logic** for Work Order lifecycle events.

This is not a convenience pattern — it is a deliberate architectural decision. Bundle-specific logic must live in its own module. Do not consolidate `wo_*` modules.

---

## The Pattern

Every `wo_*` bundle module has the same structural role:

```
wo_{bundle}.module
  ├── hook_entity_presave(work_order:{bundle})
  │     ├── Guard: exit if bundle doesn't match
  │     ├── Guard: exit if WO status != Complete (1097)
  │     ├── Read from child entities (wo_tasks_list, wo_time_clock, etc.)
  │     ├── Read from property_* detail entities
  │     ├── Read rates from config_pages:business_setting
  │     ├── Calculate all billing subtotals
  │     └── Write totals to WO fields (field_wo_total, field_labor_total, etc.)
  │
  ├── hook_entity_insert(work_order:{bundle})
  │     ├── Guard: exit if bundle doesn't match
  │     ├── Guard: exit if WO status != Complete (1097)
  │     └── Write "last completed" summary data back to property_* detail entity
  │
  └── hook_entity_update(work_order:{bundle})
        ├── Guard: exit if bundle doesn't match
        ├── Guard: exit if WO status != Complete (1097)
        └── Write "last completed" summary data back to property_* detail entity
```

All three hooks are guarded. A `wo_*` module never touches Work Orders of other bundles.

---

## Why Per-Bundle Modules

1. **Bundle-specific data sources differ.** Snow removal reads salt/mag amounts from `wo_tasks_list:snow_removal`. Aerating reads sq footage from `property_landscape_details`. Fertilizing reads product and rate from `property_fertilizing_info`. A single shared module would become an unmanageable conditional tree.

2. **Billing formulas differ.** Each service has a distinct calculation (per-push pricing, sq-ft breakpoints, hourly labor, chemical minimums, etc.). These formulas belong together with the service they govern.

3. **Property detail write-back targets differ.** Each service writes completion data to its own `property_*` detail entity. These relationships are 1:1 per service domain.

4. **Independent deployability.** A bug in `wo_snow_removal` does not risk `wo_fertilizing`. Modules can be enabled/disabled per bundle without affecting other services.

5. **Clear ownership.** Each module has a single, named responsibility. Future developers know exactly where to look for aerating logic.

---

## Status Term IDs (Hardcoded in `wo_*` Modules)

| Status | Term ID |
|---|---|
| Complete | 1097 |
| In Progress | 1092 |
| Cancelled | 1098 |

These IDs are referenced directly in `wo_*` module hook guards. If the status taxonomy ever changes, all `wo_*` modules must be updated. This is a known technical debt — a future improvement would be to load these via config or a service.

---

## Complete List of `wo_*` Bundle Modules

| Module | Bundle | Notes |
|---|---|---|
| `wo_aerating` | `aerating` | Reads `property_landscape_details.field_turf_sq_footage`; uses `sq_ft_break_points` pricing. Also calls `_wo_aerating_sync_aeration_flag()` on insert/update to update `field_aeration_flag_heads` on linked sprinkler_start_up WOs via `bos_scheduling.aeration_flag` service. |
| `wo_aspen_twig_gall` | `aspen_twig_gall` | Reads/writes `property_spraying_info:aspen_twig_gall` |
| `wo_christmas_decorations` | `christmas_decorations` | Reads/writes `property_christmas_decor` |
| `wo_cooley_spruce_gall` | `cooley_spruce_gall` | Reads/writes `property_spraying_info:cooley_spruce` |
| `wo_deciduous_bore` | `deciduous_bore` | Reads/writes `property_spraying_info:deciduous_bore` |
| `wo_deer_prevention` | `deer_prevention` | |
| `wo_dethatching` | `dethatching` | Uses `sq_ft_break_points` pricing |
| `wo_dormant_oil` | `dormant_oil` | Reads/writes `property_spraying_info:dormant_oil` |
| `wo_fall_cleanup` | `fall_cleanup` | |
| `wo_fertilizing` | `fertilizing` | Reads/writes `property_fertilizing_info:lawn_fertilizing_information` |
| `wo_fertilizing_trees_and_shrubs` | `fertilizing_trees_and_shrubs` | Reads/writes `property_fertilizing_info:shrub_and_tree_fertilizing` |
| `wo_grub_prevention` | `grub_prevention` | Reads/writes `property_spraying_info:grub_prevention` |
| `wo_in_house_tasks` | `in_house_tasks` | |
| `wo_landscaping` | `landscaping` | |
| `wo_lawn_mowing` | `lawn_mowing` | Reads/writes `property_lawn_maintenance` |
| `wo_misc_services` | `misc_services` | |
| `wo_pinion_pine_ips_beetle` | `pinion_pine_ips_beetle` | Reads/writes `property_spraying_info:ips_beetle` |
| `wo_pre_emergent` | `pre_emergent` | Reads/writes `property_spraying_info:pre_emergent` |
| `wo_snow_removal` | `snow_removal` | Complex: reads salt/mag/shoveling from `wo_tasks_list:snow_removal`; reads per-push rate + shoveling flag from `contracts:snow_removal`; writes to `property_snow_removal_info` |
| `wo_special_mowing` | `special_mowing` | |
| `wo_spring_cleanup` | `spring_cleanup` | |
| `wo_sprinkler_check_up` | `sprinkler_check_up` | Reads `property_sprinkler_info`, `property_ss_zones` |
| `wo_sprinkler_design` | `sprinkler_design` | Writes to `property_sprinkler_design` |
| `wo_sprinkler_installation` | `sprinkler_installation` | Writes to `property_sprinkler_system` |
| `wo_sprinkler_repair` | `sprinkler_repair` | |
| `wo_sprinkler_start_up` | `sprinkler_start_up` | Reads/writes `property_sprinkler_system`, `property_system_controller`. Also calls `bos_scheduling.aeration_flag` service on insert/update to set `field_aeration_flag_heads` based on active aerating WOs for the property. |
| `wo_sprinkler_winterizing` | `sprinkler_winterizing` | Reads/writes `property_sprinkler_system`, `property_system_controller` |
| `wo_summer_pruning` | `summer_pruning` | |
| `wo_trunk_bore` | `trunk_bore` | Reads/writes `property_spraying_info:trunk_bore` |
| `wo_weed_pulling` | `weed_pulling` | |
| `wo_weed_spraying` | `weed_spraying` | Reads/writes `property_spraying_info:weed_spraying`. Presave guard blocks duplicate open WOs per property. Form alter redirects crew roles to existing open WO if one exists. |
| `wo_winter_pruning` | `winter_pruning` | |
| `wo_backflow_testing` | `backflow_testing` | Labor: `field_sprinkler_technician_rate`; TODO: write last test date to `property_sprinkler_system:system` once field exists |
| `wo_landscape_lighting` | `landscape_lighting` | Labor: `field_maintenance_crew_labor`; no property detail write-back |
| `wo_exterior_lighting` | `exterior_lighting` | Labor: `field_maintenance_crew_labor`; no property detail write-back |

Bundles without a dedicated module: `estimate` — relies on cross-cutting modules; not yet fully implemented as a billable service type.

---

## Cross-Cutting Work Order Modules

These modules handle WO lifecycle concerns that span all bundles.

### `wo_sign_off`

**Trigger:** `hook_entity_presave` on `wo_complete_info` (bundles: `complete`, `landscape_crew`, `clean_up_crew`, `fertilizing_crew`, `irrigation_crew`, `spray_crew`)

**On presave of `wo_complete_info`:**
- If `field_canceled = TRUE`: sets WO status to Cancelled (1098), sends email notification to `wo_notices@brookstoneoutdoors.com`, exits.
- Otherwise: sets WO status to Complete (1097).
- Calculates trip fee: loads `zipcodes.field_trip_fee` via `properties.field_zipcode_reference`, multiplied by truck count.
- Calculates total time: SUM(`wo_time_clock:entry.field_total_time`) × `field_those_on_crew` count.
- Saves the WO with updated fields.

**On delete of `wo_complete_info`:**
- Reverts WO status to In Progress (1092).
- Clears all billing totals: `field_total_time`, `field_trucks`, `field_labor_total`, `field_material_chemical_total`, `field_trip_fee`, `field_rental_total`, `field_wo_total`.
- Creates a `wo_status_updates:update` entry documenting the reversal.

**Excluded from trip fee calculation:** `sprinkler_start_up`, `sprinkler_winterizing`

**Excluded from total time calculation:** `dethatching`, `lawn_mowing`, `landscaping`, `sprinkler_repair`, `sprinkler_check_up`, `sprinkler_start_up`, `sprinkler_winterizing`, `sprinkler_installation`, `sprinkler_design`, `in_house_tasks`, `christmas_decorations`, `misc_services`

---

### `wo_status_updates`

Propagates `wo_status_updates` entity changes back to the parent Work Order's `field_status`.

---

### `wo_total_time`

Computes `work_order.field_total_time` by summing `wo_time_clock:entry.field_total_time` records for the WO.

---

### `wo_timer_flag_update`

Manages the `work_order_timer` Flag entity. Used during crew time-clocking workflow.

---

### `wo_chemical_used_subtotal`

On presave of `wo_chemicals_used`: calculates chemical subtotal (quantity × unit cost). Rolls up to `work_order.field_material_chemical_total` via the parent WO presave triggered by bundle modules.

---

### `wo_material_item_subtotal`

On presave of `wo_material_list_item`: calculates line subtotal (quantity × snapshot unit price). Rolls up to `work_order.field_material_chemical_total`.

---

### `wo_material_list_form`

Provides form handling for material list entry on WOs. Controls field visibility, defaults, and validation specific to the material list workflow.

---

### `wo_material_list_management`

Manages the creation, update, and deletion lifecycle of `wo_material_list` entities. Ensures a material list exists when needed and handles cleanup when a WO is deleted.

---

### `wo_dump_fees`

Provides computed fields for dump fees on WOs. Tracks `wo_material_dumping` entity lifecycle and rolls up dump fee totals to `work_order.field_dump_fee_total`.

---

### `wo_estimate`

Manages bidirectional linking between Work Orders and Estimates:
- `work_order.field_estimate` → `estimate`
- `estimate.field_work_order` → `work_order`

---

### `wo_notes`

Manages `wo_notes:note` entity lifecycle. Provides structured note creation, display, and access control for WO notes (distinct from the `field_work_order_notes` comment field).

---

### `wo_schedule`

On creation of a `scheduling:work_order` entity: creates a corresponding `wo_status_updates:update` entry to reflect the scheduling event on the WO status timeline.

---

### `wo_deletion_manager`

Controls Work Order deletion based on status. Blocks deletion of WOs in Complete or other protected statuses. Only allows deletion of WOs in draft/cancelled states with no operational history.

---

### `wo_actions`

Provides a VBO bulk action: re-saves `wo_complete_info` entities to trigger recalculation of WO totals via `wo_sign_off` presave. Used for data correction without manual re-entry.

---

## Rate and Pricing Sources

`wo_*` modules must not hardcode rates. Rates are sourced from:

| Source | What it provides |
|---|---|
| `config_pages:business_setting` | Salt rate, bag size/increments, mag rate + minimum, snow labor rate, shoveling minimum, ATV charge, hourly labor rates, aeration pricing reference |
| `sq_ft_break_points` ECK entity | Area-based pricing breakpoints for aeration, dethatching, overseeding (referenced from `business_setting`) |
| `zipcodes.field_trip_fee` | Per-zipcode trip fee (loaded via `properties.field_zipcode_reference`) |
| `contracts:snow_removal.field_per_push_rate` | Per-push rate for snow removal (from the property's latest snow removal contract) |
| `contracts:snow_removal.field_shoveling_labor_included` | Whether shoveling is included in the snow removal contract |

---

## Invariants

- A `wo_*` bundle module must never act on a WO of a different bundle.
- A `wo_*` bundle module must never act when WO status != Complete (1097), except for field pre-population logic that runs unconditionally.
- Bundle modules must not replicate the logic of cross-cutting modules (`wo_sign_off`, `wo_total_time`, etc.) — call or depend on those instead.
- When writing back to a `property_*` detail entity, always load by `field_property` and update the existing record. Never create duplicate property detail records.
- Billing totals written by `wo_*` modules become immutable once the WO is invoiced (`field_invoiced = TRUE`). Admin corrections are still possible but must be explicit.

---

## Adding a New Bundle Module

When a new Work Order bundle is created:

1. Create `web/modules/custom/wo_{bundle}/` with:
   - `wo_{bundle}.info.yml` (package: `Work Orders`, deps: `drupal:field`, `drupal:config_pages`)
   - `wo_{bundle}.module` with guarded `hook_entity_presave`, `hook_entity_insert`, `hook_entity_update`
2. Determine which `property_*` detail entity the bundle reads from and writes to.
3. Add the bundle's rate/pricing source to the inventory above.
4. Update this file with the new module entry.
5. Update `__BOS_AI/Entities/work_orders.md` with the new bundle.
6. Update `CLAUDE.md` bundle lists.
