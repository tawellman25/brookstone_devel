# BOS Module — supplier_price_ingest

Machine name: `supplier_price_ingest`
Package: `Brookstone Outdoors`
Status:
- **Phase 3.1 shipped 2026-05-25** — data-model foundation only.
- **Phase 3.2 shipped 2026-05-25** — parser service (`IngestParser`), supplier ingest config admin (form alter + JSON validation), batch upload form, presave validation hook.
- **Phase 3.3 shipped 2026-05-25** — matcher service (`IngestMatcher`) with Tier 1 (manufacturer item #), Tier 2 (existing material_suppliers SKU), discontinued material retargeting, bundle policy enforcement, discovery routing, and supplier-do-not-use short-circuit. Parser auto-invokes matcher after parse. Tier 3 fuzzy matching still ahead in 3.4.

---

## Purpose

Owns the data model and service pipeline for ingesting supplier price feeds (CSV/XLSX) into the BOS material catalog. The pipeline is intentionally staged:

1. **Phase 3.1** — schema. Three ECK entity types, one new field on `material`, one new field on `material_price_history`, six new `field_source` values, pathauto patterns, permission.
2. **Phase 3.2 (this phase)** — `IngestParser` service, presave validation on `supplier_ingest_config` (uniqueness + JSON shape), supplier config admin form (monospace JSON + Phase-2-§6 default-policy button), batch upload form with synchronous + Batch-API parse paths.
3. **Phase 3.3** — Matching service (Tier 1/2/3 cascade, discovery routing).
4. **Phases 3.4–3.8** — dry-run reporter, commit pipeline, office-manager dashboards, estimator-facing surfaces.
5. **Phase 3.9** — discontinued-material warning on estimate add (consumes `material.field_replaced_by`).
6. **Phase 3.12** — SOP authoring (forward dependency flagged in Phase 3.2 completion report: first user-facing workflows now exist — Upload Catalog and Supplier Config — and need SOPs).

Architectural reference: `__BOS_AI/Architecture/supplier_pricing_pipeline_phase2.md` and `supplier_pricing_pipeline_phase3_sequencing.md`.

---

## Files Owned by This Module

| File | Status |
|---|---|
| `supplier_price_ingest.info.yml` | Phase 3.1 |
| `supplier_price_ingest.module` | Phase 3.1 stub (hooks land 3.2+) |
| `supplier_price_ingest.permissions.yml` | Phase 3.1 (`administer supplier price ingest`) |
| `supplier_price_ingest.services.yml` | Phase 3.1 (empty container) |
| `supplier_price_ingest.install` | Phase 3.1 (intentionally empty install/uninstall stubs — see file docblock) |

### Owned config (config/sync)

- ECK entity types: `eck.eck_entity_type.supplier_price_ingest_batch.yml`, `…_row.yml`, `eck.eck_entity_type.supplier_ingest_config.yml`
- ECK bundles: `eck.eck_type.supplier_price_ingest_batch.batch.yml`, `…_row.row.yml`, `eck.eck_type.supplier_ingest_config.config.yml`
- All `field.storage.supplier_price_ingest_batch.*.yml`, `field.storage.supplier_price_ingest_row.*.yml`, `field.storage.supplier_ingest_config.*.yml`
- All matching `field.field.*.{bundle}.*.yml`
- Default form/view displays for all three bundles
- `pathauto.pattern.supplier_price_ingest_batch.yml`
- `pathauto.pattern.supplier_ingest_config.yml`

---

## Files Modified by This Phase (not module-owned)

These configs and entities are not owned by this module but were extended as part of the Phase 3.1 data-model foundation:

- `field.storage.material.field_replaced_by.yml` — **new field on `material`**, applied to 17 bundles (see `__BOS_AI/Entities/material.md` for bundle inclusion + rationale).
- 17 × `field.field.material.{bundle}.field_replaced_by.yml` — new instance on each included bundle.
- 17 × `core.entity_form_display.material.{bundle}.default.yml` — placement of `field_replaced_by` after `field_discontinued`.
- 17 × `core.entity_view_display.material.{bundle}.default.yml` — visible by default.
- `field.storage.material_price_history.field_ingest_batch.yml` — new field on `material_price_history`.
- `field.field.material_price_history.entry.field_ingest_batch.yml` — new instance.
- `core.entity_form_display.material_price_history.entry.default.yml` — placed inside the existing `group_source_origin` fieldset.
- `core.entity_view_display.material_price_history.entry.default.yml` — visible by default.
- `field.storage.material_price_history.field_source.yml` — **append-only** extension; 6 new values added at the end (`catalog`, `quote`, `website`, `phone`, `feed_import_auto`, `feed_import_reviewed`). Existing 4 values unchanged in order. See `__BOS_AI/Entities/material_price_history.md` for the full set.
- `pathauto.settings.yml` — registered `supplier_price_ingest_batch` and `supplier_ingest_config` in `enabled_entity_types`. `supplier_price_ingest_row` is deliberately NOT registered (rows are accessed via the parent batch view, not via direct URL aliases).
- `core.extension.yml` — added `supplier_price_ingest: 0` to `module`.

---

## Permission

`administer supplier price ingest` (restrict access: TRUE).

Granted to roles in subsequent phases when the upload UI ships (3.3+). Per the Phase 2 architecture, the initial grant target is `administration`, paralleling the existing `review material price changes` permission.

---

## Why `hook_install()` / `hook_uninstall()` Are Empty

Both hooks are deliberate no-ops. The rationale lives in the file docblock of `supplier_price_ingest.install`, summarized here:

- All schema additions ride in via `drush cim` from the shipped YAMLs. There is no install-time data seed.
- The three owned ECK entity types are removed when their bundle YAMLs leave `config/sync/`, not by this module's uninstall hook.
- `material.field_replaced_by` is **not module-owned** — it lives on the `material` entity and accumulates data through the apprentice's catalog cleanup work. It must survive uninstall/reinstall of the ingest pipeline.
- `material_price_history.field_ingest_batch` is module-adjacent (references this module's batch entity type). If the module is uninstalled, existing history rows that reference a batch should retain the reference for audit; the field stays.

In short: nothing the uninstall hook would do is correct.

---

## Phase 3.1 Verification

Schema round-trips were verified via `web/scripts/verify_supplier_price_ingest_schema.php` (CREATE → LOAD → ASSERT field values → DELETE for each of the three entity types). All assertions PASS, all deletions confirmed.

The verification script is kept in `web/scripts/` for re-run after any future schema changes — same convention as `verify_wo_48948_state.php` and other diagnostic scripts already in that directory.

---

## What Is NOT in Phase 3.1

(Per the build spec.)

- No service definitions beyond the empty container.
- No hook implementations beyond the install/uninstall stubs.
- No routes, controllers, forms, or views.
- No deployment to live (DDEV only).
- No backfill of `field_replaced_by` (ships empty).
- No reordering or renaming of existing `field_source` values (append-only).

---

## Phase 3.2 — Parser Service + Supplier Config UI

### `IngestParser` service

Class: `Drupal\supplier_price_ingest\Service\IngestParser`
Service ID: `supplier_price_ingest.parser`
DI: `entity_type.manager`, `file.repository`, `logger.factory`, `current_user`

Public API:

```php
public function parseUploadedFile(EntityInterface $batch): ParseResult;
public function countSourceRows(EntityInterface $batch): int;
```

Behavior — `parseUploadedFile`:

1. Loads the supplier's `supplier_ingest_config` (errors loudly if missing).
2. Detects format by file extension: `.csv` → native `fgetcsv`, `.xls`/`.xlsx` → PhpSpreadsheet `IOFactory::load()` via `realpath()` (stream URIs aren't accepted).
3. Honors `column_mapping.header_row`, `case_sensitive_headers`, `trim_whitespace`, `skip_rows_until_header`.
4. Per data row, builds a `supplier_price_ingest_row` entity with:
   - `field_batch` = parent batch
   - `field_row_number` = 1-indexed data row position (after header)
   - `field_raw_data` = JSON encoding of the **original** header → cell map (every source cell, including unmapped columns, preserved for audit)
   - All mapped fields populated
   - `field_row_status` = `dry_run`
5. **Skip** (no entity created) rows with no identifier (`field_supplier_sku` / `field_manufacturer_item_number` / `field_description`) **OR** no `field_unit_cost`. Counted in `rowsSkipped`.
6. **Error** (entity created with `field_match_tier = 'error'`) rows where cost is unparseable or JSON encoding of raw data fails. Resolution notes capture the reason. Counted in `rowsErrored`.
7. **UOM fallback:** unmapped UOM values fall back to `supplier_ingest_config.field_default_cost_uom`. Rows error only if neither the cell nor the default is a valid UOM.
8. **Cost normalization:** strips leading `$`, commas, whitespace before parsing. `"$1,234.56"` → `"1234.56"`. Anything not numeric after strip → row errors.
9. Updates batch `field_row_count_total` (created + errored) and `field_row_count_skipped`. Status NOT advanced — matcher (3.3) owns the `pending_dry_run` → `dry_run_complete` transition.
10. Returns a `ParseResult` DTO (`Drupal\supplier_price_ingest\Service\ParseResult`): `rowsCreated`, `rowsSkipped`, `rowsErrored`, `parseErrors[]`.

**Defensive guarantees:**

- Never throws on a single bad row — captures + continues.
- On unrecoverable failure (file unreadable, config missing), batch transitions to status `failed`, the exception's message + per-row error log written into `field_dry_run_report` as JSON, exception re-thrown to the caller.
- Sync vs Batch API split: `SYNCHRONOUS_PARSE_THRESHOLD = 500` rows. Form layer decides; parser handles any count.

### Column Mapping Contract

`supplier_ingest_config.field_column_mapping` JSON shape (validated at presave):

```json
{
  "source_columns": {
    "Item Number": "field_supplier_sku",
    "Mfr Part #":  "field_manufacturer_item_number",
    "Brand":       "field_manufacturer_name",
    "Description": "field_description",
    "Price":       "field_unit_cost",
    "UOM":         "field_cost_uom",
    "Pack Qty":    "field_pack_quantity"
  },
  "header_row": 1,
  "skip_rows_until_header": false,
  "case_sensitive_headers": false,
  "trim_whitespace": true
}
```

Allowed target field names (whitelist, enforced by both presave and parser):
`field_supplier_sku`, `field_manufacturer_item_number`, `field_manufacturer_name`, `field_description`, `field_unit_cost`, `field_cost_uom`, `field_pack_quantity`.

Required: ≥ 1 identifier (`field_supplier_sku` OR `field_manufacturer_item_number` OR `field_description`) AND `field_unit_cost` mapped.

Unmapped source columns are ignored (not errored). Suppliers commonly add columns BOS doesn't care about.

### Bundle Policy Contract

`supplier_ingest_config.field_bundle_policy` JSON shape (validated at presave):

```json
{
  "irrigation":      "matched_only",
  "decorative_rock": "discovery",
  "plants":          "excluded"
}
```

Allowed values: `matched_only`, `discovery`, `both`, `excluded`. Keys must be valid `material` bundle machine names (validated against the live bundle list at save time).

**Default for unlisted bundles:** `matched_only`. Conservative — known catalog gets matched, unknown bundles don't generate discovery noise. Documented in `supplier_ingest_config.md`.

Consumed by the matcher (3.3), not the parser. The parser ignores the bundle policy entirely.

### Presave validation hook

`hook_ENTITY_TYPE_presave` for `supplier_ingest_config` enforces:

1. **Uniqueness** — one config per supplier. Same-entity update detected by ID comparison. On violation, throws `EntityStorageException` with the conflicting config's ID in the message.
2. **JSON shape on `field_column_mapping`** — valid JSON, contains `source_columns` object, all targets in the whitelist, ≥ 1 identifier mapped, `field_unit_cost` mapped.
3. **JSON shape on `field_bundle_policy`** — valid JSON, all keys are real material bundles, all values in the allowed set.

Empty values for either JSON field are accepted (allows incremental editing). The parser will fail loudly at upload time if the config is incomplete.

A pre-submit guard in the form alter surfaces JSON-decode errors on the source field before the entity is saved, so the user sees a focused error rather than a generic exception.

### Upload form

Class: `Drupal\supplier_price_ingest\Form\BatchUploadForm`
Route: `/admin/materials/supplier-ingest/upload`
Permission: `administer supplier price ingest`

Flow:
1. Supplier select (only suppliers with `field_active = TRUE` configs appear).
2. Managed-file upload (csv/xls/xlsx, 50 MB, `public://supplier_ingest/`).
3. Creates `supplier_price_ingest_batch` entity, status `pending_dry_run`.
4. Pre-scans row count. If ≤ 500 → synchronous parse in the submit handler. If > 500 → Batch API with progress page.
5. Redirects to placeholder batch view at `/admin/materials/supplier-ingest/batch/{id}` regardless (placeholder is replaced by the full dry-run UI in 3.5).

### Batch placeholder view

Class: `Drupal\supplier_price_ingest\Controller\BatchPlaceholderController`
Template: `templates/supplier-price-ingest-batch-placeholder.html.twig`
Route: `/admin/materials/supplier-ingest/batch/{supplier_price_ingest_batch}`

Renders batch metadata, row counts (created/skipped/errored), source-file download link, sample of first 10 parsed rows, and (when status is `failed`) the captured error report. Replaced by the dry-run review UI in 3.5.

### Files owned by Phase 3.2 (added)

| File | Purpose |
|---|---|
| `supplier_price_ingest.routing.yml` | Upload, batch view, config add routes |
| `supplier_price_ingest.links.menu.yml` | Admin menu links (hub + 3 children) |
| `src/Service/IngestParser.php` | Parser service |
| `src/Service/ParseResult.php` | Parser result DTO |
| `src/Form/BatchUploadForm.php` | Upload form |
| `src/Controller/BatchPlaceholderController.php` | Placeholder batch view |
| `templates/supplier-price-ingest-batch-placeholder.html.twig` | Placeholder template |
| `supplier_price_ingest.services.yml` | Service registration (was empty in 3.1) |
| `supplier_price_ingest.module` | Presave hook, form alter, theme hook (was stub in 3.1) |
| `config/sync/views.view.supplier_ingest_configs.yml` | List view at `/admin/materials/supplier-ingest/configs` |

### Files modified in Phase 3.2

- `composer.json` + `composer.lock` — `phpoffice/phpspreadsheet ^2.2` promoted from transitive to direct dependency. Used by the parser for XLSX/XLS support.
- `config/sync/user.role.administration.yml` — `administer supplier price ingest` granted to `administration` role (mirrors the `review material price changes` permission grant pattern).

### Phase 3.2 verification

End-to-end via `web/scripts/verify_supplier_price_ingest_parser.php`. All 8 spec steps PASS:

1. Create supplier_ingest_config — PASS
2. Uniqueness violation blocks second config — PASS
3. Malformed JSON in column_mapping blocked at presave — PASS
4. Unknown BOS field in mapping blocked at presave — PASS
5. Parse valid CSV (3 rows, 7 columns) — PASS (3 created, 0 skipped, 0 errored, raw_data round-trips, `$` stripped from cost)
6. Verify rows persist with correct field values — PASS
7. Parse broken CSV (bad cost, no-identifier-no-cost row, bad UOM with default-fallback) — PASS (parser doesn't crash; 3 created with 1 errored, 1 skipped)
8. Parse XLSX — PASS

Script is kept at `web/scripts/verify_supplier_price_ingest_parser.php` as a sibling to `verify_supplier_price_ingest_schema.php` (same convention).

---

## Phase 3.3 — Matcher Service (Tiers 1 + 2)

### `IngestMatcher` service

Class: `Drupal\supplier_price_ingest\Service\IngestMatcher`
Service ID: `supplier_price_ingest.matcher`
DI: `entity_type.manager`, `database`, `logger.factory`

Public API:

```php
public function matchBatch(EntityInterface $batch): MatchResult;
```

Preconditions:
- Batch must be in status `pending_dry_run`. Other statuses are rejected with a clear exception. Re-matching an already-matched batch is a separate admin operation (deferred to a later phase).

Behavior:

1. Load the batch's supplier. If supplier carries `field_supplier_status = 'do_not_use'`, **short-circuit**: tag every row as `skipped_do_not_use`, transition batch → `dry_run_complete`, return early. No matching attempted.
2. Load `supplier_ingest_config` for the supplier (errors if missing). Memoize the parsed bundle-policy JSON per batch.
3. Stream rows in chunks of 100, only processing those where `field_match_tier IS NULL` (parser-errored rows already have `tier='error'`).
4. Apply the decision tree (below). Per-row exceptions are caught, the row is tagged `error`, and the batch continues.
5. After all rows processed, recompute batch row-count rollups by querying the persisted rows (single source of truth).
6. Transition batch `pending_dry_run` → `dry_run_complete`.
7. Return `MatchResult` DTO.

On unrecoverable failure (missing config, etc.), batch transitions to `failed` and the exception is re-thrown. The placeholder batch view surfaces the failure.

### Decision tree

For each row, in order:

**Tier 1 — Manufacturer Item Number**
- Precondition: `field_manufacturer_name` AND `field_manufacturer_item_number` both non-empty.
- Resolve manufacturer entity by title match (relies on DB `_ci` collation for case-insensitive). If no manufacturer entity exists for the row's brand string → fall through to Tier 2 (the brand just isn't in BOS yet).
- Query `material` entities by `field_manufacturer = <mfr> AND field_manufacturer_item_number = <item#>`. **No bundle-exclusion pre-filter** — the match must be visible to `applyMatch()` so the `skipped_excluded_bundle` route can fire correctly.
- Outcomes:
  - **Exactly one match** → direct hit. Apply discontinued handling + bundle policy. Final disposition: `tier_1_mfr` (confidence 100) or `skipped_discontinued` / `skipped_excluded_bundle` per gates.
  - **Multiple matches** → ambiguous. Pick lowest-id material as reference. `field_match_tier = 'tier_3_fuzzy_med'`, confidence 50, note lists all candidate IDs.
  - **Zero matches** → fall through to Tier 2.

**Tier 2 — Existing material_suppliers SKU**
- Precondition: `field_supplier_sku` non-empty.
- Query `material_suppliers` by `field_supplier = <batch supplier> AND field_supplier_item_number = <row sku>`.
- Outcomes:
  - **Exactly one match** → direct hit. Set `field_existing_link`. Apply discontinued handling + bundle policy. Final disposition: `tier_2_supplier_sku` (confidence 100) or `skipped_discontinued` / `skipped_excluded_bundle` per gates.
  - **Multiple matches** → "defensive" outcome — violates the `(material × supplier)` uniqueness convention. Treat as ambiguous: `tier_3_fuzzy_med`, confidence 50, WARNING logged for investigation.
  - **Zero matches** → discovery routing.

**Tier 3 fuzzy — DEFERRED to Phase 3.4**
- The decision tree is structured so 3.4 inserts cleanly between Tier 2 and discovery routing.

**Discovery routing (when no Tier 1 / Tier 2 match)**
- Scan supplier's bundle policy values. If any bundle has policy `discovery` or `both` → `field_match_tier = 'discovery'`, confidence 0. Reviewer routes manually.
- If ALL bundles are `matched_only` or `excluded` → `field_match_tier = 'skipped_excluded_bundle'`, no matched material, note explains the supplier's policy excludes discovery. (This avoids creating noise for suppliers like Denver Brass that aren't supposed to introduce new materials.)

### Discontinued material handling

Applied within Tier 1 / Tier 2 after a direct match is found:

| Material state | Outcome |
|---|---|
| `field_discontinued` false / unset | Match stands. Confidence 100. |
| Discontinued, `field_replaced_by` set | **Retarget** to replacement. `field_matched_material` = replacement id. Confidence **95** (structural inference discount). Note records both ids. Tier stays `tier_1_mfr` / `tier_2_supplier_sku`. Bundle policy then re-checked against the replacement's bundle. |
| Discontinued, no replacement | **Orphan rejection.** `field_match_tier = 'skipped_discontinued'`, no matched material, note explains and prompts the reviewer to consider setting `field_replaced_by` on the discontinued material. Row appears in the discovery queue for human routing. |

`field_replaced_by` resolution is single-hop with self-reference guard. We don't chase chains because the field's semantic is "current replacement," not "next link in a chain."

### Bundle policy enforcement

Applied after match + discontinued handling. Read `policy[<final_material.bundle>]`, default `matched_only`:

| Policy | Outcome |
|---|---|
| `matched_only` / `discovery` / `both` | Match stands. |
| `excluded` | `field_match_tier = 'skipped_excluded_bundle'`, no matched material, note records the exclusion. |

The `excluded` outcome is the gate that lets an irrigation-only supplier ship a few landscape rows without those rows landing in the catalog.

### MatchResult value object

Class: `Drupal\supplier_price_ingest\Service\MatchResult`

Fields: `rowsProcessed`, `tier1Matches`, `tier2Matches`, `tier1Ambiguous`, `discoveryRows`, `skippedDiscontinued`, `skippedExcludedBundle`, `skippedDoNotUse`, `errors`, `matchErrors[]`.

`summary(): string` — one-line for logging.

### Auto-invocation wiring

`BatchUploadForm` runs parser → matcher as a chain:

- **Synchronous (≤500 rows):** parser submit handler runs inline; on success the matcher runs immediately after. Either failure transitions the batch to `failed` and the form layer surfaces an error.
- **Batch API (>500 rows):** two BatchBuilder operations are registered — `batchParseOperation` then `batchMatchOperation`. The match operation defensively checks that parse didn't error and that batch status is still `pending_dry_run` before running.

### Phase 3.3 parser correction (UOM strictness)

The Phase 3.2 review noted that the parser silently fell back to the default UOM for ANY unmapped source UOM string, masking real data problems (a "gallon" UOM mapped to "each"). Phase 3.3 changes the behavior:

- **Empty / whitespace source UOM** → fall back to `field_default_cost_uom` (unchanged).
- **Non-empty unmapped UOM** → row marked `field_match_tier = 'error'` with `field_resolution_notes`: `"Unrecognized UOM in source: '<original>'. Expected one of: each, case, box, bag, roll."`

Mystery UOMs now surface in the dry-run report so the office can decide: expand `allowed_values`, fix the supplier's column mapping, or treat the source row as data error.

### Files added in Phase 3.3

| File | Purpose |
|---|---|
| `src/Service/IngestMatcher.php` | Matcher service |
| `src/Service/MatchResult.php` | Matcher result DTO |
| `web/scripts/verify_supplier_price_ingest_matcher.php` | 8-step verification with full fixtures |

### Files modified in Phase 3.3

- `src/Service/IngestParser.php` — UOM strictness fix (small change, two-line note added explaining the behavior change).
- `src/Form/BatchUploadForm.php` — wired matcher into the sync + Batch API paths; added `batchMatchOperation` and adjusted finish callback to report both parse + match summaries.
- `supplier_price_ingest.services.yml` — registered `supplier_price_ingest.matcher`.
- `web/scripts/verify_supplier_price_ingest_parser.php` — Step 7 expectation updated for UOM strictness, new Step 9 covers the strict-error path.

### Phase 3.3 verification

`web/scripts/verify_supplier_price_ingest_matcher.php` exercises all eight paths from the spec:

| # | Scenario | Result |
|---:|---|---|
| 1 | Clean Tier 1 (2 rows) | **PASS** — tier_1_mfr, confidence 100, correct material |
| 2 | Tier 1 → discontinued WITH `field_replaced_by` | **PASS** — retargeted, confidence 95, note records both ids |
| 3 | Tier 1 → discontinued WITHOUT replacement | **PASS** — skipped_discontinued, no matched material, note prompts review |
| 4 | Tier 1 ambiguous (2 materials same mfr+item#) | **PASS** — tier_3_fuzzy_med, confidence 50, picks lowest id, note lists candidates |
| 5 | Tier 2 hit (no Tier 1 candidate) | **PASS** — tier_2_supplier_sku, confidence 100, field_existing_link set |
| 6 | Discovery routing (2 rows) | **PASS** — discovery, confidence 0 |
| 7 | Excluded bundle (Tier 2 match against plants material) | **PASS** — skipped_excluded_bundle, no matched material |
| 8 | Supplier `do_not_use` short-circuit | **PASS** — all rows skipped_do_not_use, batch → dry_run_complete |

Plus batch row-count rollups verified against actual row entity counts.

**Trap encountered during verification:** AEL on `manufacturer` and `supplier` entities overrides `title` with `[manufacturer:field_name]` / `[supplier:field_supplier_name]`. Test fixtures originally set `title` directly, which AEL nuked to empty string, making the manufacturer un-queryable by title. Fixed by setting `field_name` / `field_supplier_name` in the fixtures (so AEL has a value to interpolate). Same family as the sprinkler_check_up AEL trap from the broader codebase.

---

## Status

- **Phase 3.1** — shipped 2026-05-25 (commit `911c2221`).
- **Phase 3.2** — shipped 2026-05-25 (commit `96571a39`).
- **Phase 3.3** — shipped 2026-05-25 (commit on `feature/supplier-price-ingest`).

Branch: `feature/supplier-price-ingest`.

Next phase: 3.4 — Tier 3 fuzzy matching with confidence scoring and bundle inference from row description.
