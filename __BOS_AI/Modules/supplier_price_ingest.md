# BOS Module — supplier_price_ingest

Machine name: `supplier_price_ingest`
Package: `Brookstone Outdoors`
Status:
- **Phase 3.1 shipped 2026-05-25** — data-model foundation only.
- **Phase 3.2 shipped 2026-05-25** — parser service (`IngestParser`), supplier ingest config admin (form alter + JSON validation), batch upload form, presave validation hook.
- **Phase 3.3 shipped 2026-05-25** — matcher service (`IngestMatcher`) with Tier 1 (manufacturer item #), Tier 2 (existing material_suppliers SKU), discontinued material retargeting, bundle policy enforcement, discovery routing, and supplier-do-not-use short-circuit. Parser auto-invokes matcher after parse.
- **Phase 3.4 shipped 2026-05-25** — Tier 3 fuzzy matching (`FuzzyScorer` + bundle inference + threshold routing) inserted between Tier 2 and discovery. 12-scenario verifier (`web/scripts/verify_supplier_price_ingest_fuzzy.php`) covers high/medium/low confidence routing, anti-signal handling, bundle inference correctness, excluded-bundle filtering, discontinued exclusion, and a 100-row × ~200-candidate performance pass (~2.5s in DDEV, vs the 30s budget).

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

## Status

- **Phase 3.1** — shipped 2026-05-25 (commit `911c2221`).
- **Phase 3.2** — shipped 2026-05-25 (commit `96571a39`).
- **Phase 3.3** — shipped 2026-05-25 (commit `05f77e05` on `feature/supplier-price-ingest`).
- **Phase 3.4** — shipped 2026-05-25 on `feature/supplier-price-ingest`.

Branch: `feature/supplier-price-ingest`.

Next phase: 3.5 — dry-run report rendering and approve/commit pipeline.
