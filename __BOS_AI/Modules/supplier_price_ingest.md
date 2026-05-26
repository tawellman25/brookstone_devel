# BOS Module — supplier_price_ingest

Machine name: `supplier_price_ingest`
Package: `Brookstone Outdoors`
Status:
- **Phase 3.1 shipped 2026-05-25** — data-model foundation only.
- **Phase 3.2 shipped 2026-05-25** — parser service (`IngestParser`), supplier ingest config admin (form alter + JSON validation), batch upload form, presave validation hook.
- **Phase 3.3 shipped 2026-05-25** — matcher service (`IngestMatcher`) with Tier 1 (manufacturer item #), Tier 2 (existing material_suppliers SKU), discontinued material retargeting, bundle policy enforcement, discovery routing, and supplier-do-not-use short-circuit. Parser auto-invokes matcher after parse.
- **Phase 3.4 shipped 2026-05-25** — Tier 3 fuzzy matching (`FuzzyScorer` + bundle inference + threshold routing) inserted between Tier 2 and discovery. 12-scenario verifier (`web/scripts/verify_supplier_price_ingest_fuzzy.php`) covers high/medium/low confidence routing, anti-signal handling, bundle inference correctness, excluded-bundle filtering, discontinued exclusion, and a 100-row × ~200-candidate performance pass (~2.5s in DDEV, vs the 30s budget).
- **Phase 3.5 shipped 2026-05-25** — dry-run report UI, approve/reject confirm forms, CSV export, stub committer, source filter on `views.view.material_price_review_queue`, Tier 1 sort fix from the range-audit. Commit logic was stubbed pending Phase 3.6.
- **Phase 3.6 shipped 2026-05-25** — real commit pipeline. `IngestCommitter` replaces the stub; per-row hand-off to `PriceSyncService::ingestRow()` (new unified mutation authority extending the existing WO-driven service). Approve form runs synchronously for small batches (<500 auto-applying rows) and via Drupal Batch API for large batches. Idempotent recovery on interrupted commits.
- **Phase 3.7 shipped 2026-05-25** — Office Manager dashboards. Three new Views surfaces (Batch Manager, Discovery Queue, Fuzzy Match Review). Eight per-row operations: Create Material from Row, Link to Existing, Mark as Replacement, Reject (×4 for discovery); Confirm Match, Override Match, Send to Discovery, Reject (×4 for fuzzy review). Committer extended to route discovery + fuzzy_med rows to `discovery_pending` on commit so they surface in the queue views. Bulk Reject action wired into both row views. **(Phase 3.7 also shipped two seed-load buttons — "Load default bundle policy" and "Load SiteOne column mapping" — on the supplier_ingest_config form. Both buttons were REMOVED in the 2026-05-25 form-alter follow-up. Office staff paste seed JSON directly from Chat output. See the Phase 3.7 section below for the current state.)**
- **Phase 3.10 (matcher enhancement) shipped 2026-05-26** — SKU normalization + supplier-specific transformations in Tier 1 / Tier 2. Empirical motivation: first SiteOne dry-run produced 5/59 Tier 1 hits on Rain Bird rows; diagnostics traced two distinct gaps — distributor-vs-catalog format drift (hyphen / dot / space / case) and SiteOne-specific "R" prefix on Rain Bird nozzle SKUs. The combined fix is projected to move Tier 1 hit rate from 8% to ~60% on Rain Bird rows. New `field_sku_transformations` JSON field on `supplier_ingest_config`; per-batch normalized index cache keyed by manufacturer (Tier 1) / supplier (Tier 2); audit-note transparency that explains every non-exact match in `field_resolution_notes`.

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

## Phase 3.4 — Matcher Tier 3 (Fuzzy Matching)

### Architecture

`FuzzyScorer` is a stateless service (`supplier_price_ingest.fuzzy_scorer`) shared with `IngestMatcher`. The matcher's per-row decision tree now reads:

1. Tier 1 — manufacturer item # exact match (Phase 3.3)
2. Tier 2 — existing `material_suppliers` SKU match (Phase 3.3)
3. **Tier 3 — bundle inference + multi-factor fuzzy scoring** (this phase)
4. Discovery routing (Phase 3.3)

Skipped buckets (`skipped_discontinued`, `skipped_excluded_bundle`, `skipped_do_not_use`) preserve their Phase 3.3 semantics.

### Bundle Inference

Pure-PHP keyword classifier on the row description, exposed as `IngestMatcher::inferCandidateBundles(string $description): array`. Keyword map is `const BUNDLE_KEYWORDS` on the matcher (static, shared across rows). Map covers 16 material bundles; entries are space-padded substring matches to support multi-word keywords like `"sch 40"`. Tie-broken by hit count DESC. Hard cap: 3 candidate bundles per row to avoid degenerate "every bundle is a hit" cases.

After inference, supplier's `field_bundle_policy` filters the candidate bundles:

- `excluded` → bundle removed from candidates
- `matched_only` / `discovery` / `both` / *(unset → default matched_only)* → bundle retained as scoring target

If every inferred bundle is excluded, the row gets `field_match_tier = 'skipped_excluded_bundle'` with a note naming the excluded bundles — *this is a definitive policy decision, not a discovery fall-through*. If no bundle matched at all (empty inference), the row falls to discovery with a note `"Tier 3: bundle inference returned no candidates."`.

### Candidate Pool Query

For each retained candidate bundle, the matcher queries active materials:

- `type = $bundle`, sorted by `id DESC` (newest first; deterministic across calls)
- Per-bundle cap: **200 materials**. If a bundle has more, a second query applies a `CONTAINS` pre-filter on the **largest non-stopword token (≥3 chars)** from the row description.
- Total cap across all candidate bundles: **600 materials**. If exceeded, the matcher logs a warning, records a note on the row (`"candidate pool exceeded 600; routed to discovery"`), and lets discovery handle it.
- Discontinued exclusion is enforced **in the scoring loop** (`isDiscontinued()` check before considering a candidate as the winner), **not in the query**. The query layer can't reliably filter `field_discontinued <> 1` because Drupal entity queries treat NULL field values as "not <> 1" and exclude rows that have never had the field set — collapsing the pool. Filtering at scoring time also catches any race between query and load.

### Multi-Factor Scoring

`FuzzyScorer::score(row, candidate)` returns a `ScoreBreakdown` DTO with `total` plus per-signal contributions. Signal weights (sum to 100 when all signals fire perfectly):

| Signal | Max | Behavior |
|---|---|---|
| Description token similarity | 50 | Weighted Jaccard on normalized tokens, +10 substring bonus when the candidate's title appears verbatim within the row description. |
| UOM match | 10 | +10 exact (post-alias-canonical), **−5 mismatch** anti-signal, 0 missing. |
| Size match | 25 | Size tokens extracted from both descriptions via regex; +25 exact-set, +10 partial, **−10 set-disjoint** anti-signal, 0 missing on either side. |
| Manufacturer name match | 15 | +15 exact (post-suffix-strip: Inc/Corp/Co/Industries/Mfg/etc.), +10 substring, 0 otherwise. |

Missing signals contribute 0 — the scorer **does not rescale** when a signal can't be computed. A row with no UOM can only reach 90; rescaling to 100 would inflate weak matches and bias reviewers.

Anti-signal contributions can push the raw sum negative, but the total is floored at 0.0 and capped at 100.0 before being written to `field_match_confidence`.

#### Description Normalization

Applied to both row and candidate description before tokenization (and before size extraction):

1. Lowercase + collapse whitespace.
2. Strip punctuation except `"`, `'`, `/`, `-`, `.`.
3. `inch` / `inches` / `in` → `"`; `feet` / `ft` → `'`.
4. Collapse spacing around fractions and size+unit (`3 /4` → `3/4`, `1/2 "` → `1/2"`).
5. Decimal → fraction equivalence (`0.5` → `1/2`, plus 1/8 / 1/4 / 3/8 / 5/8 / 3/4 / 7/8).

#### UOM Canonical Map

Row UOM (lowercase: `each`/`case`/`box`/`bag`/`roll`) and material UOM (uppercase abbreviation: `EA`/`LF`/`M`/`C`/`TON`/...) are mapped to a common canonical key inside the scorer before comparison. Unmapped UOMs compare verbatim (so future additions still produce useful exact-match signal).

### Threshold Routing

Supplier's `supplier_ingest_config.field_fuzzy_threshold_high` (default 90.0) and `field_fuzzy_threshold_med` (default 70.0) loaded once per batch.

- `total >= high` → `field_match_tier = 'tier_3_fuzzy_high'`, applied at commit.
- `total >= med` → `field_match_tier = 'tier_3_fuzzy_med'`, surfaced for review.
- `0 < total < med` → **fall to discovery** (not `tier_3_fuzzy_low`). Best candidate's id and score logged into `field_resolution_notes` as `"Tier 3 low-confidence (below X): best candidate #N <label>; Score Y.Z (...). Routed to discovery."` so reviewers see what *would have matched*. The `tier_3_fuzzy_low` machine name remains in the storage allowed_values for legacy/future use but is never assigned by the matcher as a terminal state.
- `total == 0` → discovery, no candidate note.

For high/medium hits, `field_resolution_notes` always carries the full breakdown: `"Tier 3 high-confidence match. Score 92.5 (desc 47/50, uom 10/10, size 25/25, mfr 10/15)."`. This is the audit string a reviewer reads to understand *why* the matcher chose what it chose.

### MatchResult DTO Changes

`MatchResult` gained `tier3High` and `tier3Med` counts. `tier1Ambiguous` stays distinct from `tier3Med` because the workflows differ: ambiguous matches surface multiple candidate IDs for review-then-pick; fuzzy_med matches surface one scored candidate with confidence. Batch rollup field `field_row_count_tier3_med` totals both (real fuzzy_med + tier 1 ambiguous + tier 2 defensive — all share the medium-confidence review queue). New rollup `field_row_count_tier3_high` records real fuzzy_high wins.

`countRowsByTier` distinguishes the two buckets by `field_match_confidence`: when a row's tier is `tier_3_fuzzy_med` AND confidence equals `CONFIDENCE_TIER_AMBIGUOUS` (50), it counts as ambiguous; any other confidence value (i.e., a real score ≥70) counts as fuzzy_med.

### Performance Budget

Tier 3 is the expensive tier. Verifier's perf pass: 100 rows × 200-candidate pvc pool, full scoring loop, runs in ~2.5s in DDEV against a 30s budget. Live batches should land well within budget; if a future batch exceeds the budget, suspect a row whose largest-significant-token is so generic the pre-filter doesn't narrow the pool.

### Files Added in Phase 3.4

| File | Purpose |
|---|---|
| `src/Matching/FuzzyScorer.php` | Pluggable scoring component (description / uom / size / mfr) |
| `src/Matching/ScoreBreakdown.php` | Per-candidate breakdown DTO, also formats the audit string |
| `web/scripts/verify_supplier_price_ingest_fuzzy.php` | 12-scenario verifier + perf pass |

### Files Modified in Phase 3.4

| File | Change |
|---|---|
| `src/Service/IngestMatcher.php` | `tryTier3()` + `inferCandidateBundles()` + `queryFuzzyPool()` + `applyFuzzyMatch()` + threshold loading; `countRowsByTier` splits fuzzy_med from tier1_ambiguous by confidence |
| `src/Service/MatchResult.php` | `tier3High` + `tier3Med` constructor params |
| `supplier_price_ingest.services.yml` | `supplier_price_ingest.fuzzy_scorer` registration; injected into matcher |

### Phase 3.4 Verification

`web/scripts/verify_supplier_price_ingest_fuzzy.php` — 12 scenarios:

1. Clean Tier 3 high-confidence hit (PASS)
2. Medium-confidence (no mfr, no UOM → score 75) (PASS)
3. Low-confidence rejection → discovery (PASS)
4. Size mismatch anti-signal picks the matching-size candidate (PASS)
5. UOM mismatch anti-signal reduces score from 100 → 85 (PASS)
6. Bundle inference correctness (irrigation) (PASS)
7. Multi-bundle inference (pvc + irrigation) (PASS)
8. Empty inference → discovery with explanatory note (PASS)
9. Excluded bundle → skipped_excluded_bundle (PASS)
10. Candidate pool overflow — SKIP, verified by code inspection
11. Discontinued exclusion from candidate pool (PASS)
12. Score breakdown present in resolution notes (PASS)

Plus performance: 100 rows × ~200 candidates → 2.53s elapsed (budget 30s) — PASS.

**Trap encountered during verification:** Two compounded issues collapsed the test's PVC candidate pool intermittently. Documented for future debugging:

1. **Query-side `field_discontinued <> 1` excludes NULL-value rows.** Drupal entity queries treat `<>` against a not-set field as exclusion (NULL <> 1 is NULL → falsy in SQL WHERE). Fresh fixtures that never wrote field_discontinued got filtered out. Moved discontinued filtering to the scoring loop (`isDiscontinued()` per candidate).
2. **`range(0, 201)` without ORDER BY is non-deterministic.** With 666 real pvc materials in the DB, the storage returned different 201-row windows across calls within the same batch — my fixtures were sometimes in, sometimes out. Added `sort('id', 'DESC')` so newest materials win, which is also the right production behavior (recently-created SKUs are most likely current).
3. **AEL on `material.irrigation`** uses pattern `[material:field_size] [material:field_name]`. Fixtures originally set `title` directly; AEL overrode it. Fixed by setting `field_size` + `field_name` on the irrigation fixture. Same family as the manufacturer / supplier AEL trap from Phase 3.3.

### Bundle Keyword Map Tuning

The bundle keyword map shipped close to the spec's suggested seed. One refinement from verification: dropped `'misc'` from the keyword map entirely. A `misc` bundle is reachable only when a supplier policy explicitly opts into it; inferring `misc` from row text would defeat the bundle-discrimination signal Tier 3 depends on. (`misc` is still a valid `material` bundle and can still appear in supplier policies — it just won't be inferred from row keywords.) Spec-suggested `'misc' => []` with empty keywords had the same effect; removing the line makes the omission explicit.

---

## Phase 3.5 — Dry-Run Report UI + Approval Gate

### Architecture

Phase 3.5 turns the batch URL into the office-manager-facing decision surface. Same URL handles every batch status; the controller renders the right shell based on `field_status`. Approve and Reject are confirm forms; Approve triggers a **stub** commit (no catalog mutation) that flips the batch through the lifecycle to `committed` so the report's committed state can be rendered end-to-end before Phase 3.6 lands the real commit.

The Phase 3.2 placeholder controller and template are deleted in this phase (`BatchPlaceholderController`, `supplier-price-ingest-batch-placeholder.html.twig`).

### Batch detail controller — state-driven rendering

`BatchDetailController::view($batch)` branches on `field_status`:

| Status | What renders |
|---|---|
| `pending_dry_run` | "Parsing in progress" notice + 5s auto-refresh (`<noscript><meta http-equiv="refresh">`) |
| `dry_run_complete` | Full report (sections 2–5 below) + action buttons enabled |
| `awaiting_approval` | "Approval in progress" notice + auto-refresh |
| `approved` | "Commit in progress" notice + auto-refresh. (3.5's stub committer flips this state to `committed` synchronously inside the approve handler, so users rarely see it; 3.6's real async commit will park batches here while running.) |
| `committed` | Same layout as `dry_run_complete` plus a "STUB — no catalog mutations yet" notice on the committed banner. The notice goes away when 3.6 lands. |
| `rejected` | Same layout, plus rejection banner + reason from `field_dry_run_report` if present |
| `failed` | Error state with failure report from the matcher / parser |

Page cache: `max-age: 0` because the rendered state depends on a status that can transition mid-request.

### Dry-run report sections

1. **Header** — batch ID, supplier, source file (linked), uploaded by/on, total rows, decided by/on if set.
2. **Match summary** — row counts per tier from the batch's `field_row_count_*` fields. The Tier 1 ambiguous bucket is computed live as a sub-count: rows with `field_match_tier = 'tier_3_fuzzy_med'` AND `field_resolution_notes LIKE 'Tier 1 ambiguous%'`. The real Tier 3 medium count is the difference. Same surface for both, but the report shows them split so reviewers know which require multi-candidate disambiguation vs single fuzzy-score review.
3. **Price change impact** — for every row tagged `tier_1_mfr / tier_2_supplier_sku / tier_3_fuzzy_high`, the controller looks up the existing `material_suppliers` row for `(matched_material, batch_supplier)` and bucketizes the price delta:
   - No existing link → counts as "new link to create"
   - Existing link, delta ≤ ±10% → "will auto-apply"
   - Existing link, delta > ±10% → "will queue in `/admin/materials/price-review`"
   - **Soft budget: 5 seconds.** If the loop exceeds that, the section renders a degraded "see CSV export" notice instead of incomplete numbers. The CSV export always has the full row-by-row data, so the budget is a UX speed-bump, not a correctness bound.
4. **Sample rows per tier** — up to 10 each (Tier 1 / Tier 2 / Tier 3 high / Tier 3 medium / discovery / error), rendered in collapsible `<details>` blocks. Each row shows: row number, truncated description (60 chars, mb-safe), SKU/Mfr#, cost+UOM, matched material link, resolution-notes excerpt (truncated to 240 chars, mb-safe).
5. **Discovery breakdown by inferred bundle** — re-runs `IngestMatcher::inferCandidateBundles()` over every discovery row to bucket by inferred target bundle. Bundles with empty inference land in an `(uninferred)` row.

Action buttons render at the bottom: Approve and Commit (enabled only when status is `dry_run_complete`); Reject Batch (enabled for `dry_run_complete` or `failed`); Export Detailed Report (always enabled).

### Approve flow

Form: `ApproveBatchForm` (extends `ConfirmFormBase`).
Route: `/admin/materials/supplier-ingest/batch/{batch}/approve`.

Status transitions in the submit handler:

1. `dry_run_complete` → `awaiting_approval` (intermediate save — a poll request mid-submit sees the in-flight status, not stale dry_run_complete).
2. `awaiting_approval` → `approved` (saves `field_committed_by` + `field_committed_on` here).
3. Stub committer invoked: stamps every auto-applying row's `field_row_status` to `committed`, transitions batch `approved` → `committed`.

The two-hop pattern through `awaiting_approval` is intentional even though the stub is synchronous — Phase 3.6 will replace the stub with a real async commit (batch API / queue worker) that needs the intermediate state to be persisted before the worker dispatches.

Validation rejects (with form error) any status other than `dry_run_complete`.

### Reject flow

Form: `RejectBatchForm` (extends `ConfirmFormBase`).
Route: `/admin/materials/supplier-ingest/batch/{batch}/reject`.

- Validation accepts `dry_run_complete` and `failed` (the latter lets office staff close out a failed batch cleanly).
- Submit sets batch status to `rejected`, captures `field_committed_by` + `field_committed_on` (reusing the "committed" field for any decision endpoint — see note in `Entities/supplier_price_ingest_batch.md`).
- All child rows updated to `field_row_status = 'rejected'`. Batch and rows are **NOT** deleted — they remain for audit.

### CSV export

Controller: `BatchExportController::export($batch)`.
Route: `/admin/materials/supplier-ingest/batch/{batch}/export.csv`.

Streamed response (`Symfony\Component\HttpFoundation\StreamedResponse`). Rows loaded in chunks of 200 with `loadMultiple` + `resetCache` so memory stays bounded regardless of batch size. Per-row `flush()` between chunks pushes data to the browser as it's generated.

Filename pattern: `batch-{id}-{YYYY-MM-DD}.csv`. Column order:

```
row_number, match_tier, match_confidence, row_status, supplier_sku,
manufacturer_item_number, manufacturer_name, description, unit_cost,
cost_uom, pack_quantity, matched_material_id, matched_material_title,
existing_link_id, resolution_notes
```

### Stub commit (`Drupal\supplier_price_ingest\Service\StubCommitter`)

Service ID: `supplier_price_ingest.stub_committer`. Mirrors the eventual contract of the real commit service so Phase 3.6 can swap implementations under the same ID without touching the form. Per the Phase 3.5 spec:

- Requires the batch to be in `approved` status (throws otherwise).
- Marks every row whose `field_match_tier IN (tier_1_mfr, tier_2_supplier_sku, tier_3_fuzzy_high)` as `field_row_status = 'committed'`.
- Transitions batch to `committed`.
- Logs an INFO line: `"Batch N approved by user U. Phase 3.5 STUB COMMIT: ... no material_suppliers / material_price_history mutations occurred."` so an audit search for "STUB COMMIT" surfaces every batch that landed before 3.6 ships.

**No catalog mutations** occur. The committed-state report includes a STUB banner so office staff aren't surprised when their approved batches don't actually update supplier pricing in the catalog.

### Source filter on `/admin/materials/price-review`

`views.view.material_price_review_queue.yml` gained an exposed `field_source_value` filter (multi-select). Default: unfiltered (all sources show). Once Phase 3.6 starts writing `feed_import_auto` / `feed_import_reviewed` entries to `material_price_history`, reviewers can narrow by source from the same view.

### IngestMatcher Tier 1 sort fix

`IngestMatcher::attemptTier1()` manufacturer lookup gained `->sort('id', 'ASC')` before its existing `->range(0, 1)`. Single audit-derived fix in scope for this phase; remaining range-audit findings stay open in `__BOS_AI/Reports/range_audit_2026-05-25.md`.

### Files Added in Phase 3.5

| File | Purpose |
|---|---|
| `src/Controller/BatchDetailController.php` | State-driven dry-run report controller |
| `src/Controller/BatchExportController.php` | Streamed CSV export |
| `src/Form/ApproveBatchForm.php` | Approve confirm form |
| `src/Form/RejectBatchForm.php` | Reject confirm form |
| `src/Service/StubCommitter.php` | Phase 3.5 stub commit (replaced in 3.6) |
| `templates/supplier-price-ingest-batch-detail.html.twig` | Twig template for all 7 batch states |
| `web/scripts/verify_supplier_price_ingest_p35_e2e.php` | One-off e2e sanity (parse→match→approve→stub-commit) |

### Files Removed in Phase 3.5

| File | Replaced by |
|---|---|
| `src/Controller/BatchPlaceholderController.php` | `BatchDetailController` |
| `templates/supplier-price-ingest-batch-placeholder.html.twig` | `supplier-price-ingest-batch-detail.html.twig` |

### Files Modified in Phase 3.5

| File | Change |
|---|---|
| `src/Service/IngestMatcher.php` | Tier 1 manufacturer query gains `->sort('id', 'ASC')` |
| `supplier_price_ingest.module` | `hook_theme()` registers the new template, drops the placeholder hook |
| `supplier_price_ingest.routing.yml` | `batch_view` repointed to `BatchDetailController`; new routes for approve/reject/export |
| `supplier_price_ingest.services.yml` | `supplier_price_ingest.stub_committer` registered |
| `config/sync/views.view.material_price_review_queue.yml` | Exposed `field_source_value` filter added |
| `web/scripts/verify_supplier_price_ingest_parser.php` | Step 10 promotes one batch to `dry_run_complete` via matcher, hits 4 new URLs + asserts content-type on CSV |
| `web/scripts/verify_supplier_price_ingest_matcher.php` | Step 9: determinism — re-run matcher against same input, assert identical per-row outcomes |

### Phase 3.5 verification

- All three existing verify scripts PASS end-to-end:
  - `verify_supplier_price_ingest_parser.php` — 10/10 steps (incl. 9 admin-URL smoke checks)
  - `verify_supplier_price_ingest_matcher.php` — 3/3 steps (incl. new determinism step)
  - `verify_supplier_price_ingest_fuzzy.php` — 12 scenarios + perf
- One-off e2e (`verify_supplier_price_ingest_p35_e2e.php`) PASSES — parse → match → approve → stub commit reaches `committed` cleanly.

### Forward dependency for SOP authoring (Phase 3.12)

This is the phase where office-manager workflow goes user-facing: "review dry-run report → decide approve or reject → click button". An SOP for the Office Manager covering when to approve, when to reject, what the discovery queue means, and how to read the price-impact section becomes the natural Phase 3.12 SOP target. ⚠ SOP NEEDED — flagged here, authored in Phase 3.12.

---

## Phase 3.6 — Commit Phase + PriceSyncService Extension

### Architecture

`IngestCommitter` replaces Phase 3.5's stub. The commit phase has one job: walk the approved batch's auto-applying rows (`tier_1_mfr` / `tier_2_supplier_sku` / `tier_3_fuzzy_high`, status=`dry_run`) and hand each to `PriceSyncService::ingestRow()` — the unified mutation authority that ALSO services the existing WO-driven flow. Both feed-driven and WO-driven price changes write through the same decision tree, the same threshold (10%), and the same audit log surface.

`PriceSyncService` is owned by the `wo_material_price_sync` module. Per the Phase 3.6 spec's "do not disturb the existing public API" constraint:

- `process()`, `validate()`, and all private helpers are unchanged.
- `ingestRow()` is a **new public method** added to the same class — no shared state with `process()`, no modification of existing decision-tree code, no behavioral side effects on the WO flow.
- `PriceHistoryWriter::write()` gained an optional `$ingest_batch_id = NULL` named parameter (Phase 3.6) and a widened return type (`bool` → `?int`) so feed-driven callers can capture the audit-entry id. All existing callers pass via named arguments and discard the return — they continue to behave identically.

### `PriceSyncService::ingestRow()` decision tree

Mirror of `process()`:

| Case | Outcome |
|---|---|
| No existing `(material, supplier)` link | `auto_created` — new row inserted with feed cost as first-known |
| Existing link, no prior cost | `applied` — first cost recorded |
| Existing link, delta < +10% (decrease or small increase) | `applied` — catalog updated; material's `field_cost_integer` recomputes via existing MAX-sync |
| Existing link, delta ≥ +10% | `flagged_high` — catalog NOT updated; audit entry surfaces in `/admin/materials/price-review` |

A 10%-exact increase is **flagged** (matches `process()`'s `>=` comparison). Always writes a `material_price_history.entry` with `field_source` ∈ `{feed_import_auto, feed_import_reviewed}` and `field_ingest_batch` populated. The catalog mutation and audit write run inside a single DB transaction via `Drupal\Core\Database\Connection::startTransaction()`.

Returns `IngestRowResult` (readonly DTO). Status values: `applied`, `flagged_high`, `auto_created`, `error`. Never throws on per-row data issues — error rows return `status='error'` so the caller can keep processing the batch.

### `IngestCommitter::commitBatch()`

Public surface:

```php
public function commitBatch(EntityInterface $batch): CommitResult;
public function commitOneRow(EntityInterface $batch, EntityInterface $supplier, EntityInterface $row): IngestRowResult;
public function queryAutoApplyingRowIds(int $batchId): int[];
public function finalizeBatch(EntityInterface $batch): void;
```

Three of those are public so the Batch API operation callback in `ApproveBatchForm` can drive the commit per-chunk while keeping the orchestration logic centralized.

Per-row commit:
1. Load matched material (error if deleted between match and commit).
2. Build `row_data` from row fields (unit_cost, cost_uom, supplier_sku, etc.).
3. Call `priceSync->ingestRow($material, $supplier, $row_data, 'feed_import_auto', $batchId)`.
4. Stamp the ingest row's `field_row_status` (`committed` or `error`), `field_resolution_action` (`created_link` for `auto_created`, `updated_link` for `applied` / `flagged_high`), and append the outcome message to `field_resolution_notes` so the audit trail captures both matcher and committer notes.

### Idempotent recovery

Spec requirement: if `commitBatch()` is interrupted partway (server reboot, fatal error, timeout), re-invoking it picks up where it left off without double-applying.

The mechanism is simple and inherent to the design: the auto-applying-row query filters on `field_row_status = 'dry_run'`. Already-committed rows are stamped `committed` row-by-row INSIDE the loop, so they're filtered out automatically on retry. The batch's own status transition to `committed` is the LAST step of `commitBatch()`, so an interrupted run leaves the batch in `approved` — a retry sees that and continues processing.

Documented in the `IngestCommitter` class docblock.

### Sync vs Batch API path in `ApproveBatchForm`

| Auto-applying row count | Path |
|---:|---|
| < 500 | Synchronous — `submitForm()` calls `commitBatch()` directly, surfaces `CommitResult::summary()` as a status message. |
| ≥ 500 | Drupal Batch API — operations of 50 rows each, finish callback calls `finalizeBatch()` and posts the summary. |

Both paths produce identical state transitions. Batch API is purely for request-time / memory pressure on large batches.

### Failure handling

- **Per-row error** (matched material deleted, threshold misconfig, etc.) — `ingestRow()` returns `status='error'`, committer marks that row as `error` row status, loop continues, batch ends in `committed` with the errored row counted in `CommitResult::rowsErrored`.
- **Batch-level fatal** (uncaught exception escaping `commitBatch()`) — Approve form catches, marks batch as `failed`, stashes JSON failure report into `field_dry_run_report`, surfaces error message to the user. Synchronous path only; Batch API path uses a similar mechanism inside `batchCommitFinished()`.

### Phase 3.5's stub is gone

`StubCommitter.php` deleted. Service ID re-keyed (`supplier_price_ingest.stub_committer` → `supplier_price_ingest.committer`). The Phase 3.5 one-off e2e verifier (`verify_supplier_price_ingest_p35_e2e.php`) is also deleted — superseded by the 9-scenario committer verifier.

### Spec deviation: `field_supplier_status_override`

The Phase 3.6 spec instructed setting `material_suppliers.field_supplier_status_override = 'applied'` on auto-created links. That field's allowed_values are `inherit | active | limited | do_not_use` — there is no `applied` value. The WO-driven `autoCreatePair()` doesn't set this field either (leaves it NULL, which means "inherit from supplier default"). Phase 3.6 mirrors the WO behavior — `field_supplier_status_override` is left unset on auto-created rows. Flagged in completion report.

### Files Added in Phase 3.6

| File | Purpose |
|---|---|
| `wo_material_price_sync/src/Service/IngestRowResult.php` | Per-row outcome DTO returned by `ingestRow()` |
| `src/Service/IngestCommitter.php` | Batch-orchestration committer |
| `src/Service/CommitResult.php` | Batch-level outcome DTO |
| `web/scripts/verify_supplier_price_ingest_committer.php` | 9-scenario e2e committer verifier |
| `web/scripts/verify_wo_driven_price_sync_regression.php` | WO-driven sync regression sanity (validates that Phase 3.6's additions don't break the existing flow) |

### Files Removed in Phase 3.6

| File | Replaced by |
|---|---|
| `src/Service/StubCommitter.php` | `IngestCommitter` |
| `web/scripts/verify_supplier_price_ingest_p35_e2e.php` | committer verifier above |

### Files Modified in Phase 3.6

| File | Change |
|---|---|
| `wo_material_price_sync/src/Service/PriceSyncService.php` | Added `ingestRow()` public method + 4 private helpers (auto-create/first-cost/flag-high/apply); added `@database` injection for transaction wrapping. Existing methods + private helpers untouched. |
| `wo_material_price_sync/src/Service/PriceHistoryWriter.php` | Added optional `$ingest_batch_id` parameter; widened return type `bool` → `?int` (existing callers discard return → backwards-compatible) |
| `wo_material_price_sync/wo_material_price_sync.services.yml` | Added `@database` arg to `price_sync` service |
| `src/Form/ApproveBatchForm.php` | Wired to `IngestCommitter`, sync vs Batch API path selection (threshold 500), failure-handling path |
| `supplier_price_ingest.services.yml` | Replaced `stub_committer` registration with `committer` |
| `__BOS_AI/Modules/supplier_price_ingest.md` | This section |
| `__BOS_AI/Modules/wo_material_price_sync.md` | New section documenting the `ingestRow()` public API |
| `__BOS_AI/Entities/material_price_history.md` | `field_ingest_batch` now populated live, not just in schema |
| `__BOS_AI/Entities/supplier_price_ingest_batch.md` | `approved → committed` arrow is real now; partial-commit recovery documented |
| `__BOS_AI/Entities/supplier_price_ingest_row.md` | `field_row_status` transitions on commit |

### Phase 3.6 Verification

- `verify_supplier_price_ingest_committer.php` — 9 scenarios + flagged-entry-in-review subcheck. All PASS:
  1. Auto-create new `material_suppliers` link
  2. Decrease price — applied
  3. +7.5% increase — applied (within threshold)
  4. +25% increase — flagged_high (catalog unchanged, audit entry created)
  5. `material.field_cost_integer` recomputes via MAX-sync downstream
  6. Idempotent recovery from interrupted commit
  7. Error containment (matched material deleted between match and commit)
  8. `feed_import_auto` entries present in DB (source filter on price-review view has data to filter)
  9. Determinism across full pipeline (parse + match + commit twice → identical outcomes)
- `verify_wo_driven_price_sync_regression.php` — WO-driven sync regression sanity. PASS.
- All 4 standing verifiers (`parser`, `matcher`, `fuzzy`) — PASS, no regressions.

### Trap encountered during verification

The WO-driven `PriceSyncService::process()` short-circuits on the INSERT hook because `EntityInterface::isNew()` returns FALSE inside `hook_ENTITY_TYPE_insert` (the entity has just been persisted; its id is populated). The intended `isNew()` branch in `hasPriceChanged()` is never reached from the insert hook, and the fallback `$entity->original` check fails because `$entity->original` isn't set on inserts. **WO-driven sync only fires meaningfully on UPDATE hooks** — when crews edit existing line items, not when they create them.

This is **pre-existing** Phase 2 behavior (the service has been in prod since April 2026). Phase 3.6 didn't introduce it and doesn't fix it. The regression test was rewritten to exercise the UPDATE path. Flagged as a "look at this before approving 3.7" item in the completion report — the office team should know whether the WO-driven sync is firing as expected on first-time-line creation in production.

---

## Phase 3.7 — Office Manager Dashboards

### Three new Views surfaces

| View | Path | Filters | Sort |
|---|---|---|---|
| Batch Manager | `/admin/materials/supplier-ingest/batches` | Supplier, Status (multi), Date Range (exposed) | `field_uploaded_on DESC, id DESC` |
| Discovery Queue | `/admin/materials/supplier-ingest/discovery` | row_status=`discovery_pending` AND tier=`discovery` (baked into view); Batch + Description-contains + Inferred-bundle (exposed) | `field_batch DESC, field_row_number ASC, id ASC` |
| Fuzzy Match Review | `/admin/materials/supplier-ingest/fuzzy-review` | row_status=`discovery_pending` AND tier=`tier_3_fuzzy_med` (baked); Batch + Confidence range (exposed) | `field_match_confidence DESC, id ASC` |

All three views use `base_table: {entity}_field_data` (the BOS-standard pattern from commit 558934ed — never the bare entity table). Every sort definition includes `id ASC/DESC` as a deterministic secondary tiebreaker per the range-audit gotcha.

### IngestCommitter update — discovery routing

Phase 3.6 left discovery + fuzzy_med rows in `field_row_status = 'dry_run'` after commit. Phase 3.7 added `IngestCommitter::routeRemainingRowsToDiscovery(int $batchId): int` which transitions both tiers' rows to `discovery_pending` after the auto-applying commit loop finishes. The Discovery Queue and Fuzzy Match Review views filter on that status — without this transition, the queues would stay empty even after committed batches.

`CommitResult` gained a `rowsRoutedToDiscovery` counter. The Batch API path in `ApproveBatchForm::batchCommitFinished()` calls the routing step explicitly (it doesn't go through `commitBatch()`).

### Eight per-row operations

Each operation is a route + a custom form. All operations share the `IngestRowFormTrait` for the row summary block, `row_data` array shape, and `appendNote()` helper.

| View | Operation | Form | Catalog effect | Row status after |
|---|---|---|---|---|
| Discovery | Create Material | `CreateMaterialFromRowForm` | Creates material + link via `ingestRow(source='feed_import_reviewed')` | `discovery_resolved` (action: `created_new_material_and_link`) |
| Discovery | Link to Existing | `LinkRowToMaterialForm` | Links to chosen existing material via `ingestRow(...)` | `discovery_resolved` (action: `linked_to_existing_material`) |
| Discovery | Mark as Replacement | `MarkRowAsReplacementForm` | Sets `field_replaced_by` on discontinued material + creates/uses replacement material's link | `discovery_resolved` (action: `marked_as_replacement`) |
| Discovery | Reject Row | `RejectRowForm` | None — audit-only | `rejected` (action: `rejected`) |
| Fuzzy | Confirm Match | `ConfirmFuzzyMatchForm` | Links to matcher's proposed material via `ingestRow(...)` | `committed` (action: `updated_link` or `created_link`) |
| Fuzzy | Override Match | `OverrideFuzzyMatchForm` | Links to a different material the reviewer chose, via `ingestRow(...)` | `committed` (action: `updated_link` or `created_link`) |
| Fuzzy | Send to Discovery | `SendToDiscoveryForm` | None — changes `field_match_tier` from `tier_3_fuzzy_med` to `discovery`; status stays `discovery_pending` so row appears in Discovery Queue | `discovery_pending` (no action set) |
| Fuzzy | Reject Row | `RejectRowForm` (reused) | None | `rejected` |

`MarkRowAsReplacementForm` is the only dual-mode form — the reviewer either picks an existing live material as the replacement OR creates a new one inline from the row data. The "create new" path runs the same material-creation logic `CreateMaterialFromRowForm` uses but inside the same transaction as the `field_replaced_by` set on the discontinued material.

All catalog-touching operations route through `PriceSyncService::ingestRow($material, $supplier, $row_data, 'feed_import_reviewed', $batch_id)` — the unified mutation authority added in Phase 3.6. The `feed_import_reviewed` source value distinguishes manually-resolved entries from auto-committed ones in the price-review queue's source filter.

### Bulk Reject action (VBO)

Plugin: `supplier_price_ingest_bulk_reject_rows` (`@Action` annotation, picked up by `plugin.manager.action`). Class extends `ViewsBulkOperationsActionBase`.

Wired into both row views via the `views_bulk_operations_bulk_form` field. Same effect as `RejectRowForm` applied per row. Useful for clearing obvious-junk rows (e.g., branded apparel mixed into an irrigation supplier's catalog scrape).

No bulk Create / Link / Confirm — those are inherently per-row decisions and per-row review is the point of the queue surface. Bulk Confirm in particular was considered risky enough to defer until a demonstrated need.

### Seed JSON for supplier_ingest_config (copy-paste from Chat)

**As of 2026-05-25 the two seed-load buttons on the supplier_ingest_config form ("Load default bundle policy" and "Load SiteOne column mapping") have been REMOVED.** Office staff configure new supplier configs by pasting JSON directly from Claude Chat output into the two textareas. The buttons added a population path that became worthless once the field had any content, and the rebuild-doesn't-update-textarea-from-form_state mechanic (see `__BOS_AI/Governance/drupal_bos_gotchas.md`) made the buttons unreliable.

Reference seed JSON (paste into the matching textarea — both are plain `string_long` so no formatting concerns):

**Default bundle policy** (Phase 2 §6 matrix):

```json
{
  "irrigation":      "matched_only",
  "pvc":             "matched_only",
  "galv":            "matched_only",
  "poly":            "matched_only",
  "brass":           "matched_only",
  "electric":        "matched_only",
  "misc":            "matched_only",
  "backflow":        "matched_only",
  "decorative_rock": "discovery",
  "landscape":       "discovery",
  "copper":          "discovery",
  "pavers":          "discovery",
  "xmas":            "discovery",
  "supplies":        "discovery",
  "mulch":           "discovery",
  "bulk_material":   "discovery",
  "plants":          "excluded",
  "shrubs":          "excluded",
  "trees":           "excluded",
  "annuals":         "excluded",
  "sod":             "excluded"
}
```

**SiteOne column mapping** (reflects the actual scraped CSV shape):

```json
{
  "source_columns": {
    "supplier_item_number":  "field_supplier_sku",
    "product_name":          "field_description",
    "manufacturer_inferred": "field_manufacturer_name",
    "your_price":            "field_unit_cost",
    "cost_uom":              "field_cost_uom"
  },
  "header_row": 1,
  "skip_rows_until_header": false,
  "case_sensitive_headers": false,
  "trim_whitespace": true
}
```

#### Known limitation — 1:many column mapping for SiteOne

SiteOne's `supplier_item_number` column frequently IS the manufacturer's item number too (their SKU is the mfr SKU passthrough). The current 1:1 source-columns shape doesn't express that — the same source column can only map to one BOS field. If Tier 1 hit rate is poor against SiteOne data in Phase 3.10 verification, the parser will need extension to support `{source_column: [target_field_1, target_field_2]}` array values. Deferred until there's evidence the limitation matters in practice — for now we map `supplier_item_number → field_supplier_sku` only.

### Files Added in Phase 3.7

| File | Purpose |
|---|---|
| `src/Form/IngestRowFormTrait.php` | Shared helpers for the per-row dashboard forms |
| `src/Form/CreateMaterialFromRowForm.php` | Discovery — create new material |
| `src/Form/LinkRowToMaterialForm.php` | Discovery — link to existing material |
| `src/Form/MarkRowAsReplacementForm.php` | Discovery — mark as replacement (dual-mode) |
| `src/Form/RejectRowForm.php` | Discovery + Fuzzy — reject row |
| `src/Form/ConfirmFuzzyMatchForm.php` | Fuzzy — confirm match |
| `src/Form/OverrideFuzzyMatchForm.php` | Fuzzy — override match |
| `src/Form/SendToDiscoveryForm.php` | Fuzzy — send to discovery queue |
| `src/Plugin/Action/BulkRejectIngestRowsAction.php` | VBO bulk-reject action |
| `config/sync/views.view.supplier_ingest_batches.yml` | Batch Manager view |
| `config/sync/views.view.supplier_ingest_discovery_queue.yml` | Discovery Queue view |
| `config/sync/views.view.supplier_ingest_fuzzy_review.yml` | Fuzzy Match Review view |
| `web/scripts/setup_phase37_dashboards.php` | Setup script (generates the three views — re-runnable for spec changes) |
| `web/scripts/verify_supplier_price_ingest_dashboards.php` | 11-scenario verifier |

### Files Modified in Phase 3.7

| File | Change |
|---|---|
| `src/Service/IngestCommitter.php` | `routeRemainingRowsToDiscovery()` + integration into `commitBatch()` |
| `src/Service/CommitResult.php` | `rowsRoutedToDiscovery` counter |
| `src/Controller/BatchDetailController.php` | `buildReviewRoutingSummary()` for committed-state banner |
| `src/Form/ApproveBatchForm.php` | Batch API finish callback calls routing step |
| `templates/supplier-price-ingest-batch-detail.html.twig` | Committed-state banner shows routed counts + links to queue views |
| `supplier_price_ingest.routing.yml` | 8 new operation routes |
| `supplier_price_ingest.links.menu.yml` | Discovery Queue + Fuzzy Match Review menu links; Batches link repointed from ECK list to new Views page |
| `supplier_price_ingest.module` | Phase 3.7 also shipped seed-load buttons here — REMOVED 2026-05-25 (see follow-up note above) |
| `web/scripts/verify_supplier_price_ingest_parser.php` | Step 10 smoke-test extended with 11 new URLs |

### Phase 3.7 Verification

- `verify_supplier_price_ingest_dashboards.php` — **13 scenarios all PASS** (after the 2026-05-25 follow-up):
  - 0. Committer routes 4 discovery+fuzzy_med rows to `discovery_pending` (the upstream dependency)
  - 1-4. Discovery: Create Material / Link to Existing / Mark as Replacement / Reject
  - 5a-5d. Fuzzy: Confirm / Override / Send to Discovery / Reject
  - 6. Seed-load button artifacts are absent (constants + handlers removed after 2026-05-25 form-alter follow-up)
  - 7. Bulk Reject action — 3/3 rows transitioned
  - 8. supplier_ingest_config form-render assertions: marker class present on textareas; CKEditor absent; uid / created / pathauto noise hidden; seed buttons absent
  - 9. field_column_mapping + field_bundle_policy storage type is `string_long` (not `text_long`)

- `verify_supplier_price_ingest_parser.php` Step 10 — **18 URLs PASS** (5 prior + 4 batch-id + 3 Phase 3.7 views + 8 row operations).
- All other standing verifiers (matcher, fuzzy, committer, WO regression) — PASS, no regressions.

### Forward dependencies

- ⚠ SOP NEEDED — three new Office Manager workflows now user-facing: Batch Manager navigation, Discovery Queue resolution, Fuzzy Match Review. Listed in Phase 2 §11 as separate SOPs (Discovery Queue Resolution, Fuzzy Match Review, plus the Upload-and-Review SOP that now includes Batches view navigation). All three owned by Phase 3.12 SOP authoring. The Upload-and-Review SOP should also document the copy-paste-from-Chat seed JSON workflow for new supplier_ingest_config setups (the two reference snippets above).
- 1:many column mapping for SiteOne (see "Known limitation" above) — deferred until Phase 3.10 produces evidence the 1:1 shape costs Tier 1 hits.
- CPS / Denver Brass column mapping JSON snippets — add to this doc when those CSVs are scraped.

---

## Phase 3.10 — SKU Normalization + Supplier Transformations (matcher enhancement)

### Architecture

Tier 1 and Tier 2 matching previously did exact-string-equality comparison of incoming SKU vs. BOS-stored SKU. That brittle comparison missed two classes of legitimate match:

1. **Format drift.** BOS writes `1806-PRS`, SiteOne writes `1806PRS`. Both are Rain Bird's `1806-PRS` model. Hyphen / dot / whitespace / case all drift between distributor and catalog. ~20 of 54 Rain Bird misses in the first SiteOne dry-run.
2. **Distributor-specific prefixes.** SiteOne prefixes Rain Bird nozzle SKUs with `R` (their `R15H` is Rain Bird's native `15H`). Manufacturer SKU drift between distributor and catalog conventions. ~10–12 additional Rain Bird misses.

Combined, the matcher's Tier 1 hit rate on Rain Bird rows in the first SiteOne dry-run was 5/59 (8%). Phase 3.10 is projected to move that to ~60% (35/59).

### `normalizeSku()` helper

Private method on `IngestMatcher`. Lowercases, trims, strips whitespace / hyphens / dots. Applied to both sides of every Tier 1 / Tier 2 comparison.

```php
private function normalizeSku(?string $value): string {
  if ($value === NULL) return '';
  $trimmed = strtolower(trim($value));
  if ($trimmed === '') return '';
  return preg_replace('/[\s\-\.]+/', '', $trimmed) ?? $trimmed;
}
```

### Supplier-specific transformations (`field_sku_transformations`)

New JSON field on `supplier_ingest_config`. Shape and validation contract in `__BOS_AI/Entities/supplier_ingest_config.md`. The matcher applies `strip_prefix` / `strip_suffix` BEFORE normalization — distributor-side SKUs are stripped of their distributor-specific affix and then normalized against BOS's manufacturer-native form.

SiteOne config seed: `{"strip_prefix": ["R"], "strip_suffix": []}` — stored in `supplier_ingest_config:64`.

### Per-batch normalized index (Tier 1 + Tier 2 cache)

Loading every BOS material matching a manufacturer reference once per batch and indexing it by normalized SKU is dramatically more efficient than running per-row entity queries with normalized predicates (which can't use indexes). Same SiteOne batch's 59 Rain Bird rows all hit the same Rain Bird index built once at the start.

Implementation:

```php
// Instance properties, cleared at start of each matchBatch():
private array $tier1Index = [];  // [mfr_id => [normalized_sku => [material_ids]]]
private array $tier2Index = [];  // [supplier_id => [normalized_sku => [link_ids]]]
```

`getTier1Index(int $mfrId)` and `getTier2Index(int $supplierId)` build-lazily and cache. Skip materials/links with empty SKU (can't be matched). All BOS-side values are normalized at index-build time; row-side values are normalized at lookup time.

### Audit-note transparency

`field_resolution_notes` on the matched row carries one of three states:

- **No note** when the match was exact (empty resolution_notes preserves the simple-case audit signal: blank = clean tier-1 hit, no drift).
- `Matched via mfr item # normalization: row '1806PRS' → BOS '1806-PRS'.` — when only normalization (hyphen / case / whitespace collapse) was load-bearing.
- `Matched via mfr item # transformation: row 'R15H' stripped to '15H', normalized to BOS '15H'.` — when supplier-specific transformation contributed to the match.

Same shape for Tier 2 with "supplier SKU" in place of "mfr item #". The note combines with any discontinued-retarget note (one note per line, newline-separated).

### Files modified in Phase 3.10

| File | Change |
|---|---|
| `src/Service/IngestMatcher.php` | `normalizeSku()`, `applySkuTransformations()`, `classifyMatchPath()`, `parseSkuTransformations()`; per-batch `$tier1Index` + `$tier2Index` caches; `attemptTier1` + `attemptTier2` rewritten to use indexed lookups; `applyMatch` writes audit notes for non-exact matches |
| `supplier_price_ingest.module` | `_supplier_price_ingest_validate_sku_transformations()` presave validator |
| `config/sync/field.storage.supplier_ingest_config.field_sku_transformations.yml` | New storage (string_long) |
| `config/sync/field.field.supplier_ingest_config.config.field_sku_transformations.yml` | New field instance |
| `config/sync/core.entity_form_display.supplier_ingest_config.config.default.yml` | string_textarea widget |
| `config/sync/core.entity_view_display.supplier_ingest_config.config.default.yml` | basic_string formatter |
| `web/scripts/update_siteone_config_strip_prefix.php` | Idempotent SiteOne config update — sets `strip_prefix=["R"]` on supplier_ingest_config #64 |
| `web/scripts/verify_supplier_price_ingest_matcher.php` | New Step 10 (3 scenarios: 10a hyphen norm, 10b prefix strip + norm, 10c no-transform-no-match) |

### Phase 3.10 Verification

`verify_supplier_price_ingest_matcher.php` Step 10 — all 13 sub-checks PASS:

- 10a (hyphen normalization): row `1806PRS` → BOS `1806-PRS`. Tier 1 hit, confidence 100, note contains "normalization" but NOT "transformation".
- 10b (prefix strip + normalization): config has `strip_prefix=["R"]`; row `R15H` → strip R → `15H` → match BOS `15H`. Tier 1 hit, confidence 100, note contains both "transformation" and "stripped to '15H'".
- 10c (no transformation match): row `Z99Q` — no prefix to strip, no matching material. Falls through cleanly (not tier_1_mfr, not tier_2_supplier_sku, no matched material).

All existing matcher / fuzzy / committer / dashboard verifiers — PASS, no regressions.

### Out of scope (per the spec)

- Historical product naming drift (`5004PLPC` ↔ `5004PC` where `PL` was historically `Plus`). Beyond punctuation/prefix handling; routes to discovery queue.
- Manufacturer alias resolution (Aqualine → Hunter). Deferred per Phase 2 §7.6.
- Tier 3 fuzzy scoring changes.
- Backfill of empty `field_manufacturer_item_number` — handled by `bos:mfr-backfill` drush commands.

---

## Status

- **Phase 3.1** — shipped 2026-05-25 (commit `911c2221`).
- **Phase 3.2** — shipped 2026-05-25 (commit `96571a39`).
- **Phase 3.3** — shipped 2026-05-25 (commit `05f77e05` on `feature/supplier-price-ingest`).
- **Phase 3.4** — shipped 2026-05-25 (commit `c5782cfb` on `feature/supplier-price-ingest`).
- **Phase 3.5** — shipped 2026-05-25 (commit `27a81558` on `feature/supplier-price-ingest`).
- **Phase 3.6** — shipped 2026-05-25 (commit `0f2650a1` on `feature/supplier-price-ingest`).
- **Phase 3.7** — shipped 2026-05-25 (commit `20856acd` on `feature/supplier-price-ingest`).
- **Phase 3.10 matcher enhancement** — shipped 2026-05-26 on `feature/supplier-price-ingest`.

Branch: `feature/supplier-price-ingest`.

Next phase: 3.8 — estimator surfaces (Refresh Prices button on Estimates, discontinued warnings).
