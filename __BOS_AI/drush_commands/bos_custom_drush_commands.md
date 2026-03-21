# BOS Custom Drush Commands

All custom Drush commands provided by BOS modules. Run via `ddev drush` in local dev.

---

## material-supplier:audit

**Alias:** `ms-audit`
**Module:** `material_supplier`
**Source:** `web/modules/custom/material_supplier/src/Commands/MaterialSupplierCommands.php`

Audits all `material_suppliers:supplier` link records for data quality issues.

**Checks performed:**
1. **Duplicate links** — same material + supplier pair appearing more than once
2. **Missing pack quantity** — Order UOM differs from Cost UOM but pack quantity is empty or zero
3. **Suspicious SKU values** — `field_supplier_item_number` contains URLs, email addresses, or long descriptions instead of actual SKU codes

```bash
ddev drush ms-audit
```

No options. Output is a formatted table with counts and details for each category.

---

## eck:clone-bundle

**Alias:** `eck-bundle-clone`
**Module:** `eck_bundle_clone`
**Source:** `web/modules/custom/eck_bundle_clone/src/Commands/EckBundleCloneCommands.php`

Clones an ECK bundle: bundle config entity + all field instances + base field overrides.

**What is cloned:**
- Bundle config entity (including third_party_settings like auto_entitylabel)
- Field config instances (field storage is shared, so only field_config entities are created)
- Base field overrides

**What is NOT cloned (by design):**
- `core.entity_form_display.*` — form displays
- `core.entity_view_display.*` — view displays
- Field groups, field layout, layout builder metadata

This is intentional — cloning display config often copies invalid third_party_settings that corrupt the new bundle's display. Configure form/view displays and field groups manually after cloning.

```bash
ddev drush eck:clone-bundle <entity_type_id> <source_bundle> <new_bundle> [--label="Human Label"]

# Examples:
ddev drush eck:clone-bundle sop system_procedures training --label="Training"
ddev drush eck:clone-bundle estimate aerating landscaping
```

| Argument | Description |
|---|---|
| `entity_type_id` | ECK entity type (e.g., `estimate`, `sop`, `work_order`) |
| `source_bundle` | Existing bundle machine name to clone from |
| `new_bundle` | New bundle machine name (must not already exist) |
| `--label` | Optional human-readable label (defaults to ucfirst of machine name) |

---

## bos:contracts:sections-backfill

**Alias:** `bos-cs-backfill`
**Module:** `contract_residential`
**Source:** `web/modules/custom/contract_residential/src/Commands/ContractResidentialCommands.php`

Backfills `contract_sections.field_contract` from residential contract slot fields.

**Context:** Legacy residential contracts reference contract_sections via per-section slot fields on the contract (e.g., `field_aerating_of_lawn`, `field_fall_cleanup`). Newer workflows and Views use `contract_sections.field_contract` (back-reference from section to contract). This command populates `field_contract` on legacy sections that don't have it set.

**Safety rules:**
- Only sets `field_contract` when it is currently **empty**
- Never overwrites an existing different value — logs a conflict instead
- Logs missing section references (contract references a section ID that doesn't exist)
- Logs schema drift (section entity missing `field_contract` field)

```bash
ddev drush bos:contracts:sections-backfill [--dry-run] [--limit=N] [--start-id=N] [--contract-id=N]

# Examples:
ddev drush bos:contracts:sections-backfill --dry-run        # Preview changes without saving
ddev drush bos:contracts:sections-backfill --limit=200       # Process first 200 contracts
ddev drush bos:contracts:sections-backfill --contract-id=4199  # Process one specific contract
```

| Option | Description |
|---|---|
| `--dry-run` | Report what would change without saving |
| `--limit=N` | Max contracts to process (0 = no limit) |
| `--start-id=N` | Process contracts with id >= this value |
| `--contract-id=N` | Process a single contract by ID |

**Output:** Summary table with counts of contracts processed, sections examined, sections updated, conflicts, and missing sections.

**Slot fields scanned:** 23 fields on `contracts:residential` — one per contract section type (aerating, aspen_twig_gall, christmas_decorations, etc.)

---

## bos:checkups:generate

**Module:** `contract_residential`
**Source:** `web/modules/custom/contract_residential/src/Commands/ContractResidentialCheckupsGeneratorCommands.php`

Enqueues the irrigation check-up generator dispatcher into the Drupal queue system.

**Context:** Irrigation check-up work orders are generated automatically based on contract sections. This command triggers the dispatch process that creates check-up WOs. It shares guard logic with cron — if the dispatcher has already run today, it skips unless `--force` is used.

```bash
ddev drush bos:checkups:generate           # Normal run (skips if already dispatched today)
ddev drush bos:checkups:generate --force   # Force dispatch even if already run today
```

| Option | Description |
|---|---|
| `--force` | Override the daily dispatch guard |

The dispatcher enqueues items into the `contract_residential_checkup_generator` queue, which is processed by Drupal's queue worker system (cron or `drush queue:run`).
# wo-shared:backfill-spraying-info

**Alias:** `wo-bsi`
**Module:** `wo_shared`
**Source:** `web/modules/custom/wo_shared/src/Commands/WoSharedCommands.php`

Backfills missing `property_spraying_info` records for all spray-related Work Orders.

**Context:** Spray route views join `work_order` to `property_spraying_info` via a
relationship filter. If a property has a spray WO but no `property_spraying_info`
record for that bundle, the WO is excluded from the view. This command creates
the missing bare records (type + field_property only).

**Safety rules:**
- Never overwrites existing records
- Deduplicates: processes each property+bundle combination once
- No status filter — checks ALL WOs regardless of status
- Skips `deer_prevention` (no `property_spraying_info` bundle mapping)

```bash
drush wo-shared:backfill-spraying-info
# or
drush wo-bsi
```

No options. Reports created count per bundle and total.

**Bundle mapping:**

| WO Bundle | property_spraying_info bundle |
|---|---|
| pre_emergent | pre_emergent |
| weed_spraying | weed_spraying |
| aspen_twig_gall | aspen_twig_gall |
| cooley_spruce_gall | cooley_spruce |
| deciduous_bore | deciduous_bore |
| dormant_oil | dormant_oil |
| grub_prevention | grub_prevention |
| pinion_pine_ips_beetle | ips_beetle |
| trunk_bore | trunk_bore |
| deer_prevention | (skipped) |

**Run after:** Each deploy to live at start of season to catch any properties
that were added between deploys.

Expected counts (March 2026):
- Local: 45 records created
- Live: 37 records created
