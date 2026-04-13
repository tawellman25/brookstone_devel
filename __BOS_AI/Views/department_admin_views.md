# BOS Views — Department Landing Pages & Admin Views

## Overview

Four department sections live under System Content, each with a `site_landing_page` entity as the hub and a set of taxonomy admin views beneath it. All views share a common pattern: table format, taxonomy base table, standard access roles, and admin menu placement.

**Menu hierarchy:** Admin > Operations > System Content > `{department}`

**Common access roles:** administrator, site_admin, site_assistant, administration

**Common view pattern:**
- Base table: `taxonomy_term_field_data`
- Style: table
- Fields: Name (linked), Description (200-char trim), Operations links
- Pager: none
- Sort: weight ASC, name ASC

---

## 1. Spray Department

- **Landing Page:** `/admin/operations/system_content/spray_department`
- **Entity:** `site_landing_page` (created via `dev_scripts/create_spray_department_content.php`)
- **Menu Parent UUID:** `2281f592-9494-4554-8870-bae14f25ecc2`
- **Views:** 11

| View ID | Path (relative to `/admin/operations/system_content/`) | Vocabulary | Extra Fields |
|---|---|---|---|
| `admin_spraying_locations` | `spray_department/site_locations` | `spraying_locations` | Applicable Services |
| `admin_spraying_carrier` | `spray_department/carrier` | `carrier` | — |
| `admin_spraying_emergence_types` | `spray_department/emergence_types` | `emergence_types` | — |
| `admin_spraying_frequency` | `spray_department/frequency` | `frequency` | — |
| `admin_spraying_methods` | `spray_department/methods` | `spraying_methods` | — |
| `admin_spraying_soil_moisture` | `spray_department/soil_moisture` | `soil_moisture` | — |
| `admin_spraying_weed_growth_stages` | `spray_department/weed_growth_stages` | `weed_growth_stages` | — |
| `admin_spraying_wind_direction` | `spray_department/wind_direction` | `wind_direction` | — |
| `admin_spraying_wind_speed` | `spray_department/wind_speed` | `wind_speed` | — |
| `admin_spraying_signal_words` | `spray_department/signal_words` | `signal_words` | — |
| `admin_spraying_weed_categories` | `spray_department/weed_categories` | Lawn & Garden Pests | — |

---

## 2. Irrigation Department

- **Landing Page:** `/admin/operations/system_content/irrigation_department`
- **Views:** 5

| View ID | Path (relative to `/admin/operations/system_content/`) | Base Table / Vocabulary | Notes |
|---|---|---|---|
| `admin_irrigation_check_up_frequency` | `irrigation_department/check_up_frequency` | taxonomy: `check_up_frequency` | — |
| `admin_irrigation_system_complexity` | `irrigation_department/system_complexity` | taxonomy: `system_complexity` | — |
| `admin_irrigation_system_operation` | `irrigation_department/system_operation` | taxonomy: `system_operation` | — |
| `admin_irrigation_sprinkler_system_types` | `irrigation_department/sprinkler_system_types` | ECK: `sprinkler_system_types_field_data` | Not taxonomy |
| `admin_irrigation_sprinkler_types` | `irrigation_department/sprinkler_types` | ECK: `sprinkler_types_field_data` | Not taxonomy |

---

## 3. Lighting Department

- **Landing Page:** `/admin/operations/system_content/lighting_department`
- **Views:** 2

| View ID | Path (relative to `/admin/operations/system_content/`) | Vocabulary |
|---|---|---|
| `admin_lighting_christmas_colors` | `lighting_department/christmas_colors` | `christmas_light_colors` |
| `admin_lighting_christmas_types` | `lighting_department/christmas_types` | `christmas_light_types` |

---

## 4. Material Info

- **Landing Page:** `/admin/operations/system_content/material_info`
- **Menu Parent UUID:** `7361406e-a3fd-48bd-84e3-3e9c0ba6db18`
- **Views:** 7

| View ID | Path (relative to `/admin/operations/system_content/`) | Vocabulary | Extra Fields |
|---|---|---|---|
| `admin_material_types` | `material_info/material_types` | `material_types` | Teammate Description |
| `admin_material_tags` | `material_info/material_tags` | `material_tags` | — |
| `admin_hardiness_zones` | `material_info/hardiness_zones` | `growth_zone` | Zone Number, Subzone, Min/Max Temp |
| `admin_material_plant_characteristics` | `material_info/plant_characteristics` | `plant_characteristics` | + Add Term header button |
| `admin_material_bloom_time` | `material_info/bloom_time` | `bloom_time` | — |
| `admin_material_rock_types` | `material_info/rock_types` | `rock_types` | Teammate Description |
| `admin_material_supplier_types` | `material_info/supplier_types` | `supplier_types` | — |

---

## Deleted Views (Replaced by Department Views)

| Old View ID | Old Path | Replacement |
|---|---|---|
| `admin_material_hardiness_zones` | `/admin/office/materials/hardiness_zones` | `admin_hardiness_zones` under Material Info |
| `plant_characteristics` | (public) | `admin_material_plant_characteristics` under Material Info |

---

## Dev Scripts (One-Time Setup)

These scripts in `dev_scripts/` were used to create department content and populate data. They are safe to re-run but intended as one-time setup:

| Script | Purpose |
|---|---|
| `create_spray_department_content.php` | Creates Spray Dept landing page entity, menu links, and updates 13 view menu parents |
| `create_material_type_terms.php` | Creates Mulch and Backflow material_types taxonomy terms |
| `update_spraying_locations.php` | Populates all 18 spraying_locations terms with public descriptions and detailed teammate field instructions |

---

Created: April 2026
