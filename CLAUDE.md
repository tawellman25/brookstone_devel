# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Identity

This is **BOS** (Brookstone Operating System) — the internal operations platform for Brookstone Outdoors LLC, built on Drupal 10 (Drupal 11 compatible). BOS centralizes operational, client, property, and work order data. It is **not** an ERP in user-facing language.

The authoritative system documentation lives in `__BOS_AI/`. Read those files before implementing anything non-trivial. Code must conform to those documents. Do not invent entities, bundles, or rules not defined there.

## Local Development Environment

BOS uses **DDEV** for local development. All Drush and Composer commands should be run through DDEV.

```bash
ddev start                          # Start containers
ddev stop                           # Stop containers
ddev drush cr                       # Clear Drupal cache
ddev drush cim -y                   # Import config from config/sync/
ddev drush cex -y                   # Export config to config/sync/
ddev drush updb -y                  # Run database updates
ddev drush en -y <module>           # Enable a module
ddev drush pmu -y <module>          # Uninstall a module
ddev composer require <package>     # Add a Composer dependency
ddev composer install               # Install all dependencies
```

**Stack:** PHP 8.3, MariaDB 10.11, nginx-fpm, Drupal 10.5.x.
**URL:** `https://brookstone.ddev.site`

### After DB Import

DDEV automatically runs these on `ddev import-db`:
- Disables `s3fs` (files live on AWS S3 in production)
- Enables `stage_file_proxy` pointed at `https://brookstone-images.s3.us-east-2.amazonaws.com` (origin dir: `s3fs-public`)
- Clears cache

Files are served from S3 in production. In local dev, `stage_file_proxy` proxies them on demand — never commit or sync user-uploaded files.

## Dev Scripts

All scripts are in `dev_scripts/`. They require SSH host aliases configured in `~/.ssh/config`.

| Script | SSH Host | Purpose |
|---|---|---|
| `brookstone-sync-db-from-live.sh` | `brookstone` | Pull live DB (reads creds from remote `settings.php`, no Drush required on remote) |
| `brookstone-sync-code-from-live.sh` | `sewardsdevel` | Pull live custom code + config into local |
| `brookstone-sync-all.sh` | both | Run code sync then DB sync in sequence |
| `bos-backup-dev.sh` | — | Backup local dev to `/mnt/d/Backups/brookstone-dev/` |
| `brookstone-sync-to-remote-DANGEROUS.sh` | `sewardsdevel` | Deploy to production (dry-run by default) |

```bash
# Safe dry-run preview of deploy
./dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh

# LIVE deploy — will ask you to type LIVE to confirm
./dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh --live

# Other flags: --skip-composer  --cim  --skip-cr  --no-maintenance  --yes
```

The deploy script rsyncs code to live, then runs `composer install --no-dev` and `drush cr` on the remote. Config import (`drush cim`) does **not** run by default — pass `--cim` to enable it. **The DB is never touched by the deploy.** Directories `.vscode/`, `dev_scripts/`, and `__BOS_AI/` are protected from deletion on live even with `--delete`.

## Custom Drush Commands

```bash
ddev drush ms-audit          # Audit material↔supplier link records (duplicates, missing pack qty, bad SKUs)
                             # Alias: drush material-supplier:audit
```

## Architecture Overview

### The Operational Spine

BOS is built almost entirely on **ECK** (Entity Construction Kit) — no nodes for operational data. The three core entity types anchor everything:

```
Properties (ECK: properties)          ← physical location anchor
  ├── Property Detail Sub-entities    ← service-specific property facts (see below)
  ├── Work Orders (ECK: work_order)   ← execution record
  │     ├── wo_time_clock             time punch entries
  │     ├── wo_material_list          materials list container (bundles: material_list, estimate_list)
  │     │     └── wo_material_list_item  line items w/ snapshot pricing
  │     ├── wo_chemicals_used         chemicals applied (12 bundles, one per spray service)
  │     ├── wo_rental_equipment       equipment/rentals used
  │     ├── wo_material_dumping       dump loads and dump totals
  │     ├── wo_complete_info          completion sign-off (8 bundles, one per crew type)
  │     ├── wo_notes                  structured notes
  │     ├── wo_spraying_conditions    weather/conditions for compliance (5 bundles)
  │     ├── wo_status_updates         append-only event timeline
  │     └── wo_tasks_list             crew task checklist (5 bundles, one per service type)
  └── Contracts (ECK: contracts)      ← intent/agreement record
        └── Contract Sections (ECK: contract_sections)
              └── field_service → Services (taxonomy)
                    └── field_service_bundle → work_order bundle (machine name)
```

**Critical invariant:** `work_order.bundle` must equal `work_order.field_service.term.field_service_bundle`. The Services taxonomy is the single source of truth for Work Order bundle mapping.

### The wo_* Module Pattern

Each work order bundle has a dedicated custom module (`wo_{bundle}`) that implements bundle-specific business logic. This is a **formal architectural pattern** — do not consolidate these into a single module.

See `__BOS_AI/Modules/wo_bundle_modules.md` for the full architectural specification.

**Summary of what each `wo_*` module does:**
1. `hook_entity_presave` on `work_order` — guarded to its own bundle. When WO status = Complete (term 1097): reads data from child entities and property detail sub-entities, calculates all billing subtotals, writes totals back to WO fields.
2. `hook_entity_insert` / `hook_entity_update` on `work_order` — when Complete: writes "last completed" data back to the corresponding `property_*` detail entity (e.g., `property_snow_removal_info`, `property_fertilizing_info`).

**Cross-cutting WO modules** (not bundle-specific):
- `wo_sign_off` — watches `wo_complete_info` presave for all crew bundles; drives WO status to Complete (1097), calculates trip fee from `zipcodes.field_trip_fee`, calculates total time; on `wo_complete_info` delete reverts WO to In Progress (1092) and clears all billing totals
- `wo_status_updates` — propagates status update entity changes back to WO
- `wo_total_time` — computes `field_total_time` roll-up
- `wo_timer_flag_update` — manages the work order timer flag
- `wo_chemical_used_subtotal` — computes chemical subtotals on spray WOs
- `wo_material_item_subtotal` — computes material item subtotals
- `wo_material_list_form` / `wo_material_list_management` — form handling and lifecycle management for material lists
- `wo_dump_fees` — computed dump fee fields and material dumping tracking
- `wo_estimate` — links WOs to estimates
- `wo_notes` — manages `wo_notes` ECK entity lifecycle
- `wo_schedule` — creates `wo_status_updates` entries from scheduling entity creation
- `wo_deletion_manager` — controls WO deletion based on status

**Rate/pricing sources used by `wo_*` modules:**
- `config_pages:business_setting` — holds all rate tables (salt rate, mag rate, snow labor, shoveling minimum, aeration pricing, etc.)
- `sq_ft_break_points` ECK entity — aeration/dethatching/overseeding pricing breakpoints (referenced from business_setting config page)
- `zipcodes.field_trip_fee` — per-zipcode trip fee
- `contracts:snow_removal.field_per_push_rate` — per-push rate for snow removal

### The property_* Detail Sub-Entity Pattern

Properties have 15+ service-specific detail entity types that record service facts about a property. These are **not** just static data — they participate in a bidirectional pattern with `wo_*` modules.

See `__BOS_AI/Entities/property_detail_entities.md` for the full specification.

**Read direction (WO creation/save):** `wo_*` modules read from property detail entities to pre-populate WO fields (e.g., `property_landscape_details.field_turf_sq_footage` → `work_order:aerating.field_current_turf_sq_footage`).

**Write direction (WO completion):** `wo_*` modules write "last completed" data back to property detail entities on WO completion (e.g., `property_snow_removal_info.field_snow_removal_last_plowed` updated when a snow removal WO is marked Complete).

| Entity Type | Bundles | Purpose |
|---|---|---|
| `property_christmas_decor` | `information` | Christmas decor job facts |
| `property_fertilizing_info` | `lawn_fertilizing_information`, `shrub_and_tree_fertilizing` | Fertilizing history and settings |
| `property_instructions` | `residential` | Property-specific service instructions |
| `property_landscape_details` | `current` | Lawn sq footage, landscape measurements |
| `property_lawn_maintenance` | `lawn_maintenance_info` | Mowing specs and history |
| `property_snow_removal_info` | `information` | Snow removal history (last plowed, last salt amt) |
| `property_spraying_info` | `aspen_twig_gall`, `cooley_spruce`, `deciduous_bore`, `dormant_oil`, `grub_prevention`, `ips_beetle`, `pre_emergent`, `trunk_bore`, `weed_spraying` | Per-service spraying history and settings |
| `property_sprinkler_design` | `design` | Sprinkler system design specs |
| `property_sprinkler_info` | `general_information` | General sprinkler system facts |
| `property_sprinkler_pumps` | `pump` | Pump details |
| `property_sprinkler_system` | `system` | System overview |
| `property_ss_sources` | `dirty_water_source`, `domestic_source`, `well_water_source` | Water source details |
| `property_ss_zones` | `zone` | Sprinkler zone details |
| `property_system_controller` | `controller` | Irrigation controller details |
| `property_zone_watering_time` | `watering_time` | Per-zone watering time settings |

All property detail entities share the pattern: `field_property` → `properties` (required reference back to the parent property).

### Entity Types and Bundles (Full Inventory)

#### `work_order` — 36 bundles
`aerating`, `aspen_twig_gall`, `backflow_testing`, `christmas_decorations`, `cooley_spruce_gall`, `deciduous_bore`, `deer_prevention`, `dethatching`, `dormant_oil`, `estimate`, `exterior_lighting`, `fall_cleanup`, `fertilizing`, `fertilizing_trees_and_shrubs`, `grub_prevention`, `in_house_tasks`, `landscape_lighting`, `landscaping`, `lawn_mowing`, `misc_services`, `pinion_pine_ips_beetle`, `pre_emergent`, `snow_removal`, `special_mowing`, `spring_cleanup`, `sprinkler_check_up`, `sprinkler_design`, `sprinkler_installation`, `sprinkler_repair`, `sprinkler_start_up`, `sprinkler_winterizing`, `summer_pruning`, `trunk_bore`, `weed_pulling`, `weed_spraying`, `winter_pruning`

> Note: `backflow_testing` is the correct machine name (not `sprinkler_backflow`). Lighting bundles are `landscape_lighting` and `exterior_lighting` (not `lighting_landscape` / `lighting_exterior`).

> **Legacy bundle — `estimate`:** The `estimate` work_order bundle is being phased out. Do not add new fields, modules, or business logic to this bundle. It will be removed once the `estimate` ECK entity is fully operational. No `wo_estimate` per-bundle module should be created.

Required fields: `field_property`, `field_service`, `field_status`
Billing fields: `field_estimated_price`, `field_trip_fee`, `field_dump_fee_total`, `field_rental_total`, `field_labor_total`, `field_material_chemical_total`, `field_billing_adjustment`, `field_wo_total`
Invoice flags: `field_invoiced`, `field_printed`
ID: `field_work_order_id` (stable BOS-visible ID, never reused)
WO status Complete term ID: **1097** (In Progress: **1092**, Cancelled: **1098**)

#### `wo_chemicals_used` — 12 bundles (one per spray service)
`aspen_twig_gall`, `cooley_spruce_gall`, `deciduous_bore`, `dormant_oil`, `fertilizers`, `fertilizers_tree_and_shrubs`, `fertilizing_chemicals`, `grub_prevention`, `pinion_pine_ips_beetle`, `pre_emergent`, `trunk_bore`, `weed_spraying`

#### `wo_complete_info` — 8 bundles (one per crew type)
`clean_up_crew`, `complete`, `fertilizing_crew`, `irrigation_crew`, `landscape_crew`, `lawn_mowing`, `snow_removal`, `spray_crew`

#### `wo_tasks_list` — 5 bundles
`aerating`, `dethatching`, `lawn_mowing`, `snow_removal`, `special_mowing`

#### `wo_spraying_conditions` — 5 bundles
`fertilizing`, `grub_prevention`, `pre_emergent`, `tree_spraying`, `weed_spraying`

#### `wo_material_list` — 2 bundles: `material_list`, `estimate_list`

#### `contracts` — 3 bundles
- `residential` — fully implemented; governed by `contract_residential` module
- `snow_removal` — in progress
- `commercial` — in progress

The residential contract contains 20+ explicit section slot fields (e.g. `field_aerating_of_lawn`, `field_fall_cleanup`) each referencing a `contract_sections` entity.

**Contract status lifecycle (residential):**
`Created → Ready to Send → Sent-Posted → Received Back → Changes Entered → Approved → Work Orders Created → Assigned → Completed / On Hold / Cancelled`

Transitions are enforced by action classes in `contract_residential`. Direct field edits to `field_contract_status` are discouraged. **One residential contract per property per year** enforced at entity validation time.

#### `contract_sections` — 24 bundles
`aerating_of_lawn`, `aspen_twig_gall_control`, `christmas_decorations`, `cooley_spruce_gall_treatment`, `deciduous_bore_treatment`, `deer_protection_wire`, `dethatching_of_lawn_areas`, `dormant_oil_spray`, `fall_cleanup`, `fertilizing_of_shrubs_and_trees`, `grub_prevention_on_lawn`, `ips_beetle_on_pinion_pine`, `irrigation_check_ups`, `irrigation_shut_down`, `irrigation_start_up`, `lawn_fertilizing`, `lawn_mowing_and_trimming`, `pre_emergent`, `spring_cleanup`, `summer_hedge_shrub_pruning`, `trunk_bore_prevention`, `weed_spraying_landscape_beds`, `weed_spraying_of_misc_areas`, `winter_pruning`

#### `properties` — 1 bundle: `property`
Key fields: `field_nickname` (crew-facing label, mutable — does not affect URL), `field_geofield`, `field_zipcode_reference`, `field_primary_contact_ref`, `field_contacts`, `field_work_order_note`
Operational flags: `field_call_ahead`, `field_cod_customer`, `field_no_services`, `field_must_use_client_app`, `field_client_app`

#### `estimate` — 34 bundles (mirrors most WO bundles, plus `winter_pruning`)
`aerating`, `aspen_twig_gall`, `backflow_testing`, `christmas_decorations`, `cooley_spruce_gall`, `deciduous_bore`, `deer_prevention`, `dethatching`, `dormant_oil`, `exterior_lighting`, `fall_cleanup`, `fertilizing`, `fertilizing_trees_and_shrubs`, `grub_prevention`, `landscape_lighting`, `landscaping`, `lawn_mowing`, `misc_services`, `pinyon_pine_ips_beetle`, `pre_emergent`, `snow_removal`, `special_mowing`, `spring_cleanup`, `sprinkler_check_up`, `sprinkler_design`, `sprinkler_installation`, `sprinkler_repair`, `sprinkler_start_up`, `sprinkler_winterizing`, `summer_pruning`, `trunk_bore`, `weed_pulling`, `weed_spraying`, `winter_pruning`

Each estimate references exactly one `estimate_request`. Revision chains scoped by `(estimate_request_id + estimate_type_id)`. Converts to WO via `estimate.work_order_converter` service. `estimate.settings` config required with `accepted_stage_tid` and `declined_stage_tid`.

#### `estimate_request` — 1 bundle: `standard`
Intake container. One request → many estimates. Fields: `field_owner`, `field_contact`, `field_property`, `field_contract`, `field_service`, `field_priority`, `field_status`, `field_estimates`.

#### `estimate_items` — 4 bundles: `labor`, `materials`, `equipment`, `subcontractor`
`line_total = quantity × unit_price × (1 + markup)`. Labor has no markup. Totals roll up to `estimate.field_estimate_total` automatically.

#### `estimate_notes` — 1 bundle: `note`
#### `estimate_action_log` — 2 bundles: `log`, `request_log`

#### `equipment` — 8 bundles
`attachements` *(permanent typo — do not rename)*, `heavy_equipment`, `power_tools`, `small_engine`, `snow_plows`, `sprayers`, `trailers`, `vehicles`

#### `material` — 18 bundles
`annuals`, `brass`, `copper`, `decorative_rock`, `electric`, `galv`, `irrigation`, `landscape`, `misc`, `pavers`, `plants`, `poly`, `pumps`, `pvc`, `shrubs`, `sod`, `trees`, `xmas`

Source of truth for current pricing. WO usage snapshots unit cost into `wo_material_list_item` at time of use.

#### `chemical` — 6 bundles: `fertilizer`, `fungicide`, `herbicide`, `indicator`, `insecticide`, `surfactant`

#### `sop` — 11 bundles
`landscaping`, `sprinkler_maintenance`, `office_administration`, `system_procedures`, `sop_governance`, `lighting`, `maintenance`, `safety`, `snow_removal`, `spray`, `training`
SOP codes are immutable once approved.

#### `contacts` — 2 bundles: `contact`, `emergency_contacts`
#### `address` — 4 bundles: `contact_address`, `profile_mailing_addresses`, `supplier`, `teammate_address`
#### `phone_number` — 3 bundles: `contacts`, `profile_phone_numbers`, `suppliers`
#### `material_suppliers` — 1 bundle: `supplier`
Link entity between `material` and `supplier`. Enforced by `material_supplier` module.

#### `contract_sections_audit` — 1 bundle: `log` (append-only, entity lifecycle hooks only)
#### `contract_action_log` — 1 bundle: `log`
#### `contract_notes` — 1 bundle: `note`

#### Pricing/rate reference entities
- `sq_ft_break_points` — 4 bundles: `aeration`, `dethatching`, `overseeding_labor`, `overseeding_seed_markup` (referenced from `business_setting` config page for area-based pricing)

#### Sprinkler reference entities
- `sprinkler_system_types` — bundle: `types`
- `sprinkler_types` — bundle: `types`

#### Content/knowledge entities
- `handbook` — bundles: `cover`, `page`
- `manual` — bundles: `chapter`, `page`, `title_page`
- `lawn_and_garden_pests` — bundle: `weed_types`
- `testimonial` — bundle: `client`
- `site_content` — bundles: `public_info`, `teammate`
- `site_landing_page` — bundles: `office_administration`, `supervisor`, `teammate`

#### People/classification reference entities
- `contacts` — bundles: `contact`, `emergency_contacts`
- `supplier` — bundle: `supplier` *(distinct from `material_suppliers`)*
- `manufacturer` — bundle: `manufacturer`
- `client_type` — bundle: `client_type`
- `crew_types` — bundle: `crew_types`
- `client_app` — bundle: `app` (external check-in app reference)
- `classification` — bundles: `absorption`, `chemical_types`
- `positions` — bundle: `role`
- `department` — bundle: `details`
- `employment` — bundle: `notes`

#### Geographic reference entities
- `zipcodes` — bundle: `zipcode` (holds `field_trip_fee` used for trip fee calculation)
- `city` — bundle: `city`
- `county` — bundle: `county`
- `state` — bundle: `state`

#### Equipment tracking entities
- `equipment_check_in_out` — bundle: `check_in`
- `equipment_status_update` — bundle: `update`

#### Time/scheduling entities
- `scheduling` — bundle: `work_order`
- `time_clock_entry` — bundle: `entry`

#### Property sub-entity
- `property` — bundle: `included_address` *(distinct from `properties` entity type)*
- `ownership_record` — bundle: `record`

### Non-ECK Key Entities

**`user`** (Drupal core) — authentication + roles + governance flags (`field_do_not_schedule`, `field_credit_hold`, `field_service_suspension_reason`, `field_ok_to_email`, `field_sms_consent`). QB: `field_qb_refnum`, `field_qb_list_id`.

**`profile`** (Profile module) — 1:1 with user
- `customer_profile`: `field_client_status`, `field_payment_terms`, `field_invoice_delivery_method`, `field_portal_allowed`, `field_billing_allowed`, `field_tax_status`, `field_primary_contact_ref`, `field_contacts`, `field_quickbooks_notes`, `field_qb_list_id`
- `teammate_profile`: `field_job_title`, `field_assigned_crew`, `field_emergency_contacts`, `field_qb_account_number`

**`taxonomy`** — key vocabularies:
- `services` — `field_work_order_service` (bool), `field_service_bundle` (WO bundle machine name)
- `contract_status` — contract lifecycle states
- `equipment_types` — `field_small_engine_type`
- `brookstone_tags` — operational tagging

**`config_pages:business_setting`** — holds global rate tables used by `wo_*` modules (salt rate, mag rate, snow labor, shoveling minimum, aeration pricing breakpoints, ATV charge, etc.)

### User Roles

`anonymous` → `authenticated` → `user` (no BOS) → `client` (read-only portal) → `teammates` (crew execution) → `supervisor` (assign/status WOs) → `administration` (office staff) → `site_assistant` → `site_admin` → `administrator` (Drupal superuser)

Admin theme (`brookstone_admin`) is forced on contract routes for: `administrator`, `site_admin`, `administration`, `site_assistant`, `supervisor`.

## Custom Modules

Located in `web/modules/custom/`. Modules are grouped by the `package` key in their `.info.yml`.

### Contract modules
| Module | Purpose |
|---|---|
| `contract_residential` | Residential contract governance: one contract per property per year; bidirectional Contract↔Section linking; status lifecycle actions; admin theme enforcement |
| `contract_sections_ui` | UX-only: modal editing of contract sections; History modal; Admin Table vs legacy EVA modes |
| `contract_section_audit` | Append-only audit log for contract section changes (entity lifecycle hooks only) |
| `bos_contract_migrate` | Migration helpers for contracts (Feeds-based) |
| `bos_contract_sections_attach` | Auto-attaches contract_sections to parent contract field_* references |
| `estimate_contract_residential` | Creates Estimate Requests when Contract Sections are marked "Request Quote" |

### Estimate modules
| Module | Purpose |
|---|---|
| `estimate` | Full estimating domain: Estimate Requests, Estimates, revision chains, WO conversion |
| `estimate_items` | Line-item pricing engine (labor/materials/equipment/subcontractor bundles) |
| `estimates` | Estimate and lead workflow integration |
| `estimate_notifications` | Sends assignment email to estimator when `field_assigned_to` is set on `estimate_request`. Fires on insert (if assigned) and on update (empty → populated transition only). |

### Work Order — per-bundle modules (package: Work Orders)
One module per WO service bundle. Each implements `hook_entity_presave` to calculate totals on completion and writes "last completed" data back to `property_*` detail entities.

`wo_aerating`, `wo_aspen_twig_gall`, `wo_christmas_decorations`, `wo_cooley_spruce_gall`, `wo_deciduous_bore`, `wo_deer_prevention`, `wo_dethatching`, `wo_dormant_oil`, `wo_fall_cleanup`, `wo_fertilizing`, `wo_fertilizing_trees_and_shrubs`, `wo_grub_prevention`, `wo_in_house_tasks`, `wo_landscaping`, `wo_lawn_mowing`, `wo_misc_services`, `wo_pinion_pine_ips_beetle`, `wo_pre_emergent`, `wo_snow_removal`, `wo_special_mowing`, `wo_spring_cleanup`, `wo_sprinkler_check_up`, `wo_sprinkler_design`, `wo_sprinkler_installation`, `wo_sprinkler_repair`, `wo_sprinkler_start_up`, `wo_sprinkler_winterizing`, `wo_summer_pruning`, `wo_trunk_bore`, `wo_weed_pulling`, `wo_weed_spraying`

### Work Order — cross-cutting modules
| Module | Purpose |
|---|---|
| `wo_sign_off` | `wo_complete_info` presave → sets WO Complete (1097), calculates trip fee + total time; on delete reverts WO to In Progress (1092). Also sends cancellation email |
| `wo_status_updates` | Propagates `wo_status_updates` entity changes back to WO |
| `wo_total_time` | Computes `field_total_time` roll-up from `wo_time_clock` |
| `wo_timer_flag_update` | Manages the work order timer flag |
| `wo_chemical_used_subtotal` | Computes chemical subtotals on spray WOs |
| `wo_material_item_subtotal` | Computes material item line subtotals |
| `wo_material_list_form` | Form handling for material lists on WOs |
| `wo_material_list_management` | Creates/updates/deletes material lists on WOs |
| `wo_dump_fees` | Computed dump fee fields and material dumping tracking |
| `wo_estimate` | Links WOs to estimates (bidirectional) |
| `wo_notes` | Manages `wo_notes` ECK entity lifecycle |
| `wo_schedule` | Creates `wo_status_updates` entries from scheduling entity creation |
| `wo_deletion_manager` | Controls WO deletion based on status (guards against deleting completed WOs) |
| `wo_actions` | VBO bulk action: re-save `wo_complete_info` to update related WO records |

### Material/Equipment modules
| Module | Purpose |
|---|---|
| `material` | Material entity guardrails + WO snapshot pricing rules |
| `material_supplier` | `material_suppliers:supplier` integrity: no duplicates, preferred supplier, pack qty, SKU normalization |
| `equipment_actions` | VBO actions for equipment entities |
| `equipment_status_updates` | Propagates equipment status update entity changes to Equipment entity |

### Property modules
| Module | Purpose |
|---|---|
| `properties` | Block plugin: dynamic WO creation links from Property detail page |
| `property_full_address` | Computes `field_full_address` for properties |
| `bos_contact_attach` | Attaches new Contacts to Customer Profiles and Properties; cleans up refs on Contact delete |
| `customer` | Customer profile helpers (Contact attach + cleanup) |

### SOP modules
| Module | Purpose |
|---|---|
| `sop_code_validation` | Global SOP code validation: format, uniqueness, immutability after approval across all bundles |
| `sop_office_admin` | SOP code validation for `office_administration` bundle |
| `sop_sprinkler_maintenance` | SOP code validation for `sprinkler_maintenance` bundle |
| `sop_system_prosedures` | SOP code validation for `system_procedures` bundle *(directory typo is permanent)* |

### User/access modules
| Module | Purpose |
|---|---|
| `user_teammate_profile` | Auto-creates `teammate_profile` when user is assigned the `teammates` role |
| `custom_user_redirect` | Redirects to appropriate profile edit page after user creation based on role |
| `role_delegation` | (contrib) Role assignment delegation |

### Utility/infrastructure modules
| Module | Purpose |
|---|---|
| `eck_bundle_clone` | Drush commands for cloning ECK bundles (definition + fields + base field overrides) |
| `admin_calendar` | Custom adjustments for the admin WO scheduling calendar |
| `site_landing_page` | Custom functionality for `site_landing_page` ECK entity; admin theme for `office_administration` bundle |
| `crew_types` | Cross-references Crews and Departments ECK entities |
| `system_readiness` | System health/readiness checks |
| `fix_field_ui` | Fixes Field UI row handler error for all entities |
| `custom_date_all_day` | Extends Date All Day to save the all_day flag |
| `scheduling_date_migration` | One-time: migrates scheduling dates from old to new field |
| `work_order_notes_migration` | One-time: migrates WO comments to `wo_notes` ECK entity |
| `sewards_custom` | Legacy module (migration-era) |

## Configuration Management

All Drupal config is exported to `config/sync/` and deployed via `drush cim`. The `config_ignore` module excludes environment-specific config (credentials, keys, `stage_file_proxy` origin) from config management. Never commit `settings.php` or `services.yml`.

## Themes

- `web/themes/custom/multipro/` — primary admin/staff theme (includes Font Awesome)
- `web/themes/custom/olivero_sewards/` — client-facing portal theme (sub-theme of Olivero)

## What Is Not in Git

- `vendor/` — managed by Composer
- `web/core/`, `web/modules/contrib/`, `web/themes/contrib/`, `web/libraries/` — managed by Composer
- `web/sites/*/settings*.php`, `web/*/services*.yml` — environment secrets
- `web/sites/*/files/` — user files (on S3)
- `*.sql.gz`, `*.sql` — database dumps

## Known Issues / Pending Renames

- **`estimate.pinyon_pine_ips_beetle` → `work_order.pinion_pine_ips_beetle` spelling mismatch.** The estimate bundle uses `pinyon` (correct botanical spelling); the work_order bundle uses `pinion` (legacy typo). The `field_work_order` field on `estimate.pinyon_pine_ips_beetle` intentionally targets `work_order.pinion_pine_ips_beetle`. Do not "fix" that reference until the work_order bundle is renamed to `work_order.pinyon_pine_ips_beetle` — renaming the WO bundle requires coordinated changes to config, the `wo_pinion_pine_ips_beetle` module, and any views/reports that reference it by name.

- **`_estimate_contract_residential_OLD_VERSION/` directory.** A prior version of the `estimate_contract_residential` module existed in `estimate_contract_residential_OLD_VERSION/`. Drupal's `ExtensionDiscovery` was silently loading it instead of the correct module. Resolved by renaming to `_estimate_contract_residential_OLD_VERSION/` (leading underscore). It is dead code — do not restore or reference.

- **`form_mode_control` contrib warning.** The `form_mode_control` module emits a `foreach()` warning on non-array in staff-facing UI. Suppressed by the `bos_error_filter` module via a custom error handler in `hook_init()`. This is a contrib bug workaround, not a fix — monitor for upstream resolution.

## BOS Architectural Rules

From `__BOS_AI/README.md` and `__BOS_AI/Entities/01_entities_policy.md`:

1. **Intent vs Execution** — Contracts/Contract Sections = intent. Work Orders = execution. Never store execution data in Contracts.
2. **Custom over contrib** — prefer custom modules when logic is BOS-specific.
3. **Access must be explicit** — ownership, edit rights, and view rights documented per entity. Do not infer from names or paths.
4. **No deletion of operational history** — prefer archival status flags. Deletion is role-restricted with no surprise cascades.
5. **Pricing snapshots are immutable** — once a WO is completed, `wo_material_list_item` and `wo_chemicals_used` costs must not change except via admin correction.
6. **Automation must not create hidden side effects** — every automated action must be traceable.
7. **BOS is authoritative; accounting is downstream** — QB receives exports from BOS. External IDs may be stored; external logic must not govern BOS workflows.
8. **SOP codes are immutable** once approved.
9. **Audit trails are append-only** — use entity lifecycle hooks, not form/route handlers.
10. **Business logic belongs in code** — services, event subscribers, hooks — not only in Views/Rules UI config.
11. **One `wo_*` module per bundle** — bundle-specific WO logic lives in its own module. Do not consolidate.
12. **Property detail entities are read-write** — `wo_*` modules both read from and write back to `property_*` entities. These entities represent persistent service history, not just static property facts.

## Contrib Module Policy

Contrib modules are tiered in `__BOS_AI/contrib_modules.md`:
- **Tier 1** — foundational, always allowed: `eck`, `profile`, `pathauto`, `smart_date`, `inline_entity_form`, `views_bulk_operations`, `config_ignore`, `feeds`, `migrate_*`, etc.
- **Tier 2** — allowed with justification: `rules`, `computed_field`, `feeds_tamper`, `conditional_fields`, `eva`, `auto_entitylabel`, etc.
- **Tier 3** — discouraged/legacy: `cer`, `module_builder`, standalone `rules`. Do not expand usage.

Do not add new contrib modules without justification against the tier policy.

## UI Flow Reference

From `__BOS_AI/Entities/03_bos_ui_flow_map.md`:

| User | Entry Point | Key Flow |
|---|---|---|
| Office | Properties list → Property detail | Find property → view WOs/contracts → create WO or start workflow |
| Office | Contracts list → Contract detail | Add/edit Contract Sections via modal dialogs (Admin Table mode preferred) |
| Office | Scheduling views | Filter WOs by service/status/date/area → assign to crew |
| Crew | Daily assigned WO list | Open WO → time clock → tasks → materials/chemicals → mark complete |
| Office billing | Completed WOs view | Verify totals → mark invoiced/printed → export to accounting |
| Admin | Services taxonomy | Maintain WO service flags and bundle mappings |

Contract Section editing opens in a **modal dialog** from the Contract page. Two patterns: Admin Table (preferred, page-refresh on save) and legacy EVA/multi-block (AJAX block refresh).
