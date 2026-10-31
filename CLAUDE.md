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

The deploy script rsyncs code to live, then runs `composer install --no-dev` and `drush cr` on the remote. Config import does **not** run by default. ⚠️ **Do NOT pass `--cim`** — it runs a *full* `drush cim` against **1,975** differing config entries — **1,236 that exist only in the database, which a full import DELETES**, 696 it would overwrite, and 43 it would create (measured on live 2026-10-04) (see "Configuration Management" below). Import config changes with a **surgical partial-cim** of only the specific files instead. **The DB is never touched by the deploy.** Directories `.vscode/`, `dev_scripts/`, and `__BOS_AI/` are protected from deletion on live even with `--delete`.

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

#### `properties` — 2 bundles: `property`, `hoa`
Key fields: `field_nickname` (crew-facing label, mutable — does not affect URL), `field_geofield`, `field_zipcode_reference`, `field_primary_contact_ref`, `field_contacts`, `field_work_order_note`
Operational flags: `field_call_ahead`, `field_cod_customer`, `field_no_services`, `field_must_use_client_app`, `field_client_app`
Winterizing discount flags (residential): `field_hoa_contracted` (boolean — triggers the winterizing HOA discount), `field_neighborhood` (text, informational).
- **`hoa` bundle** (HOA / Common Area, added 2026-09-07): an HOA common-area we maintain, reusing the property page/map/photo-gallery. It is a **superset of the `property` bundle** — all 27 property fields (contacts, zipcode, WO note, gate code, maps, full address, flags, etc.) **plus** 4 HOA-specific extras: **`field_boundary`** (geofield GPS polygon — for point-in-polygon auto-flagging of member homes), `field_hoa_public_desc` (public description), `field_service_start`/`field_service_end` (common-area timeframe). 31 field instances total. Auto-labels from `field_nickname` (HOA name). Being a superset makes a future `property`→`hoa` conversion lossless. The `/admin/properties` list has exposed **Type** (Property/HOA) + **Discounted (HOA)** filters. Phase 2 (GPS auto-flag member homes) + Phase 3 (public HOA showcase pages) are planned — see ROADMAP.

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
| `bos_equipment` | Cross-cutting equipment behavior (formerly `equipment_labels`, renamed 2026-09-19). Reliable auto-titles for equipment bundles that have **no** `auto_entitylabel` pattern (e.g. `power_tools`, which also lacks `field_equipment_number`) — composes `make + type(common name) + model - number` when the title is empty (clear+save regenerates); bundles WITH a pattern are left to `auto_entitylabel` (detected via live config → no conflict/drift). Also an **`equipment_types` name fallback**: copies a typed term `name → field_common_name` when the latter is empty, forced (via `hook_module_implements_alter`) to run **before** `auto_entitylabel` so a term created with only a name isn't saved blank. Works for form + programmatic creation. |
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
| `bos_scheduling` | Crew daily schedule (/teammates/calendar/my-schedule), supervisor dispatch board (/teammates/calendar/dispatch), sprinkler bulk scheduling (/admin/office/work-orders/scheduling/sprinkler), map **Route Editor** (/admin/office/work-orders/scheduling/route-editor — Office ▸ Work Orders ▸ Scheduling ▸ Route Editor). Aeration flag heads service. |
| `site_landing_page` | Custom functionality for `site_landing_page` ECK entity; admin theme for `office_administration` bundle |
| `crew_types` | Cross-references Crews and Departments ECK entities |
| `system_readiness` | System health/readiness checks |
| `fix_field_ui` | Fixes Field UI row handler error for all entities |
| `custom_date_all_day` | Extends Date All Day to save the all_day flag |
| `scheduling_date_migration` | One-time: migrates scheduling dates from old to new field |
| `work_order_notes_migration` | One-time: migrates WO comments to `wo_notes` ECK entity |
| `sewards_custom` | Legacy module (migration-era) |
| `bos_toolbar_perf` | Prunes the heavy per-view / per-bundle / per-entity-type developer links `admin_toolbar_tools` injects into the admin menu (545 view-edit links, field-UI/display links, per-ECK-type permission forms) — thousands of inline access checks per admin page (~5–6s). `hook_menu_links_discovered_alter` removes those families (guarded to `admin_toolbar_tools`-generated links only). Reversible by disabling the module. See `Governance/drupal_bos_gotchas.md`. |
| `bos_block_plugin_perf` | Prunes the `field_block` / `extra_field_block` definitions Layout Builder derives for bundles that have **no** Layout Builder display. LB generates one per field per bundle per entity type regardless of use — on BOS 13,480, of which only **1,290** belong to a bundle that actually has an LB display. Measured effect: **16,940 → 4,932** block plugin definitions and the `block_plugins` cache item **20.89 → 5.09 MB** serialized, with LB fully working on all 65 displays that use it. `hook_block_alter`, guarded: a failed config read prunes nothing, and an empty result while `field_block`s exist prunes nothing (that means the read was wrong, not that LB is unused). The block manager declares **empty cache tags**, so its definitions refresh only on a full `cr` — a display insert/update/delete hook clears them, or a newly LB-enabled display would have its blocks missing from Add block until someone ran `cr`. Audit: `web/scripts/audit_layout_builder_usage.php`; tests `test_block_plugin_prune.php` (7/7 dev + live). |
| `bos_edge_cache` | Lets LiteSpeed serve anonymous pages without entering PHP — a **memory** measure, not a latency one (the account's binding limit is **1 GB PMEM**; an edge hit costs zero PHP). **MEASURED 2026-10-04 → 10-12** by a temporary shutdown-function probe in live `settings.php` (now removed), logging every request peaking above 150 MB: **611 web requests qualified — average peak 214 MB, max 456 MB, 69 at or above 300 MB and 17 at or above 400 MB**, the worst being anonymous public pages (`/`, `/colorado/…`, `/material/…`). So the real headroom is roughly **TWO concurrent cold anonymous renders**, not the ~4 previously assumed here — which is the strongest argument for this module existing. Raw data: `~/logs/bos_memlog.tsv` on live (68 KB, 691 lines incl. drush). `EdgeCacheSubscriber` at `RESPONSE` priority −100 emits `X-LiteSpeed-Cache-Control: public,max-age=<system.performance>` only when GET/HEAD + 200 + anonymous + core already marked it `public` + no `Set-Cookie` + no session cookie + no `X-API-KEY`; otherwise an explicit `no-cache`. **Drupal decides cacheability and LiteSpeed obeys — there is deliberately no URL allowlist at the edge**, which would be a second source of truth. Purges are raised by `hook_cache_flush()` and spent only on a non-cacheable response, because a purge header riding on a cacheable one would be stored and replayed on every hit; the rule is asserted in code. Manual purge at `/admin/config/development/bos-edge-cache/purge`. Requires the `CacheLookup on` block in `web/.htaccess` (scaffold-excluded, or composer overwrites it). |
| `bos_jsonapi_guard` | Requires authentication on **every** `jsonapi.*` route (all 16,322). JSON:API serialises by **field access, never by view mode**, and Drupal field access defaults to **allow** — so curating a display protects the HTML page and nothing else. Anonymous requests were returning the whole trip-fee schedule, HOA addresses and GPS, and a 623-type map of the data model; scanners had already pulled it. Gated at the route rather than at entity access so the public pages, which are ordinary routes, keep working untouched; matched on route **name** because one route sits outside `/jsonapi`. Uninstalling is the complete rollback. **Not the whole fix** — which authenticated teammate may read which field is still open, and is tracked with the mobile app on the ROADMAP. |

## Entity Field Reference Notes

1. **`phone_number.profile_phone_numbers`** links to user via `field_user` (NOT `field_profile_attached_to` — that field is labeled "Old User Reference" and is deprecated).
2. **`address.profile_mailing_addresses`** (bundle machine name) links to user via `field_user` (NOT `field_profile` — that field is labeled "Old Profile" and is deprecated). Bundle name is `profile_mailing_addresses`, not `userprofile_mailing_addresses`.
3. **`phone_number.contacts`** has NO back-reference field to `contacts.contact` — the relationship is a forward reference from `contacts.contact.field_phone_number` → `phone_number.contacts`. Always traverse forward from the contact entity.

## Configuration Management

> ## ⛔ NEVER run a full `drush cim` (or the deploy's `--cim`) against live
> `config/sync` is **intentionally drifted** from live's active config — **1,975 entries differ**
> (measured on live 2026-10-04; the long-standing "~340" figure was badly stale):
> - **1,236 "Only in DB"** — in active, absent from sync. **A full `cim` DELETES these.** This is
>   the destructive category.
> - **696 "Different"** — in both; a full `cim` overwrites active with the stale sync version.
> - **43 "Only in sync dir"** — in sync, absent from active; a full `cim` CREATES them, which risks
>   resurrecting config that was deliberately removed. It deletes nothing.
>
> ⚠ Those counts are **live active vs LIVE's sync directory**, which is itself stale against the
> repo — so they mix genuine intentional drift with that staleness and are **not** repo-vs-live
> drift. Separating the two is `deferred_work.md` **#28**.
> (active is the source of truth; BOS evolves config via the UI and deploys do **not** import
> config). A full `drush cim` would revert all of them to the stale sync versions, breaking views,
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

**Audience view-mode tiers (public-facing pages):** a content type with both a
public and an internal face serves **one canonical URL** and swaps the body by
the viewer's role — never a separate route. Author these view modes on the
bundle: **Default** (everything), **Admin View** (`admin_view` — office/admin:
identity on top, then collapsible `field_group` **Details** sections mirroring the
audiences — "Public View" / "Crew View" / "Office Admin"), **Teammate View**
(`teammate_view` — crew: icon + crew description), **Public View** (`full` —
public minimum: icon + public description). Route with
`hook_entity_view_mode_alter` (office → `admin_view`, crew → `teammate_view`,
else `full`; office checked first) **plus a `user.roles` cache context** in
`hook_ENTITY_TYPE_view_alter` (mandatory — else render cache leaks one audience's
body to another). Section headings on `admin_view` use the shared theme library
`brookstone_olivero/audience_admin` (`.bos-admin-view` wrapper). Reference impls:
`bos_equipment` (equipment_types), `bos_services` (services) — both 3-tier. Public
description = a dedicated
`field_*_public_desc` or the core taxonomy `description` relabeled "Public
Description" per bundle (`relabel_term_description_public.php`).

**Three-field content model for public taxonomy terms (enforced in code):** every public
vocabulary carries `field_short_description` + `field_public_description` +
`field_teammate_description`, identically configured, **all Full HTML**.
**`field_short_description` is VIEWS-ONLY — the card/list text on the PARENT term's page, never
on any view mode.** `bos_content_coverage_entity_view_display_presave()` strips it from any
taxonomy_term view display and logs a warning; Views are untouched. A card never shows a
truncated body, and an empty teaser renders no text. `field_teammate_description` is never on a
public display. **When a marketing brief and this model disagree, the model wins — raise it,
do not implement it** (that is exactly how it was broken on 2026-10-03). Check live state at
`/admin/office/content-coverage`, never by inferring from a copy file or a build log.

**Hero captions live in the image's `title` sub-field (standard, 2026-10-11):**
a hero caption is **per photo** and rotates with the slides — `alt` describes the
picture (required, for screen readers and image search), the image item's `title`
is the visible line (optional; **empty = no line, never fall back to alt**, which
would print technical alt text at the customer and make a screen reader read it
twice). Enable `title_field` per **instance** so other bundles sharing the field
are unaffected, and **relabel the widget's alt/title boxes** via
`hook_field_widget_single_element_form_alter` + `#after_build` (`ImageWidget::process()`
builds them after the hook) or the office fills the wrong one. Migrating an
existing hero: copy `alt` → `title` **before** deploying the builder change.
Reference impls: `bos_seasons`, `bos_backflow_types`. **Use this for new heroes and
convert an existing one while you are already in it — no retro sweep.** Still
pre-standard: `bos_geo` reads `alt`; `bos_services` uses a static `field_subtitle`.
**Note the views-only teaser guard (`bos_content_coverage`) only strips view
displays — a field read directly in a preprocess hook is outside it**, which is how
`bos_backflow_types` rendered `field_short_description` on a public page until
2026-10-11.

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

The change log lives in
[`__BOS_AI/Governance/change_log.md`](__BOS_AI/Governance/change_log.md).

It was moved out of this file on 2026-10-09: at 447 KB of a 512 KB CLAUDE.md it
was roughly 125,000 tokens of history read into every session before any work
started, which is what a session is for reading *when it needs the history*, not
always. **The rule is unchanged — a commit or deploy that ships, changes or
obsoletes something gets an entry there, newest first, in the same session**,
anchored to evidence (commit hash · module · config key · a named live check).
[`ROADMAP.md`](__BOS_AI/ROADMAP.md) remains the status-of-record for unfinished
work, and `deferred_work.md` the surfaced-but-deferred scratch list.
