# BOS Entity — supplier_price_ingest_row

Entity Type ID: `supplier_price_ingest_row`
Bundle: `row` (single bundle)
Storage: ECK
Module: `supplier_price_ingest` (Phase 3.1, 2026-05-25)

---

## Purpose

One record per row of a supplier-price-ingest source file. Created during the **Parse** stage (Phase 3.2), persisted for audit, mutated through the **Match** and **Resolve** stages, then persisted again at **Commit** with `field_row_status = committed`.

Rows are the unit of matchability — each one carries both the parsed source data (the supplier's view of an item) and the matched result (which BOS material it maps to, with what confidence, by which tier).

**Volume note:** a single batch can create thousands of rows. A 4,000-line SiteOne ingest creates 4,000 row entities. This is intentional and not a concern — ECK handles the volume comfortably and the audit-trail value is high. Periodic archival can be added later if storage pressure emerges.

---

## Required Relationships

| Field | Target | Cardinality | Notes |
|---|---|---:|---|
| `field_batch` | `supplier_price_ingest_batch` (bundle: `batch`) | 1 | Parent batch. Required and immutable. |
| `field_row_number` | integer | 1 | 1-based row index in the source file (after header). |
| `field_raw_data` | text_long (JSON) | 1 | Original CSV row encoded as JSON. **Immutable post-creation.** |
| `field_row_status` | list_string | 1 | Lifecycle state. Default `dry_run`. |

---

## Key Fields

### Parsed cells (from source CSV)

All optional — some supplier feeds omit fields:

- `field_supplier_sku` (string) — vendor's own SKU.
- `field_manufacturer_item_number` (string) — manufacturer part number.
- `field_manufacturer_name` (string) — manufacturer name as printed in the CSV. **String, not a reference** — we record what the CSV said, not what BOS thinks the manufacturer is.
- `field_description` (text) — item description.
- `field_unit_cost` (decimal 10,2) — unit cost.
- `field_cost_uom` (list_string) — `each` / `case` / `box` / `bag` / `roll`. Mirrors `material_suppliers.field_cost_unit_of_measure` allowed values.
- `field_pack_quantity` (integer) — pack qty when present.

### Match result (populated by the matcher service, Phase 3.2)

- `field_match_tier` (list_string) — what tier matched (or skipped):
  - `tier_1_mfr` — matched on manufacturer item number.
  - `tier_2_supplier_sku` — matched on existing supplier SKU.
  - `tier_3_fuzzy_high` — fuzzy match above the high threshold (auto-apply).
  - `tier_3_fuzzy_med` — fuzzy match above the medium threshold (review queue).
  - `tier_3_fuzzy_low` — fuzzy match below the medium threshold (discovery queue).
  - `discovery` — no match found.
  - `skipped_discontinued` — matched but target material is discontinued.
  - `skipped_do_not_use` — matched but target supplier-link is marked do-not-use.
  - `skipped_excluded_bundle` — bundle policy in `supplier_ingest_config.field_bundle_policy` excludes this bundle.
  - `error` — matcher threw an exception on this row; see `field_resolution_notes`.
- `field_match_confidence` (decimal 5,2) — 0.00 – 100.00. Always populated for fuzzy tiers; populated as 100.00 for tier_1 / tier_2.
- `field_matched_material` (entity_reference → material, all bundles) — the BOS material this row maps to. NULL for `discovery` and the `skipped_*` / `error` tiers.
- `field_existing_link` (entity_reference → material_suppliers, bundle: supplier) — the existing `material_suppliers` row for `(matched_material × batch's supplier)` if one already exists. NULL if a new link will be created at commit.

### Resolution (set when an office-manager intervenes)

- `field_row_status` (list_string) — `dry_run` / `committed` / `discovery_pending` / `discovery_resolved` / `rejected` / `error`.
- `field_resolution_action` (list_string) — what the resolver did:
  - `created_link` — new `material_suppliers` row was written.
  - `updated_link` — existing `material_suppliers` row was updated.
  - `created_new_material_and_link` — discovery row promoted to a new material + new link.
  - `linked_to_existing_material` — discovery row mapped to an existing material the matcher missed.
  - `marked_as_replacement` — row's source item was identified as a replacement for an existing (discontinued) material; populated `material.field_replaced_by`.
  - `rejected` — explicit reject; no catalog mutation.
  - `noop` — explicit no-op (e.g., price unchanged, nothing to do).
- `field_resolution_notes` (text_long) — free-text reviewer notes.
- `field_resolved_by` (entity_reference → user) — reviewer.
- `field_resolved_on` (datetime) — when resolved.

---

## Invariants (Non-Negotiable)

1. **`field_raw_data` is immutable post-creation.** It captures the source-of-truth as the CSV said it. Editing it would falsify the audit chain. Phase 3.2 presave hook enforces.
2. **`field_batch` is immutable post-creation.** Once a row is created under a batch, it stays with that batch.
3. **`field_row_number` is immutable post-creation.** Position in source file is part of the audit identity.
4. **Row status transitions are forward-only for terminal states.** `committed`, `rejected`, and `discovery_resolved` are end states. Once a row is committed it does not return to `dry_run`. `discovery_pending → discovery_resolved` is the standard resolution path.
5. **No cascade-on-batch-delete in Phase 3.1.** A batch delete in Phase 3.1 leaves orphan rows. Phase 3.2 will introduce a cascade for non-committed batches. (Committed batches don't delete — see batch entity invariants.)

---

## Deletion / Archival

- **Committed rows:** never delete in normal operation. They're audit chain.
- **Dry-run / rejected / error rows:** safe to delete via admin. Most often this happens implicitly when their parent batch is deleted (once cascade lands in 3.2).
- No URL alias for rows (`supplier_price_ingest_row` is deliberately not in `pathauto.settings.enabled_entity_types`) — rows are accessed via the parent batch's view, not via direct routes.

---

## Form / View Display

Form: 19 components, grouped roughly: batch reference + row number + status → parsed cells → match result → resolution → raw data (audit dump at the bottom). No tab structure in Phase 3.1.

View: same 19 components, mostly inline labels. The Phase 3.4 batch view aggregates rows in a custom view rather than rendering each row's default view display.

---

## Pathauto

**NOT registered** in `pathauto.settings.enabled_entity_types` by deliberate choice. Rows are accessed via the parent batch view (Phase 3.4), not via direct URL aliases. There would be thousands of these per batch — generating aliases for each is wasted I/O.

---

## Permissions

`administer supplier price ingest` (defined by the `supplier_price_ingest` module).

---

## Related Entities

- `supplier_price_ingest_batch` — parent.
- `supplier_ingest_config` — per-supplier config consulted at parse and match time.
- `material` — matched-material reference target.
- `material_suppliers` — existing-link reference target; mutated at commit time.
- `material_price_history` — feed-import entries written at commit time link back to the batch (not directly to rows) via `field_ingest_batch`.
