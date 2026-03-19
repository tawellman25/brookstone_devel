# BOS Pathauto Patterns Reference

All active Pathauto patterns as of March 2026.

---

## Contracts

| Label | Pattern | Machine Name |
|---|---|---|
| Contracts - Residential Alias | `[contracts:field_property:entity:url:path]/contracts/[contracts:field_contract_year]` | contracts_residential_alias |

---

## Estimates

| Label | Pattern | Machine Name |
|---|---|---|
| Estimate Path | `[estimate:field_property:entity:url:path][estimate:field_name]/estimates/[estimate:field_estimate_type:entity:name]/[estimate:title]` | estimate_path |
| Estimate Component Alias | `[estimate:field_estimate_request:entity:url:path]/est/[estimate:id]` | estimate_component_alias |
| Estimate Request Alias | `[estimate_request:url:path]/estimates/req[estimate_request:id]` | estimate_request_alias |
| Estimate Note Paths | `[estimate_notes:url:path]` | estimate_note_paths |

---

## Work Orders

| Label | Pattern | Machine Name |
|---|---|---|
| Work Order Paths | `[work_order:field_property:entity:url:path]/work-orders/[work_order:title]` | work_order_paths |
| WO Scheduling pattern | `[scheduling:field_work_order:entity:url:path]/schedule` | admin_wo_scheduling_pattern |
| WO Chemicals Used Paths | `[wo_chemicals_used:field_work_order:entity:url:path]/[wo_chemicals_used:title]` | wo_chemicals_used_paths |
| WO Completion Info Paths | `[wo_complete_info:field_work_order:entity:url:path]/complete` | wo_completion_info_paths |
| WO Spraying Conditions Paths | `[wo_spraying_conditions:field_work_order:entity:url:path]/conditions` | wo_spraying_conditions_paths |
| WO Time Clock Paths | `[wo_time_clock:field_work_order:entity:url:path]/hours/[wo_time_clock:title]` | wo_time_clock_paths |
| WO Lawn Mowing Tasks Paths | `[wo_tasks_list:field_work_order:entity:url:path]/completed_info` | wo_lawn_mowing_tasks_paths |
| WO Snow Removal Tasks Paths | `[wo_tasks_list:field_work_order:entity:url:path]/tasks` | wo_snow_removal_tasks_paths |
| WO Material Lists | `[wo_material_list:field_work_order:entity:url:path]/[wo_material_list:field_materials_for]_materials` | material_lists |
| WO Material Item Paths | `[wo_material_list_item:field_list_id:entity:url:path]/[wo_material_list_item:field_list_id:entity:id]` | wo_material_item_paths |
| WO Dumping Record Path | `[wo_material_dumping:field_work_order:entity:url:path]/dumping_[wo_material_dumping:id]` | wo_dumping_record_path |
| WO Media Groups | `[media:field_work_order:entity:url:path]/[media:name]` | work_order_media_groups |
| Work Order Notes Path | `[wo_notes:field_work_order:entity:url:path]/[wo_notes:uid:entity:account-name]_note` | work_order_notes_path |
| Work Order Status Update Paths | `[wo_status_updates:field_status_of_wo:entity:url:path]/status_updates/[wo_status_updates:id]` | work_order_status_update_paths |
| WO Status Description Path | `teammate/employment/manual/website/work-orders/status/[term:name]` | wo_status_description_path |

---

## Properties

| Label | Pattern | Machine Name |
|---|---|---|
| Property | `[properties:field_zipcode_reference:entity:url:path]/[properties:field_street_address]` | property |
| Property - Christmas Decorations | `[property_christmas_decor:field_property:entity:url:path]/christmas-decor` | property_christmas_decorations |
| Property - Landscape Details Paths | `[property_landscape_details:url:path]/landscape_details` | property_landscape_details_paths |
| Property - Lawn Fertilizing Info Alias | `[property_fertilizing_info:field_property:entity:url:path]/lawn_fertilizing` | property_lawn_fertilizing_info_alias |
| Property - Shrubs and Tree Fertilizing Info Alias | `[property_fertilizing_info:field_property:entity:url:path]/shrub_and_tree_fertilizing` | property_shrubs_and_tree_fertilizing_info_alias |
| Property - Spray Aspen Twig Gall Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/aspen_twig_gall` | property_spray_aspen_twig_gall_alias |
| Property - Spray Cooley Spruce Gall Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/cooley_spruce_gall` | property_spray_cooley_spruce_gall_alias |
| Property - Spray Deciduous Bore Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/deciduous_bore` | property_spray_deciduous_bore_alias |
| Property - Spray Dormant Oil Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/dormant_oil` | property_spray_dormant_oil_alias |
| Property - Spray Grub Control Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/grub_prevention` | property_spray_grub_control_alias |
| Property - Spray Ips Beetle Gall Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/pinion_pine_ips_beetle` | property_spray_ips_beetle_gall_alias |
| Property - Spray Pre-emergent Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/pre-emergent` | property_spray_pre_emergent_alias |
| Property - Spray Trunk Bore Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/trunk_bore` | property_spray_trunk_bore_alias |
| Property - Spray Weed Spraying Alias | `[property_spraying_info:field_property:entity:url:path]/spray_info/weed_spraying` | property_spray_weed_spraying_gall_alias |
| Property - Sprinkler Info | `[property_sprinkler_info:field_property:entity:url:path]/sprinkler-information` | property_sprinkler_info |
| Property - Sprinkler Design Path | `[property_sprinkler_design:field_property:entity:url:path]/sprinkler-information/design` | property_sprinkler_design_path |
| Property - Sprinkler Systems | `[property_sprinkler_system:field_property_system_info:entity:url:path]/[property_sprinkler_system:field_system_name]-system` | property_sprinkler_systems |
| Property - Sprinkler System Controller Path | `[property_system_controller:field_property_ss_system:entity:url]/[property_system_controller:field_controller_type]-controller` | property_sprinkler_system_controller_path |
| Property SS Source | `[property_ss_sources:field_property_ss_system:entity:url:path]/[property_ss_sources:field_ss_source_name]-source` | property_ss_source |
| Property SS Pump | `[property_sprinkler_pumps:field_property_ss_source:entity:url:path]/[property_sprinkler_pumps:title]` | property_ss_pump |
| Sprinkler Zone | `[property_ss_zones:field_property_ss_system:entity:url:path]/zone-[property_ss_zones:field_clock_zone_number]` | sprinkler_zone |
| Ownership Record | `[ownership_record:field_property_reference:entity:url:path]/[ownership_record:title]` | ownership_record |
| Testimonials | `[testimonial:field_customer:entity:url:path]/[testimonial:field_testimony_service:entity:name]-testimonial-[testimonial:id]` | testimonials |

---

## Chemicals

| Label | Pattern | Machine Name |
|---|---|---|
| Herbicide Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/herbicide/[chemical:field_name]` | herbicide_chemical_paths |
| Fungicide Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/fungicide/[chemical:field_name]` | fungicide_chemical_paths |
| Insecticide Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/insecticide/[chemical:field_name]` | insecticide_chemical_paths |
| Fertilizer Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/fertilizer/[chemical:field_name]` | chemical_paths |
| Indicator Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/indicator/[chemical:field_name]` | indicator_chemical_paths |
| Surfactant Chemical Paths | `services/landscape-lawn-care/spraying/chemicals/surfactant/[chemical:field_name]` | surfactant_chemical_paths |
| Chemical Type Paths | `services/landscape-lawn-care/spraying/chemicals/[classification:title]` | chemical_type_paths |

---

## Materials

| Label | Pattern | Machine Name |
|---|---|---|
| Material - Brass - Path | `material/brass/[material:title]` | materials_brass_path |
| Material - Copper - Path | `material/copper/[material:title]` | materials_copper_path |
| Material - Electric - Path | `material/electric/[material:title]` | material_electric_path |
| Material - Galvanized - Path | `material/galvanized/[material:title]` | material_galvanized_path |
| Material - Irrigation - Path | `material/irrigation/[material:title]` | material_irrigation_path |
| Material - Landscape - Path | `material/landscape/[material:title]` | material_landscape_path |
| Material - Lighting - Path | `material/lighting/[material:title]` | material_lighting_path |
| Material - Miscellaneous - Path | `material/misc/[material:title]` | material_misc_path |
| Material - Pavers - Path | `material/pavers/[material:title]` | material_pavers_path |
| Material - Plants - Path | `material/plants/[material:title]` | material_plants_path |
| Material - Poly - Path | `material/poly/[material:title]` | material_poly_path |
| Material - Pumps - Path | `material/pumps/[material:title]` | material_pumps_path |
| Material - PVC - Path | `material/pvc/[material:title]` | material_pvc_path |
| Material - Rock - Path | `material/rock/[material:title]` | material_rock_path |
| Material - Shrubs - Path | `material/shrubs/[material:title]` | material_shrubs_path |
| Material - Sod - Path | `material/sod/[material:title]` | material_sod_path |
| Material - Trees - Path | `material/trees/[material:title]` | material_trees_path |
| Material - Supplier Info Path | `[material_suppliers:field_material:entity:url:path]/suppliers/[material_suppliers:field_supplier:entity:field_supplier_name]` | material_supplier_info_path |
| Manufacturer Paths | `material/[manufacturer:title]` | manufacturer_paths |
| Supplier Aliases | `materials/[supplier:title]` | supplier_aliases |

---

## Equipment

| Label | Pattern | Machine Name |
|---|---|---|
| Equipment | `[equipment:field_equipment_type:entity:url:path]/[equipment:title]` | equipment |
| Equipment Types | `about-us/our-equipment/[term:name]` | equipment_types |
| Equipment Check In/out | `[equipment_check_in_out:field_equipment_name:entity:url:path]/status/[equipment_check_in_out:field_checking_equipment]-[equipment_check_in_out:created:date:custom:h-i-a--m-d-Y]` | equipment_check_in_out |

---

## Geography

| Label | Pattern | Machine Name |
|---|---|---|
| State Paths | `[state:field_state_name]` | state_paths |
| County Paths | `[county:field_state:entity:field_state_name]/[county:title]` | county_paths |
| City Paths | `[city:field_county:entity:url:path]/[city:field_city_name]` | city_paths |
| Zipcode Path Aliases | `[zipcodes:field_city:entity:url]/[zipcodes:field_zipcode]` | zipcode_path_alliases |

---

## Teammates / HR

| Label | Pattern | Machine Name |
|---|---|---|
| User Pages | `[user:roles:last]/[user:name]` | user_pages |
| Department Aliases | `/teammates/[department:title]` | department_aliases |
| Crew Type Alias | `teammates/[crew_types:field_department]/[crew_types:title]` | crew_type_alias |
| Company Positions Path | `/teammates/positions/[positions:title]` | company_positions_aliase |
| Client App Path | `teammates/client-apps/[client_app:title]` | client_app_path |
| Employment Note Aliases | `[employment:field_employee:entity:url:path]/employment-notes/[employment:created]` | employment_note_aliases |
| Teammate - Emergency Contacts Aliases | `teammates/[contacts:field_teammate:entity:display-name]/contacts/[contacts:field_first_name]-[contacts:field_last_name]` | teammate_emergency_contacts_aliases |
| Teammate Handbook Aliases | `[handbook:field_parent_page:entity:url]/[handbook:title]` | teammate_handbook_aliases |
| Teammate Manual Page Alias | `[node:book:parent:url:path]/[node:title]` | teammate_manual_page_alias |
| Time Clock Entries | `[time_clock_entry:field_team_member:entity:url:path]/time-card/[time_clock_entry:title]` | time_clock_entries |
| SOP Aliases | `teammates/sop/[sop:type]/[sop:field_sop_code]` | sop_aliases |

---

## Services / Taxonomy

| Label | Pattern | Machine Name |
|---|---|---|
| Services Paths | `[term:parent:url:path]/[term:name]` | services_paths |
| Sprinkler System Types | `services/sprinkler-system/[sprinkler_system_types:title]` | sprinkler_system_types |
| Sprinkler Types | `services/sprinkler-system/types-of-sprinklers/[sprinkler_types:title]` | sprinkler_types |
| Christmas Light Colors | `services/christmas-decorations/lights/[term:name]` | christmas_light_colors |
| Christmas Light Types | `services/christmas-decorations/lights/[term:name]` | christmas_light_types |
| Snow Plow Route Paths | `teammate/snow-removal/routes/[term:field_route_name]` | snow_plow_route_paths |
| WO Status Description Path | `teammate/employment/manual/website/work-orders/status/[term:name]` | wo_status_description_path |

---

## Notes

- Patterns use Drupal token syntax: `[entity:field:...]`
- `url:path` resolves to the already-aliased path of the referenced entity
- Changes to a parent entity path will cascade and regenerate child paths (if bulk update is run)
- Run `drush pathauto:aliases-generate` to regenerate all aliases after pattern changes

---

*Generated: March 2026*