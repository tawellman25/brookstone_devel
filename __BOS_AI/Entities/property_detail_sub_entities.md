# BOS Property Detail Sub-Entities

These entities record service-specific facts about a property. All follow the same pattern:
- `field_property` → `properties` (required reference to parent property)
- Read by `wo_*` modules at WO creation to pre-populate WO fields
- Written to by `wo_*` modules at WO completion to record "last completed" history
- Do not delete if they contain service history

See `property_detail_entities.md` for the architectural pattern.

---

# BOS Entity — property_christmas_decor

Entity Type ID: `property_christmas_decor`
Storage: ECK

## Bundles
`information`

## Purpose
- Christmas decoration job facts and contracted status for a property.

## Required Relationships
- `field_property` → `properties`

## Key Fields
- `field_christmas_decor_contracted` — boolean: contracted for Christmas decorations
- `field_christmas_decor_lightcolor` → taxonomy term: light colors
- `field_christmas_decor_light_type` → taxonomy term: light types
- `field_christmas_decor_photos` — finished installation photos
- `field_christmas_light_diagram` — installation diagram
- `field_xmas_installation_photos` — installation process photos

## Invariants
- One record per property. Do not create duplicates.

---

# BOS Entity — property_fertilizing_info

Entity Type ID: `property_fertilizing_info`
Storage: ECK

## Bundles
- `lawn_fertilizing_information` — lawn fertilizing history and settings
- `shrub_and_tree_fertilizing` — shrub/tree fertilizing history

## Required Relationships
- `field_property` → `properties`

## Key Fields
- `field_lawn_fertilizer_contracted` / `field_shrub_tree_fert_contracted` — contracted boolean
- `field_fertilized_last` — datetime: last fertilized date (written by `wo_fertilizing` / `wo_fertilizing_trees_and_shrubs` on completion)
- `field_fertilized_last_by` → `user` — who last fertilized
- `field_fertilizer_lbs_applied` — last amount applied
- `field_fertilizer_notes` — instructions for crew (lawn bundle)
- `field_fertilizer_route_order` — route ordering (lawn bundle)
- `field_turf_pre_emergent_property` — boolean flag (lawn bundle)

## Invariants
- `field_fertilized_last` and `field_fertilized_last_by` are written back by `wo_fertilizing` module on WO completion.

---

# BOS Entity — property_instructions

Entity Type ID: `property_instructions`
Storage: ECK

## Bundles
`residential`

## Purpose
- Property-specific service instructions visible to crew. Free-text instructions for any service type.

## Required Relationships
- `field_property` → `properties`

## Key Fields
- `field_instruction_notes` — long text: instruction content
- `uid` (base) — who entered the instruction
- `created` (base) — when entered

## Invariants
- Multiple instruction records per property are allowed (append pattern).
- Read by crew on WO detail views.

---

# BOS Entity — property_landscape_details

Entity Type ID: `property_landscape_details`
Storage: ECK

## Bundles
`current`

## Purpose
- Landscape measurements, tree/shrub counts, and contracted service flags for a property.
- Key source for billing calculations on aerating, dethatching, weed pulling, and landscaping WOs.

## Required Relationships
- `field_property` → `properties`

## Key Fields
- `field_turf_sq_footage` — turf square footage; read by `wo_aerating` and `wo_dethatching` for billing
- `field_number_of_trees` — total tree count
- `field_number_of_shrubs` — shrub count
- Tree type breakdown: `field_number_of_trees_aspen`, `field_number_of_trees_bluespruce`, `field_number_of_trees_deciduous`, `field_number_of_trees_frui`, `field_number_of_trees_pinyon`
- `field_num_backflows` — number of backflows on property
- Contracted flags: `field_fall_cleanup_contracted`, `field_spring_cleanup_contracted`, `field_summer_pruning_contracted`, `field_weed_pulling_contracted`, `field_deer_protection_contracted`, `field_new_landscaping_contracted`
- `field_last_pulled_by` → `user`, `field_last_pulled_date` — weed pulling history

## Invariants
- `field_turf_sq_footage` is the authoritative source for area-based billing on lawn services.
- Written back by `wo_weed_pulling` on completion (`field_last_pulled_by`, `field_last_pulled_date`).

---

# BOS Entity — property_lawn_maintenance

Entity Type ID: `property_lawn_maintenance`
Storage: ECK

## Bundles
`lawn_maintenance_info`

## Purpose
- Mowing schedule, assignment, and history for a property.

## Required Relationships
- `field_property` → `properties`

## Key Fields
- `field_mowing_contracted` — boolean: mowing contracted
- `field_mowing_assigned_to` → `user` — assigned mow crew
- `field_mowing_frequency` → taxonomy term: how often
- `field_mowing_weekday` — day of week to mow
- `field_mowing_priority` — integer priority for route ordering
- `field_mowing_last_mowed` — datetime (written back by `wo_lawn_mowing` on completion)
- `field_mowing_last_mowed_by` → `user` (written back on completion)
- `field_mowing_instructions` — special mowing instructions for crew
- `field_mowing_dumping_location` — where clippings are dumped
- `field_aerating_lawns_contracted`, `field_dethatching_contracted` — contracted flags

## Invariants
- `field_mowing_last_mowed` and `field_mowing_last_mowed_by` are written back by `wo_lawn_mowing` on completion.
- `field_mowing_assigned_to` is read by scheduling to pre-populate mowing WOs.

---

# BOS Entity — property_snow_removal_info

Entity Type ID: `property_snow_removal_info`
Storage: ECK

## Bundles
`information`

## Purpose
- Snow removal configuration, plow route, and history for a property.

## Required Relationships
- `field_property` → `properties`
- `field_snow_removal_assigned_to` → `user` (plow driver)
- `field_plow_route` → taxonomy term
- `field_app` → `client_app` (client check-in app reference)

## Key Fields
- `field_snow_removal_contracted` — boolean: contracted for plowing
- `field_snow_removal_assigned_to` → `user` — assigned plow driver
- `field_plow_route` → taxonomy term — which plow route
- `field_snow_removal_priority` — integer plow priority
- `field_snow_removal_minimum` — snow level trigger (list)
- `field_snow_removal_max_salt` — max salt per visit (list)
- `field_snow_removal_salt` — boolean: salt this property
- `field_snow_removal_sidewalks` — boolean: shovel sidewalks
- `field_snow_removal_icy_condition` — icy condition check (list)
- `field_snow_removal_instructions` — special instructions
- `field_snow_removal_map` — plow map image
- `field_snow_removal_last_plowed` — datetime (written back by `wo_snow_removal` on completion)
- `field_snow_removal_last_plow_by` → `user` (written back on completion)
- `field_snow_removal_last_salt_amt` — last salt amount (written back on completion)
- `field_snow_last_mag_amount` — last mag chloride amount (written back on completion)
- `field_snow_removal_complete_by` — complete by time string

## Invariants
- `field_snow_removal_last_plowed`, `field_snow_removal_last_plow_by`, `field_snow_removal_last_salt_amt`, `field_snow_last_mag_amount` are all written back by `wo_snow_removal` on completion.
- `field_app` references `client_app` for properties using a client check-in app.

---

# BOS Entity — property_spraying_info

Entity Type ID: `property_spraying_info`
Storage: ECK

## Bundles
`aspen_twig_gall`, `cooley_spruce`, `deciduous_bore`, `dormant_oil`, `grub_prevention`, `ips_beetle`, `pre_emergent`, `trunk_bore`, `weed_spraying`

## Purpose
- Per-service spray history, contracted status, and application notes for a property.
- One bundle per spray service type.

## Required Relationships
- `field_property` → `properties`

## Key Fields (all bundles)
- `field_*_contracted` — boolean: contracted for this service (name varies per bundle)
- `field_last_applied_date` — datetime: last application date (written back by corresponding `wo_*` module)
- `field_last_applied_by` → `user` (written back on completion)
- `field_last_amount_applied` — last amount applied (written back on completion)
- `field_spray_map` — image: spray map for this service
- `field_spray_notes` — application notes for crew

### weed_spraying additional fields
- `field_weed_beds_contracted`, `field_weed_misc_contracted` — separate contracted flags per area type
- `field_beds_spraying_frequency` → taxonomy term
- `field_misc_spraying_frequency` → taxonomy term
- `field_spray_route` — boolean: include on spray route
- `field_route_order` — integer route ordering

### pre_emergent additional fields
- `field_last_chemicals` → `chemical` — which chemical was last used
- `field_last_season_applied` — season list
- `field_spray_map_file` — Corel Draw file

## Invariants
- `field_last_applied_date`, `field_last_applied_by`, `field_last_amount_applied` written back by the corresponding `wo_*` module on completion.
- One record per service per property.

---

# BOS Entity — property_sprinkler_design

Entity Type ID: `property_sprinkler_design`
Storage: ECK

## Bundles
`design`

## Purpose
- Stores the irrigation system design files (CAD, PDF, diagrams) for a property.
- Linked to the design work order that produced it.

## Required Relationships
- `field_property` → `properties`
- `field_work_order` → `work_order` (optional — attached design WO)

## Key Fields
- `field_sprinkler_design` — image: design diagram
- `field_corel_draw_file` — file: Corel Draw design file
- `field_design_pdf_file` — file: PDF design file

## Invariants
- Design files should be retained indefinitely — do not delete.

---

# BOS Entity — property_sprinkler_info

Entity Type ID: `property_sprinkler_info`
Storage: ECK

## Bundles
`general_information`

## Purpose
- General irrigation system facts, contracted services, and check-up history for a property.
- Parent record for the sprinkler system sub-entity graph.

## Required Relationships
- `field_property` → `properties`
- `field_systems` → `property_sprinkler_system` (the systems on this property)

## Key Fields
- `field_ss_start_up_contracted` — boolean
- `field_ss_shut_down_contract` — boolean
- `field_ss_check_contract` — boolean
- `field_field_ss_check_how_often` → taxonomy term: check-up frequency
- `field_last_system_check` — datetime (written back by `wo_sprinkler_check_up` on completion)
- `field_last_checked_by` → `user` (written back on completion)
- `field_ss_check_instructions` — special instructions
- `field_ss_start_up_notes` — start-up notes for crew
- `field_ss_winterizing_notes` — winterizing notes for crew

## Invariants
- `field_last_system_check` and `field_last_checked_by` written back by `wo_sprinkler_check_up` on completion.
- `field_systems` links to one or more `property_sprinkler_system` entities.

---

# BOS Entity — property_sprinkler_pumps

Entity Type ID: `property_sprinkler_pumps`
Storage: ECK

## Bundles
`pump`

## Purpose
- Records pump details for irrigation systems that use pumps (dirty water / well water sources).

## Required Relationships
- `field_property_ss_source` → `property_ss_sources`
- `field_pump_in_house_item` → `material` (optional — stocked pump)
- `field_pump_manufactured` → `manufacturer`

## Key Fields
- `field_pump_size` — horsepower (list)
- `field_pump_phase` — phase (list)
- `field_pump_volts` — volts (list)
- `field_pump_suction_size` — suction size (list)
- `field_pump_discharge_size` — discharge size (list)
- `field_pump_model_number`, `field_pump_serial_number` — identifiers
- `field_pump_serial_number_pic` — serial number photo
- `field_pump_configuration_photos` — configuration photos
- `field_pump_location` — location description on property

---

# BOS Entity — property_sprinkler_system

Entity Type ID: `property_sprinkler_system`
Storage: ECK

## Bundles
`system`

## Purpose
- System-level record for one irrigation system on a property. A property may have multiple systems.
- Links zones, water sources, and controller to a named system.

## Required Relationships
- `field_property` → `properties`
- `field_property_system_info` → `property_sprinkler_info`
- `field_water_sources` → `property_ss_sources`
- `field_ss_zones` → `property_ss_zones` (multi-value)
- `field_system_type` → `sprinkler_system_types`

## Key Fields
- `field_system_name` — name of the system
- `field_total_zones` — total zone count
- `field_operation` → taxonomy term: how the system operates
- `field_complexity_level` → taxonomy term
- `field_controler` → `material` (the controller unit)
- `field_zone_map` — image: zone map
- `field_old_system_id` — legacy ID field (migration artifact)

---

# BOS Entity — property_ss_sources

Entity Type ID: `property_ss_sources`
Storage: ECK

## Bundles
- `domestic_source` — city/domestic water
- `dirty_water_source` — pond/ditch/canal
- `well_water_source` — well

## Purpose
- Records the water source(s) for an irrigation system. One entity per source.

## Required Relationships
- `field_property_ss_system` → `property_sprinkler_system`
- `field_pumps` → `property_sprinkler_pumps` (dirty_water_source only)
- `field_ss_backflow` → `material` (domestic_source only — backflow device)

## Key Fields
- `field_ss_source_name` — source name
- `field_ss_shut_off_notes` — shut-off notes
- `field_ss_shut_off_location_pic` — shut-off location photo
- `field_ss_key_needed` — key required (list)
- `field_water_shut_off` — shut-off description (dirty_water_source)
- `field_source_size` — size (domestic, well)
- `field_ss_shut_off_location` — shut-off location description (domestic, well)
- `field_dirty_water_source_type` — source type (dirty_water_source)
- `field_location` — location on property (dirty_water_source)
- `field_ss_backflow_location` — backflow location (domestic)
- `field_ss_source_valve` — valve description (domestic, well)

---

# BOS Entity — property_ss_zones

Entity Type ID: `property_ss_zones`
Storage: ECK

## Bundles
`zone`

## Purpose
- Individual irrigation zone details. One entity per zone per system.

## Required Relationships
- `field_property` → `properties`
- `field_property_ss_system` → `property_sprinkler_system`
- `field_ss_zone_sprinkler_types` → `sprinkler_types`
- `field_ss_valve_type` → `material`

## Key Fields
- `field_zone_name` — zone name
- `field_clock_number` — clock number
- `field_clock_zone_number` — clock zone number
- `field_ss_zone_type` — zone type (list)
- `field_lateral_lines` — lateral line type (list)
- `field_ss_zone_location` — location description
- `field_ss_zone_swing_pipe` — boolean: swing pipe used
- `field_manual_automatic` — boolean: manual vs automatic
- `field_ss_valve_box_location` — valve box location description
- `field_ss_valve_box` — valve box identifier
- `field_valve_box_gps_coordinates` — geofield: GPS coordinates
- `field_ss_zone_photos` — zone photos

## Invariants
- `field_ss_zone_watering_time` data lives in the separate `property_zone_watering_time` entity.

---

# BOS Entity — property_system_controller

Entity Type ID: `property_system_controller`
Storage: ECK

## Bundles
`controller`

## Purpose
- Controller details for an irrigation system. One entity per controller.

## Required Relationships
- `field_property_ss_system` → `property_sprinkler_system`
- `field_controller` → `material` (the controller unit as a stocked material item)

## Key Fields
- `field_controller_number` — controller number
- `field_controller_type` — controller type (list_integer)
- `field_controller_location` — location notes
- `field_controller_description` — description
- `field_controller_photos` — photos
- `field_stocked_controller` — boolean: is this a stocked item

---

# BOS Entity — property_zone_watering_time

Entity Type ID: `property_zone_watering_time`
Storage: ECK

## Bundles
`watering_time`

## Purpose
- Per-zone watering time settings. Separate from zone entity to allow multiple program settings per zone.
- Written back by `wo_sprinkler_check_up` module when watering times are adjusted during a check-up.

## Required Relationships
- `field_ss_zone_referenced` → `property_ss_zones`

## Key Fields
- `field_ss_zone_run_time` — run time in minutes
- `field_ss_zone_clock_program` — clock program (list)
- `field_ss_zone_timechange_reason` — reason for time change
- `uid` (base) → `user` (who changed it — labeled "Changed By")
- `changed` (base) — when last changed

## Invariants
- Written back by `wo_sprinkler_check_up` on completion when watering times are adjusted.
- One record per zone per program; multiple records allowed per zone.
