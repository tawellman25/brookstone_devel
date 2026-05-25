# BOS Module — supplier_price_ingest

Machine name: `supplier_price_ingest`
Package: `Brookstone Outdoors`
Status: **Phase 3.1 shipped 2026-05-25** — data-model foundation only. Service code, hooks, and UI land in Phases 3.2+.

---

## Purpose

Owns the data model and (eventually) the service pipeline for ingesting supplier price feeds (CSV/XLSX) into the BOS material catalog. The pipeline is intentionally staged:

1. **Phase 3.1 (this phase)** — schema. Three ECK entity types, one new field on `material`, one new field on `material_price_history`, two new `field_source` values, pathauto patterns, permission.
2. **Phases 3.2–3.8** — service container, hooks, parser, matcher, dry-run reporter, commit pipeline, office-manager dashboards, estimator-facing surfaces.
3. **Phase 3.9** — discontinued-material warning on estimate add (consumes `material.field_replaced_by`).
4. **Phase 3.12** — SOP authoring (none required for 3.1).

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

## Status

Phase 3.1 — shipped 2026-05-25. Branch: `feature/supplier-price-ingest`.

Next phase: 3.2 — service container, `IngestParser`, presave hook enforcing one-config-per-supplier on `supplier_ingest_config`.
