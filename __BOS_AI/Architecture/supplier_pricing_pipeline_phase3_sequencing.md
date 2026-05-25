# Supplier Pricing Pipeline — Phase 3 Build Sequencing

**Status:** Active build plan.
**Date authored:** 2026-05-24.
**Parent doc:** `__BOS_AI/Architecture/supplier_pricing_pipeline_phase2.md`.
**Last completed phase:** None — Phase 3.1 is next.

---

## Working Conventions

**Module name:** `supplier_price_ingest`
**Service namespace:** `Drupal\supplier_price_ingest\Service\*`
**Route prefix:** `supplier_price_ingest.*`
**Admin path prefix:** `/admin/materials/supplier-ingest/`
**Permission:** `administer supplier price ingest`
**Branch:** new feature branch `feature/supplier-price-ingest` from current `drupal-update-20251206`
**Commit cadence:** One coherent commit per sub-phase. No bundled commits across sub-phases.
**Deploy cadence:** Bundle 3.1 ships alone (schema only). 3.2 through 3.6 ship as a unit (logic without UI). 3.7+ ship as the UI bundle. 3.10–3.11 are the production cut-over.

**Standard checks for every prompt:**
- DDEV first; ask before touching live.
- Production-grade code only.
- ECK file naming follows `01_entities_policy.md` BOS standard.
- pathauto registration for new ECK entity types (gotcha noted in CLAUDE.md).
- `_BOS_AI/` governance docs updated as part of each phase.
- Completion report at end of each phase: what shipped, what's deployable, what's next.

---

## Phase 3.1 — Data Model Foundation

**Goal:** All schema artifacts exist in DDEV. No service code yet. No UI yet.

**Deliverables:**

1. ECK entity types created:
   - `supplier_price_ingest_batch` (bundle: `batch`)
   - `supplier_price_ingest_row` (bundle: `row`)
   - `supplier_ingest_config` (bundle: `config`)

2. All fields defined per Phase 2 §2.1, §2.2, §2.3 specifications.

3. New field on `material` entity: `field_replaced_by` (entity_reference → material, cardinality 1).
   - Added to all hard-goods + plant bundles: irrigation, pvc, brass, copper, galv, electric, poly, pumps, backflow, landscape, pavers, supplies, xmas, plants, shrubs, trees, annuals
   - NOT added to: bulk_material, mulch, decorative_rock, sod (no discontinuation concept)

4. New field on `material_price_history:entry`: `field_ingest_batch` (entity_reference → supplier_price_ingest_batch, cardinality 1, optional).

5. New allowed values on `material_price_history:entry.field_source`:
   - `feed_import_auto`
   - `feed_import_reviewed`

6. New allowed values on `supplier_price_ingest_batch.field_status`:
   - `pending_dry_run`, `dry_run_complete`, `awaiting_approval`, `approved`, `committed`, `rejected`, `failed`

7. New allowed values on `supplier_price_ingest_row.field_match_tier`:
   - `tier_1_mfr`, `tier_2_supplier_sku`, `tier_3_fuzzy_high`, `tier_3_fuzzy_med`, `tier_3_fuzzy_low`, `discovery`, `skipped_discontinued`, `skipped_do_not_use`, `skipped_excluded_bundle`, `error`

8. New allowed values on `supplier_price_ingest_row.field_row_status`:
   - `dry_run`, `committed`, `discovery_pending`, `discovery_resolved`, `rejected`, `error`

9. New allowed values on `supplier_price_ingest_row.field_resolution_action`:
   - `created_link`, `updated_link`, `created_new_material_and_link`, `linked_to_existing_material`, `marked_as_replacement`, `rejected`, `noop`

10. pathauto patterns:
    - `supplier_price_ingest_batch:batch` → `/admin/materials/supplier-ingest/batch/[supplier_price_ingest_batch:id]`
    - `supplier_price_ingest_row:row` → no pathauto (children of batch, accessed via batch view)
    - `supplier_ingest_config:config` → `/admin/materials/supplier-ingest/config/[supplier_ingest_config:field_supplier:entity:title]`
    - Each entity type registered in `pathauto.settings.enabled_entity_types`

11. Module skeleton created at `web/modules/custom/supplier_price_ingest/`:
    - `supplier_price_ingest.info.yml`
    - `supplier_price_ingest.module` (stub, no hooks yet)
    - `supplier_price_ingest.permissions.yml` (defines `administer supplier price ingest`)
    - `supplier_price_ingest.services.yml` (empty service container)
    - Dependencies: `eck`, `material`, `material_supplier`, `wo_material_price_sync`

12. Updated governance docs:
    - New entity files in `__BOS_AI/Entities/`:
      - `supplier_price_ingest_batch.md`
      - `supplier_price_ingest_row.md`
      - `supplier_ingest_config.md`
    - Update `__BOS_AI/Entities/material.md` to document `field_replaced_by`.
    - Update `__BOS_AI/Entities/material_price_history.md` to document new allowed values + `field_ingest_batch`.
    - New module file at `__BOS_AI/Modules/supplier_price_ingest.md` (skeleton, expanded in later phases).

13. Single commit: `feat(supplier-price-ingest): Phase 3.1 — data model foundation`

**Pause point:** After 3.1 completes in DDEV, Todd reviews. Schema-only commit can deploy to live independently during a quiet window with no operational risk (nothing references the new entities yet).

**Deployment readiness:** Schema-only deploy. Run `drush cim` after rsync. No data migration needed.

---

## Phase 3.2 — Parser Service + Supplier Config UI

**Goal:** Office Manager can create a supplier ingest config and upload a CSV. The system parses it into `supplier_price_ingest_row` entities. No matching yet — just parse and persist.

**Deliverables:**

1. `Drupal\supplier_price_ingest\Service\IngestParser`:
   - Reads CSV or XLSX files (PhpSpreadsheet for XLSX, native CSV for CSV).
   - Applies supplier's `field_column_mapping` to translate column headers.
   - Creates one `supplier_price_ingest_row` entity per source row.
   - Validates row data (skips rows missing both identifier and cost).
   - Updates batch with row counts and parsed status.
   - Defensive: never throws on bad row data — captures errors per-row in `field_match_tier = 'error'`.

2. Admin form: Supplier Ingest Config edit form at `/admin/materials/supplier-ingest/config/{config_id}`.
   - Fields exposed: supplier reference, active flag, column mapping JSON, default cost UOM, bundle policy JSON, fuzzy thresholds, notes.
   - Column mapping presented as a JSON textarea for now (UI sugar deferred to a later phase).
   - Bundle policy presented as JSON textarea, with the default matrix pre-populated when a new config is created.

3. Admin form: Batch upload at `/admin/materials/supplier-ingest/upload`.
   - Select supplier (dropdown of suppliers with active ingest configs).
   - Upload CSV/XLSX file.
   - Submit creates `supplier_price_ingest_batch` entity, triggers `IngestParser` synchronously for small files, asynchronously via Batch API for files >500 rows.
   - On success, redirects to batch detail view (placeholder page for now — full view comes in 3.5).

4. Routing in `supplier_price_ingest.routing.yml`:
   - `supplier_price_ingest.upload` → upload form
   - `supplier_price_ingest.config_list` → list of configs (deferred to UI phase, stub for now)
   - `supplier_price_ingest.batch_view` → batch detail (placeholder)

5. Permission gating: all routes require `administer supplier price ingest`.

6. Updated `__BOS_AI/Modules/supplier_price_ingest.md` documenting the parser service public API.

7. Single commit: `feat(supplier-price-ingest): Phase 3.2 — parser service and upload form`

**Pause point:** After 3.2 completes in DDEV, Todd uploads a small test CSV (10–20 rows from a SiteOne portal scrape). Verify rows are parsed correctly and persisted as `supplier_price_ingest_row` entities. No production deploy yet — schema-only deploy from 3.1 is sufficient.

**Deployment readiness:** Holds in DDEV. Will deploy as part of the 3.2–3.6 logic bundle.

---

## Phase 3.3 — Matcher Tiers 1 and 2

**Goal:** Parsed rows are scored and matched against existing materials using manufacturer item number and existing supplier SKU. No fuzzy matching yet. Discovery routing works.

**Deliverables:**

1. `Drupal\supplier_price_ingest\Service\IngestMatcher`:
   - Public method: `matchBatch(SupplierPriceIngestBatchInterface $batch): void`
   - Iterates all rows for the batch with `field_match_tier IS NULL`.
   - For each row, applies Tier 1 (manufacturer item # match), then Tier 2 (existing material_suppliers SKU match).
   - On match: populates `field_match_tier`, `field_match_confidence`, `field_matched_material`, `field_existing_link`.
   - Discontinued handling per Phase 2 §3.3: if matched material is discontinued, check `field_replaced_by`, re-target or skip accordingly.
   - On no match: `field_match_tier = 'discovery'`, `field_match_confidence = 0`.
   - Bundle policy enforcement: rows whose target bundle is `excluded` in supplier's policy → `field_match_tier = 'skipped_excluded_bundle'`.

2. Batch API integration for large batches (process in chunks of 100 rows to avoid timeout).

3. Trigger: after parser completes successfully, automatically invoke matcher. Batch transitions `pending_dry_run` → `dry_run_complete` when matching finishes.

4. Logging: each match decision logged at INFO level. Errors logged at WARNING with full context.

5. Updated `__BOS_AI/Modules/supplier_price_ingest.md` documenting the matcher service and its decision tree.

6. Single commit: `feat(supplier-price-ingest): Phase 3.3 — matcher tiers 1 and 2`

**Pause point:** After 3.3 completes in DDEV, Todd uploads a real SiteOne PVC bundle CSV (the highest-fill-rate bundle, 97.9% mfr item # fill). Verify Tier 1 catches most rows. Inspect a sample of discovery rows to confirm they're genuinely unmatchable.

**Deployment readiness:** Holds in DDEV.

---

## Phase 3.4 — Matcher Tier 3 (Fuzzy Matching)

**Goal:** Rows that don't match Tier 1 or Tier 2 are scored for fuzzy match against existing materials. Bundle inference, confidence scoring, threshold routing.

**Deliverables:**

1. Extend `IngestMatcher` with Tier 3 implementation:
   - Bundle inference from row description (keyword-based, sufficient for v1).
   - Token-based description similarity scoring.
   - UOM, size, and manufacturer-name contribution to confidence score.
   - Routing: high → `tier_3_fuzzy_high`, medium → `tier_3_fuzzy_med`, low → `discovery`.

2. Pluggable scoring component:
   - `Drupal\supplier_price_ingest\Matching\FuzzyScorer` class.
   - Public method: `score(SupplierPriceIngestRowInterface $row, MaterialInterface $candidate): float`
   - Returns 0.0–100.0.
   - Designed so individual scoring rules can be tuned or replaced later without touching matcher orchestration.

3. Candidate pool query:
   - For each row, determine candidate bundle(s) via inference.
   - Query all active (`field_discontinued != TRUE`) materials in those bundles.
   - Score each. Take top N (default 5) by score. Best candidate stored in `field_matched_material` if above medium threshold.

4. Performance: candidate pool query must use entity query API with reasonable filtering — don't load every material in a bundle if bundle has thousands of entries. Add caching layer if iteration cost is high in DDEV testing.

5. Updated module doc with fuzzy matching algorithm description and tuning knobs.

6. Single commit: `feat(supplier-price-ingest): Phase 3.4 — matcher tier 3 fuzzy matching`

**Pause point:** Todd uploads a SiteOne `brass` bundle CSV (the lowest fill rate, 18%). Verify Tier 3 captures the majority of would-be discoveries. Inspect medium-confidence rows manually to assess scoring quality.

**Deployment readiness:** Holds in DDEV.

---

## Phase 3.5 — Dry-Run Report + Approval Gate

**Goal:** Office Manager sees a clear, actionable report of what an ingest will do before any catalog mutation. Approval gate fully wired.

**Deliverables:**

1. Batch detail view at `/admin/materials/supplier-ingest/batch/{id}`:
   - Header: batch metadata, supplier, file name, upload date, current status.
   - Summary section: match tier counts (per Phase 2 §3.4 report shape).
   - Price change impact section: how many existing links would update, how many new links would create, how many would flag the >10% threshold.
   - Sample rows per tier (10 rows each, expandable).
   - Discovery breakdown by bundle.
   - Action buttons: "Approve and Commit", "Reject Batch", "Export Detailed Report" (CSV download of all rows + their decisions).

2. Approval action handler:
   - Validates batch is in `dry_run_complete` status.
   - Transitions batch to `awaiting_approval` then `approved` on confirmation step.
   - Captures committer and timestamp.
   - Triggers commit (Phase 3.6 will implement; in 3.5 this is a stub).

3. Reject action handler:
   - Updates batch status to `rejected`.
   - Updates all batch rows to `field_row_status = 'rejected'`.
   - No catalog mutation.
   - Confirmation step required.

4. Export action: generates CSV of all rows with their match decisions for offline review.

5. Twig template for the dry-run report — clean, scannable, follows BOS admin styling conventions.

6. Updated module doc with dry-run report structure.

7. Single commit: `feat(supplier-price-ingest): Phase 3.5 — dry-run report and approval gate`

**Pause point:** Todd uploads a real CSV, reviews the dry-run report end-to-end. Approves a small batch — but commit is still a stub at this point so nothing actually mutates. Verify the report itself is accurate and actionable.

**Deployment readiness:** Holds in DDEV.

---

## Phase 3.6 — Commit + PriceSyncService Extension

**Goal:** Approved batches mutate the catalog. Existing `PriceSyncService` is extended to handle ingest-driven row processing through the same decision tree as WO-driven sync.

**Deliverables:**

1. Extend `wo_material_price_sync` module's `PriceSyncService` with new public method:
   - `ingestRow(MaterialInterface $material, SupplierInterface $supplier, array $row_data, string $source): IngestRowResult`
   - Implementation: find-or-create `material_suppliers` row for the pair, apply the existing decision tree (≤10% applies, >10% flags, no row exists creates new with applied status).
   - Writes `material_price_history:entry` with the passed source value and the batch reference.
   - Returns structured result for the caller's audit.

2. `IngestRowResult` value object:
   - `status`: applied | flagged_high | auto_created | rejected | error
   - `material_suppliers_id`: the affected link entity ID
   - `material_price_history_id`: the created history entry ID
   - `message`: human-readable explanation

3. `Drupal\supplier_price_ingest\Service\IngestCommitter`:
   - Public method: `commitBatch(SupplierPriceIngestBatchInterface $batch): void`
   - Iterates all batch rows where `field_match_tier IN (tier_1_mfr, tier_2_supplier_sku, tier_3_fuzzy_high)` and `field_row_status = 'dry_run'`.
   - For each, calls `PriceSyncService::ingestRow()`.
   - Captures result, updates row entity with commit outcome.
   - On completion, sets batch status to `committed`.
   - Batch API for large batches.

4. Hook the commit trigger into the approval action from 3.5 (replace stub).

5. Error handling: if commit fails partway, batch status goes to `failed`, partial state is preserved. Manual recovery via admin action.

6. Updated module doc with commit behavior and the PriceSyncService extension contract.

7. Single commit: `feat(supplier-price-ingest): Phase 3.6 — commit phase and PriceSyncService extension`

**Pause point:** Todd uploads a small (20–30 row) SiteOne PVC CSV in DDEV. Approves and commits. Verifies:
- `material_suppliers` rows created or updated.
- `material_price_history:entry` rows appear with `field_source = 'feed_import_auto'` and `field_ingest_batch` populated.
- `material.field_cost_integer` recomputes via existing MAX-sync.
- Any >10% changes appear in `/admin/materials/price-review`.

**Deployment readiness:** End of the 3.2–3.6 logic bundle. **First production deploy candidate.** After Todd's DDEV validation, the entire 3.1–3.6 bundle ships to live during an off-hours window. Live is now capable of ingesting CSVs but no UIs for managing batches or discovery — those land in 3.7.

---

## Phase 3.7 — Office Manager Dashboards

**Goal:** Office Manager has full UI for managing batches, resolving discovery rows, reviewing fuzzy matches. No more JSON spelunking.

**Deliverables:**

1. Batch Manager view at `/admin/materials/supplier-ingest/batches`:
   - Tabular list of all batches with key columns (per Phase 2 §4.1).
   - Status filters, supplier filter, date range filter.
   - Operations: View Dry Run, Approve, Reject, View Committed.

2. Discovery Queue view at `/admin/materials/supplier-ingest/discovery`:
   - All rows with `field_row_status = 'discovery_pending'`.
   - Inline operations per row:
     - **Create New Material** — opens material creation form pre-filled from row data, target bundle inferred. On save, creates the material AND creates the `material_suppliers` link AND updates row status to `discovery_resolved` with action `created_new_material_and_link`.
     - **Link to Existing Material** — opens autocomplete search modal. Reviewer picks material. Creates `material_suppliers` link. Row resolved with action `linked_to_existing_material`.
     - **Mark as Replacement** — opens autocomplete for a discontinued material. Sets that material's `field_replaced_by` to the new material from this row (or to an existing material the reviewer specifies). Row resolved with action `marked_as_replacement`.
     - **Reject** — row marked rejected. No mutation.
   - Bulk actions: bulk-reject selected rows.

3. Fuzzy Match Review view at `/admin/materials/supplier-ingest/fuzzy-review`:
   - All rows with `field_match_tier = 'tier_3_fuzzy_med'` and `field_row_status = 'dry_run'`.
   - Side-by-side display: row data ↔ proposed match.
   - Operations: Confirm Match, Override Match (autocomplete), Send to Discovery, Reject.
   - Confirming triggers `PriceSyncService::ingestRow()` for that row immediately (small per-row commits, not batched).

4. Filter enhancement on existing `/admin/materials/price-review`:
   - Add source filter to the existing view config.
   - Sources: WO entry, Invoice, Feed (auto), Feed (reviewed), Manual.

5. Updated module doc with dashboard documentation.

6. Single commit: `feat(supplier-price-ingest): Phase 3.7 — office manager dashboards`

**Pause point:** Todd and Office Manager use the dashboards in DDEV against the test data from 3.6. Resolve a few discovery rows. Confirm a few fuzzy matches. Reject one batch. Verify all flows are intuitive and don't require explanation.

**Deployment readiness:** Holds in DDEV until 3.8–3.9 also complete, then UI bundle ships as a single deploy.

---

## Phase 3.8 — Estimator Surface

**Goal:** Estimators can refresh prices on open estimates and see warnings on discontinued materials and stale prices.

**Deliverables:**

1. "Refresh Material Prices" button on estimate edit page:
   - Visible only on draft estimates (not accepted/converted).
   - Click opens a diff modal.
   - Modal shows: line item name, current line price, current catalog price, delta %, checkbox per row.
   - "Update Selected" applies chosen updates to `estimate_items:materials.field_unit_price`.
   - Line totals and estimate total recompute via existing rollup.

2. Discontinued material warning on estimate line item add/edit form:
   - When the selected material in `field_material` has `field_discontinued = TRUE`:
     - Form-level warning rendered (yellow box).
     - If `field_replaced_by` is set, display the replacement and a button "Use replacement instead" that swaps the selection.
     - Save is allowed (warning is informational, not blocking).

3. Stale price indicator on material display:
   - Material view page: show last `field_price_effective_date` from highest-priority active supplier link.
   - Older than 90 days: amber styling.
   - Older than 180 days: red styling.
   - Estimate line item display: same indicator next to material name.

4. Twig template updates for the warning/indicator surfaces.

5. New entity_action_log entries on estimate when prices are refreshed (audit trail for "estimator updated prices on YYYY-MM-DD").

6. Updated module doc with estimator surface specs.

7. Single commit: `feat(supplier-price-ingest): Phase 3.8 — estimator surface (refresh, warnings, indicators)`

**Pause point:** Todd and the estimator do a walk-through. Open an existing estimate. Click refresh. Confirm the diff modal works as expected. Select a discontinued material in a test estimate, confirm warning. Verify stale price indicators look right.

**Deployment readiness:** Holds in DDEV until 3.9 completes.

---

## Phase 3.9 — `field_replaced_by` Surfacing Polish

**Goal:** The `field_replaced_by` field, created in 3.1, has rich display and editing UX throughout BOS.

**Deliverables:**

1. Material view page display:
   - When `field_discontinued = TRUE` and `field_replaced_by` is set: prominent banner "This material has been replaced by: [link to replacement]".
   - When `field_discontinued = TRUE` and `field_replaced_by` is empty: prominent banner "This material is discontinued. No replacement specified."

2. Material edit form widget enhancement:
   - When `field_discontinued` is toggled TRUE, reveal `field_replaced_by` autocomplete with helpful guidance text.

3. View: list of discontinued materials with no replacement specified — at `/admin/materials/discontinued-needing-replacement`. Apprentice/office task list.

4. WO line item display:
   - If a WO references a material that's now discontinued and has a replacement specified, show a footnote "This material was replaced by X after this WO was completed" (audit context, not a problem to fix).

5. Estimate line item display:
   - If an estimate has a line referencing a discontinued material that has a replacement, show inline prompt "Use replacement instead?" with one-click swap.

6. Updated `__BOS_AI/Entities/material.md` with full `field_replaced_by` semantics.

7. Single commit: `feat(supplier-price-ingest): Phase 3.9 — replaced-by surfacing polish`

**Pause point:** Todd reviews discontinued-material UX end-to-end.

**Deployment readiness:** End of the UI bundle. **Second production deploy candidate.** After Todd's DDEV validation, the entire 3.7–3.9 bundle ships to live during an off-hours window.

---

## Phase 3.10 — End-to-End SiteOne Ingest in DDEV

**Goal:** Run the full pipeline against a real, full SiteOne catalog scrape in DDEV. Find bugs. Fix bugs.

**Deliverables:**

1. Catalog acquisition (Todd or apprentice):
   - Claude in Chrome session against SiteOne portal.
   - Scrape full catalog (or a substantial subset — e.g., irrigation + pvc + brass + galv as the first pass).
   - Save as CSV.

2. Create SiteOne `supplier_ingest_config` in DDEV with appropriate column mapping for the scraped CSV's actual columns.

3. Upload, parse, match, dry-run review, approve, commit.

4. Document all bugs found during the run.

5. Each bug fix is its own commit, prefixed `fix(supplier-price-ingest): ...`.

6. After fixes, re-run the ingest end-to-end and confirm clean execution.

7. Final commit: `chore(supplier-price-ingest): Phase 3.10 — end-to-end SiteOne ingest validation complete`

**Pause point:** Todd reviews the result. Inspects sample `material_suppliers` rows created. Inspects review queue entries. Confirms no catalog corruption.

**Deployment readiness:** Bug fixes from 3.10 ship as a hotfix bundle on top of the previous live deploy.

---

## Phase 3.11 — First Production SiteOne Ingest

**Goal:** First real production ingest. Controlled conditions. Reversibility plan in place.

**Deliverables:**

1. Pre-deploy database snapshot of live (full backup).

2. Deploy bug fix bundle from 3.10 to live during off-hours window.

3. First live ingest:
   - Office Manager (or Todd) uploads the same CSV used in 3.10.
   - Reviews dry-run report carefully.
   - Approves.
   - Monitors commit progress.

4. Post-commit verification:
   - Spot-check 20 randomly-selected `material_suppliers` rows created.
   - Verify `material_price_history` audit entries.
   - Check `/admin/materials/price-review` for any flagged entries.
   - Run a sample estimate workflow to confirm estimator surface still works correctly.

5. If anything looks wrong:
   - Reject any flagged price review entries until investigated.
   - Worst case: restore from pre-deploy snapshot.

6. Document the run in `__BOS_AI/Reports/first_siteone_ingest_YYYY-MM-DD.md`:
   - Rows imported.
   - Match tier breakdown.
   - Time elapsed per stage.
   - Issues encountered.
   - Discovery queue state post-import.

7. No new code commits in this phase — production exercise only.

**Pause point:** Wait at least 48 hours after ingest before initiating any further changes. Watch for any issues surfaced by estimators, crews, or Office Manager.

**Deployment readiness:** Live is now running the full pipeline.

---

## Phase 3.12 — SOP Authoring

**Goal:** All workflows documented per GOV-SOP-001 dual-format standard.

**Deliverables:** The 7 SOPs identified in Phase 2 §11.

Each SOP authored in its own focused session with Claude Chat (not Code), following the SOP authoring workflow. Each delivered in dual format (`.docx` + paste-ready HTML).

Sequence (sustainable cadence — one SOP per week is fine):

1. SOP: Supplier Catalog Acquisition (apprentice/office)
2. SOP: Supplier Price Ingest — Upload and Review (Office Manager)
3. SOP: Discovery Queue Resolution (Office Manager)
4. SOP: Fuzzy Match Review (Office Manager)
5. SOP: Estimator — Refreshing Material Prices on Open Estimates (Estimators)
6. SOP: Discontinued Materials — Marking and Replacement Spec (Office Manager)
7. SOP: Supplier Catalog Cleanup — Apprentice Bootstrap (Apprentice)

Each SOP gets a draft → review → approve cycle. `field_sop_code` immutable once approved.

---

## Summary of Deploy Bundles

| Bundle | Phases | Risk | Recommended deploy window |
|---|---|---|---|
| Schema | 3.1 | Low | Any time |
| Logic | 3.2–3.6 | Medium | Off-hours, quiet window |
| UI | 3.7–3.9 | Low (no business logic changes) | Off-hours |
| Hotfixes | 3.10 results | Medium | Off-hours, after DDEV validation |
| Production exercise | 3.11 | High (first live run) | Weekend morning, full attention available |

---

## What Todd Decides at Each Pause Point

- "Does this work?" — functional check.
- "Is this the right behavior?" — architectural check.
- "Should we ship this now or hold?" — deploy decision.
- "Anything to adjust before continuing?" — iteration.

A "go" at a pause point authorizes the next phase's Claude Code prompt. A "hold" pauses the build until concerns are resolved.

---

## What Claude (Chat, this role) Delivers Between Phases

- The next phase's Claude Code prompt (production-grade, complete, copy-paste-ready).
- Any architectural clarifications surfaced by the previous phase.
- Updated governance docs (if changes were structural).
- A clear summary of what's in the prompt and what to watch for during review.

---

## End of Phase 3 Sequencing
