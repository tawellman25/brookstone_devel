# BOS Module — supplier_price_ingest

Machine name: `supplier_price_ingest`
Package: `Brookstone Outdoors`
Status:
- **Phase 3.1 shipped 2026-05-25** — data-model foundation only.
- **Phase 3.2 shipped 2026-05-25** — parser service (`IngestParser`), supplier ingest config admin (form alter + JSON validation), batch upload form, presave validation hook. Matching, dry-run UI, and commit pipeline still ahead.

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

## Status

- **Phase 3.1** — shipped 2026-05-25 (commit `911c2221`).
- **Phase 3.2** — shipped 2026-05-25 (commit on `feature/supplier-price-ingest`).

Branch: `feature/supplier-price-ingest`.

Next phase: 3.3 — Matching service. Tier 1 (manufacturer item #), Tier 2 (existing supplier SKU), Tier 3 (fuzzy with confidence scoring + bundle inference), discovery routing per bundle policy. Status transition `pending_dry_run` → `dry_run_complete` happens at the end of the matcher's run.
