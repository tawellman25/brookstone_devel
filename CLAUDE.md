# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **New to BOS? Start with [`__BOS_AI/Governance/onboarding.md`](__BOS_AI/Governance/onboarding.md)** — the day-one map: the Intent-vs-Execution spine, the four audiences (public / client / teammates / office-admin), the ~7 functional domains with hand-off risk, the house rules, and a first-week path.
>
> **For process discipline and engineering norms when working with Claude on BOS, read [`__BOS_AI/Governance/working_with_claude.md`](__BOS_AI/Governance/working_with_claude.md).** Pause-and-verify pattern, targeted commits, end-to-end verification, recovery-point pushes — required reading before non-trivial work. The companion docs [`drupal_bos_gotchas.md`](__BOS_AI/Governance/drupal_bos_gotchas.md) and [`architectural_patterns.md`](__BOS_AI/Governance/architectural_patterns.md) cover Drupal/BOS-specific traps and reusable patterns. [`deferred_work.md`](__BOS_AI/Governance/deferred_work.md) tracks surfaced-but-deferred items.

> **📍 Roadmap = status-of-record. [`__BOS_AI/ROADMAP.md`](__BOS_AI/ROADMAP.md) is the authoritative tracker of every unfinished BOS initiative** (each row carries Horizon / Tier / Effort / Season; it has its own Maintenance protocol at the bottom). **Keep it in sync as part of every commit/deploy — do not let it drift:**
> - When a commit or deploy **ships, changes, or obsoletes** a roadmap item, reconcile the affected rows in ROADMAP.md **in the same session** (protocol rule 7 — "end each work session by reconciling the affected rows").
> - **Move shipped work** to the "✅ Shipped — verified DONE" archive with a commit-hash / live-check anchor; archived items live **one cycle, then drop** — delete old finished references so the archive doesn't accrete.
> - **Add newly-surfaced initiatives** with at minimum Area + one-line scope + a tier guess (protocol rule 6). No untitled rows.
> - **Anchor every status to evidence** (commit hash · module · config key · named live check) so the quarterly read-only recon can re-verify it.
> - If a status here **conflicts** with memory, a chat, or Todoist, **ROADMAP.md wins** — reconcile the others to it.
> - `deferred_work.md` stays the finer-grained "surfaced-but-deferred" scratch list; ROADMAP.md is the curated, prioritized board. When something graduates from a passing mention to a real initiative, promote it onto the roadmap.

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

The deploy script rsyncs code to live, then runs `composer install --no-dev` and `drush cr` on the remote. Config import does **not** run by default. ⚠️ **Do NOT pass `--cim`** — it runs a *full* `drush cim`, which would revert ~340 intentionally-drifted configs (see "Configuration Management" below). Import config changes with a **surgical partial-cim** of only the specific files instead. **The DB is never touched by the deploy.** Directories `.vscode/`, `dev_scripts/`, and `__BOS_AI/` are protected from deletion on live even with `--delete`.

## __BOS_AI Documentation Bundle

The `__BOS_AI/` tree is the authoritative governance documentation for BOS, organized into subdirectories (`Entities/`, `Modules/`, `Governance/`, `Business/`, etc.). Claude.ai's project knowledge UI requires a flat list of files for upload, so we maintain a flattened staging dir at `__BOS_AI/_upload_bundle/` for that purpose.

### Regenerating the bundle

**Just run the script** — [`__BOS_AI/bos-ai-sync.sh`](__BOS_AI/bos-ai-sync.sh) does the clean, stage, and verify in one path-independent step (exits non-zero if a duplicate basename slips through or an expected file is missing):

```bash
./__BOS_AI/bos-ai-sync.sh
```

> **⚙️ Standing directive (auto-regenerate):** at the **end of any session where `__BOS_AI/` `.md`/`.docx` content changed** (including any [`ROADMAP.md`](__BOS_AI/ROADMAP.md) reconciliation), **run `./__BOS_AI/bos-ai-sync.sh` automatically** so the staged bundle stays current — do not wait to be asked. The staged `_upload_bundle/` is gitignored, so this is a local-only, copy-only artifact refresh (no commit needed for the bundle itself; the source `.md` edits are committed as usual). The user uploads the refreshed bundle to the Claude.ai project; Chat reads `ROADMAP.md` there as the overview (Claude Code is the sole writer of the roadmap — Chat does not edit it once it's in `__BOS_AI/`).

The script keeps the staging rules below in lockstep — if you change the exclude sets or `RENAME` map, update **both** the script and this section. The equivalent inline logic (Python, since `zip` isn't available in WSL by default), for reference:

```bash
# Clean and re-stage
rm -rf __BOS_AI/_upload_bundle
mkdir -p __BOS_AI/_upload_bundle
```


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

#### `equipment` — 9 bundles
`attachements` *(permanent typo — do not rename)*, `heavy_equipment`, `power_tools`, `small_engine`, `snow_plows`, `sprayers`, `trailers`, `vehicles`, `it_equipment`

> `it_equipment` (IT / computer assets — PCs, NAS, switches, router/gateway, printers) reuses the shared equipment machinery (defect / maintenance-event / inspection). Reused fields + net-new `field_it_*` fields (instanced only on this bundle). Design: `__BOS_AI/Entities/equipment_it_equipment.md`; import: `bos_it_import`.

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
| `wo_profit` | Supervisor/admin-only WO **cost & gross-profit**. Blended labor cost/hr in `business_setting` + `field_wo_cost`/`field_wo_profit` on all 35 real WO bundles; `WoProfitCalculator` (labor = hours × blended rate; materials = Σ `field_subtotal` cost basis; chemicals/rentals/dump pass-through; profit = `field_wo_total` − cost). Generic `entity_presave` (weight 100) freezes cost/profit at Complete (1097); role-gated live panel on the WO page (`view wo cost profit` perm). Stage 1 of the cost/profit roadmap item. Docs: `Modules/wo_profit.md`. |
| `wo_billing_recalc` | Auto-recomputes WO billing totals when a billing child (`wo_material_list`/`_item`, `wo_rental_equipment`, `wo_chemicals_used`, `wo_material_dumping`) is inserted/updated/deleted — re-saves the parent WO **only while Complete (1097)** so billed totals stay frozen. Removes the manual re-open/re-save of the sign-off form. `wo_time_clock` excluded (handled by `wo_total_time`). |

### Material/Equipment modules
| Module | Purpose |
|---|---|
| `material` | Material entity guardrails + WO snapshot pricing rules |
| `material_supplier` | `material_suppliers:supplier` integrity: no duplicates, preferred supplier, pack qty, SKU normalization |
| `bos_it_import` | Drush `it:import <file>` — imports IT assets (PCs/NAS/switches/gateway/printers) from the Office Network Baseline XLSX into `equipment:it_equipment`, idempotent on Asset ID. Patterned after `bos_wex_import`. |
| `equipment_actions` | VBO actions for equipment entities |
| `equipment_status_updates` | Propagates equipment status update entity changes to Equipment entity |
| `equipment_inspection_workflow` | Equipment automation: defect auto-creation on inspection approval (18 rules), maintenance event defect closure, equipment status sync on out-of-service |
| `wo_rental_equipment_ui` | WO **Equipment section** — owned-equipment billing + card display. Presave on `wo_rental_equipment`: for an **owned** (`field_equipment_used` set) + **Billable** machine, auto-fills `field_hourly_rate` from the machine's `field_rate` ("Hourly Work Order Rate") so the existing rental billing (`COALESCE(receipt, hourly_rate × hours)`) charges it → `field_rental_total` → invoice (manual rates never overwritten). Renders each equipment entry as a **card** (Labor-hours pattern) showing hours, **Charge** (= hours × `field_hourly_rate`, matches what bills) and **Our cost** (= hours × `field_operating_cost_per_hour`), Owned/Rented badge, edit link. View switched to card rows by `wo_rented_equipment_to_cards.php`. |
| `equipment_similar` | "Similar Equipment" strip at the bottom of each equipment page — other machines of the same `field_equipment_type` (pic + title), excluding the current one. The list is the **`equipment_similar` view** (fields/sort/count adjustable in Views UI; cloned from `equipment_type_current_list`); `hook_ENTITY_TYPE_view` on `equipment` embeds it, feeding [type_tid, self_id] (EVA can't pass a field value) and hiding the section when there are no siblings. |
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

> ## ⛔ NEVER run a full `drush cim` (or the deploy's `--cim`) against live
> `config/sync` is **intentionally drifted** from live's active config — ~340 configs differ
> (active is the source of truth; BOS evolves config via the UI and deploys do **not** import
> config). A full `drush cim` would revert all ~340 to the stale sync versions, breaking views,
> displays, fields, permissions, ECK types, and more. **Always use a surgical partial import**
> of only the specific new/changed configs:
> ```bash
> mkdir -p /tmp/cimstage && cp config/sync/<exact files…> /tmp/cimstage/
> drush cim --partial --source=/tmp/cimstage -y
> ```
> Likewise never run a blind `drush cex` (it dumps the whole drift into sync + mangles ECK
> exports). Reconciling the drift so `cim` is safe again is a documented future project
> (`__BOS_AI/Governance/deferred_work.md` #22) — until then, partial-only.

All Drupal config is exported to `config/sync/` and deployed via surgical **partial** `drush cim` (see the warning above). The `config_ignore` module excludes environment-specific config (credentials, keys, `stage_file_proxy` origin) from config management. Never commit `settings.php` or `services.yml`.

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

**No manual contrib patches are required anymore** (as of 2026-06-27) — both former ones were
obsoleted by upstream releases:

- **`views_bulk_operations`** — the `viewsFormValidate()` `end(): … null given` crash is **fixed
  upstream as of 4.4.5** (the installed version: the guarded `if (!empty($trigger['#parents']) && is_array(...))`
  is now in the module). The old manual patch (written for 4.4.4) is neither applied nor needed.
- **`form_mode_control`** (8.x-2.6, latest) — the `foreach` on null `$defaults` is guarded
  **upstream** by an early `if (empty($defaults)) { return; }` before the loop, so `$defaults`
  can never be null at the `foreach`. The old `?? []` sed patch is redundant. (A harmless
  leftover `?? []` may persist in an environment's copy until the next module update re-extracts
  it — no action needed.)

Declared contrib patches (`drupal/core`, `smart_date`, `page_manager`,
`views_aggregator`) are managed by **`cweagans/composer-patches`** via `composer.json`
`extra.patches` and applied automatically on every `composer install`. **`contrib/` is
composer-managed** (installed on each environment by `composer install`, not committed/rsynced),
so any future contrib patch must be declared in composer-patches — never a one-off manual `sed`,
which silently vanishes on the next `composer install`/update.

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

- **2026-08-23** — **`/winterize/week` "Check your service week" — pc26a postcard (LIVE).** The **pc26a** ("already on our list") QR now lands on a dedicated **Check-your-week** page (`CheckWeekForm`, route `bos_service_request.check_week`) instead of the signup form; pc26b/website still go to `/winterize`. It **reveals a customer's scheduled week ONLY on contact corroboration** — the visitor enters last name + service address + ZIP + phone/email, and the week shows only when the phone/email **matches the matched property's on-file contact** (`ServiceRequestPropertyMatcher::contactCorroborates` — the same §6.0 gate as intake). A street-address-only or non-corroborated attempt returns an **identical neutral answer** (no enumeration); reCAPTCHA + a per-IP flood cap (`check_week_ip`, 15/hr, registered every attempt) guard against brute-forcing contact combos; no property element is ever rendered. The week is read from the WO's `scheduling` record (Monday of that week, `D m/d/Y`); "on the list, week not set yet" when the WO exists but is unscheduled; a "not found → sign up" CTA otherwise. The marketing template is now route-aware (parameterized `bo_eyebrow/headline/subhead/cta_text/formband_*` + `bo_show_detail` — signup shows the "what happens next" + accordions; the check page hides them). `ServiceRequestQrController` routes pc26a → check page. **Owner-accepted tradeoff (2026-08-23):** someone who knows a customer's address AND phone/email can see that customer's week — chosen deliberately over "call the office to confirm." Deploy: rsync module + scp template + `cr` (no cim/DB). Verifier `verify_service_request_phase2.php` **31/31 dev + live** (incl. check page renders, no property element, no week on GET). Docs: `Modules/bos_service_request.md`.

- **2026-08-23** — **BOS brand tokens published site-wide (LIVE).** Made the Brookstone palette (`--bo-*` CSS custom properties in `bos_service_request/css/bo-tokens.css`) the **single source of truth**, attached to every page (both themes) via `bos_service_request_page_attachments()` → `brand_tokens` library. **Survey finding:** the site's common CSS does **not** use the brand colors — the palette was new to `/winterize`, and the only oranges elsewhere are intentional **status** colors (a `#f5a623` alert border, the `#d35400` COD badge), not the brand accent — so there was nothing to "convert" and **no site recolor was done** (defining custom properties is inert until referenced). Tokens = CorelDraw Pantone accents (orange **#CB6015**, green **#007A33**, blue #62B5E5) + warm neutrals. New BOS convention: reference `--bo-*` instead of hardcoding brand hexes. Verified live: `bo-tokens.css` loads on front/admin/winterize; winterize verifier 27/27. (If BOS ever wants the public/admin UI to visually **adopt** the brand accents, that's a separate, review-gated design pass — not done here.) Deploy: rsync module + `cr` (no cim/DB).

- **2026-08-23** — **`/winterize` public landing page — Phase 3 presentation (LIVE).** Rebuilt the public winterize page to the approved Claude design (artifact fetched via WebFetch, artboard extracted from the `appifact-doc` block). **Dedicated marketing template** `page--winterize.html.twig` (theme-suggestion via `bos_service_request` `hook_theme_suggestions_page_alter` + `hook_preprocess_page`) renders **marketing chrome only** — logo/wordmark/tagline/phone, hero, form, dark footer — and **none of the site's nav/menus/regions** (verified 0 `<nav>`, no internal paths for anon; fixes the P3.1 leak). **Design:** Oswald (display) + Source Serif 4 (body); brand tokens in `css/bo-tokens.css` = **CorelDraw Pantone** accents (orange **#CB6015** 159 C, green **#007A33** 356 C, blue #62B5E5) + mockup neutrals (paper #FAF7F2, sand #F2E9DC, card #FFFDFA, ink #1A1613); page CSS `css/bo-winterize.css`. **Hero:** the owner's original background asset (CMYK→sRGB, downscaled to 1840×1026), **left-justified** `object-fit:cover` so the whole composition shows and fills top-to-bottom, with an **eased CSS fade** (50%→82%) layered over the softened printed fade + white behind the copy; eyebrow, headline, subhead, orange CTA, and 30+ years / Guaranteed stats. **Form card** on the sand band: 2-col grid, "Not sure"-first water-supply select, orange-ruled specific-date block, opt-in pair, disclaimer w/ warning glyph (`Markup::create` so the SVG survives), orange **"Get on the list"** submit (white text forced past Olivero's `.button`), 3-col "What happens next" w/ green icons, 2-col accordions (editable term body, JS-free `+`→`×`). **reCAPTCHA** replaces the math captcha (keys live). **JS** (`js/bo-winterize.js`, progressive): hero CTA drops the cursor into Last name; after a submit the page scrolls to the warning/confirmation (form sits below the hero). Logo trust mark is the transparent round emblem direct on the dark footer (no backing plate). Assets live **in the module** (`assets/`, deployed via rsync — not S3). Deploy: rsync module + scp template + `cr` (no cim/DB; the P0–P1/P3.3 field+view+config changes shipped earlier). Verifier `verify_service_request_phase2.php` **27/27 dev + live**. Owed: site-wide color-token refactor (palette now locked); P3.2b inline seasonal photos (not supplied). Docs: `Modules/bos_service_request.md`.

- **2026-08-22** — **Winterize signup open/close gate → Business Settings (LIVE).** The public `/winterize` intake gate had no admin UI (drush/config only). Moved it onto the **Business Settings** page (`config_pages:business_setting`, `/admin/config/business_settings`) as a new **"Public Service Requests"** group with 3 office-editable fields: **`field_winterize_signup_open`** (boolean master toggle), **`field_winterize_open_from`** / **`field_winterize_open_until`** (date-only window). `WinterizeForm::signupOpen()` now reads these config_pages fields first, **falling back** to the module config (`bundles.sprinkler_winterizing.{signup_open,open_from,open_until}`) when absent. Seeded from the current gate so behaviour is preserved (open, 2026-08-20 → 2026-11-15). Built via idempotent entity-API script `web/scripts/setup_winterize_gate_business_setting.php` — **no cim**; only `WinterizeForm.php` changed in the module. Toggle verified end-to-end on dev (off→closed page, on→open) and live (form group renders, gate seeded, `/winterize` OPEN). Deploy: scp form + script + run + `cr` (safety dump `~/pre-winterize-gate-bs-20260822.sql.gz.gz`; no cim/DB migration). Office can now open/close winterize signup (and set the window) from Business Settings.

- **2026-08-22** — **Services taxonomy — public vs teammate service pages (LIVE).** Each `/services/{name}` page renders **by the viewer's role, same URL**: public/clients see a "what we do" page; crew/office see a "how we do it" training page. New **`bos_services`** module — `hook_entity_view_mode_alter` switches the canonical full-page render to the **`teammate_view`** view mode for internal roles (`teammates`, `supervisor`, `administration`, `site_assistant`, `site_admin`, `administrator`), else **`full`** (public). **Two DEDICATED fields** (not shared — the summary lives on the public side, and no misleading names): **`field_service_public_desc`** (`text_with_summary`, **has the optional summary**) = public "what we do", shown in `full` + trimmed (300) as the `/services` listing teaser; **`field_service_crew_desc`** (`text_long`, no summary) = crew "how we do it", shown in `teammate_view` only. **`full` display** = banner image(s) (`field_banner_image`, multi-value) + iconic image + subtitle + public desc; **`teammate_view`** = crew desc + department + service code (no images — deliberate). **Form:** core taxonomy `description` retired from the form; **Public View** tab holds the public desc (summary widget), new **Teammate View** tab holds the crew desc (plain). Built via one idempotent entity-API script `web/scripts/configure_services_descriptions.php` — **no cim**; module is one `hook_entity_view_mode_alter`. Crew field **not rendered at all** for anon (no leakage — verified by term-page render + `/services` listing text scan). **Iteration note:** a first pass reused the shared `field_public_description` (text_long, no summary) for public + relabeled the shared `field_description` for crew; replaced same-day with the two dedicated fields above (content migrated, shared instances **removed from the services bundle**; their storages remain for equipment_types/material_types/backflow_*) so the public field is summary-capable and nothing is misnamed. Deployed dev → verified → live (safety dumps `~/pre-services-split-20260822.sql.gz.gz` + `~/pre-services-fields-redo-20260822.sql.gz.gz`; rsync module + scp script + run + `cr`; no cim/DB migration). ⚠ **SOP flagged** `CRW-SVC-TRN-001` (per-service crew training on the Services page) — logged in the **Pending SOPs backlog** in `SOPs/SOP-AUTHORING-WORKFLOW.md`; Chat authors. Docs: `Entities/services.md`, `Entities/taxonomy_vocabularies.md`.

- **2026-08-21** — **Winterizing schedule carry-forward — `bos:winterize:*` (SHIPPED LIVE; 457 records applied).** Two Drush commands in **`bos_scheduling`** that propose next season's `sprinkler_winterizing` schedule from prior-season history and, on a second explicit run, apply the reviewed proposals as real `scheduling:work_order` records. **Creates ONLY scheduling records + flips `work_order.field_scheduled`** — never creates/cancels/deletes a WO/Property/Contract, never writes `field_status` (that's `wo_schedule` via `wo_status_updates`). **`plan`** (read-only, safe on live) selects target-year winterizing WOs (created in the **calendar year**) with no scheduling record + not excluded status (1098/1097/1283/1281/1504); per candidate: prior counterpart **recency-wins never averaged** (source-years fallback chain 2025,2024; authority = the prior scheduling record's `field_date`), **date = same nth-weekday-of-month** (5th→last fallback, all via `DrupalDateTime`, never `FROM_UNIXTIME`), **tech = the ACTUAL signer** (`wo_complete_info.field_signed_off_by`, fallback planned assignee; dead/inactive → blank/unassigned), **route order = the driven order** (sign-off ts → clock start → Complete status ts → planned; dense 1..N per date+tech). Action split: **schedule** (confident + dead-tech-unassigned) / **review** (Sunday/closure only — holidays non-blocking, crews work 10/12) / **skip** (new customer, no prior + no neighbour). **Proximity fill:** new-customer rows placed on the nearest confidently-scheduled property's day (haversine over `field_geofield` ≤10mi), unassigned, flagged. Writes a full CSV + a focused `*_REVIEW.csv`. **`apply`** re-reads the reviewed CSV (the edited file is authority — does NOT recompute), re-validates each row against live, creates via the shared writer; `field_scheduled_firm=FALSE` (soft proposals) + `field_notify_assigned_teammate=FALSE`; **idempotent** (a WO with a schedule is skipped → a 2nd run is a no-op); one bad row never aborts. `--actor` required + switched-to for real access/attribution; **uid 1 rejected unless `--allow-superuser`** (uid 1 = Todd Wellman, who owned this run). New **`bos_scheduling.schedule_writer`** (`ScheduleWriter`) = the single scheduling-creation path, **extracted verbatim** from `SprinklerSchedulingController::save()` (bulk scheduler refactored onto it, behavior byte-identical); writes `field_date` (duration 1439 all-day) only — `wo_schedule`'s presave Smart-Date→DateRange sync back-fills legacy `field_scheduled_date_and_time` + `custom_date_all_day` sets the flag (verified, never hand-written). **§0.5 read-only probe** (`web/scripts/probe_winterize_order_signal.php`) chose the order+tech signals from 2025 data (634 sign-offs all irrigation_crew; sign-off ts real-time not batch; 23% planned-vs-actual tech mismatch → use actual signer). **Verifier** `web/scripts/verify_winterize_carry_forward.php` (8 checks: ≤1 schedule/WO, in-window+1439, field_scheduled flipped, nth-weekday rule, no excluded-status, dense route order, UI 200s). **Dev dress rehearsal** (DB synced from live): plan 459 → apply 457 → **8/8**; surfaced + fixed a verifier-only bug (its WO window started Aug 1, dropping a Feb-created 2026 WO → false dense-order FAIL; widened to calendar-year to match `plan()`). **Live:** safety dump (`~/pre-winterize-apply-20260821.sql.gz.gz`, 132M, verified) → plan 459 (432 auto+tech / 25 unassigned [18 proximity new-customers + 7 dead-tech] / 2 Sunday-review / 0 skip) → apply `--limit=10` (8/8) → full apply **447 (+10 = 457), 12 skipped** (10 already + 2 review) → **8/8, 457 records** → idempotent re-run 0/459. Distribution Sep 33 · Oct 421 · Nov 3 (Mon 10/12 Columbus Day carries 19, non-blocking). **Office follow-ups:** confirm/assign the 25 unassigned + manually add the 2 Sunday rows, from `~/tmp/winterize_plan_2026_REVIEW.csv` on live. Deploy: rsync `bos_scheduling` + scp verifier + `cr` (no cim/DB). Docs: `__BOS_AI/Modules/winterize_carry_forward.md`.

- **2026-08-20** — **Public service-request intake layer — Gates 1–4 (LIVE).** New **`bos_service_request`** module + **`service_request`** ECK entity (bundle `sprinkler_winterizing`, 23 fields). A public, unauthenticated path that produces a **controlled internal request record** and creates a real Work Order **only after office approval** — intake is never execution; no anonymous path creates/schedules a WO/Property/Contact. **Gate 1** — entity/bundle/fields (via idempotent `setup_service_request_entity.php`, not cim), `service_request_status` vocab (New/Needs Review/Verified/Already Covered/Duplicate/Rejected/Converted — content, resolved by name; TIDs differ dev/live), perm `administer service requests`. **Gate 2** — services: `ServiceRequestEligibility` (first-hit authority: property `field_no_services` → owner `field_credit_hold`/`field_do_not_schedule` via latest ownership_record → existing non-Canceled WO in service-year window → **contract coverage** [`contract_sections` field_service=369 + `field_do_you_want IN (1 Yes, 4 Accepted)` on a current-year residential contract in status {1123,1651,1124,1125,1127}] → existing active request → eligible), `ServiceRequestPropertyMatcher` (server-side ZIP-filtered, suffix-agnostic street prefilter), `ServiceRequestConverter` (locked, transactional, idempotent; delegates WO creation to `bos_wo_intake.intake`), `ServiceRequestStatusResolver`. Extracted `bos_wo_intake`'s private normalizers into a shared **`PropertyMatchNormalizer`** service both modules use (live intake re-verified). **Gate 3** — public **`/winterize`** form (Form API, captcha, flood per-IP-hr + per-address-yr, campaign `?c=` allowlist, open/closed gate). **§6.0 property-disclosure invariant** enforced + tested vs rendered HTML: no property element/candidate, injected `property_id` ignored+logged, matching entirely server-side, confirmation byte-identical (ref aside) for matched/ambiguous/unmatched; unmatched creates ZERO properties/contacts; "already on our list"/duplicate copy fires ONLY on email/phone contact corroboration (enumeration control). **Gate 4** — office queue view **`service_request_admin`** at **`/admin/office/service-requests`** (built by `build_service_request_admin_view.php`); **Approve & Create Work Order** + Mark Duplicate / Already Covered / Reject as **per-row confirm forms** (NOT VBO — the documented VBO-footgun family); `hook_entity_operation` dropbutton; presave backstop blocks status→Converted without a linked WO. Verifiers `web/scripts/verify_service_request_gate{2,3,4}.php` (13/13, 13/13, 10/10 dev; read-only on live). **`/winterize` is OPEN on live** (`open_from` set to 2026-08-20 for preview; office phone 970-835-9661). **Deploy:** scp module + `drush en` + entity/vocab/view scripts + `cr` (no cim/DB). Remaining: Gate 5 (campaign report + QR asset), Gate 6 (more bundles, offseason). First workflow = 2026 winterizing postcard QR (mails early–mid Sept). §5.4 contract-status set broadened beyond the spec's three (added 1651 Generate WO + 1127 Completed) — flagged, easily narrowed. Docs owed: `__BOS_AI/Modules/bos_service_request.md` + ROADMAP row.

- **2026-08-16** — **Public menu: "Lighting" moved under the Services tab (LIVE + dev).** On the public site (`main` menu), the service links are `taxonomy_menu`-derived off the **services** vocabulary and **hand-arranged** (the menu doesn't purely mirror the taxonomy — e.g. "Holiday Decorations", a child of the Lighting term, sits under Services). "Lighting" (services term **1505**) had been left at the **top level** instead of nested under the **Services** view link (`views_view:views.services.page_1`) like its siblings (Landscaping/Sprinklers/Snow/etc.). Fixed by setting that derived link's parent to the Services link via `MenuLinkManager::updateDefinition` — a **runtime menu override** (survives a full `menu.link` rebuild; verified), **NOT config/cim**. Idempotent script **`web/scripts/fix_lighting_menu_parent.php`** (run per env — menu overrides are DB/runtime, not deployed). Lighting keeps its own Landscape/Exterior Lighting children as a sub-menu under Services. A plain `->rebuild()` did **not** fix it (proving it was an override, not stale cache). Applied live + dev.

- **2026-08-16** — **Off-site DB backups → S3 (LIVE).** The nightly `bos_db_backup.sh` (2:30 AM, `drush sql:dump --gzip`, 14-day rotation in `~/db_backups`) now **also pushes each dump to S3** — a separate failure domain from the Hosting.com data center (motivated by the **2026-08-13/14 Phoenix AZ1 thermal outage**: storm knocked out DC cooling → network/power disruption → BOS unreachable ~1 hr; server never rebooted — 846-day uptime — it was facility-level, not our box, and **not** the concurrent photo-scan we initially/wrongly blamed). **New bucket** `brookstone-db-backups` (**us-east-1**, Global namespace, Block-all-public, SSE-S3, ACLs disabled, lifecycle **expire after 30 days**). **New least-privilege IAM user** `brookstone-db-backup` with an `s3:PutObject`-only policy scoped to `arn:aws:s3:::brookstone-db-backups/*` (**no read/delete/list** — a compromised box can only drop backups, not read data or wipe the archive; incomplete-multipart cleanup left to lifecycle). Upload uses **new `web/scripts/s3_backup_upload.php`** (single PutObject via the **aws-sdk-php already in `vendor/`** — s3fs dep; **no AWS CLI on the box**, none installed). Credentials live **off-git** in `$HOME/.bos_s3_backup.env` (chmod 600; `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`BOS_S3_BACKUP_BUCKET`/`BOS_S3_BACKUP_REGION`) sourced by the script; `.bos_s3_backup.env` gitignored. S3 failure **emails an alert but does NOT abort** the local dump (local stays primary). **No cron change** — the existing 2:30 cron calls the same script. Verified end-to-end on live (dump → `uploaded s3://brookstone-db-backups/…` → rotation kept 14); also pulled a manual post-incident dump off-server to `/mnt/d/Backups/brookstone-live/` (gzip-verified). ⚠ Access key passed through chat during setup — **rotate it** (IAM → user → Security credentials). Deploy: `scp` the 2 scripts + create the env file; no rsync/cim/DB.

- **2026-08-03** — **Preferred Mow Height on Mowing Information → mowing WO + Mow list (LIVE).** New **`field_preferred_mow_height`** (string, free text — "3 in", "2.5″ front / 3″ back", …). **Source:** `property_lawn_maintenance.lawn_maintenance_info` (the Mowing Information), on its form + view display. **Mow list:** added as a column to `teammate_mow_route` + `admin_mow_crew_route` (both property_lawn_maintenance-based, table style — native field, always live). **Mowing WO:** a copy on `work_order.lawn_mowing`, **auto-populated from the property** on new mowing WOs — `wo_lawn_mowing_entity_presave` copies it (before the Complete guard, only when empty) so the crew sees it on the WO (snapshot at creation; Mow list stays live). Idempotent entity-API scripts (`setup_preferred_mow_height.php`, `add_mow_height_to_views.php`) — **no cim**; module = one guarded presave block. Deployed dev → verified property→WO copy → live (**no maintenance mode, zero downtime** — crews were mowing). Docs: `Entities/property_detail_entities.md`.

- **2026-08-03** — **`equipment.attachements` bundle built out — attachments catalog (LIVE).** The attachements bundle had **zero non-base fields**; added a full field set so skid-steer/tractor/mini-ex attachments (buckets, forks, blades, rakes, mower decks, trencher attachments, hydraulic thumb/clamp, etc.) can be catalogued. **All 15 storages already existed** — instances **cloned from `heavy_equipment`** (and `field_attaches_to` from `snow_plows`), so **no new storage, no cim**; idempotent entity-API script `setup_attachements_bundle.php`. Fields: **`field_attaches_to`** (entity_reference → equipment; targets **heavy_equipment, vehicles, small_engine**) + equipment_type, status, make, model, serial, size, manufactured_year, date_purchased, purchase_price, depriciated_value, equipment_number, public_description, pictures, documents. Form + default view display mirror heavy_equipment. **Bundle label fixed "Attachements" → "Attachments"** (machine name stays `attachements` — permanent typo). **New equipment_types terms** (`field_equipment_bundle=attachements`, via `seed_attachment_types.php`, live TIDs): Bucket 1922, Forks 1923, Hydraulic Thumb/Clamp 1924, Landscape Rake 1925, Mower Deck 1926, Trencher Attachment 1927, Blade / Plow 1928, Mulching Kit 1929, Aerator Attachment 1930. Requested by the 2nd Brain (Claude Code on D:), which will populate data next (move the 16" trenching bucket #35136 + hydraulic clamp E26 #35137 out of heavy_equipment into this bundle, attached to the Bobcat mini-ex). Verified live (15 fields, label, targets). Docs: `Entities/equipment.md`.

- **2026-08-03** — **Hardscape (pavers) bundle field build — `material.pavers` now models pavers + wall blocks (LIVE).** Reworked the "Block and Pavers" bundle (one bundle, not a split). New **`hardscape_types`** vocabulary (Paver/Wall Block/Cap/Coping/Edger/Step/Slab/Other) + **11 new fields**: `field_hardscape_type` (ref, options_select), `field_color`, `field_finish_texture`, `field_setback` (string); `field_length_in`/`field_width_in`/`field_thickness_in` decimal(6,2), `field_units_per_sqft` decimal(8,4), `field_units_per_pallet` int, `field_weight_each` decimal(8,2); `field_application` **multi** list_string. **`field_name` relabeled → "Name"** (no AEL on this bundle, so safe). Built via **idempotent entity-API scripts — no cim** (`setup_hardscape_fields.php` structural, `seed_hardscape_types.php` terms, `backfill_hardscape_type_pavers.php` — all 10 Holland Pavers → Paver, done this pass). New **`material_form_alter()`** (`material` module): `field_setback` shows only when Hardscape Type = "Wall Block" (core #states, Wall-Block TID resolved dynamically by name — differs per env: dev 1921 / live 1915). **Form layout:** loose catalog/dimension/pricing fields on top, the 5 field-groups below, and the 5 supplier **pack** fields (`field_pack_*`) tucked INTO the **Manufacturer Information** group (packaging is manufacturer-dictated) — kept, not deleted, since they're load-bearing for `wo_material_price_sync`/`supplier_price_ingest` (empty on pavers). Entity view display (`material.pavers.default`) gained hardscape_type + color. **Process:** came from Chat as a 2-gate task; Gate 0 inspection **corrected Chat's deploy path** (Chat said stage to `_upload_bundle/` + deploy `--cim`; that's the docs dir + a full cim that reverts the ~340 drifted configs — replaced with the entity-API setup-script idiom). Deployed dev → verified render/#states → live → verified. Docs: `Entities/material.md` (pavers section); `material_views.md` unchanged (only the entity view display changed, no list View).

- **2026-08-03** — **Owned-equipment billing (Phase 2) + Equipment-section cards (LIVE).** New **`wo_rental_equipment_ui`** module — completes the owned-equipment work. **(1) Billing:** `hook_ENTITY_TYPE_presave` on `wo_rental_equipment` auto-fills `field_hourly_rate` from the machine's `field_rate` ("Hourly Work Order Rate") when an **owned** (`field_equipment_used` set) + **Billable** machine is used — so the **existing** rental billing (`COALESCE(receipt, hourly_rate × hours)` in the 32 `wo_*` modules) charges it → `field_rental_total` → invoice. **No 32-module refactor needed** — feeding the existing formula the rate was enough. Manual rates preserved (guard: only fills when empty). **(2) Cards:** the WO **Equipment section** (`wo_rented_equipment` view) now renders each entry as a **card** (My-Schedule/Labor-hours pattern, `views-view-fields--wo-rented-equipment` template + preprocess + CSS) — owned machines show hours, **Charge** (= hours × `field_hourly_rate`, in sync with what bills) and **Our cost** (= hours × `field_operating_cost_per_hour`), an Owned/Rented badge and edit link; rented rows keep the existing `hrs × rate = receipt` line. View converted to unformatted card rows by `wo_rented_equipment_to_cards.php`. Requires **Billable** checked on the machine. Verified live (WO 50967 owned Bobcat 10×$55=$550; WO 50077 manual $125 rate preserved → 2×$125=$250; cards match billing). Deploy: rsync module + view script, `drush en` + run script + `cr`; also set the Bobcat SS#1 Billable + re-saved its entries so they bill. This **turns owned equipment into real invoice charges** — the Phase 2 the owner had deferred, now enabled at their request. Docs: `wo_profit.md` (equipment section), this changelog.

- **2026-08-03** — **Equipment Type exposed filter + filter cleanup on the equipment admin view (LIVE).** Added an exposed **"Equipment Type"** (`field_equipment_type`) **single-select dropdown** to the `equipment` view (page_1 = `/admin/operations/equipment`) so staff can filter by type (Skid-Steer, Mini Excavator, …). Cloned from the view's existing exposed **Status** filter (`taxonomy_index_tid`, `vid=equipment_types`, `type=select`, `expose.multiple=false`); `page_1` inherits the default display's filters, so it's added to default. Also **relabeled the bundle `type` filter "Type" → "Bundle"** and **reordered the exposed filters to Bundle, Status, Equipment Type, Title**. Idempotent script `add_equipment_type_filter.php` (active-config edit on dev+live — not cim, the view is drifted). Verified on both.

- **2026-08-03** — **Unit-number field on heavy equipment / skid-steers (LIVE).** Added the existing **`field_equipment_number` ("Asset ID")** — the same per-unit number field the mowers/chain saws already use (e.g. `CS2-X` → "Stihl Chain Saw MS290 - CS2-X") — to the **heavy_equipment** bundle + its edit form, so the office can designate each skid-steer (`SS1`, `SS2`…) rather than rely on the internal entity id or a non-unique Model number. Idempotent entity-API script `add_heavy_equipment_number.php` (storage already existed on it_equipment/small_engine/snow_plows; no cim/DB). **In the title (like the mowers):** heavy_equipment auto-label changed to `[make] [type] [model] - [field_equipment_number]` (was `- [id]`), via surgical partial-cim of `auto_entitylabel.settings.equipment.heavy_equipment`. **Titles are NOT mass-regenerated** — existing machines keep their `- {id}` title until each is edited/numbered (avoids dangling "- " on the ~18 currently-unnumbered machines + alias collisions); as the office numbers a machine, its title + pathauto URL update and the old URL **auto-redirects** (`redirect` module on, `pathauto update_action=2`). Uniqueness not enforced (matches the mower pattern). Deployed dev + live.

- **2026-08-03** — **"Similar Equipment" strip on each equipment page (LIVE).** New `equipment_similar` module + view: at the bottom of an equipment page, a strip of other machines of the **same `field_equipment_type`** (first `field_pictures` thumbnail + title, linked), excluding the one you're on — e.g. a Skid-Steer shows the other Skid-Steer. Built as a **Drupal view** (`equipment_similar`, cloned from `equipment_type_current_list`; fields/sort/count adjustable in the Views UI) per the list-UIs-are-Views rule; `hook_ENTITY_TYPE_view` on `equipment` embeds it with `[type_tid, self_id]` (EVA passes the entity id, not a field value, so the type is fed by the hook) and **hides the whole section when there are no siblings** (runs the view, checks result count). Second contextual filter = `id` with the numeric **Exclude** (`not`) option to drop self. Wrapped in a `.equipment-similar` container for CSS (the bare `default` display omits the `view-id-*` class). Verified live (skid-steer 35138 → shows 35139 "Skid-Steers 753" with S3 thumbnail). Deploy: rsync module + build script, `drush en` + run script + `cr` (no cim/DB). Docs: this changelog + Material/Equipment modules table.

- **2026-08-03** — **`wo_profit` — owned-equipment cost & margin visibility, Phase 1 (LIVE).** Owned **heavy_equipment / small_engine** used on a WO (recorded as a `wo_rental_equipment` row: `field_equipment_used` set, not rented, + `field_hours`) now shows on the live panel: **cost** = hours × `equipment.field_operating_cost_per_hour` (an "Equipment (owned)" cost line) and **revenue** = hours × `equipment.field_rate` ("Hourly Work Order Rate") when the machine is `field_billable` (folded into the computed live-revenue projection). **No new fields** — `field_rate`/`field_operating_cost_per_hour`/`field_internal_cost_rate`/`field_billable` already existed on those two bundles (empty + unused; a never-implemented scaffold) and are already on the equipment edit form. **Phase 1 is visibility-only:** in-progress panel only; **does NOT touch invoicing/`field_wo_total`/QuickBooks or completed WOs' frozen figures** (owner chose "visibility first, then invoice"). Office sets rates per machine + records usage via the existing equipment/rental form. New `WoProfitCalculator::ownedEquipment($woId)` → `[cost, revenue]`; verified on dev (10 hrs × $40 cost = $400, × $150 rate = $1,500, flows into liveRevenue) and calculator loads clean on live. Code-only (`wo_profit`); deploy = rsync + `cr`. **Phase 2 (not built, roadmap):** wire owned-equipment charges into the ~32-module sign-off billing (consolidated into one shared rental/equipment service) so it hits customer invoices — once rates are validated via Phase 1. Docs: `Modules/wo_profit.md`.

- **2026-08-03** — **`wo_profit` — live projected revenue/profit for open jobs (per-bundle; landscaping first) (LIVE).** So supervisors see projected *profit* on in-progress WOs, not just cost. New `WoProfitCalculator::liveRevenue($wo)`: **estimate-first** (any bundle — `field_estimated_price`, else `field_estimate → field_estimate_total`), **else a per-bundle formula** for bundles in `LIVE_REVENUE_BUNDLES` — standard **hours × billable rate + materials(marked up) + rentals**, replicating the `wo_*` sign-off math (increment rounding + minimum floor) so the projection matches completion. **`landscaping` implemented** (rate `field_maintenance_crew_labor` = $65, `field_hour_billing_increment`, `field_general_minimum_time`); other bundles fall back to cost-so-far until added (deliberately per-bundle to avoid duplicating all ~30 billing formulas at once; snow/spray need bespoke methods). Panel gains a **"Cost & Profit — projected"** state (Revenue (projected)/Profit/Margin + "finalizes at sign-off" note). Verified live: WO 51656 (landscaping, no estimate) → 305.47 hrs × $65 + materials + rentals = **revenue $27,606.40**, cost $14,313.69, **projected profit $13,292.71 / 48.2%**. Code-only (`wo_profit`); deploy = rsync + `cr`. Docs: `Modules/wo_profit.md`.

- **2026-08-03** — **`wo_profit` live panel — real hours before completion + honest "Cost so far" (LIVE).** Follow-up to the Stage 1 panel: on an in-progress WO it showed **0 labor hours** because `WoProfitCalculator` read the WO's `field_total_time` roll-up, which is only filled at sign-off (along with `field_wo_total` revenue) — even though the clock entries already existed (e.g. WO 51656: 305.47 hrs logged, roll-up empty). Fix: (1) calculator now sums **`wo_time_clock.field_total_time` directly** (`laborHours()`), so labor is live/correct before completion (same source the `wo_*` billing modules use, so live and frozen agree); (2) since **revenue isn't computed until sign-off**, the panel no longer shows a misleading profit/loss against $0 revenue — for an in-progress WO with no revenue it renders a **"Cost so far — live"** breakdown (labor + materials + chemicals + rentals + dump) with a note that revenue & profit finalize at completion; completed WOs are unchanged (frozen Revenue/Profit/Margin). Verified live (WO 51656 → 305.47 hrs, $8,247.69 labor, $14,313.69 cost so far). Code-only (`wo_profit` module); deploy = rsync + `cr`. Docs: `Modules/wo_profit.md`.

- **2026-08-03** — **Mobile-responsive WO-page tables (crew theme) — no more content off the right edge (LIVE).** On phones, the WO-page Views **tables** ran off the right side — most visibly the **schedule** (`wo_scheduled_teammate_view`: Scheduled · Assigned To · Note · Links) whose rightmost **Links/Edit** column dropped off-screen; same failure across ~11 tables on the WO page (all in the crew-facing **`brookstone_olivero`** theme). New **`brookstone_olivero/responsive_tables`** library (attached globally via the theme): **JS** (`js/responsive-tables.js`, `Drupal.behaviors` + `once`, re-runs after AJAX) copies each column's `<th>` header onto its body cells as `data-bos-label` and tags the table `.bos-stack-table`; **CSS** (`css/responsive-tables.css`, `@media (max-width: 48em)`) **stacks** each row into a labeled block — `thead` hidden, each cell full-width with its label above (so "Scheduled / Assigned To / Note / Links [Edit]" all stay on-screen and the Edit button is reachable) — plus a **horizontal-scroll fallback** (`.view-content { overflow-x:auto }`) for any table without headers and `img { max-width:100% }`. Deployed live (rsync theme files + `cr`; no cim/DB). Scope: **`brookstone_olivero` only** (the crew/mobile theme where the WO page renders); `brookstone_admin` can get the same treatment if office staff hit it on mobile. Reusable responsive-table convention noted in `Governance/ui_patterns.md`.

- **2026-08-03** — **WO cost & gross-profit visibility, Stage 1 — `wo_profit` (LIVE).** Supervisor/admin-only **cost & profit per work order**, live "as things happen." Revenue was already computed (`field_wo_total` + components); this adds the **cost** side by mirroring the existing `wo_*` billing rollups and summing the **cost** columns: **labor** = `field_total_time` × a **blended labor-cost/hr** rate in `business_setting` (`field_blended_labor_cost`, seeded **$27** — wage+tax+comp+benefits, owner-set); **materials** = Σ `wo_material_list_item.field_subtotal` (cost basis; charged side is `field_subtotal_w_markup`, so the delta is the markup margin); **chemicals** = Σ `wo_chemicals_used.field_subtotal` (no markup → pass-through); **rentals** = Σ `COALESCE(receipt_total_cost, hourly_rate×hours)`; **dump** = `field_dump_fee_total`. `profit = field_wo_total − total cost` = **job-level gross margin** (excludes fuel/overhead/depreciation — stated in the UI). New `field_wo_cost`/`field_wo_profit` on all **35 real WO bundles** (legacy `estimate` excluded); `WoProfitCalculator` service shared by the freeze + the panel. **Freeze:** generic `entity_presave` at **weight 100** (the `wo_*` bundle modules set `field_wo_total` in *generic* `entity_presave`, which fires after the type-specific batch — so a type-specific hook read a stale $0 revenue; the fix uses the generic hook + weight so it runs last), writes cost/profit while **Complete (1097)** like the billing freeze. **Panel:** role-gated `hook_entity_view` on the full WO page — frozen figures once recorded (1097/1281/1504/1283), live estimate in progress; permission `view wo cost profit` granted to supervisor/administration/site_admin/administrator (crew + clients never see it). Setup via idempotent entity-API script (`setup_wo_profit_fields.php`; ECK/config_pages configs cim-skip). **Deployed live** (rsync module + script → run script → `drush en wo_profit` → `cr`; no cim/DB migration). **Verified dev + live:** rate $27, WO 9882 → cost $1,810.13 / profit $1,837.83 / 50.4%, freeze correct, panel shows for admin on brookstoneoutdoors.com + hidden from anon. **Stage 2 (roadmap):** profit-by-service-line dashboard on the stored fields ("are fertilizing WOs profitable overall?"). Docs: `Modules/wo_profit.md`.

- **2026-08-03** — **Per-photo public control for WO photos — `wo_photo_split` (LIVE).** Technicians bulk-upload many photos into one `wo_images` media (one "Photos of" label, phone-friendly), but `field_public_ok` is per **media entity** — so a batch published all-or-nothing and admin couldn't exclude a single unfit photo without deleting it. New dedicated **`wo_photo_split`** module splits each multi-image `wo_images` into **one media per photo** so every photo carries its own public flag — **without changing the tech upload UX** (the split is automatic/invisible). `hook_ENTITY_TYPE_insert`/`_update` on `media:wo_images`: when >1 image, keep the first photo on the original and create a new `wo_images` media per additional image, inheriting `field_photos_of`/`field_stage`/`field_work_order`/`field_property`/`field_public_ok`; **children created before the original is trimmed** so each file's usage never drops to 0 (no orphaned/temporary files; safe on S3 — references reassigned, no files moved); reentrancy-guarded. **Migration** `drush wo:photos:split` (dry-run default, `--apply`), idempotent. **Display unchanged:** the WO-page `wo_media_photos` EVA groups by **Stage → Photos of** via Views grouping, which re-clusters the now-individual media under the same heading (WO gallery looks identical); property gallery shows each photo as its own Public/Held-toggleable tile. Scope: only `wo_images` (sole gallery bundle that's multi-image *and* has `field_public_ok`); `estimate_images`/`estimate_design` (no public flag) + single-value `wo_videos` untouched. **Deployed live:** safety DB dump (`~/pre-woimage-split.sql.gz.gz`, verified) → rsync module + `drush en` + `cr` → dry-run (986 multi → 3,436 new) → `--apply`. **Verified live:** total `wo_images` 1,966 → **5,402**, **0 multi-image remaining**, image refs (5,358) + distinct fids (5,343) **unchanged** (nothing lost/duplicated), WO gallery still grouped + property gallery per-tile. **Gotcha (2nd occurrence):** the `--apply` drush process **hung after completing all work** on live (count stable at target ~18 min while still "running") — same post-completion hang as the 08-02 photo import; killed once integrity confirmed. Long bulk media ops on live are also **~2s/media** (S3 thumbnail read per new media). Docs: `Modules/property_photo_gallery.md`; gotcha in `drupal_bos_gotchas.md`.

- **2026-08-03** — **Gallery display polish — one tile per photo + clean staff caption bar (LIVE).** Follow-up to the property photo gallery. Two owner-reported display issues on the staff **Gallery** tab: (1) photos were **grouped/stacked by media entity** (a WO image media with many photos rendered as one tall column) — root cause: `media.field_media_image_1` is **multi-value** (cardinality -1; **985 media hold >1 image, max 54**). Fixed by setting the Views image field **`group_rows = FALSE`** so each image value explodes into its own row → its own grid **tile** (Colorbox switched to **page-level** gallery so lightbox prev/next still pages through all). (2) the per-tile **"Public:" label + operations dropbutton** ("List additional actions") looked raw — the old `.bos-gallery__*` CSS didn't match the Views markup. Rebuilt as the **BOS status-card pattern**: both views use the **unformatted list** style with module CSS laying rows out as a responsive card grid, and the staff view gets a **row template** (`views-view-fields--property-photos` via `hook_theme` + `hook_preprocess_views_view_fields`) rendering a colored **Public**(green)/**Held**(amber) badge + a single **Edit** link (`destination` back to gallery), dropping the labelled field + dropbutton. `field_public_ok` stays per-media (multi-photo WO media = all-or-nothing; archive photos = per-photo). All in `bos_property_gallery` + `web/scripts/build_gallery_views.php`. Verified live (authed: colored badges + single Edit + one tile/image, no dropbutton). Deploy: rsync module (`--delete`, incl. new `templates/`) + build script, run script + `cr` — no cim/DB. Docs: `Modules/property_photo_gallery.md`.

- **2026-08-02** — **Property photo gallery — archive + WO photos, staff tab + public/SEO gallery (SHIPPED LIVE).** Per-property gallery associating ~5,100 historical job photos (matched to properties by GPS/customer in a separate project) and showing them, alongside existing work-order photos, on each property page. **Model = media entities referencing the property** (reverse ref, not a field on the property — scales, no duplication, idiomatic since BOS already models photos as media). New **`media.property_photo` / `media.property_video`** types (source image/video + `field_property` + provenance/SEO fields); **`field_property`** also added to `wo_images`/`wo_videos` and **backfilled** from `field_work_order → work_order.field_property` (1,941/1,960 WO photos); **`field_public_ok`** opt-in flag on all four bundles (uniform public gate). Setup: `web/scripts/setup_property_photo_media.php` + `backfill_wo_photo_property.php` (idempotent, entity-API — no cim). **Importer `bos_photo_import`** (`drush photo:import <csv> <root>`): mapping → media, idempotent on `field_original_path`, **confidence-gated** (`field_public_ok=1` only for non-fuzzy, non-Low; else held for review), SEO alt `"{nickname} — {address}"`, files via file API → `public://property-photos/{pid}` → **s3fs to S3 on live**. **Gallery = two Drupal Views** (built by `web/scripts/build_gallery_views.php`) so the office can adjust filters/sorting/pager in the Views UI — **NOT** a bespoke controller/hook (an initial controller + `hook_ENTITY_TYPE_view` build was rejected by the owner and rebuilt as Views; standing rule now: list/gallery/table UIs = Views by default, ask before going bespoke): **`property_photos`** (staff **"Gallery" tab** — page display + local task at `properties/%properties/gallery` beside Work Orders, staff access, ALL photos + **Public/Held** badge + edit op per item, contextual filter on `media.field_property`) and **`property_photos_public`** (public **EVA** auto-attached to the property full page, `field_public_ok=1` + published only). Both render the media **source fields directly** — `field_media_image_1` via the **Colorbox** formatter (thumb `medium` → lightbox `max_1300x1300`, `alt` as caption for SEO), `field_media_video_file_1` via the player — **not** `rendered_entity` (which doesn't render reliably inside a view); grid CSS attached by the trimmed `bos_property_gallery` module (`hook_views_pre_render`). **Live rollout complete:** schema + backfill + importer + views deployed; full archive imported to live + S3 — **3,131 photos + 371 videos** (2,297 photos auto-published; all 371 videos were `customer-fuzzy` → held for staff review by the gate; sampled files confirmed present in S3), staff tab + public EVA verified rendering with Colorbox on a live property. Gotchas: mapping CSV BOM broke `fgetcsv` header enclosure on the first cell → headers normalized; the import process **hung after finishing all images** (idempotent; killed and completed) — watch for post-completion hangs on long bulk imports. Docs: `Modules/property_photo_gallery.md`. _(Dev can't render S3-only WO photos via stage_file_proxy; archive photos are local + display. Live serves both.)_

- **2026-08-01** — **IT / computer-equipment asset tracking added — new `equipment:it_equipment` bundle + import pipeline (DDEV-tested, not yet on live).** IT gear (office PCs, NAS, network switches, router/gateway, printers) is tracked as a **new bundle on the EXISTING `equipment` ECK entity** (same entity as vehicles/mowers), so it reuses the equipment **defect / maintenance-event / inspection** machinery. **Reuses 8 existing fields** (`field_equipment_number`=Asset ID/idempotency key, `field_equipment_make`, `field_model`, `field_serial_code_number`, `field_equipment_type`=device type via new terms, `field_status`, `field_date_purchased`, `field_purchase_price`) and adds **20 net-new `field_it_*` fields** (hostname, user, ipv4, mac, os, os_build, cpu, ram_gb, location, notes, disk_encryption, firewall, antivirus, time_sync, network_profile, workgroup, dhcp, gateway, dns, link_speed) — new storages instanced **only** on `it_equipment`, so no existing equipment bundle is touched. `field_comments` (a comment-thread type) and `field_public_description` (public-facing) were deliberately **not** reused for IT notes (sensitive posture ≠ public) → dedicated `field_it_notes`. Bundle + fields + 6 device-type `equipment_types` terms + form/view displays created by idempotent entity-API script **`web/scripts/setup_it_equipment_bundle.php`** (ECK/field configs silent-skip on cim → script is the deploy path). **Importer:** new **`bos_it_import`** module — Drush **`it:import <file>`** (CSV/XLSX via PhpSpreadsheet), patterned after `bos_wex_import`, reading the **Workstations** + **Network Devices** tabs of the Office Network Baseline; **idempotent on Asset ID**; device type resolved by `BUS-*` prefix; `Netgear GS605v5 (WS2 area)`-style names split into make/model/location. **DDEV test:** 13 records imported (5 PCs + gateway + NAS + unidentified interface + 2 printers + 3 switches), re-run = 13 updated / 0 created (idempotent), device types + Active status + full PC security posture all correct. **Gotcha found + documented:** `equipment_types` uses auto_entitylabel `[term:field_common_name]`, so new terms must set `field_common_name` (setting `name` alone saves empty). **Deployed live 2026-08-01** (commit `b14b4949`): `scp` module + setup script → `drush en bos_it_import` → ran `setup_it_equipment_bundle.php` → `it:import` the baseline → verified (13 records, correct device types + Active status, full PC posture) → `cr`. No cim/DB-migration/maintenance. Staged baseline removed from live after import. Docs: `Entities/equipment_it_equipment.md`, `Modules/bos_it_import.md`.

- **2026-08-01** — **Auto-recalc WO totals when a billing child changes on a Complete WO (`wo_billing_recalc`).** Cut the recurring step where the office fixes a material/rental/chemical/dump on a signed-off WO and then has to **re-open + re-save the sign-off form** just to refresh the totals. New cross-cutting module re-saves the parent WO on **insert/update/delete** of a billing child (`wo_material_list`, `wo_material_list_item`, `wo_rental_equipment`, `wo_chemicals_used`, `wo_material_dumping`), which cascades through the existing `wo_*` presave recalc. **Mirrors the existing time-clock precedent** (`_wo_total_time_trigger_wo_recalc`); `wo_time_clock` is excluded here (already covered by `wo_total_time`). **Gated to Complete (1097) ONLY** — per the owner decision, once a WO is **Invoiced (1281) / Paid (1504) / Warrantied (1283) / Canceled (1098)** its totals are **frozen** so a child edit can't silently move a number already in QuickBooks; to revise a billed WO the office deliberately moves it back to Complete first (a tracked status-update action), edits (auto-recalcs while Complete), then re-invoices — several purposeful, logged actions. Child→WO resolution: `field_work_order` (chemicals/dump/material_list), `field_rented_for` (rentals), `field_list_id → wo_material_list → field_work_order` (line items). Static per-WO loop guard. Tested locally (material + rental edits on a Complete WO auto-recompute — and corrected stale `$0.00`/`510` totals in the process; child edit on an Invoiced WO stays frozen) and verified live (hooks present, link chains resolve, gate=1097). **Deploy: `scp` module + `drush en wo_billing_recalc` + safe `cr` — no cim/DB migration/maintenance.** Docs: `wo_billing_recalc.md`.

- **2026-08-01** — **Sign-off open-clock-in block made graceful (inline message, not a fatal).** The Phase B guard (`wo_sign_off_entity_presave` → `_wo_sign_off_assert_no_open_clockins`) correctly refuses to sign off a WO while a crew member is still clocked in — crews hit it on **WO #50967** ("Kyle McElhaney still has open clock-ins"). The **stop is wanted** (they hadn't entered materials), but it threw an `EntityStorageException` at entity-save → Drupal **WSOD**. Fix (commit `0496d5b2`, deployed live): a **form-layer validate handler** (`_wo_sign_off_open_clockin_form_guard`, registered in the existing `wo_sign_off_form_alter` after the reconciliation validate) surfaces a **clean inline error** instead. It only flags open clock-ins the save will **not** resolve — crew still clocked in who are **not on the submitted roster** (roster members with open entries are closed by the reconciliation submit, so they're excluded to avoid blocking the normal flow); cancellations skipped, mirroring the presave guard. Message: *"…@names @isare still clocked in and not on the crew list. Add them to the crew (their time will be recorded and closed) or have them clock out, then sign off again."* Refactored `_wo_sign_off_open_clockin_names()` to delegate to a new `_wo_sign_off_open_clockin_map()` (uid→name) so the form guard can exclude roster uids (presave message unchanged). **The presave guard stays as the backstop** for non-form paths (REST/VBO/programmatic). Tested locally (non-roster→inline error; on-roster→no error; canceled→no error) + verified live (functions present, backstop intact). **Deploy: `scp` one `.module` + safe `cr` — no maintenance/cim/DB.** Docs: `wo_sign_off.md`.

- **2026-08-01** — **Graceful redirect on the empty VBO confirm page (no more white-screen).** On 2026-07-31 the office manager (uid 6165) hit a fatal `TypeError` white-screen loading the **mow-crew billing** VBO confirm page (`/views-bulk-operations/confirm/admin_office_work_orders_mow_crew_billing/page_1`). Root cause is a **contrib VBO fragility**, not our code and not the 07-14 replay guard: `ConfirmAction::getFormData()` reads a **NULL** tempstore (empty selection) and passes it to `addListData(array $form_data)` → *"Argument #1 ($form_data) must be of type array, null given"*. Triggered by loading the confirm URL with no live selection — **browser Back to the confirm page / refresh after the batch already ran**. **Her invoices had actually gone through** (18 successful `wo_actions` invoice log lines that morning; no new stranded WOs) — the crash was only the scary screen *after* a completed batch. The fatal is inside contrib code during form **BUILD**, before any `hook_form_alter`, so it can't be caught there. Fix (commit `a319f234`, deployed live): new **`VboConfirmGuardSubscriber`** (`wo_actions`, `kernel.request` **priority 30** — after RouterListener sets the route params, before the form builds) targets **only** `views_bulk_operations.confirm`; if the user has no live tempstore selection for that `{view}_{display}`, it **redirects to the view list** with a friendly *"Your selection has expired…"* message instead of the fatal. A live selection passes through untouched. Companion to the submit-side stale-selection replay guard (`WO_ACTIONS_REPLAY_GUARDED`). Redirect target resolves via `view.{view_id}.{display_id}` (access-checked), fallback front. New files: `wo_actions.services.yml` + `src/EventSubscriber/VboConfirmGuardSubscriber.php`. Tested (empty→redirect, live→passthrough, non-confirm route→ignored; service registered+subscribed). **Deploy: `scp` two files + safe `cr` — no maintenance/composer/cim/DB.** Gotcha updated in `drupal_bos_gotchas.md`. _(Also noted, separate/historical: ~12k old `lawn_mowing` WOs carry `field_invoiced=1` with a non-Invoiced status — a pre-existing pattern, unrelated to this incident; flagged for a later look.)_

- **2026-07-16** — **Contract-section "Mark Yes/No" bulk buttons get the billing guardrails (after a select-all rewrote a whole contract).** On **2026-07-15 11:13:19** the office manager (uid 6165) selected every row in the contract sections table on contract **#4700** (2730 Commercial Way, 2026) and clicked **Mark "Yes"** — it applied instantly to all **23** sections; **19 actually changed** (the other 4 were already Yes, which the action no-ops — hence 19 audit records, not 23). She reverted them by hand that afternoon (14:38–14:39 cluster). **The audit trail worked**: `contract_sections_audit` (append-only, `contract_section_audit` module) captured all 19 with actor + timestamp + changed-field label — note it records *which* field changed, **not old→new values**. **Root cause = the same VBO footgun as the 07-14 mass-invoice:** the "Mark Yes" button is a **VBO bulk action** (`contract_section_set_do_you_want_yes`, + `_no` / `_request_quote`) on the `contract_sections_admin_table` view, and all three carried **`add_confirmation: false`** — a select-all misclick rewrote the contract with nothing to catch it. (`show_select_all: always_hide` was already set, but that only hides the *cross-page* link; core `tableselect`'s per-page header checkbox still selects every row.) **This is why it "only happens to her" — not carelessness: she's the one using the bulk buttons, and the billing views got a confirmation step on 06-30 while this view never did.** **Two guardrails (commit `85f7bf11`, deployed live):** (1) **`add_confirmation: true`** on all three actions → VBO now shows *"…perform 'Mark "Yes"' on N entities?"*, so a select-all mistake is visible **before** it applies (the count is what catches a *fresh* select-all — the replay guard alone can't, since it's a first-and-only submit); applied via the idempotent **`web/scripts/contract_sections_add_confirmation.php`** (entity-API, run per env) **not** a partial-cim, because the view is drifted from sync (live lacks two `languages:*` cache-context lines) so importing the file would push unrelated changes — sync YAML updated to match intent. (2) The **stale-selection / browser-Back replay guard** (`WO_ACTIONS_REPLAY_GUARDED` in `wo_actions`) extended to the three contract-section actions — the guard is **entity-agnostic** (it only compares the confirm form's baked-in selection to the live tempstore), so it covers `contract_sections` unchanged. `field_do_you_want` values: **1=Yes, 2=No, 3=Request Quote, 4=Accepted/Price Confirmed**. **Deploy: `scp` of the module + script, run the script, safe `cr` — no maintenance mode, no cim/DB.** Verified live (all three `add_confirmation=TRUE`, guard list covers all five actions).

- **2026-07-14** — **Accidental mass-invoice (82 WOs) reverted + browser-Back VBO replay guard.** The office invoiced **82 work orders by accident**: the manager (uid 6165) selected 1 WO, got "0 to invoice" (her pick wasn't Complete, so the eligibility gate skipped it), hit the browser **Back button**, and a *stale confirm page* — built earlier for an 82-row select-all — re-submitted and invoiced all 82. **Diagnosis (read-only, live):** the audit trail (`wo_status_updates` 1281 records) showed a single **8-second VBO burst at 11:38:21–28** (exactly 82), cleanly separated by a 9-min gap from her legit small batches (11:07–11:29, ~34 WOs). **The eligibility gate held** — all 82 were Complete (1097), so no pre-completion WOs were wrongly billed; nothing had left BOS (QB export is manual). **Revert:** mirrored the sanctioned `MarkWorkOrderNotInvoicedAction` for exactly the 82 (isolated to the 11:38 minute) — `field_invoiced=0` + status→Complete (1097) + a per-WO audit note; 82/82 reverted, 0 errors, her earlier 34 left untouched (verified). **Root cause:** VBO bakes the selection into the cached `form_state`, so a Back-button re-POST of a stale confirm executes its *original* rows even though the live tempstore has changed; the 2026-06-30 confirmation step + eligibility gate can't catch a *first-and-only* submit of a stale-but-valid confirm. **Guard (commit `b4317ee8`, deployed live):** `wo_actions` now adds a `hook_form_FORM_ID_alter` on `views_bulk_operations_confirm_action` that **prepends a validate handler** for the financial WO actions (`mark_work_order_invoiced_action` + `mark_work_order_not_invoiced_action`): it requires the confirm form's **baked-in selection to still match the user's LIVE tempstore** (validate runs *before* VBO's submit clears the tempstore, so a legit submit always matches; a stale/replayed confirm does not → blocked with a friendly message + dblog warning, nothing executes). Also blocks double-click double-submit. Selection signature = sorted entity keys + `exclude_mode` + `total_results` (ignores the label/count fields `addListData()` adds). Unit-tested (identical→allow, different/null→block); verified live (hook discovered, functions present). **Deploy: `scp` of the one `.module` + safe `cr` — no maintenance mode, no cim/DB.** New gotcha in `drupal_bos_gotchas.md` ("VBO confirm form is replayable via browser Back"). _(Follow-on to the 2026-06-30 VBO confirmation-step + 2026-06-20 eligibility-gate work.)_

- **2026-07-14** — **Scheduling calendar timezone fixes — multi-day WOs no longer a day early, timed events no longer an hour early.** Two month-view rendering bugs in `admin_calendar` `AdminCalendarEventsController`, **same root cause**: the event feed built start/end via `FROM_UNIXTIME(field_date_*)`, which renders in **MariaDB's session tz = `SYSTEM` = fixed UTC−7 on live** (not `America/Denver` = UTC−6 during DST). (1) **Multi-day WO shown a day early** (`fdf68a2f`): a WO scheduled across >1 day (WO 50485, 07-16→07-17, smartdate duration 2879 min) failed the old all-day detector (which only matched single-day `1365–1440` min) and fell to the timed branch, where UTC−7 turned the 07-16 00:00 MT start into **07-15 23:00** ("11p" bar on the 15th). Fix: detect multi-day all-day spans (total ≥ 1 day AND `duration % 1440` is 0 or ≥ 1365) and emit FullCalendar's **exclusive** all-day end = `start + ceil(minutes/1440)` days, computed in site tz from the timestamp. (2) **Timed events an hour early during DST** (`775873f2`): the timed branch's `FROM_UNIXTIME` strings were UTC−7, an hour behind Denver's UTC−6 from ~mid-March to early November (dead-on in winter → seasonal/intermittent). Fix: emit **both** branches from the raw UTC timestamps converted to site tz in PHP (FullCalendar `timeZone` = America/Denver interprets the tz-less wall-clock strings as site-local); dropped the `date_start`/`date_end` `FROM_UNIXTIME` query expressions, added `field_date_end_value` (`date_end_ts`). Verified on live: WO 50485 → renders 07-16..07-17 (all-day, end-excl 07-18); sampled timed events shift +1h to correct Denver time; single-day all-day unchanged. **Deploy: `scp` of the one controller + safe `cr` (Alt-PHP, mem cap) — no maintenance mode, no cim/DB** (per request). New gotcha in `drupal_bos_gotchas.md` ("FROM_UNIXTIME renders in MariaDB session tz"); docs in `Entities/scheduling.md` ("2026-07-14 fix").

- **2026-07-13** — **WEX import false-alarm fixed — no-email days no longer trip the failure watcher.** The office was getting "WEX fuel import failed" alerts on 07-12 and 07-13. **No data was missed and the import was never broken** — `~/wex_fetch.log` shows every real WEX email imported cleanly (07-10 = 5 rows, 07-11 = 1 row); 07-12/07-13 simply had **no WEX email**. Root cause: the 07:15 watcher (`web/scripts/wex_alert_check.sh`, drush-independent by design) greps the fetch log for the `fetch-email complete` sentinel to decide success, but `WexFetchEmailCommands::fetchEmail()` had a **separate early-return for "No UNSEEN WEX messages found" that returned *before* printing that sentinel** (the grand-summary line) — so every quiet no-email day looked like a failure (the watcher's own comment wrongly claimed a 0-message run still prints "complete"). Fix (commit `8abb41d1`): (1) **command** — emit the `WEX fetch-email complete …` line (all-zero counters, `(No UNSEEN WEX messages — nothing to import.)`) on the no-messages path too, honoring the watcher contract; (2) **watcher** — defense-in-depth, also accept `No UNSEEN WEX messages found` as a healthy signal so any future no-sentinel path can't re-trip it. Tested locally (watcher stays silent on no-email + normal-import days, still alerts on a genuine drush crash) and **verified live** (manual `wex:fetch-email` today printed the new sentinel; watcher against the fresh log exited 0 / silent). **Deploy: `scp` of the two files only — no maintenance mode, no rsync/composer, no cim/DB** (per request); the running command was confirmed live by the manual fetch. Docs: `wex_fuel_import_workflow.md`.

- **2026-07-11** — **Invoiced/Paid WOs closed to clock-in on every path + path-independent resurrection guard.** Directive: an invoiced work order must not let anyone clock back in. Root cause of the stranded WOs (`45301` reopened 2025-10-14, `50078` reopened 2026-06-05 — both fixed this session, restored to Invoiced via `_skip_invoiced_guard`): a crew clock-in on an already-billed WO created a **"Teammate Clocked In"** `wo_status_updates` record that bounced the WO from Invoiced (1281) back to In Progress (1092). Both predate the **2026-06-19** flag-path guard, which only covered the one creation path. Three layers, **all clock-in paths covered** (commit `cc2ffb38`, deployed live): (1) **wo_clock green button** — `WoClockService::clockIn()` now refuses clock-in when the WO is Invoiced (1281)/Paid (1504) via new public `woIsLocked($woId)` (throws → `WoClockController::clockInAction` catch → `{status:error}` → `wo-clock.js doClockIn` else-branch shows the message: *"…already been invoiced and is closed to time entry… contact the office"*). (2) **Legacy flag path** (`wo_timer_flag_update_flagging_insert`) — on Invoiced/Paid creates **no** `wo_time_clock` entry and no status flip (mirrors the button); Complete 1097 / Warrantied 1283 / Canceled 1098 still allow a *forgotten* entry with the status-flip suppressed (unchanged). (3) **Path-INDEPENDENT resurrection guard** at the single propagation chokepoint (`wo_status_updates_entity_presave`) — a status-update record can **never** reopen a billed/closed WO (terminal set `1097/1283/1281/1504/1098`) to In Progress (1092); covers every creator (flag, button, VBO, future) in one place, logs a `wo_status_updates` warning naming the WO. **Un-sign-off is unaffected:** `wo_sign_off_entity_delete` performs its own **direct** `$work_order->save()` with 1092 (line ~408), which does not depend on the record-driven write, so suppressing at the chokepoint doesn't break the legitimate Complete→In-Progress revert (regression-tested: record-write suppressed **and** direct save still lands 1092). Design scope: block is **Invoiced/Paid only** — Complete WOs keep the pre-billing window where a crew can add a forgotten entry without un-completing the WO. Tested locally (button refused, stray 1092 suppressed, un-sign-off intact) and **verified live** (`woIsLocked` present, live invoiced WO refuses clock-in). Code-only (3 modules), no cim/DB. Docs: `wo_clock.md`, `wo_timer_flag_update.md`, `work_order_status.md`.

- **2026-07-10** — **Edit Time Entry modal — mobile polish + lean form.** Follow-ons to the WO Hours time-entry cards: (1) **hour-card notes** condensed on phones only (`@media max-width:640px` — smaller font, tighter, lighter; desktop unchanged). (2) **Edit modal width** made responsive — `"width":"min(100%, 640px)"` (was a fixed `640` that cut the right side off on phones; matches the BOS mobile-modal pattern). (3) **Modal buttons** (Save/Delete/Add/Remove) + the multi-value notes widget shrunk on phones via `dialogClass:"wo-time-entry-dialog"` + a `max-width:700px` rule. (4) **Leaned the `wo_time_clock:entry` edit form** (`web/scripts/wo_time_clock_form_leanup.php`, idempotent per-env): **Teammate + Start + End + time-limit override** are the primary visible fields (**Teammate stays up top** — foremen set it during manual entry), **Notes moved into a COLLAPSED `group_entry_notes` "Notes" group** (expand to add/edit), the existing collapsed Office Administration group unchanged. (5) The multi-value Notes **"Add another item" → "Add a note"** (`wo_clock_form_alter`) and CSS **right-justified** in the modal button pane away from Save/Delete — it's a `.form-actions` submit, so `dialog.ajax.js` (`prepareDialogButtons`, copies the button `class`) hoists it into the `.ui-dialog-buttonset` with Save/Delete; flexing the buttonset + `order:99`/`margin-left:auto` on `.field-add-more-submit` pushes it right. It had read like "add another time entry". All CSS/template/module in `wo_clock`; deployed live.

- **2026-07-09** — **WO Hours breakdown → time-entry cards + modal edit + refresh.** The per-teammate hours breakdown on the WO page (the `wo_hours_grouping` EVA, which embeds `wo_time_clock_entries` per teammate via `views_field_view`) now renders each time entry as a **My-Schedule-style card** (start–end · hours · source · notes) instead of a views_aggregator table row. Built in `wo_clock`: `wo_clock_theme()` registers `views_view_fields__wo_time_clock_entries`; `wo_clock_preprocess_views_view_fields()` builds the card (`_wo_clock_entry_card()`); `wo_clock_preprocess_views_view()` re-adds the **per-teammate subtotal** as a footer (the aggregator SUM row is lost with the card style); library attached via `wo_clock_views_pre_render()` **and** `wo_clock_work_order_view()`. **Click-to-edit:** for users with `update` access each card is a **`use-ajax` modal** link to `entity.wo_time_clock.edit_form` with `?destination=<WO alias>`, so on save it redirects back to the WO and reloads (which recomputes `field_total_time` server-side) — crew without edit access get a plain, non-clickable card. **Green button refresh:** `wo-clock.js` now calls `reloadWo()` (500ms → `location.reload()`) after a successful clock in/out, so the new entry + total appear (was button-DOM-only, went stale). View style change via idempotent `web/scripts/wo_time_clock_entries_to_cards.php` (per env). The other Hours EVA — `wo_time_clock_entries_total` (WO grand total) — is unchanged. Deploy: rsync + run the script on live + `cr`. Verified live: WO w/ 72 entries → 72 cards + modal links + subtotal. _(Modal save currently does a full WO reload via the `destination` redirect; a smoother in-modal close+refresh is a possible follow-up.)_

- **2026-07-09** — **Green Clock-Out button — over-cap confirm flow + `summer_pruning` → 14h tier.** (1) **Cap:** `summer_pruning` added to `WO_TOTAL_TIME_LONG_JOB_BUNDLES` so it uses the **14-hour** single-entry cap (was the 4-hour default) — pruning crews work a full day on one WO. (2) **Button confirm:** previously a crew clock-out that exceeded a bundle's single-entry cap (`wo_total_time` Guard 6) **dead-ended** — the green `wo_clock` button had no way to reach the `field_time_limit_override` checkbox, so crews were stuck (the 07-08 pruning incident). Now `WoClockController::clockOutAction` pre-checks the cap via new **`WoClockService::capExceedanceHours()`** and, when over, returns **`status:'confirm_long'`** with the hours/cap instead of saving; the button JS shows a **`confirm()`** ("This is a long entry — X hours, over the Y-hour limit… tap OK to clock out anyway, otherwise Cancel and have the office fix the time") and on OK re-POSTs with `override:1` → `clockOut(...,$override=TRUE)` sets `field_time_limit_override` → Guard 6 accepts it and appends its `[Time limit override: …h exceeds …h cap — confirmed by {crew} (uid N)]` audit note. **Cancel leaves the entry open** for office correction. Works for **every** bundle, not just pruning. Verified: over-cap prompts, no-override blocks, override saves (6h test entry) + audit note. Deploy: code-only (wo_clock service/controller/JS + wo_total_time constant), rsync + `cr`. Docs: `wo_clock.md`.

- **2026-07-06** — **Removed the obsolete `drupal/calendar` composer patch** (deferred #23). The declared patch `3177761-6 "Support for Smart Date"` (2022) targets a line in `calendar/src/Plugin/views/row/Calendar.php` that **no longer exists** — beta5 rewrote that method (now a `datetime_type` match) and handles smart_date fields **natively**. So the patch has been **silently failing to apply on every deploy** ("Could not apply patch! Skipping"), and composer-patches was removing+reinstalling calendar each time to retry — creating a brief window where `calendar.theme.inc` is missing (the transient warning behind a "wall of admin messages" if you browse mid-deploy). Confirmed the smart_date calendar (`teammate_properties`/`work_order_teammate_calendar`, plotting `scheduling.field_scheduled_date_and_time`) renders **571 events with the patch NOT applied**, so removing it is a runtime no-op — it just stops the churn/warnings. Removed the `drupal/calendar` block from `composer.json` `extra.patches`; `composer update drupal/calendar` refreshed the lock (content-hash only). The **companion `drupal/smart_date` "Support Calendar module" patch stays** (it applies cleanly and is the load-bearing half). Deploys are now quiet on calendar. **Note:** the two view scripts (`properties_view_to_cards.php`, `teammate_properties_split.php`) are **not re-run by deploys** — they're one-time per env, already applied.

- **2026-07-05** — **Property search — one box, all-words, name + address (+ ID on admin).** Both the crew (`/teammates/properties`) and admin (`/admin/properties`) card views now use a single **"Search properties"** box backed by a Views **`combine`** filter with the **`allwords`** operator: each space-separated word must appear somewhere across the combined fields, in **any order** (e.g. `680 Delta` == `Delta 680` → 680-numbered properties in Delta). Crew combines **`field_nickname` + `field_full_address`**; admin adds **`id`** so a bare **Property ID** (e.g. `27000` → 1 card) also matches — folding the earlier "should IDs stay visible?" concern into the search. On admin this **replaced** the four separate exposed filters (nickname / street / city / id). `field_full_address` (and `id` on admin) are added as **excluded** fields so `combine` can reach them. Identifier is `search` (**not** the reserved `q`, which 403s). Applied via the idempotent view scripts (`properties_view_to_cards.php`, `teammate_properties_split.php`); verified live on both views.

- **2026-07-05** — **Crew `/teammates/properties` → card list + map split to its own page.** (1) **Card list:** `teammate_properties` `page_1` converted to the same property-card pattern (slim crew variant): nickname, operational-flag badges, compact street+city, **contact + phone with owner fallback**, Mow Day, and a **GPS "Directions"** Google-Maps link — **no** Edit / Add Contract / Add Work Order (read-only for crew). (2) **Performance:** added a **"Search properties"** box — a Views **`combine`** filter over `field_nickname` + `field_full_address` so one input matches **name, street, city, or ZIP** (identifier `search` — **not** the reserved `q`, which 403s) + a **50/page pager** so the list loads ~50 cards instead of all **2,531** rows; mow days + contacts are **batch-prefetched** (one query set per page, not per row). (3) **Map split:** the all-pins Google map (the slow part) moved off the list into a new **`page_map`** display at **`/teammates/properties/map`**, added as a **child menu item under Properties** in `teammate-navigation`; the 4 map **block placements disabled**. **Gotcha:** a child display (`page_1`) only honors its own `display_options` overrides when the matching `display_options['defaults'][<opt>]` flag is set **FALSE** — without that it silently inherits the default (table) display. View change via idempotent `web/scripts/teammate_properties_split.php`; shared card builder gained `with_contact` / `with_office` flags; the crew row template falls back to default field output for the map's marker popups. Deploy: `78b7ec10` → rsync + run the script on live + `cr`. Verified live: list = 50 cards + search + pager, `search=safeway` → 5, map page 200.

- **2026-07-05** — **Property admin view (`/admin/properties`) → card layout.** Converted the `properties` view to the BOS status-card pattern (Unformatted rows + `views-view-fields--properties.html.twig` row template registered via `properties_theme()` base-hook + card data computed in `properties_preprocess_views_view_fields()` + CSS/JS attached via `properties_views_pre_render()`; mirrors `backflow_device` / My Schedule). Each card: nickname (linked to the property), operational-flag badges (Call Ahead / COD / No Services / Client App), **compact street + city**, primary contact + phone with **owner fallback** (same source as the `property_contacts` view — shows the current owner, tagged "(owner)", when no Primary Contact is set), **Mow Day** (`property_lawn_maintenance.field_mowing_weekday`), current-year residential **contract status**, and a **Google-Maps GPS link** when coords are set. Actions: **Edit**, **Add Contract** (only when no current-year contract), and an **Add Work Order** service-type picker (per-card `<select>` of all WO bundles minus `estimate` → opens the bundle add form prefilled with the property; reuses the `PropertyWorkOrderLinksBlock` URL scheme via `js/property-cards.js`). **Removed** from the view: Property ID column, Full Address column, Aerial view, Map Point, per-row Operations, VBO bulk form (Property ID stays as an **exposed filter** for lookups). View change applied via `web/scripts/properties_view_to_cards.php` (idempotent entity-API edit — run per env; **not** cim, to preserve any live view drift). Deploy: `38218f44` → rsync + run the script on live + `cr`. Verified live: 100 cards render, no Aerial/VBO leak. All work in the `properties` module.

- **2026-07-05** — **Security: `drupal/colorbox` 2.2.0 → 2.2.1** (SA-CONTRIB-2026-069 / CVE-2026-58591, moderately-critical XSS; affected `< 2.1.5 || 2.2.0`). Lock-only update (constraint `^2.0` already covered it); `--with-dependencies` also carried 16 transitive **Symfony** patch bumps (`v6.4.x`/`v3.7.x`/`v7.4.x` — Drupal-core deps, no minor/major jumps). `composer audit` clean, no pending DB updates, site 200. Branch `security-colorbox-2026-07-05` → `main` (`da32ac60`) → deployed (rsync + remote `composer install --no-dev` installs 2.2.1 from the lock + `cr`; **no cim, DB untouched**). Verified live: colorbox **2.2.1**, `drush updb` = no pending. _(Separate non-security note: `composer audit` still flags `oomphinc/composer-installers-extender` as **abandoned** — not a vulnerability; no action needed now.)_

- **2026-07-05** — **Supervisor GPS view on the per-teammate detail page.** Added clock-in / clock-out **"distance from property"** columns (`In 📍` / `Out 📍`) to the per-day WO-entry sub-table on `VarianceTeammateDetailController` (`/admin/office/operations/teammates/variance/{user}` — the admin-themed, supervisor-gated per-teammate destination reached by clicking any teammate name in the ops hub). Each distance links to the actual punch coordinates on Google Maps (parsed from the `field_clock_{in,out}_location` geofield WKT); distances **≥ 500 ft flag red** as a possible not-on-site signal. This is the **supervisor** counterpart to the crew self-view (`bos_teammate_hours`) — GPS shows here (oversight) but never on the crew's own page. **Expanded time range was already present** on this page (30-day default, adjustable via the start/end date filter), so no new range UI was needed. Code-only change to the already-enabled `bos_teammate_operations` (commit `a7505e14`); deployed via rsync + `cr` (no cim, no new module). Verified live: columns render (Anthony Marks detail, both columns present). **Note (expected):** few punches carry GPS yet — `wo_clock` only shipped 07-03 and location is captured solely on button punches, so historical/legacy-flag entries have none by design. The `In 📍`/`Out 📍` cells show `—` where no location was captured and fill in naturally as crews use the button. Docs: `__BOS_AI/Modules/bos_teammate_hours.md`, `wo_clock.md`.

- **2026-07-05** — **`bos_teammate_hours` — "Time on Jobs" teammate profile-page hours (shipped live).** New module: a single Block plugin `teammate_time_on_jobs` on the **teammate profile page** — the user canonical page `/user/{uid}` (aliased `/teammates/{name}`), `brookstone_olivero` theme, visibility `request_path: /user/*`, guarded in build() to `entity.user.canonical` + a `teammates` page-owner — showing that teammate's `wo_time_clock` hours for a calendar week (Sun–Sat), grouped by day with per-day + week totals; each entry links its WO + property nickname + clock-in/out range. **(Placement fix:** first shipped pointing at `request_path: /teammates`, copied from the existing `teammate_profile_*` blocks — but there is **no bare `/teammates` page** in BOS, so it rendered nowhere; retargeted to `/user/*` and switched from `current_user` to the **page-owner** route param so a teammate sees their own hours and a supervisor viewing a teammate sees that teammate's. Verified live: admin viewing Russell Akers `/user/2826` renders his week, 4 day cards.**)** **Reads the page-owner, not the viewer** (teammates can't reach others' profiles → effectively self-only for crew); **no GPS** (`field_*_location`/`field_*_distance_ft` intentionally not rendered — GPS stays a supervisor-only signal per the `wo_clock` design; still captured on every punch); **no dollar figures** (these are WO clocked hours, not billable/compensable — TimeTrax stays payroll of record). Filters on **`field_teammate`** (not `uid`); week bounds computed in site tz → converted to UTC to query `field_start_time`; **open (un-clocked-out) entries flagged "In progress" and excluded from totals** (day total shows `+`). Prev/next week nav (`?week=±N`). Styled to the My Schedule crew-card pattern. **Deploy:** commit `4f897909` → rsync + `drush en bos_teammate_hours` (imports the block) + `cr`; **no cim**. Verified live: real crew member's week rendered (15.63 hrs across grouped days), no GPS leak. **Deferred:** a supervisor/office "view anyone's hours" surface (self-only for now). Docs: `__BOS_AI/Modules/bos_teammate_hours.md`.

- **2026-07-05** — **Lighting `wo_*` billing modules (dedicated rate — Option B).** `wo_landscape_lighting` / `wo_exterior_lighting` (both enabled since 05-28, but previously stub logic reading `field_maintenance_crew_labor` + no materials) **rewritten to mirror `wo_sprinkler_repair`**: on Complete (1097) → labor (`get_hours_for_*` sums `wo_time_clock:entry.field_total_time` × rate, increment-rounded via `field_hour_billing_increment`, floored at the minimum) + materials with markup (`get_total_*_material_list_price`) + trip (`field_trip_fee`) + rentals (`get_*_total_rental_fees`, COALESCE receipt-cost / hourly×hours) + `field_billing_adjustment` → `field_wo_total`; also stamps `field_total_time` / `field_labor_total` / `field_material_chemical_total` / `field_rental_total`. **New dedicated business_setting rate** (Option B, separate from maintenance/sprinkler rates): `field_lighting_technician_rate` (decimal, `$`) + `field_lighting_tech_minimum` (fraction-of-hour), added via `web/scripts/add_lighting_rate_fields.php` and left **EMPTY** for the office to fill after a competitive-rate analysis — labor is **skipped while the rate is empty** (guard `$hourlyRate > 0 && $hourlyBillingIncrement > 0`), so completed lighting WOs never get a bogus total. Local billing test verified: 2 clocked hrs @ temp $65 → labor $130 → wo_total $130 (temp rate reset to empty after). ⚠ **Owed: office sets the real lighting rate** on the Business Settings page. Docs: `__BOS_AI/Modules/wo_bundle_modules.md`.

- **2026-07-05** — **Voice-to-WO refinements + lighting bundle buildout.** (1) **Spoken description → "Work To Be Done"**: the intake now appends the dictated description to `work_order.field_work_todo_description` (on a new line after the per-service default, wrapped in `<p>` for the full_html fields) instead of creating a `wo_notes` entity — Notes are reserved for post-creation notes. Any recent-terminal system flag goes there too. Added `field_work_todo_description` to the 2 lighting bundles that lacked it → **all 36 WO bundles** now have it (`web/scripts/add_lighting_wtd_field.php`). (2) **Fixed the lighting service→bundle mismatch**: the "Landscape/Exterior Lighting" service terms (1647/1648) had `field_service_bundle` pointing at **non-existent** bundles (`lighting_landscape`/`lighting_exterior`); the real bundles are `landscape_lighting`/`exterior_lighting`, which were **empty shells (3/24 fields**, missing `field_status`/`field_property`/`field_service`) — so lighting WOs never worked. Repointed the terms (`web/scripts/fix_lighting_service_bundle.php`) and **built out both bundles like `sprinkler_repair`** — copied its 25 configurable field instances (all shared storage) + form/view display widgets/formatters (`web/scripts/build_lighting_wo_bundles.php`). Applied on local + live via the entity-API setup scripts (ECK field `cim` silent-skips). Verified: voice-create now yields real `landscape_lighting`/`exterior_lighting` WOs. (Lighting per-bundle `wo_*` billing modules were added the same day — see the entry above.) Docs: `__BOS_AI/Integration/work_order_api.md`.

- **2026-07-04** — **Voice-to-Work-Order Gates 2A + 2B shipped to live** (`b17c4d27`; the coupled unit — took Gate 2A live for the first time). **Gate 2A** = `WorkOrderIntakeService::createFromText($text, $actor, $options)`, a **deterministic (no LLM)** parse+resolve of a spoken/typed command → WO + complaint note: extracts service + name + street + town + complaint (prepositions with a no-preposition street-suffix/number fallback; complaint = trailing/period clause, verbatim); resolves **service** via `bos_wo_intake.settings.synonym_map` (81 seeds) + WO-service term-name match, **property** nickname-primary **token-order-insensitive** (handles `"Smith, John"` and `"Walmart Delta"` with one rule) + compounding street/town filters + **conflict-flagging** (never silently drops a fragment); **two-tier duplicate guard** (active-block / terminal-recent system-note, deferring to `weed_spraying`'s own guard — the only guarded bundle); explicit `createAccess()` gates on `work_order` + `wo_notes`. 11/11 acceptance. **Gate 2B** = the human front door: **`/wo-intake`** mobile Form-API+AJAX page (Android keyboard mic = dictation, no voice code), gated by new **`use work order intake`** perm (granted to administration/supervisor/site_admin/administrator; crew rollout later = tick teammates). Renders the four 2A states in-page: created card (+ WO link, recent-terminal warning), property/service candidate cards (+ conflict line), **ZERO-candidate → full 37-term client-filterable service picker** (the "pruning" case — never a dead end), blocked (+ deliberate "Create anyway"), error; always echoes parsed fragments ("Understood: …"); candidate-tap/create-anyway resubmit carry resolved ids **client-side** (stateless server). **Actor = the logged-in human** (`current_user`) — WO + note authored by them, never `cowork-connect`. Top-level admin-toolbar "New Work Order" + icon. **Deploy:** branch → `main` → rsync + `drush updb` (**`bos_wo_intake_update_10001`** imports 2A settings — `config/install` does NOT re-import on an already-enabled module, Phase 0b gotcha — and grants the perm) + `cr`. **No cim.** Verified live: config landed (81 synonyms via updb), route + perms live, anon 403, authed 200 + textarea. **Data fix (both envs):** un-flagged `field_work_order_service` on parent-category terms **366 "Spraying"** / **388 "Pruning"** (0 `field_service` refs; they're grouping nodes, pool 39→37). **Owed:** Todd's phone test (parking-lot-to-WO on device). **LATER:** scheduling/date grammar, crew rollout, seasonal cleanup/pruning date-default, parent-category-word → child candidates. Test tooling: `web/scripts/wo_intake_2*.php`. Docs: `__BOS_AI/Integration/work_order_api.md` (Gate 1/2A/2B as-built), new gotcha in `drupal_bos_gotchas.md`.

- **2026-07-04** — **Cowork Connect Gate 1 shipped to live — authenticated WO-intake REST endpoint.** New `bos_wo_intake` module ("Cowork Connect"): `POST /api/wo-intake` for an external Copilot-Cowork agent to create Work Orders. **Custom route-scoped `X-API-KEY` auth** (`CoworkKeyAuthProvider` — constant-time compare, `AuthenticationProviderChallengeInterface`→401 on missing key; **not** `Authorization: Bearer`, which the live LiteSpeed/CGI SAPI strips — same nuance that broke the WEX cron). Thin `RestResource` skin over a transport-agnostic **`WorkOrderIntakeService::createBareWorkOrder(propertyId, serviceTermId)`** (all logic; reused by Gate 2 + a future MCP tool): validates WO-service → resolves `field_service_bundle` → blocks legacy `estimate` bundle → validates property → **explicit `createAccess()` gate** (bare `->save()` bypasses the role) → creates via the normal path so `wo_shared` heals the AEL title. Role `system_integration` (4 ECK entity perms + `restful post wo_intake`) + `cowork-connect` service account. **Config ships in `config/install/`** (role/key/rest.resource) → imported by `drush en` — **no `drush cim`** (would revert ~340 drifted). **Secret env-only**: `key` env provider → `BOS_WO_INTAKE_API_KEY`, born server-side via `openssl rand -hex 32`, written into live `settings.php` `putenv()` (off-git, web-SAPI context — not the WEX cron/CLI pattern), never echoed to git/config/chat. Deploy: branch → `main` (`166f573b`) → rsync (settings.php exclusion dry-run-verified first; folded a `.claude/` exclude into the deploy script) + `drush en` + server-side secret + user + `cr`. **Gate 1 milestone PASSED on live:** valid key + invalid `service_term_id` → **422 not 401** (X-API-KEY survived the SAPI, account authenticated, `work_order` count unchanged = zero prod data); no key → 401; `Authorization`-only → 401. Local acceptance 6/6. **Fixes vs the build prompt:** partial-cim→config/install, global→route-scoped auth (front page stays 200), CLI-getenv→settings.php putenv for web SAPI. **Finding (out of scope):** `field_work_order_id` isn't assigned to *any* new WO (legacy backfill = entity id on ~30.5k old; ~16k recent are NULL) — the API returns the entity `id` as the handle. **Gate 2 (own spec):** natural-language resolution + two-tier dedup + child entities. Docs: `__BOS_AI/Integration/work_order_api.md` (Gate 1 as-built) + `system_integration_role_inspection.md` + `transport_decision_mcp_vs_rest.md`.

- **2026-07-03** — **Fixed the checkup-generator queue runaway (5.1M items) + drained it.** `contract_residential_checkup_generator` had ballooned to **5,108,233** items. Root cause: `dispatchEligibleSections()` enqueued one `process_section` item per contract_section — **all ~95,279** — with **NO eligibility filter**, then the worker discarded ~99.95% one row at a time (only **~31** are genuinely eligible: has check-up frequency + service + current-year contract + allowed status 1123/1124/1125); compounded by a **UTC-day-boundary bug** in the daily guard (`date('Y-m-d')` follows ambient/UTC tz) that let cron re-dispatch multiple times/day (2× on 07-02; ~3–4× on 06-20/06-21), each fanning out 95k. Fix (commit `30bcc260`, merged to main, deployed via scp+cr): (1) the dispatch query now filters to `exists(field_check_up_frequency)` + `field_service` + `field_contract` → **95,279 → 47 per dispatch** on live (worker per-item gates take that to ~31); (2) the daily guard uses the **site timezone** and refuses to enqueue while items are still pending (anti-pileup). Worker dedup (`scheduledWorkOrderExistsForDate`) prevents duplicate WOs, so re-dispatch is safe. **Drained** the 5.1M backlog via `queue:delete` (43s), then force-dispatched once to verify — fanned out **48 items**, drained in 96s to **0**, and generated the stuck-but-eligible check-up WOs (deduped). Queue now stays ~0. Note: **a batch of legitimate sprinkler check-up WOs was generated** (the eligible ones that were stuck behind the clog) — office will see them. New gotcha in `drupal_bos_gotchas.md`.

- **2026-07-03** — **`wo_clock` (Phase A) shipped to live** — clock-in/out redesign replacing the flag-based timer UX, with silent GPS capture + structured origin attribution. New `wo_clock` module (WoClockService, WoClockController AJAX endpoints, WoClockBlock, JS/CSS/Twig) renders a phone-first Clock In/Out button on the WO page **in place of the now-hidden flag toggle** (state-aware — detects open entries elsewhere; self-service alert + modal recovery). **Five new `wo_time_clock:entry` fields:** `field_source` (list) + `field_clock_in/out_location` (geofield) + `field_clock_in/out_distance_ft` (decimal) — created on live via the entity-API **setup scripts** (`setup_wo_clock_fields.php`, `setup_wo_time_clock_field_source.php`) because ECK field `cim` silent-skips. Silent GPS at clock-in/out (5s timeout, never blocks), Haversine **distance-from-property** on presave (admin-view only, off dashboards). **`field_source`** attributes every write path (flag / wo_clock_button / wo_clock_intervention / manual / signoff_reconciliation) with accumulating structured notes; **fixed the `field_notes` "Manually Entered" default mislabel** (button entries were born labeled manual). Legacy flag path stays functional for the mowing/snow cascade (coexistence; flag toggle hidden via code-unset + CSS + EVA empty-blank). **Deploy:** merge `feature/wo-clock-phase-a` → `main` (`0e8ef18a`), rsync + `composer install --no-dev` + `cr`, `drush en wo_clock`, ran the 2 field scripts, surgical partial-cim of the `wo_time_clock_buttons` view + 36 `work_order` view displays (baseline-verified clean first). **Verified on live:** 5/5 fields, button renders, flag hidden, redundant "You last Clocked In" line gone, real clock-in→out attributes `wo_clock_button` + distance 459.83 ft. Pre-deploy dump `~/pre-deploy-woclock-20260703.sql.gz`. **Not yet migrated off flags** (later phase). Docs: `__BOS_AI/Modules/wo_clock.md` + "Structured origin attribution" / "Silent-fallback capture" / "Coexistence during migration" patterns + gotchas (field_end_time/field_notes default traps, must-stamp-field_source).

- **2026-07-03** — **Phase B: sign-off open-clock-in guard** (`wo_sign_off`). Two hard entity-layer presave guards (analogous to `wo_total_time` Guard 6) that **refuse a sign-off save while any crew member is still clocked in** — an open `wo_time_clock` entry (NULL `field_end_time`) on the WO — regardless of code path (form, REST, VBO, programmatic, or a clock-in that races the form between load and submit). **Guard 1** in `wo_sign_off_entity_presave` (the 6 in-scope `wo_complete_info` bundles: complete/landscape_crew/clean_up_crew/fertilizing_crew/irrigation_crew/spray_crew), placed beside the existing Phase 2b roster guard. **Guard 2** in `wo_sign_off_wo_tasks_list_presave` (`lawn_mowing`), beside the empty-roster guard. Bypassed for **cancellations** (`field_canceled` — a cancel doesn't require closing clock-ins) and the **in-flight reconciliation** save (`_signoff_reconciliation_in_progress`); **no admin bypass** (strict-first — add later if it proves too strict in testing). The exception message names the offenders (resolved via `field_teammate`, fallback to the entry owner uid, deduped). Shared helpers `_wo_sign_off_assert_no_open_clockins()` + `_wo_sign_off_open_clockin_names()`. Tested on local, then **deployed to live 2026-07-03** (scp `wo_sign_off.module` + `drush cr`; verified active — throws the exact message for WO 51042). Pre-deploy blast-radius scan (`web/scripts/scan_signoff_guard_blast_radius.php`, run on live) found **essentially zero impact**: only 1 in-progress WO had a blocking open clock-in (a stale self clock-in on #51042); the 2 other open entries were on already-Invoiced WOs (excluded). Also validated the guard's `notExists('field_end_time')` convention — 0 entries use an empty-string end time, so no open clock-ins are silently missed.

- **2026-06-30** — **Idle-aware auto-refresh on the crew weed-spray route** (`teammate_weed_spraying_route`). The route already resets a property's "days" and re-sorts it to the bottom the moment it's signed off (sprayed *or* "no spray needed") — but only on a fresh page load, and crews leave the report open all day without reloading, so completed stops looked stale ("not dropping off"). Verified the data + `WeedSprayDaysField` are correct on live (no-spray visits stamp `field_last_checked`, real sprays stamp `field_last_applied_date`; the days-field counts `max()` of both) — the staleness was purely no manual reload. Fix: `bos_spray_route_ui` gains a new `auto_refresh` library (`js/spray-route-autorefresh.js`) attached (via `hook_views_pre_render`) **only** to the crew route (not the office admin route/reconciliation views). It reloads the page every **5 min** but **only after 45s of no interaction** and never while a form field is focused (won't interrupt scrolling or the search filter); skips while the tab is hidden. Interval/quiet-window are one-line constants. Deployed via scp + `drush cr` (no config/cim). Note: the report never *removes* a visited property — it sinks to the bottom as "OK" (per decision, that's fine).

- **2026-06-30** — **Billing-view VBO confirmation step** (stops accidental mass-invoicing). On 2026-06-29 the office manager accidentally marked **51 work orders Invoiced in one VBO "Apply"** on a billing view (intended one). Root cause: **Drupal core `tableselect.js` shift-click range-select** (click a checkbox, Shift-click another → every row between is selected), which VBO inherits via `core/drupal.tableselect`; the billing views use **`pager: none`** so the whole eligible set is one shift-selectable page. The earlier `show_select_all: always_hide` guard only hid the cross-page select-all link — it does **not** block shift-click range. The **eligibility gate held** (only Complete/1097 WOs invoiced; no pre-completion damage); office reverts the accidental flags by hand (WO #49698 was invoiced on purpose). Fix: `preconfiguration.add_confirmation: true` on **every** VBO action in all six billing views (`admin_billing`, `admin_clean_up_crew_billing`, `admin_office_work_orders_mow_crew_billing`, `admin_pre_emergent_billing`, `admin_snow_removal_billing`, `admin_weed_spray_billing`) → any bulk apply now shows a confirm step (action + item count). Deployed via surgical partial-cim (the 6 views were undrifted; sync==active verified before+after); no pager added (per decision). Diagnosis via the audit trail: `wo_status_updates` (time+uid) + `wo_actions` watchdog (VBO stack trace) + `work_order.field_invoiced`/`changed`. New gotcha in `drupal_bos_gotchas.md`.

- **2026-06-27** — Retired **both** manual contrib patches — both obsoleted upstream, so there are no manual `sed`/`patch` steps to maintain or re-apply. `views_bulk_operations`'s `end()`-on-null crash is fixed upstream in **4.4.5** (installed); `form_mode_control`'s foreach-on-null is guarded upstream by an early `if (empty($defaults)) return;` in **8.x-2.6**, making the old `?? []` sed redundant (the leftover edit is harmless and self-clears on the next module update). Did **not** convert form_mode_control to composer-patches (would be maintaining dead code). Updated the "Patched Contrib Modules" section — declared patches (core/calendar/smart_date/page_manager/views_aggregator) remain managed by `cweagans/composer-patches`. Removes the "re-apply patches after every composer install" footgun.

- **2026-06-27** — **Self-managed nightly live DB backup** added (`web/scripts/bos_db_backup.sh`, cron `30 2 * * *`). The host's account backups are unreliable (Hosting.com stops them when the account inode count gets high), so this is our own safety net: a rotating `drush sql:dump --gzip` that keeps the newest **14** dumps in `~/db_backups` and prunes older (≈161M each, ~2.3G total; disk fine — 167G free). Uses the robust Alt-PHP + `vendor/drush/drush.php` invocation (not the global PHAR — same lesson as the WEX cron) and **emails todd@brookstoneoutdoors.com on failure** (self-monitoring). Crontab edited file-based (backup `~/crontab.bak.20260627`); the live crontab now carries DB backup (2:30), WEX fetch (7:00), WEX watcher (7:15). Test run verified (dump created, `gzip -t` valid). **Off-server copies still owed** (these dumps share the DB's disk) — pull via `dev_scripts/brookstone-sync-db-from-live.sh` or push to S3; tracked in `deferred_work.md`.
- **2026-06-27** — New **`bos_daily_recap`** module shipped to live: admin dashboard at **`/admin/office/daily-recap`** (Office menu, under Calendar). Per-department value + job-count cards for **Yesterday / WTD (Sunday start) / MTD**; click a department total → the list below re-targets to that department + range (query-param driven, bookmarkable) and **groups by service type with subtotals** (subtotals reconcile to the card). Each row: customer (`field_nickname`) + address, WO# linked to the work order, value, completed-at. Completion anchored on `wo_complete_info.field_date_completed` (timestamp; windows in site tz `America/Denver`); revenue = `field_wo_total`; department via `field_service → services → field_department`; **warrantied (1283) excluded, WOs deduped**; In-House-Tasks → "Unassigned". Mowing is contract-billed so it reads $0 + a job count (Option A; per-mow `field_mow_rate` derivation deferred). Permission `view daily recap` granted to administration/supervisor/site_admin/administrator via `hook_install`. Gate 0 feasibility (`__BOS_AI/Reports/daily_recap_feasibility_2026-06-26.md`) + Gate 1 plan (`__BOS_AI/Architecture/daily_recap_dashboard.md`). Branch `feature/daily-recap-dashboard` → `main` → deployed (rsync + `drush en bos_daily_recap`; the install hook grants the perms — no cim of core.extension needed). Verified on live.

- **2026-06-25** — Weed-spray "routed back to the same WO" fix + days-overdue fix (branch `feature/spray-route-guard` → `main`, commit `7c8c2334`; **deployed to live 2026-06-25 evening**, merge `7921e156`). The spray-route create link (`/work-order/{property_id}/weed-spray/create` → `WeedSprayWorkOrderController::startWorkflow`), the crew add-form alter (`wo_weed_spraying_form_work_order_weed_spraying_form_alter`), and the WO presave guard all treated **any** open (non-done) weed_spraying WO as a blocker and redirected to it — so a stale or resurrected open WO trapped its property's next spray (e.g. #49698: completed, then resurrected to In Progress by a stray clock-in, caught every "create" for 19988 Iris Rd). Centralized all three paths on `_wo_weed_spraying_find_active_open_wo()`, which only treats a **genuinely-active** open WO as a blocker. Abandoned ones: **stale-empty** (current-year, open, >45 days, zero `wo_time_clock`, zero `wo_chemicals_used`, not invoiced, never reached Complete) → **auto-Canceled** by the create flow + a new daily `hook_cron` sweep (number kept as a canceled audit record); **resurrected** (ever reached Complete, then reopened) → **flagged** for office review, never auto-modified (auto-restoring could overwrite the property's current last-applied date via the completion write-back → history corruption). Also fixed the route report (`WeedSprayDaysField`): it counted only `field_last_applied_date`, so a no-spray "checked" visit never reset the clock — now counts the most recent **visit** = `max(field_last_applied_date, field_last_checked)` (the sign-off already stamps `field_last_checked` on every visit; one property dropped 268→59 "days overdue"). One-time/repeatable cleanup at `web/scripts/cleanup_stale_spray_wos.php` (dry-run default; `SPRAY_CLEANUP_APPLY=1` to apply). Verified locally vs synced prod, then on live post-deploy: `find_active(29141)` = NULL (trap broken), days-field psi 935 269→59, front page 200, no new fatals. The live cleanup sweep found **nothing to do** — the office had already resolved both known traps by hand (#49698 → Complete, #49805 → Canceled, both 06-25); the daily cron now auto-handles any future stale/resurrected cases. **Owed:** invoice #49698 in the normal billing flow (now Complete); optionally tighten the 45-day threshold; review legacy 2024 WOs stuck in status **1301 "Active"** (invoiced). Also **investigated read-only** the 06-24 billing VBO batch crash (batch 9773): the `MarkWorkOrderInvoicedAction` eligibility-gate rewrite **and** the billing-views status-floor config are both live now (`config:status` clean) — the crash was a transient **config-ahead-of-code half-deploy** (old ungated code threw an uncaught `EntityStorageException` on ineligible WO #50448 → batch abort), since closed; **no lasting data damage** (0 WOs modified in the 07:21 crash window; #50448 later invoiced cleanly; 3 *old* stranded `field_invoiced` flags — 45301/49668/50078 — predate it). See `__BOS_AI/Modules/wo_weed_spraying_updates.md`, `bos_spray_route_ui.md`, `Governance/deferred_work.md`.
- **2026-06-24 (evening)** — Two deploys to live (security first, then WO Notes). **(1) Security updates:** Drupal core 10.6.10 → **10.6.12** + contrib (ai 1.2.17, ai_agents 1.2.5, ai_provider_openai 1.2.2, paragraphs 1.21.0; guzzle 7.12.3, psr7 2.12.3, jmespath 2.9.1) clearing **18 advisories / 8 packages** incl. a Critical core PHP-object-injection (SA-CORE-2026-005). AI suite held on the 1.2.x patch line (AI Core/CKEditor/Agents are enabled). `composer audit` clean. Branch `security-updates-2026-06-24` → `main` (`01fef001`) → deployed; `drush updb` ran one `ai_provider_openai` model-default migration; the two manual contrib patches (form_mode_control, views_bulk_operations) survived (their versions didn't change); live DB dumped first (`~/pre-deploy-20260624.sql.gz`, 167M). **(2) WO Notes restyle:** notes render as cards (My Schedule tokens), whole card clickable → edit modal; `wo_schedule` auto-notes restructured into separate labeled lines (Scheduled/Rescheduled, Assigned, Schedule note) with attribution from uid+created; legacy single-string notes migrated into the structured fields (`field_change_summary` / `field_note_kind` / `field_is_system_note`) via `web/scripts/migrate_legacy_wo_notes.php` (1,573 on live). Branch `wo-notes-restyle` → `main` (`e684c53c`) → deployed via surgical partial-cim (9 configs). Both verified on live. Pre-existing `eck.eck_entity_type.wo_notes` drift (description/standalone_url) left untouched.
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
  - Single-entry duration cap (`wo_total_time` Guard 6): per-bundle `business_setting.field_max_entry_hours` (4) / `field_max_entry_hours_long` (14, for landscaping/sprinkler_repair/sprinkler_installation/summer_pruning — the `WO_TOTAL_TIME_LONG_JOB_BUNDLES` list) + `wo_time_clock.field_time_limit_override` checkbox + idempotent audit note. Smart Clock-Out button routes over-cap clock-outs to the edit form; flag-off-on-close invariant; billing red-alert preprocess on `admin_billing`.
  - `wo_timer_flag_update`: clock-out notes now multi-value `appendItem` (two rows, not concatenated).
  - `wo_schedule`: every schedule create/change auto-logs a WO note (date/crew/scheduling-note, old→new); date rendered date-only. New schedules default to **today / All Day** via `hook_entity_prepare_form` (Smart Date `default_duration` 1439 alone doesn't tick the box).
  - Sign-off reconciliation: orphan handling split by form type (wo_complete_info per-row prompt; wo_tasks_list silent `end=now`). Add-form per-row fields fixed via `_wo_signoff_ctx` form-state stash.
  - New gotchas documented: `$entity->original` not populated on update (use `loadUnchanged()` in presave); `getValues()` empty at form-build-time on rebuild (stash from validate handler).
  - Deferred: auto lunch/break deduction (`Governance/deferred_work.md` #16).
- **2026-03-12** — Removed debug logging from `wo_total_time` (Presave Debug, Not updating UID notices) and `wo_timer_flag_update` (Flag state notice). Updated `teammate_pre_emergent_wos` view config.
