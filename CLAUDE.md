# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **For process discipline and engineering norms when working with Claude on BOS, read [`__BOS_AI/Governance/working_with_claude.md`](__BOS_AI/Governance/working_with_claude.md).** Pause-and-verify pattern, targeted commits, end-to-end verification, recovery-point pushes — required reading before non-trivial work. The companion docs [`drupal_bos_gotchas.md`](__BOS_AI/Governance/drupal_bos_gotchas.md) and [`architectural_patterns.md`](__BOS_AI/Governance/architectural_patterns.md) cover Drupal/BOS-specific traps and reusable patterns. [`deferred_work.md`](__BOS_AI/Governance/deferred_work.md) tracks surfaced-but-deferred items.

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

## __BOS_AI Documentation Bundle

The `__BOS_AI/` tree is the authoritative governance documentation for BOS, organized into subdirectories (`Entities/`, `Modules/`, `Governance/`, `Business/`, etc.). Claude.ai's project knowledge UI requires a flat list of files for upload, so we maintain a flattened staging dir at `__BOS_AI/_upload_bundle/` for that purpose.

### Regenerating the bundle

When `__BOS_AI/` content changes, regenerate the upload bundle:

```bash
# 1. Clean and re-stage
rm -rf __BOS_AI/_upload_bundle
mkdir -p __BOS_AI/_upload_bundle
```

Then run the staging logic (Python, since `zip` isn't available in WSL by default):

```python
import os, shutil

SRC = '__BOS_AI'
DEST = '__BOS_AI/_upload_bundle'
EXCLUDE_DIRS = {'Archive', '_upload_bundle', '.last_bundle'}
EXCLUDE_FILES = {'bos-ai-sync.sh', '__BOS_AI.zip'}
ALLOWED_EXT = {'.md', '.docx'}

# Collision rename map: source rel-path → staged basename.
# Entity/Business specs keep the clean name (canonical); Module docs get _module suffix.
RENAME = {
    'Modules/estimate.md': 'estimate_module.md',
    'Modules/weed_spray_reconciliation.md': 'weed_spray_reconciliation_module.md',
}

for root, dirs, files in os.walk(SRC):
    rel_root = os.path.relpath(root, SRC)
    parts = [] if rel_root == '.' else rel_root.split(os.sep)
    if any(p.startswith('.') or p in EXCLUDE_DIRS for p in parts):
        dirs[:] = []
        continue
    dirs[:] = [d for d in dirs if not d.startswith('.') and d not in EXCLUDE_DIRS]
    for f in files:
        if f.startswith('.') or f in EXCLUDE_FILES:
            continue
        if os.path.splitext(f)[1].lower() not in ALLOWED_EXT:
            continue
        full = os.path.join(root, f)
        rel = os.path.relpath(full, SRC).replace(os.sep, '/')
        arcname = RENAME.get(rel, f)
        shutil.copy2(full, os.path.join(DEST, arcname))
```

### Verification (always run after staging)

```bash
ls __BOS_AI/_upload_bundle | sort | uniq -d                # must output nothing
ls __BOS_AI/_upload_bundle | wc -l                         # report count
ls __BOS_AI/_upload_bundle/{estimate,estimate_module}.md   # both must exist
ls __BOS_AI/_upload_bundle/weed_spray_reconciliation*.md   # both must exist
```

### Invariants

- `_upload_bundle/` is **gitignored** (it's a generated artifact, not source).
- `Archive/`, `_upload_bundle/`, `.last_bundle/`, and hidden files are **excluded** from the bundle.
- The bundle is **flat** — no subdirectories.
- **Collisions:** `Entities/estimate.md` keeps the clean name (`estimate.md`); `Modules/estimate.md` becomes `estimate_module.md`. Same pattern for `weed_spray_reconciliation.md` (Business/ wins, Modules/ gets `_module` suffix).
- Source files in `__BOS_AI/` are **never modified** — staging is copy-only with `shutil.copy2()` (preserves mtimes).
- When new collisions appear (a new `Modules/foo.md` matches an `Entities/foo.md`), update the `RENAME` map above before re-staging — don't let the staging step silently overwrite.

### Distribution

The legacy `__BOS_AI/__BOS_AI.zip` (also gitignored) is a separate artifact for distributing the docs as a single file. The flat-bundle staging dir is preferred for Claude.ai uploads since it gives per-file timestamps and avoids zip extraction overhead.

## Custom Drush Commands

```bash
ddev drush ms-audit          # Audit material↔supplier link records (duplicates, missing pack qty, bad SKUs)
                             # Alias: drush material-supplier:audit

ddev drush eck:clone-bundle <entity_type> <source_bundle> <new_bundle> [--label="Label"]
                             # Clone an ECK bundle (definition + fields + base field overrides)
                             # Does NOT clone form/view displays — configure those manually after
                             # Alias: drush eck-bundle-clone
                             # Example: ddev drush eck:clone-bundle sop system_procedures training --label="Training"

ddev drush bos:contracts:sections-backfill [--dry-run] [--limit=N] [--start-id=N] [--contract-id=N]
                             # Backfill contract_sections.field_contract from residential contract slot fields
                             # Only sets field_contract when empty; never overwrites; logs conflicts
                             # Alias: drush bos-cs-backfill

ddev drush bos:checkups:generate [--force]
                             # Enqueue the irrigation check-up generator dispatcher
                             # Shares guard logic with cron (skips if already dispatched today)
                             # Use --force to override the daily guard
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

#### `material` — 21 bundles
`annuals`, `backflow`, `brass`, `copper`, `decorative_rock`, `electric`, `galv`, `irrigation`, `landscape`, `misc`, `mulch`, `pavers`, `plants`, `poly`, `pumps`, `pvc`, `shrubs`, `sod`, `supplies`, `trees`, `xmas`

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

#### Equipment inspection/defect/maintenance/fuel entities
- `equipment_inspection` — 6 bundles: `vehicles`, `trailers`, `heavy_equipment`, `mowers`, `sprayers`, `standard` (bundle-specific checklists)
- `equipment_defect` — bundle: `standard` (15 fields — actionable defect tracking, targets all equipment)
- `equipment_maintenance_event` — bundle: `standard` (15 fields — service/repair records, targets all equipment)
- `equipment_fuel_transaction` — bundle: `standard` (30 fields — WEX fuel card transaction records; targets vehicles bundle only via field_equipment; idempotent re-imports keyed on field_wex_transaction_id)

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
- `teammate_profile`: `field_job_title`, `field_assigned_crew`, `field_emergency_contacts`, `field_qb_account_number`, `field_wex_driver_prompt_id` (4-char zero-padded; unique; anchors WEX fuel-import driver resolution)

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
| `estimate_intake` | Presave: property/owner/contact lookup + scoring from field_requestor_address. Insert: loops over all field_service values, creates one estimate per service (field_estimate_service=TRUE only), auto-creates estimate_tasks entity per bundle. |
| `est_aerating`, `est_aspen_twig_gall`, `est_backflow_testing`, `est_cooley_spruce_gall`, `est_deciduous_bore`, `est_deer_prevention`, `est_dethatching`, `est_dormant_oil`, `est_fertilizing`, `est_fertilizing_trees_and_shrubs`, `est_lawn_mowing`, `est_pinyon_pine_ips_beetle`, `est_pre_emergent`, `est_special_mowing`, `est_sprinkler_start_up`, `est_sprinkler_winterizing`, `est_summer_pruning`, `est_trunk_bore`, `est_winter_pruning` (19 modules) | Per-bundle estimate_tasks calculation modules. Each implements hook_entity_presave to calculate field_estimate_total and sync property data. Package: Estimate Tasks |
| `estimate_request_cards` | Block plugins rendering Owner card and Contact card on estimate_request display pages. Traverses user→customer_profile→address/phone and contacts→address/phone relationship chains. |

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
| `equipment_inspection_workflow` | Equipment automation: defect auto-creation on inspection approval (18 rules), maintenance event defect closure, equipment status sync on out-of-service |
| `bos_wex_import` | WEX fleet card transaction import: admin upload form (CSV/XLSX) at `/admin/operations/equipment/fuel-transactions/import`, batch parser, driver resolution (via `teammate_profile.field_wex_driver_prompt_id`), vehicle resolution (via `equipment.vehicles.field_vehicle_number`), match-status flagging, vehicle mileage auto-update on higher-than-current odometer reads, `field_wex_transaction_id` uniqueness presave hook for idempotent re-imports. Permission: `import wex fuel transactions`. |

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
| `bos_user_time_clock_mapping` | Hides the External Time Clock Mapping fieldset on the user edit form when the user being edited does not have the `teammates` role. Phase 1A.1 of the time-clock foundation. |
| `role_delegation` | (contrib) Role assignment delegation |

### Operations dashboard modules
| Module | Purpose |
|---|---|
| `bos_teammate_operations` | Teammate Operations Hub at `/admin/office/operations/teammates`. Variance dashboards (compensable hrs vs WO hrs per teammate), per-teammate detail drill-down, time-clock data hygiene check, Active Now operational snapshot, Weekly Trends 8-week productivity table. Provides `CompensableHoursService` (8.5-hour assumption now, swappable to real `time_clock_entry` data when TimeTrax integration completes — see `__BOS_AI/Strategy/timetrax_strategy.md`) and `AnomalyDetectionService`. Phases 2A–2F delivered (2A=`8d98ba2a`, 2B=`dcb6c67f`, 2B.1=`1e72a804`, 2C=`6b1714b1`, 2D=`dd17e77f`, 2E=`a375a8ea`, 2F shipped 2026-05-30). Tier 2 surfaces (Team Roster, Today's Schedule) remain planned. |

### Utility/infrastructure modules
| Module | Purpose |
|---|---|
| `eck_bundle_clone` | Drush commands for cloning ECK bundles (definition + fields + base field overrides) |
| `admin_calendar` | Custom FullCalendar 6 scheduling calendar at /teammates/calendar. Tabs: Dispatch, Calendar, My Schedule. Completed WO overlay, business calendar background events, drag-drop rescheduling for supervisors. |
| `business_calendar` | ECK entity for company calendar events (holidays, paydays, closures). Background shading on scheduling calendar. Payday auto-generator anchored to 2026-03-16 every 14 days. |
| `bos_scheduling` | Crew daily schedule (/teammates/calendar/my-schedule), supervisor dispatch board (/teammates/calendar/dispatch), sprinkler bulk scheduling (/admin/office/work-orders/scheduling/sprinkler). Aeration flag heads service. |
| `site_landing_page` | Custom functionality for `site_landing_page` ECK entity; admin theme for `office_administration` bundle |
| `crew_types` | Cross-references Crews and Departments ECK entities |
| `system_readiness` | System health/readiness checks |
| `fix_field_ui` | Fixes Field UI row handler error for all entities |
| `custom_date_all_day` | Extends Date All Day to save the all_day flag |
| `scheduling_date_migration` | One-time: migrates scheduling dates from old to new field |
| `work_order_notes_migration` | One-time: migrates WO comments to `wo_notes` ECK entity |
| `sewards_custom` | Legacy module (migration-era) |

## Entity Field Reference Notes

1. **`phone_number.profile_phone_numbers`** links to user via `field_user` (NOT `field_profile_attached_to` — that field is labeled "Old User Reference" and is deprecated).
2. **`address.profile_mailing_addresses`** (bundle machine name) links to user via `field_user` (NOT `field_profile` — that field is labeled "Old Profile" and is deprecated). Bundle name is `profile_mailing_addresses`, not `userprofile_mailing_addresses`.
3. **`phone_number.contacts`** has NO back-reference field to `contacts.contact` — the relationship is a forward reference from `contacts.contact.field_phone_number` → `phone_number.contacts`. Always traverse forward from the contact entity.

## Configuration Management

All Drupal config is exported to `config/sync/` and deployed via `drush cim`. The `config_ignore` module excludes environment-specific config (credentials, keys, `stage_file_proxy` origin) from config management. Never commit `settings.php` or `services.yml`.

### ECK config file naming (BOS standard)

When creating a new ECK entity type or bundle, use the older pattern across the board:

| Config | File path |
|---|---|
| Entity type | `eck.eck_entity_type.{type}.yml` |
| Bundle | `eck.eck_type.{type}.{bundle}.yml` |
| Field storage | `field.storage.{type}.{field}.yml` |
| Field instance | `field.field.{type}.{bundle}.{field}.yml` |

Field instance dependencies must reference the bundle as `eck.eck_type.{type}.{bundle}`. **Do NOT use the newer `eck.eck_entity_bundle.{name}.yml` pattern** — it has a recurring `drush cex` bug that exports broken dependencies. Full convention details and step-by-step process: `__BOS_AI/Entities/01_entities_policy.md` → "ECK Config File Conventions".

### UUID drift between environments

Config-entity UUIDs are **environment-local** in BOS. When a field instance, view, or other config entity is created in one environment (local DDEV vs live), it gets a UUID generated locally. That UUID does not propagate to other environments — each environment generates its own when the config is created there independently.

Implications:
- **Sync-dir YAMLs SHOULD include the local UUID** for consistency across `drush cim` cycles. Missing UUID in sync triggers unstable diffs (sync vs active perpetually look "different" because active has a UUID and sync doesn't).
- **The same field on local vs live will have different UUIDs.** This is fine — UUIDs don't affect functionality. Code references config entities by name (`config_pages.business_setting`), never by UUID.
- **When a field is created via the cim silent-skip workaround** (direct `field_config` entity storage from PHP), Drupal generates a UUID at save time. Patch that UUID back into the sync YAML so future cim cycles produce clean diffs:
  ```bash
  ddev drush php-eval '$e = \Drupal::entityTypeManager()->getStorage("field_config")->load("ENTITY.BUNDLE.FIELD"); echo $e->uuid();'
  ```
  Then add `uuid: <printed-value>` as the first line of the sync YAML.
- **Apprentices cloning the repo** will adopt the committed UUIDs when cim runs against their fresh DDEV — that's good for dev consistency. Live retains its own pre-existing UUIDs because cim doesn't modify existing entities' UUIDs.

The UUID-stripping bug in BOS field-instance configs (CLAUDE.md "Drush cim quirk") is the recurring cause of UUID drift. Always verify sync YAMLs have a `uuid:` line before committing field configs.

## Themes

- `web/themes/custom/brookstone_admin/` — primary admin/staff theme (Claro sub-theme)
- `web/themes/custom/brookstone_olivero/` — client-facing portal theme (Olivero sub-theme)

## What Is Not in Git

- `vendor/` — managed by Composer
- `web/core/`, `web/modules/contrib/`, `web/themes/contrib/`, `web/libraries/` — managed by Composer
- `web/sites/*/settings*.php`, `web/*/services*.yml` — environment secrets
- `web/sites/*/files/` — user files (on S3)
- `*.sql.gz`, `*.sql` — database dumps

## Known Issues / Pending Renames

- **`estimate.pinyon_pine_ips_beetle` → `work_order.pinion_pine_ips_beetle` spelling mismatch.** The estimate bundle uses `pinyon` (correct botanical spelling); the work_order bundle uses `pinion` (legacy typo). The `field_work_order` field on `estimate.pinyon_pine_ips_beetle` intentionally targets `work_order.pinion_pine_ips_beetle`. Do not "fix" that reference until the work_order bundle is renamed to `work_order.pinyon_pine_ips_beetle` — renaming the WO bundle requires coordinated changes to config, the `wo_pinion_pine_ips_beetle` module, and any views/reports that reference it by name.

## Patched Contrib Modules

- **`form_mode_control`** — requires a patch to fix a `foreach` on null `$defaults`. Applied via:
  ```bash
  sed -i 's/foreach (\$defaults as \$entityTypeId/foreach (\$defaults ?? [] as \$entityTypeId/' web/modules/contrib/form_mode_control/form_mode_control.module
  ```
- **`views_bulk_operations`** (4.4.4) — `viewsFormValidate()` crashes with `end(): Argument #1 must be of type array, null given` when `getTriggeringElement()` returns null (e.g., AJAX rebuild after batch step). Patch makes it defensive:
  ```bash
  patch -p1 -d web/modules/contrib/views_bulk_operations <<'EOF'
  --- a/src/Plugin/views/field/ViewsBulkOperationsBulkForm.php
  +++ b/src/Plugin/views/field/ViewsBulkOperationsBulkForm.php
  @@ -975,7 +975,12 @@ class ViewsBulkOperationsBulkForm extends FieldPluginBase implements CacheableDe
     public function viewsFormValidate(array &$form, FormStateInterface $form_state): void {
       if ($this->options['buttons']) {
         $trigger = $form_state->getTriggeringElement();
  -      $action_delta = \end($trigger['#parents']);
  +      if (!empty($trigger['#parents']) && \is_array($trigger['#parents'])) {
  +        $action_delta = \end($trigger['#parents']);
  +      }
  +      else {
  +        $action_delta = '';
  +      }
         $form_state->setValue('action', $action_delta);
       }
       else {
  EOF
  ```

NOTE: `contrib/` is excluded from rsync deploy — patches must be re-applied manually on live after `composer update`/`composer install`.

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

## Aeration Flag Heads (field_aeration_flag_heads)
- Field on: work_order.sprinkler_start_up
- Type: boolean
- Service: bos_scheduling.aeration_flag (AerationFlagService)
- Auto-set TRUE when property has active aerating WO
- Hooks in: wo_sprinkler_start_up (insert/update) and wo_aerating (insert/update)
- Shown in: WO title block, sprinkler scheduling tool, My Schedule, Dispatch board
- Backfill command: drush php-eval with AerationFlagService->updateStartUpFlag() loop

## Date Formatting

All user-facing dates and datetimes in BOS admin UIs must render in
US format. ISO `YYYY-MM-DD` is for storage and queries only — never
display.

- **Date-only:** `MM/DD/YYYY` (e.g., `04/15/2026`)
- **Datetime:** `MM/DD/YYYY h:i AM/PM` in the site's default timezone
  (e.g., `04/15/2026 2:23 PM`). Stored values are UTC; format at the
  display layer.
- **Day-of-week prefix is allowed** when it adds context (e.g., daily
  tables): `Mon 04/14/2026`.
- **Time-only is allowed** when a date-context wrapper makes the date
  obvious (e.g., a per-day expansion where every row is from the same
  date — show only `2:23 PM`).

This applies to:
- Rendered cell values, banner text, helper text, footer notes,
  stat card values
- Form field hint/description text (the native `<input type="date">`
  picker is browser-controlled and respects locale; only our
  surrounding helper text needs formatting)

This does NOT apply to:
- Storage formats — Drupal datetime fields stay UTC `Y-m-d\TH:i:s`
- URL query parameters — `?start_date=2026-04-15` (ISO is stable
  across locales for parsing)
- Internal comparison / sorting / canonicalization
- Log messages and exception traces

The `bos_teammate_operations` module's controllers each carry small
`formatDateUs()` and `formatDateTimeUs()` helpers as the canonical
implementation. New BOS UIs should follow the same pattern (small
helper on the controller / form, using `\DateTime->format('m/d/Y')`
and `'m/d/Y g:i A'`). If you find yourself adding more than two,
promote them to a shared trait or a tiny `BosDateFormatter` service
— do not let a third copy land.

## UI Patterns

For BOS admin/crew UIs, prefer established components over new lookalikes —
genuine visual consistency, reusing the component's actual tokens.

**Status-card pattern:** for any list of stateful records (work orders,
backflow devices, equipment, items with a status), default to a **status
card per record** rather than a plain Views table. The canonical reference
is the My Schedule crew cards (`bos_scheduling` — `my_schedule.css` /
`bos-scheduling-my-schedule.html.twig`): white card, 2px `#ddd` border,
6px radius, left 5px status-accent bar, flex header with the badge pushed
right, badge `radius:3px / .15–.5rem / 700 .8rem`. Color by status keyed on
the **machine value**; never double-signal (if the badge shows the state,
don't also color a date as "overdue"). Apply to a View/EVA via: Unformatted
row style + a `views-view-fields--<view-id>.html.twig` row template
(registered in `hook_theme` with `'base hook' => 'views_view_fields'`) +
card data computed in `hook_preprocess_views_view_fields` + CSS attached via
`hook_views_pre_render`. Reference impl: `backflow_device`
(`backflow-cards.css` + the Property Devices EVA).

Full details: `__BOS_AI/Governance/ui_patterns.md`.

## SOP Governance

When making changes to BOS workflows that involve human
action, Code must follow the SOP Maintenance Rules defined
in `__BOS_AI/SOPs/SOP-AUTHORING-WORKFLOW.md`.

Key rules:
- If a workflow change affects an existing SOP → update it
  in the same commit.
- If a new human-facing workflow is built → flag ⚠ SOP NEEDED
  at the end of the completion report.
- Never write SOP content directly — flag it and Claude Chat
  authors it.
- SOP source files live in `__BOS_AI/SOPs/[SOP_CODE]/`.
- Regenerate docx with:

      ddev exec "NODE_PATH=/usr/local/lib/node_modules \
        node /var/www/html/__BOS_AI/SOPs/[SOP_CODE]/[SOP_CODE]_source.js"

## Change Log

- **2026-06-24** — WEX daily fetch silent-outage **recurrence** fixed on live (no repo code change; cron + docs only). Latest `equipment_fuel_transaction` had been stuck at 06-18 for ~6 days. Root cause: the cron's `/opt/alt/php83/usr/bin/php /usr/local/bin/drush wex:fetch-email` (the June "fix") regressed — `/usr/local/bin/drush` is a 2021 global drush **PHAR** that re-execs `php` through the `#!/usr/bin/env php` → `/usr/local/bin/php` CloudLinux wrapper, routing to **CGI PHP** under cron (`$argv` undefined, `Content-type: text/html`, "[preflight] Drush is designed to run via the command line") so drush died before any code loaded. A deploy's `composer install` (or another env shift) had put the global PHAR back in the path. Final fix: invoke the project's `vendor/drush/drush/drush.php` **directly** as a script arg to the Alt-PHP CLI binary — one process, real CLI context. Live crontab corrected (backup at `~/crontab.bak.20260624`; use file-based `crontab <file>`, **never** `… | crontab -` which silently wiped the crontab once mid-fix — restored from backup). Verified end-to-end under `env -i` (cron-like): SAPI=`cli`, connects, 0 UNSEEN. Manual catch-up imported **9 transactions** (351→360, latest TX 06-22, 0 errors, all matched; one benign odometer-skip on vehicle 77630). Gotcha + `wex_fuel_import_workflow.md` updated with the PHAR re-exec nuance and the corrected invocation. **Follow-up same day:** added a drush-independent failure watcher (`web/scripts/wex_alert_check.sh`, second cron at 7:15 AM) that emails `todd@brookstoneoutdoors.com` only when the 07:00 fetch doesn't complete (block not from today, or missing the `fetch-email complete` line; quiet "0 UNSEEN" days stay silent) — so a third silent recurrence can't happen. Pure bash + server `mail` (CageFS proxyexec → real cPanel MTA; `/usr/sbin/sendmail`'s `mailtrap` group is a CageFS group name, **not** a mail trap). Email deliverability confirmed. See `__BOS_AI/Governance/drupal_bos_gotchas.md` → "cPanel/CloudLinux cron `drush` invocation fails silently".
- **2026-06-20** — Mow-crew billing VBO crash remediation (three parts). The "Mark WO Invoiced" VBO action threw an uncaught `EntityStorageException` mid-batch when select-all swept pre-completion (Scheduled 1091) WOs into a billing batch — aborting the batch and stranding `field_invoiced=1` on 3 WOs (50834/50835/50836). Fix: (1) `MarkWorkOrderInvoicedAction` rewritten — eligibility gate (skip unless Complete 1097 / Invoiced 1281), inverted write order (status-update audit first, `field_invoiced` only after it succeeds, so a guard throw can't orphan the flag), per-row try/catch so one bad row can't abort the batch, + skip/success/failure logging (commit `54f7ab23`, merge `e21a7fbd`). (2) Non-exposed `IN(1097,1281)` status floor (`taxonomy_index_tid`, `operator: or`) added to the five `admin_*_billing` views + `show_select_all: always_hide` on all six billing/admin VBO views (matching `admin_billing`); `admin_work_order_administration` got select-all-only — **no** status floor, since it targets all bundles and carries Cancel/Warranty actions used on pre-completion WOs (commit `df6592bc`, merge `09c29913`). (3) The 3 stranded flags reverted on live (data-only). Billing-ready = Complete (1097) for every department; 1281 is the post-invoice status, deliberately kept visible for the un-invoice/correction workflow (`mark_work_order_not_invoiced`). Deployed to live via surgical partial-cim (pre-ship drift check confirmed live==repo baseline on all six); verified — 73 pre-completion WOs across fall_cleanup/pre_emergent/snow_removal, 0 leaked into billing views. See `drupal_bos_gotchas.md` ("uncaught exception in a VBO action aborts the whole batch").
- **2026-06-19** — Two WO fixes + backflow go-live. (1) **Clock-in no longer resurrects closed WOs:** `wo_timer_flag_update_flagging_insert` skips the In-Progress (1092) promotion when the WO's persisted status is terminal — Complete 1097 / Warrantied 1283 / Invoiced 1281 / Paid 1504 / Canceled 1098 (the forgotten time/material entry still saves; only the status flip is suppressed). Commit `5e76da8a`; documented in `work_order_status.md` + `work_order_status_role_authority_model.md`. (2) **AEL sentinel heal generalized:** new `wo_shared_work_order_insert()` clears+re-saves any WO whose title still carries the `%AutoEntityLabel%` placeholder — covering every creation path (interactive add-form included), not just the two programmatic check-up creators the `cabb8a6e` double-save patched (commit `8a72d4ae`). Healed 4 live check-up WOs + 53 propagated child aliases. (3) **Backflow device system (Gates 1–4) deployed to live** via surgical partial-cim; `entity_print`/`dompdf`/`endroid-qr-code` installed by composer. Owed: S3 PDF smoke-test until the first real backflow test with a tester signature exists. See `__BOS_AI/Architecture/backflow_device_system.md`.
- **2026-06-06** — New SOP **OFF-QBS-INV-003** "Printing Customer Invoices in QuickBooks Desktop" (commit `244bb546`). Child of OFF-QBS-INV-001 (parent not yet authored), sibling of OFF-QBS-INV-002. Bundle: `office_administration`. Authored by Claude Chat against `GOV-SOP-001`; installed via the Code-installs/Chat-authors workflow documented in `__BOS_AI/SOPs/SOP-AUTHORING-WORKFLOW.md`. Side-effect: re-ran the one-time `ddev exec "npm install -g docx"` setup that gets reset on DDEV image rebuilds — the regen command in the workflow doc depends on it.
- **2026-06-05** — `work_order.special_mowing` billing formula rebuilt to match the time + materials + trip + dump + rental + adjustment shape used by sprinkler_repair / landscaping / fall_cleanup (commit `7b0b5268`). Prior code computed `$timeSpent` and threw it away (read stale `field_total_time` instead), skipped dump fees entirely, and ran a rental query that only billed receipt-cost rentals (silently dropped hourly-rented equipment). Fix introduces `get_special_mowing_total_dump_fees()` (mirrors `wo_fall_cleanup`), upgrades the rental query to the COALESCE pattern from `wo_sprinkler_repair`, computes `field_total_time` locally and writes it (defensive duplicate with `wo_shared`), and removes dead `$minAllottedTime` / `$trucks` locals. Labor minimum stays at `field_cleanup_labor_minimum` (1.0 hr); `field_trucks` is informational (per-truck dollars already flow via `wo_sign_off` setting `field_trip_fee = zipcode_trip_fee × trucks`). No backfill of historical completed WOs. Companion config-capture commit `0b2ab59d` brought the live UI edits on the bundle into `config/sync/`: new `field_trucks` instance, `field_scheduled.required` flipped to FALSE, plus the form/view display additions (field_trucks widget; Hours + Scheduling field_groups around the EVA blocks). See `__BOS_AI/Modules/wo_bundle_modules.md`.
- **2026-06-04** — WEX fuel-card daily IMAP import (commits `7330db9e` merge, `ff7a1f59` fix, `3fbafc6e` doc). `feature/wex-email-fetch` branch merged to main brings two new drush commands sharing one import core: `wex:import <path>` (file path source) and `wex:fetch-email` (IMAP source). `wex:fetch-email` reads UNSEEN messages from the configured WEX mailbox via `webklex/php-imap` ^6.2, extracts the WEX download URL from each body, fetches the CSV with Guzzle, hands the file to `WexFuelImportService::importFromFile()`. Marks messages Seen only on a clean run; failures leave UNSEEN for the next pull. Config block lives in live `web/sites/default/settings.php` under `$settings['wex_imap']` (password sourced from `getenv('WEX_IMAP_PASS')`; never literal in any tracked file). First-run shakedown surfaced two bugs fixed in `ff7a1f59`: sender default was `wexonline.com` but the actual mailer is `OnlineServices@wexinc.com`; and WEX wraps the text/plain body inside `multipart/related` which webklex exposes as an attachment (URL extractor now scans text-body → HTML-body → `text/*` attachments → raw RFC822 as fallback). Daily cron installed on live: `0 7 * * * LANG=C bash -c '...' >> ~/wex_fetch.log 2>&1` reading password from off-git `~/.wex_imap_env` (0600). First production IMAP run imported 12 transactions across 3 messages (11 matched, 1 unmatched_vehicle — Gerald's personal truck, equipment record pending). Full operational doc in `__BOS_AI/Modules/bos_wex_import.md`.
- **2026-06-03** — "Copy from first crew member" button on sign-off reconciliation rows + standalone `wo_time_clock` add form (commit `a965e540`). Each missing-row and orphan-row fieldset on the `wo_complete_info` sign-off form now carries a small blue panel: "First crew entry on this WO: {name} worked {start} – {end}" + a "Copy these times" button that pre-fills that row's datetime widgets. Cuts out the foreman's hand-keying on crews where everyone worked the same window — common case on irrigation/landscape sign-offs. Same library also fires on the standalone `wo_time_clock/add/entry` form (the "Enter Manually" path off the WO page). JS uses a `data-scope` attribute (`'row'` vs `'form'`) to know whether to target inputs by name-suffix within the closest fieldset or by full input name on the form. Times are pre-formatted in PHP against the site's default timezone so the JS does no TZ math. Validate/submit handlers already read by explicit key (`start_time`, `end_time`, `notes`) — the new `copy_from_first` sibling key is a passive container with no submitted value. See `__BOS_AI/Modules/wo_sign_off.md` and `__BOS_AI/Modules/wo_total_time.md`.
- **2026-05-24** — New `bulk_material` bundle on `material` ECK entity (commit `da0f54a9`): non-decorative bulk goods sold by the cubic yard or ton (topsoil, fill dirt, compost, lime, sulfur, gypsum, non-decorative sand/gravel, decomposed granite, soil amendments). Field profile mirrors `decorative_rock` (22 shared fields) with `field_rock_type` swapped for new `field_bulk_material_type` → new `bulk_material_types` taxonomy (15 seed terms). No entries migrated from `decorative_rock`; `mulch` bundle and `decorative_rock` untouched. Permissions mirrored role-by-role. Setup script at `web/scripts/setup_bulk_material_bundle.php`, seed-terms script at `web/scripts/seed_bulk_material_types.php`. See `__BOS_AI/Entities/material_bulk_material.md` and `material.md`.
- **2026-05-23** — SOP attachment + image standardization across all 11 SOP bundles (commits `2fc20893`, `b981b709`, `a15f8246`, `75d46f24`):
  - **Add Document buttons** on the `sop_file_attachments` EVA view (empty + footer regions) that prefill `field_sop` to the current SOP and set a `destination` back — one-click attach with no manual SOP lookup. Modeled on the `estimate_notes` EVA pattern.
  - **`field_sop` target_bundles opened** on both `sop_file_attachment` and `sop_images` media types from 2-of-11 bundles to all 11. SOP attachments now work on every bundle.
  - **`field_media_file_1` file_path on `sop_file_attachment`** now uses `[media:field_sop:entity:url:path]` (was date-bucket `[date:custom:Y]-[date:custom:m]`) so uploaded documents live under the referenced SOP's URL path on disk.
  - **`field_sop_image`** (single image field) added to all 11 SOP bundles with filefield_paths `[sop:url:path]` directory + `[sop:title].ext` filename templating.
  - **Form + view displays aligned** across all 10 non-office-administration bundles to match `office_administration`'s layout (same 4-tab field-group skeleton, same field weights for shared fields, bundle-specific fields preserved at weight 12). Propagation done via `web/scripts/propagate_sop_layouts_from_office_admin.php` (idempotent, reusable). See `__BOS_AI/Entities/sop.md`.
- **2026-05-21** — Default Supervisor on `work_order.landscaping` bundle changed from Ward Vetter (uid 136) to Todd Wellman (uid 1) (commit `313fe450`). Stored as `target_uuid` so it resolves correctly across environments.
- **2026-05-23** — `wo_total_time` long-shift confirmation UX consolidation: the form previously rendered two near-duplicate "yes this is intentional" checkboxes for the same long-entry — the persistent `field_time_limit_override` (Guard 6 per-bundle cap, audit-noted) and the form-only `long_shift_confirmed` (AM/PM safety net). Now `wo_total_time_form_alter` suppresses `long_shift_confirmed` when `field_time_limit_override` is on the form (the normal case — one checkbox shown), and the validator accepts either as confirmation. Fallback `long_shift_confirmed` weight lowered to 50 so it renders above Save/Delete instead of below. See `__BOS_AI/Modules/wo_total_time.md`.
- **2026-05-23** — `contract_residential` check-up WO title fix (commit `cabb8a6e`): the sprinkler_check_up AEL pattern uses `[work_order:id]` which isn't assigned during presave on insert; both programmatic creators (`ContractResidentialCheckupGeneratorQueueWorker::createWorkOrder` and `CreateAndScheduleSprinklerCheckUpWorkOrdersAction::createAndScheduleWorkOrder`) saved once, leaving AEL's sentinel placeholder `%AutoEntityLabel: <uuid>%` stuck in title (and consequently in pathauto URL aliases). 30 WOs were broken on live. Fix: save twice with cleared title between saves. Backfill script at `web/scripts/backfill_broken_checkup_titles.php` heals existing rows. See `__BOS_AI/Governance/drupal_bos_gotchas.md` for the gotcha.
- **2026-05-20** — `admin_calendar` calendar feed UTF-8 fix (commit `366c9014`): byte-based `substr` truncating multi-byte property nicknames (en-dash in "Ambulance District – Eckert") produced invalid UTF-8 → `json_encode` rejected the entire events array → `JsonResponse` threw → empty dispatch calendar (149 valid events, all invisible). Fix uses `mb_strlen` / `mb_substr` plus `JSON_INVALID_UTF8_SUBSTITUTE` defensive flag on the response. See `__BOS_AI/Governance/drupal_bos_gotchas.md` (new gotcha) and `__BOS_AI/Entities/scheduling.md`.
- **2026-05-16** — Labor/time/scheduling hardening (see `__BOS_AI/Modules/wo_total_time.md`, `wo_sign_off.md`, `wo_shared.md`, `wo_timer_flag_update.md`, `Entities/scheduling.md`, `Governance/drupal_bos_gotchas.md`):
  - Removed the `field_total_time = sum × crew_count` multiplier (`wo_sign_off`, `wo_lawn_mowing`); 62 affected WOs backfilled on live (Pattern-B only).
  - `wo_shared` work_order presave: recalc `field_total_time` while Complete; **block Invoiced transition without prior Complete** (bypass: `$wo->_skip_invoiced_guard`).
  - Single-entry duration cap (`wo_total_time` Guard 6): per-bundle `business_setting.field_max_entry_hours` (4) / `field_max_entry_hours_long` (14, for landscaping/sprinkler_repair/sprinkler_installation) + `wo_time_clock.field_time_limit_override` checkbox + idempotent audit note. Smart Clock-Out button routes over-cap clock-outs to the edit form; flag-off-on-close invariant; billing red-alert preprocess on `admin_billing`.
  - `wo_timer_flag_update`: clock-out notes now multi-value `appendItem` (two rows, not concatenated).
  - `wo_schedule`: every schedule create/change auto-logs a WO note (date/crew/scheduling-note, old→new); date rendered date-only. New schedules default to **today / All Day** via `hook_entity_prepare_form` (Smart Date `default_duration` 1439 alone doesn't tick the box).
  - Sign-off reconciliation: orphan handling split by form type (wo_complete_info per-row prompt; wo_tasks_list silent `end=now`). Add-form per-row fields fixed via `_wo_signoff_ctx` form-state stash.
  - New gotchas documented: `$entity->original` not populated on update (use `loadUnchanged()` in presave); `getValues()` empty at form-build-time on rebuild (stash from validate handler).
  - Deferred: auto lunch/break deduction (`Governance/deferred_work.md` #16).
- **2026-03-12** — Removed debug logging from `wo_total_time` (Presave Debug, Not updating UID notices) and `wo_timer_flag_update` (Flag state notice). Updated `teammate_pre_emergent_wos` view config.
