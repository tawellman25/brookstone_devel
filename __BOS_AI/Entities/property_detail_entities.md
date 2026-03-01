# BOS Entities — Property Detail Sub-Entities

Entity category: Property Detail Sub-Entities
Storage: ECK entity types

---

## Purpose

Property detail sub-entities store service-specific facts and history about a Property. Each entity type corresponds to a service domain (sprinkler, snow removal, fertilizing, spraying, etc.).

These entities are **not static data storage** — they participate in a bidirectional pattern with the Work Order system:

- **Read direction:** `wo_*` modules read from property detail entities to pre-populate Work Order fields at WO creation or save (e.g., pulling current lawn sq footage into an aerating WO).
- **Write direction:** `wo_*` modules write "last completed" data back to property detail entities when a Work Order is marked Complete (e.g., updating the last plow date on snow removal WO completion).

This means property detail entities accumulate running history as work is performed, and serve as the input source for future work on the same property.

---

## Common Rules (All Property Detail Entities)

- Every property detail entity must have `field_property` → `properties` (required reference to parent property).
- Property detail entities must never be created without a valid parent property.
- Property detail entities are owned by the property record, not by a user.
- Deleting a property must explicitly handle its detail sub-entities (no orphans).
- Do not store Work Order execution data here. These entities hold settings and history, not WO records.
- When a `wo_*` module writes back to a property detail entity, it must load by `field_property` and update the existing record — it must never create duplicates.

---

## Entity Types

### `property_christmas_decor`

Bundle: `information`

Purpose: Stores Christmas/holiday decoration facts for the property.

Integration: Written to by `wo_christmas_decorations` on WO completion.

---

### `property_fertilizing_info`

Bundles:
- `lawn_fertilizing_information` — lawn fertilizing history and settings
- `shrub_and_tree_fertilizing` — shrub/tree fertilizing history and settings

Purpose: Tracks fertilizing settings, last applied dates, and product details per property.

Integration:
- Read by `wo_fertilizing` and `wo_fertilizing_trees_and_shrubs` at WO save to pre-populate product/rate fields.
- Written to by those same modules on WO completion to record last applied date, product used, and who signed off.

---

### `property_instructions`

Bundle: `residential`

Purpose: Property-specific service instructions visible to crew. May include gate codes, call-ahead requirements, special access notes, and service-specific directions.

Note: General instructions that apply across all services should use `properties.field_work_order_note`. This entity is for extended, service-context instructions.

---

### `property_landscape_details`

Bundle: `current`

Purpose: Stores current landscape measurements for the property.

Key fields (known):
- `field_turf_sq_footage` — current turf square footage

Integration:
- Read by `wo_aerating` at presave: if `work_order:aerating.field_current_turf_sq_footage` is empty, it is auto-populated from this entity.
- The `sq_ft_break_points` ECK entity (referenced from the `business_setting` config page) uses this sq footage to look up the aeration rate.

---

### `property_lawn_maintenance`

Bundle: `lawn_maintenance_info`

Purpose: Mowing specifications and lawn maintenance history for the property (e.g., mowing height, frequency, last mow date).

Integration: Read/written by `wo_lawn_mowing` module.

---

### `property_snow_removal_info`

Bundle: `information`

Purpose: Tracks snow removal history and settings for the property.

Key fields (known):
- `field_snow_removal_last_plowed` — datetime of last plow
- `field_snow_removal_last_plow_by` — user who last plowed
- `field_snow_removal_last_salt_amt` — pounds of salt used on last visit
- `field_snow_last_mag_amount` — gallons of mag chloride used on last visit

Integration:
- Written to by `wo_snow_removal` on WO completion (`hook_entity_insert` / `hook_entity_update`).
- The snow removal contract (`contracts:snow_removal`) provides per-push rate and shoveling-included flag read by `wo_snow_removal` presave.

---

### `property_spraying_info`

Bundles (one per spray service):
- `aspen_twig_gall`
- `cooley_spruce`
- `deciduous_bore`
- `dormant_oil`
- `grub_prevention`
- `ips_beetle`
- `pre_emergent`
- `trunk_bore`
- `weed_spraying`

Purpose: Per-spray-service history and settings. Each bundle tracks last applied date, product used, rate/dilution, and conditions specific to that spray service.

Integration:
- Read by the corresponding `wo_*` spray module at WO save to pre-populate application details.
- Written to by that same module on WO completion to record the latest application.

Note: The bundle machine name mirrors the `wo_chemicals_used` and `work_order` bundle names for the same service, making cross-referencing consistent.

---

### `property_sprinkler_design`

Bundle: `design`

Purpose: Records the sprinkler system design specifications (layout, coverage, head types, zone map).

Integration: Read by `wo_sprinkler_design` and `wo_sprinkler_installation` modules.

---

### `property_sprinkler_info`

Bundle: `general_information`

Purpose: General sprinkler system facts (system type, controller type, number of zones, water source).

Integration: Read by all sprinkler-related `wo_*` modules at WO creation/save.

---

### `property_sprinkler_pumps`

Bundle: `pump`

Purpose: Details about the sprinkler pump(s) on the property (make, model, HP, location).

Integration: Read by sprinkler `wo_*` modules when pump-specific work is involved.

---

### `property_sprinkler_system`

Bundle: `system`

Purpose: Overall sprinkler system overview (installation date, last backflow test date, backflow device details).

Integration: Read/written by `wo_sprinkler_start_up`, `wo_sprinkler_winterizing`, `wo_backflow_testing` modules.

---

### `property_ss_sources`

Bundles:
- `dirty_water_source` — details about a dirty/grey water irrigation source
- `domestic_source` — details about a domestic (municipal/potable) water source
- `well_water_source` — details about a well water source

Purpose: Documents water sources feeding the sprinkler system. Multiple sources may exist (e.g., a domestic and a well).

Integration: Read by sprinkler `wo_*` modules; relevant for backflow testing compliance records.

---

### `property_ss_zones`

Bundle: `zone`

Purpose: Individual sprinkler zone details (zone number, head type, area covered, plant type, GPM).

Note: Multiple zone records per property are expected (one per zone).

Integration: Read by `wo_sprinkler_check_up` and scheduling/crew modules.

---

### `property_system_controller`

Bundle: `controller`

Purpose: Irrigation controller details (make, model, location, programming notes).

Integration: Read/written by `wo_sprinkler_start_up` and `wo_sprinkler_winterizing`.

---

### `property_zone_watering_time`

Bundle: `watering_time`

Purpose: Per-zone watering time settings (run time in minutes per zone).

Note: Multiple records per property (one per zone).

Integration: Read by scheduling-related modules and displayed to crew on sprinkler start-up WOs.

---

## Relationship to Work Orders — Full Pattern

```
Property (properties)
  └── property_* detail entities (service-specific history + settings)
        ↕  (bidirectional)
Work Orders (work_order)
  └── wo_* modules (one per bundle)
        ├── presave: read from property_* to pre-populate WO fields
        └── insert/update: write "last completed" back to property_*
```

The `wo_*` module pattern depends on this relationship. Do not delete or restructure property detail entities without understanding which `wo_*` modules read from or write to them.

---

## Deletion / Archival

- Do not delete property detail entities if they contain service history.
- If a property is archived (no longer serviced), archive its detail sub-entities in place — do not delete.
- Deletion of a parent property must cascade-handle or explicitly check all child detail entities.

---

## Documentation Status

These entities exist in `config/sync/eck.eck_type.*` but are not yet fully documented per the `01_entities_policy.md` standard (individual field inventories, access rules, state machines). Individual entity files should be created in this folder as each service domain is formalized.

Files to create:
- `property_sprinkler_info.md`
- `property_snow_removal_info.md`
- `property_fertilizing_info.md`
- `property_spraying_info.md`
- `property_landscape_details.md`
- *(and remaining property_* types)*
