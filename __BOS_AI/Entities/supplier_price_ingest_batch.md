# BOS Entity — supplier_price_ingest_batch

Entity Type ID: `supplier_price_ingest_batch`
Bundle: `batch` (single bundle)
Storage: ECK
Module: `supplier_price_ingest` (Phase 3.1, 2026-05-25)

---

## Purpose

One record per supplier-price-ingest run. Represents the **act** of importing a CSV/XLSX file from a single supplier. The batch is the audit anchor for everything downstream: child `supplier_price_ingest_row` entities, the resulting `material_price_history` entries, and the eventual catalog mutations on `material_suppliers`.

A batch is created at upload time, moves through the five-stage pipeline (parse → match → dry-run report → approve → commit), and is permanent after commit. Even rejected and failed batches are retained as part of the audit record.

---

## Required Relationships

| Field | Target | Cardinality | Notes |
|---|---|---:|---|
| `field_supplier` | `supplier` (bundle: `supplier`) | 1 | The vendor whose price feed this batch came from. |
| `field_uploaded_by` | `user` | 1 | Who initiated the upload. |
| `field_uploaded_on` | datetime | 1 | When uploaded. |

A batch never aggregates rows from more than one supplier. If a file contains items from multiple manufacturers, that's expected — the batch is keyed to the supplier (vendor) who provided the file.

Children: `supplier_price_ingest_row` entities reference this batch via their `field_batch`.

---

## Key Fields

### Source

- `field_source_file` (file) — uploaded CSV/XLSX. `csv xls xlsx`, 50 MB max. `public://supplier_ingest/`.
- `field_source_filename` (string) — original filename, preserved for audit.

### Status

- `field_status` (list_string) — current pipeline stage. Allowed values, in lifecycle order:
  - `pending_dry_run` — created at upload time; parsing has not begun. **Phase 3.2: also the status AFTER parse completes** — the parser does not advance the status, intentionally. Matcher (3.3) owns the `pending_dry_run → dry_run_complete` transition.
  - `dry_run_complete` — parse + match passes finished; reviewer can inspect the report. **Reachable as of Phase 3.3** — the matcher transitions the batch here at the end of a successful run (including the `do_not_use` supplier short-circuit, which produces an empty-result dry-run still routed through `dry_run_complete` so the office can see the outcome). Aggregate row counts (`field_row_count_tier1`, `field_row_count_tier2`, `field_row_count_tier3_med`, `field_row_count_discovery`, `field_row_count_skipped`) are populated by the matcher from the persisted row entities at this transition.
  - `awaiting_approval` — reviewer has reviewed and queued for commit but not yet approved.
  - `approved` — reviewer has approved; commit is scheduled.
  - `committed` — pipeline has mutated `material_suppliers` and written `material_price_history` rows.
  - `rejected` — reviewer rejected the batch; no catalog mutations occurred.
  - `failed` — pipeline error during parse, match, or commit; details in `field_dry_run_report` or watchdog. **Reachable as of Phase 3.2** — the parser transitions to `failed` on any unrecoverable error (file unreadable, config missing, etc.) and stashes the error context into `field_dry_run_report` as JSON.

### Dry-run report

- `field_dry_run_report` (text_long) — JSON payload summarizing the dry-run pass.
  - **Phase 3.2:** when batch transitions to `failed`, the parser writes a JSON object with `fatal_error`, `created_so_far`, `skipped_so_far`, `errored_so_far`, and `parse_errors[]` (per-row diagnostic notes). Surfaced verbatim in the placeholder batch view's "Parse Failure Detail" pane.
  - **Phase 3.5:** the schema expands to cover the full dry-run report (per-tier counts, sample rows, match-confidence distribution) once the dry-run reporter service ships. Not a stable wire format until then.

### Commit metadata

- `field_committed_by` (entity_reference → user) — who approved + triggered commit.
- `field_committed_on` (datetime) — when committed.

### Aggregate row counts

In Phase 3.2 only `field_row_count_total` and `field_row_count_skipped` are populated by the parser (per upload). Tier counts (`field_row_count_tier1` etc.) and `field_row_count_discovery` default 0; the matcher fills them in 3.3.

- `field_row_count_total` (integer)
- `field_row_count_tier1` (integer) — manufacturer-item-# matches.
- `field_row_count_tier2` (integer) — existing supplier SKU matches.
- `field_row_count_tier3_high` (integer) — fuzzy matches above the high threshold.
- `field_row_count_tier3_med` (integer) — fuzzy matches above the medium threshold but below high.
- `field_row_count_discovery` (integer) — no match found.
- `field_row_count_skipped` (integer) — skipped (discontinued, do_not_use, excluded bundle, etc.).

### Office notes

- `field_notes` (text_long) — free-text office annotations.

---

## Invariants (Non-Negotiable)

1. **Committed batches are never deleted.** Once `field_status = committed`, the batch is part of the audit chain — every `material_price_history` row with `field_source` of `feed_import_*` references it via `field_ingest_batch`. Deletion would orphan those rows.
2. **Status transitions are forward-only.** A `committed` batch cannot return to `dry_run_complete`. A `rejected` batch cannot become `committed`. A `failed` batch can be retried by uploading a new file (a new batch entity); it does not revert in place. Phase 3.2 enforces this in presave.
3. **One supplier per batch.** `field_supplier` is required and immutable post-creation.
4. **`field_supplier` cannot change.** Once set, attempts to overwrite during update are rejected by Phase 3.2 presave (forthcoming).
5. **Row counts are computed, not authoritative.** They reflect the matcher's run output; they're not the source of truth for any downstream calculation. If you need exact counts, query the `supplier_price_ingest_row` entities for the batch.

---

## Deletion / Archival

- **Committed batches:** never delete. If a batch's data is no longer useful for ad-hoc reporting, it remains in the audit table indefinitely.
- **Pending / rejected / failed batches:** safe to delete via admin only. Use cases: bad upload, duplicate accidental upload, test data. Phase 3.5 may add an admin tool for this; for Phase 3.1 it's a manual operation through the entity edit page.
- **Cascade:** deleting a batch does NOT cascade to its `supplier_price_ingest_row` children automatically — the rows reference the batch via `field_batch` but their deletion is a separate decision. (Forthcoming Phase 3.2 hook will likely cascade row delete on batch delete, but that's not in 3.1.)

---

## Form / View Display

Form display (default): all 17 visible fields in groups roughly: supplier + file + uploader → status → row counts → commit metadata → dry-run report + notes. No tab structure in Phase 3.1.

View display (default): all 17 fields visible, mostly inline labels, dry-run report + notes rendered as text blocks.

Discovery dashboards and the dedicated Batch Manager view (`/admin/materials/supplier-ingest/batches`) land in Phase 3.4.

---

## Pathauto

Pattern: `/admin/materials/supplier-ingest/batch/[supplier_price_ingest_batch:id]`

---

## Permissions

`administer supplier price ingest` (defined by the `supplier_price_ingest` module) is the single permission gating all CRUD on this entity. Role grants ship in Phase 3.3+ alongside the upload UI.

---

## Related Entities

- `supplier_price_ingest_row` — child rows of this batch.
- `supplier_ingest_config` — per-supplier configuration consulted during parse and match.
- `material_price_history` — feed-import entries reference this batch via `field_ingest_batch`.
- `material_suppliers` — mutated at commit time (catalog updates).
