# Business Setting (config_pages:business_setting)

**Purpose:** Central rate table for all billing calculations across BOS. This is the single config page that holds global pricing rates, labor rates, equipment fees, minimums, and material markup. Nearly every `wo_*` module reads from this entity at presave to calculate billing totals.

**Entity type:** `config_pages` (Config Pages module)
**Bundle:** `business_setting`
**Total fields:** 80 (78 with field instances + 2 storage-only)

---

## How It Works

When a Work Order is marked Complete (status 1097), the bundle-specific `wo_*` module loads `business_setting` and reads the relevant rates to calculate billing fields. The config page acts as a **live rate table** — changing a value here changes all future WO billing calculations immediately.

**Loading pattern in code:**
```php
$config_pages = \Drupal::service('config_pages.loader');
$business_setting = $config_pages->load('business_setting');
$rate = (float) $business_setting->get('field_some_rate')->value;
```

---

## Field Inventory by Group

Fields are organized into collapsible groups on the admin form. Groups are listed in form display order.

### Aeration Pricing

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_aeration_pricing` | entity_reference → sq_ft_break_points | Aeration Pricing | wo_aerating |

References `sq_ft_break_points:aeration` entities for area-based pricing breakpoints.

### Dethatching Pricing

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_dethatching_pricing` | entity_reference → sq_ft_break_points | Dethatching Pricing | wo_dethatching |

References `sq_ft_break_points:dethatching` entities for area-based pricing breakpoints.

### Mowing Pricing

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_mow_rate_per_sq_ft` | decimal | Mow Rate Per Sq Ft $ | est_lawn_mowing |
| `field_edging_minimum_time` | decimal | Edging Minimum Time | wo_lawn_mowing |
| `field_debris_minimum_time` | decimal | Debris Minimum Time | wo_lawn_mowing |
| `field_trash_minimum_time` | decimal | Trash Minimum Time | wo_lawn_mowing |

### Fertilizing

#### Fertilizer and Broadleaf Lawn Rates (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_fertilizing_minimum` | decimal | Fertilizing Minimum | wo_fertilizing |
| `field_fetilizing_6k_20k_per_ft` | decimal | Fertilizing Over 6,000 to 20,000 Rate | wo_fertilizing |
| `field_fetilize_20k_80k_per_ft` | decimal | Fertilizing Over 20,000 to 80,000 Rate | wo_fertilizing |
| `field_fetilizing_80k_plus_per_ft` | decimal | Fertilizing 80,000 Plus Rate | wo_fertilizing |

> **Permanent typo:** `field_fetilize_*` / `field_fetilizing_*` (missing 'r' in fertilize). Do not rename.

#### Fertilizing Trees and Shrubs (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_fertilizing_tree_min_time` | integer | Minimum Time | wo_fertilizing_trees_and_shrubs |
| `field_fertilizer_tree_rate` | decimal | Fertilizer Rate | wo_fertilizing_trees_and_shrubs |
| `field_spreader_charge` | decimal | Spreader Fee | wo_fertilizing, wo_fertilizing_trees_and_shrubs, wo_grub_prevention |

### Irrigation Fees

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_sprinkler_tech_minimum` | decimal | Sprinkler Technician Minimum | wo_sprinkler_check_up, wo_sprinkler_design, wo_backflow_testing, wo_sprinkler_installation, wo_sprinkler_repair |
| `field_start_up_base_fee` | decimal | Start up Base Fee | wo_sprinkler_start_up |
| `field_winterizing_base_fee` | decimal | Winterizing Base Fee | wo_sprinkler_winterizing |
| `field_winterizing_zone_limit` | integer | Winterizing Zone Limit | wo_sprinkler_start_up, wo_sprinkler_winterizing |
| `field_winterizing_extra_zone_fee` | decimal | Winterizing Extra Zone Fee | wo_sprinkler_start_up, wo_sprinkler_winterizing |
| `field_winterizing_pump_fee` | decimal | Winterizing Pump Fee | wo_sprinkler_start_up, wo_sprinkler_winterizing |
| `field_backflow_testing_rate` | decimal | Backflow Testing Rate $ | est_backflow_testing |

### Labor Rates

Contains both **billing rates** (charged to client) and **labor costs** (internal cost tracking).

#### Billing Rates and Minimums

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_hour_billing_increment` | decimal | Hour Billing Increment | 17+ modules (nearly all wo_* modules) |
| `field_general_minimum_time` | decimal | General Minimum Time | wo_landscaping, wo_misc_services |
| `field_landscape_labor_rate` | decimal | Landscape Labor | *not used in code* |
| `field_maintenance_crew_labor` | decimal | Maintenance Crew | wo_christmas_decorations, wo_deer_prevention, wo_exterior_lighting, wo_fall_cleanup, wo_in_house_tasks, wo_landscape_lighting, wo_landscaping, wo_lawn_mowing, wo_misc_services, wo_special_mowing, wo_spring_cleanup, wo_summer_pruning, wo_weed_pulling, wo_winter_pruning, wo_estimate, wo_sprinkler_installation |
| `field_cleanup_labor_minimum` | decimal | Clean-up Labor Minimum | wo_christmas_decorations, wo_deer_prevention, wo_exterior_lighting, wo_fall_cleanup, wo_in_house_tasks, wo_landscape_lighting, wo_special_mowing, wo_spring_cleanup, wo_summer_pruning, wo_weed_pulling, wo_winter_pruning |
| `field_spray_crew_labor` | decimal | Spray Crew | wo_aspen_twig_gall, wo_cooley_spruce_gall, wo_deciduous_bore, wo_dormant_oil, wo_fertilizing, wo_fertilizing_trees_and_shrubs, wo_grub_prevention, wo_pinion_pine_ips_beetle, wo_pre_emergent, wo_trunk_bore, wo_weed_spraying |
| `field_sprinkler_technician_rate` | decimal | Sprinkler Technician | wo_sprinkler_check_up, wo_sprinkler_design, wo_sprinkler_start_up, wo_sprinkler_winterizing, wo_backflow_testing, wo_sprinkler_repair |
| `field_lighting_crew_labor_rate` | decimal | Lighting Crew Labor Rate | *not used in code* |
| `field_snow_removal_labor` | decimal | Snow Removal Labor | wo_snow_removal |

#### Internal Labor Costs (reference only — not used in billing calculations)

| Field | Type | Label |
|---|---|---|
| `field_labor_cost_landscape` | decimal | Landscape Labor Cost |
| `field_labor_cost_maintenance` | decimal | Maintenance Crew Labor Cost |
| `field_labor_cost_clean_up_crew` | decimal | Clean-up Crew |
| `field_labor_cost_decorations` | decimal | Decorations Crew Labor Cost |
| `field_labor_cost_fertilizing` | decimal | Fertilizing Crew Labor Cost |
| `field_labor_cost_spray_crew` | decimal | Spray Crew Labor Cost |
| `field_labor_cost_irrigation_crew` | decimal | Irrigation Crew Labor Cost |
| `field_labor_cost_lighting_crew` | decimal | Lighting Crew Labor Cost |
| `field_labor_cost_snow_removal` | decimal | Snow Removal Labor Cost |

> These 9 `field_labor_cost_*` fields are **not referenced in any custom module code**. They appear to be internal cost reference values for office use — not wired into any billing calculation.

### Legal Notices and Disclaimers

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_chemical_license_info` | text_long | Chemical License Info | *display only* |
| `field_estimate_disclosure` | text_long | Estimate Disclosure | *display only* |

### Material Dumping

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_full_load_fee` | decimal | Full Load Fee | wo_dump_fees |
| `field_1_2_load_fee` | decimal | 1/2 Load Fee | wo_dump_fees |
| `field_1_4_load_fee` | decimal | 1/4 Load Fee | wo_dump_fees |

### Material Settings

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_markup` | decimal | Markup | wo_material_item_subtotal, wo_material_list_management, material (installed price calc) |

Global material markup multiplier. Applied to material cost to calculate installed price.

### Overseeding

| Field | Type | Label | Status |
|---|---|---|---|
| `field_overseeding_labor` | entity_reference → sq_ft_break_points | Overseeding Labor | **Storage only — no field instance** |
| `field_overseeding_seed_markup` | entity_reference → sq_ft_break_points | Overseeding Seed Markup | **Storage only — no field instance** |

> These two fields have storage configs but no field instances on business_setting. They are defined but not yet wired into the form or any module. Intended to reference `sq_ft_break_points:overseeding_labor` and `sq_ft_break_points:overseeding_seed_markup` entities.

### Spray Rates

Top-level fields:

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_atv_and_sprayer_charge` | decimal | ATV and Sprayer Charge | wo_pre_emergent, wo_snow_removal, wo_lawn_mowing |
| `field_tree_sprayer_rate` | decimal | Tree Sprayer Rate | wo_aspen_twig_gall, wo_cooley_spruce_gall, wo_deciduous_bore, wo_dormant_oil, wo_pinion_pine_ips_beetle, wo_trunk_bore |

#### Aspen Twig Gall (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_aspen_twig_tree_number` | integer | Price Break Tree Number | wo_aspen_twig_gall |
| `field_aspen_twig_5_tree_rate` | decimal | <= Break Number Tree Rate | wo_aspen_twig_gall |
| `field_aspen_twig_6_more_rate` | decimal | After Break Number Tree Rate | wo_aspen_twig_gall |
| `field_aspen_twig_gall_min_time` | decimal | Minimum Allotted Time | wo_aspen_twig_gall |

#### Cooley Spruce Gall (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_cooley_spruce_gall_rate` | decimal | Cooley Spruce Gall Rate | wo_cooley_spruce_gall |
| `field_cooley_spruce_min_time` | decimal | Minimum Time Allotted | wo_cooley_spruce_gall |

#### Deciduous Bore (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_deciduous_tree_number` | integer | Price Break Tree Number | wo_deciduous_bore |
| `field_deciduous_under_break_rate` | decimal | Under Price Break Rate | wo_deciduous_bore |
| `field_deciduous_over_break_rate` | decimal | Over Price Break Rate | wo_deciduous_bore |
| `field_deciduous_bore_min_time` | decimal | Minimum Allotted Time | wo_deciduous_bore |

#### Dormant Oil (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_dormant_oil_rate` | decimal | Dormant Oil Rate | wo_dormant_oil |
| `field_dormant_oil_min_time` | decimal | Minimum Time | wo_dormant_oil |
| `field_high_gallon_pice_break` | decimal | High Gallon Price Break | *not used in code* |

> **Permanent typo:** `field_high_gallon_pice_break` (missing 'r' in price). Do not rename.

#### Grub Prevention (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_grub_prevention_rate` | decimal | Grub Prevention Rate | wo_grub_prevention |
| `field_grub_min_time` | decimal | Minimum Allotted Time | wo_grub_prevention |

#### Pinion Pine Ips Beetle (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_ips_beetle_rate` | decimal | Pinion Pine Ips Beetle Rate | wo_pinion_pine_ips_beetle |
| `field_ips_beetle_min_time` | decimal | Minimum Time Allotted | wo_pinion_pine_ips_beetle |

#### Pre-emergent (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_pre_emergent_rate` | decimal | Pre-emergent Rate | wo_pre_emergent |
| `field_pre_emergt_min_time` | decimal | Minimum Allotted Time | wo_pre_emergent |
| `field_dye_rate` | decimal | Dye Rate | wo_pre_emergent |
| `field_pre_emergent_coverage` | integer | Pre-emergent Coverage | est_pre_emergent |

> **Permanent typo:** `field_pre_emergt_min_time` (missing 'en' in emergent). Do not rename.

#### Weed Spraying (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_minimum_spray_fee` | decimal | Minimum Spray Fee | wo_weed_spraying |
| `field_spray_trip_minimum` | decimal | Spray Trip Fee Minimum | wo_weed_spraying |
| `field_spray_minimum_allotted_tim` | decimal | Spray Minimum Allotted Time | wo_weed_spraying |
| `field_spray_minimum_gallon` | decimal | Spray Minimum Gallon | wo_weed_spraying |
| `field_additional_per_gallon_fee` | decimal | Additional per Gallon Fee | wo_weed_spraying |
| `field_bio_90_rate_per_gallon` | decimal | Bio-90 Rate (Ounces per 100 Gallon) | wo_chemical_used_subtotal |
| `field_hawkeye_rate_per_gallon` | decimal | Hawkeye Rate (Ounces per 100 Gallon) | wo_chemical_used_subtotal |

> **Truncated field name:** `field_spray_minimum_allotted_tim` (Drupal 32-char field name limit). Do not rename.

#### Trunk Bore (subgroup)

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_trunk_bore_rate` | decimal | Trunk Bore Rate | wo_trunk_bore |
| `field_trunk_bore_min_time` | decimal | Minimum Allotted Time | wo_trunk_bore |

### Snow Removal

| Field | Type | Label | Used By |
|---|---|---|---|
| `field_shoveling_minimum` | decimal | Shoveling Minimum | wo_snow_removal |
| `field_salt_rate` | decimal | Salt Rate | wo_snow_removal |
| `field_salt_pounds_per_bag` | integer | Pounds per Bag | wo_snow_removal |
| `field_min_bag_increment` | integer | Min Bag Increment | wo_snow_removal |
| `field_mag_chloride_rate` | decimal | Mag Chloride Rate | wo_snow_removal |
| `field_mag_minimum_gallons` | integer | Mag Minimum Gallons | wo_snow_removal |

### Trip Fees

Empty group with description. Per-zipcode trip fees are stored on `zipcodes.field_trip_fee`, not on business_setting. The `wo_sign_off` module reads trip fees from there.

---

## Cross-Cutting Usage Patterns

### Most-Used Fields

| Field | Module Count | Pattern |
|---|---|---|
| `field_hour_billing_increment` | 17+ | Nearly all wo_* modules use this to round labor time |
| `field_maintenance_crew_labor` | 16 | All maintenance/cleanup/landscape crew modules |
| `field_cleanup_labor_minimum` | 11 | All cleanup-type service modules |
| `field_spray_crew_labor` | 11 | All spray service modules |
| `field_sprinkler_technician_rate` | 6 | All sprinkler service modules |
| `field_tree_sprayer_rate` | 6 | All tree spray service modules |
| `field_markup` | 3 | Material pricing (wo_material_item_subtotal, wo_material_list_management, material) |

### Module-to-Rate Pattern

| Crew Type | Rate Field | Cost Field | Minimum Field |
|---|---|---|---|
| Maintenance/cleanup | `field_maintenance_crew_labor` | `field_labor_cost_maintenance` | `field_cleanup_labor_minimum` or `field_general_minimum_time` |
| Spray | `field_spray_crew_labor` | `field_labor_cost_spray_crew` | per-service `field_*_min_time` |
| Sprinkler | `field_sprinkler_technician_rate` | `field_labor_cost_irrigation_crew` | `field_sprinkler_tech_minimum` |
| Snow | `field_snow_removal_labor` | `field_labor_cost_snow_removal` | `field_shoveling_minimum` |
| Landscape | `field_landscape_labor_rate` | `field_labor_cost_landscape` | `field_general_minimum_time` |
| Lighting | `field_lighting_crew_labor_rate` | `field_labor_cost_lighting_crew` | `field_cleanup_labor_minimum` |

> **Rate vs Cost:** Rate fields are used in billing calculations (charged to client). Cost fields are internal reference values for margin analysis — not wired into any billing code.

---

## Fields Not Referenced in Code

The following fields exist on business_setting but are **not referenced in any custom module**:

| Field | Notes |
|---|---|
| `field_labor_cost_*` (9 fields) | Internal cost reference — not used in billing calculations |
| `field_landscape_labor_rate` | Office reference only |
| `field_lighting_crew_labor_rate` | Office reference only |
| `field_high_gallon_pice_break` | Dormant oil gallon threshold — not yet wired |
| `field_overseeding_labor` | Storage-only, no field instance |
| `field_overseeding_seed_markup` | Storage-only, no field instance |

---

## Related Entities

- **`sq_ft_break_points`** — Area-based pricing breakpoints referenced by `field_aeration_pricing`, `field_dethatching_pricing`, and (future) overseeding fields. Bundles: `aeration`, `dethatching`, `overseeding_labor`, `overseeding_seed_markup`.
- **`zipcodes`** — Per-zipcode trip fees (`field_trip_fee`), loaded by `wo_sign_off`, not stored on business_setting.
- **`contracts:snow_removal`** — Per-push rate (`field_per_push_rate`) for snow removal billing, loaded by `wo_snow_removal`.

---

## Permanent Typos (Do Not Rename)

| Field | Typo | Correct Spelling |
|---|---|---|
| `field_fetilize_20k_80k_per_ft` | fetilize | fertilize |
| `field_fetilizing_6k_20k_per_ft` | fetilizing | fertilizing |
| `field_fetilizing_80k_plus_per_ft` | fetilizing | fertilizing |
| `field_high_gallon_pice_break` | pice | price |
| `field_pre_emergt_min_time` | emergt | emergent |
| `field_spray_minimum_allotted_tim` | tim (truncated) | time (Drupal 32-char limit) |
