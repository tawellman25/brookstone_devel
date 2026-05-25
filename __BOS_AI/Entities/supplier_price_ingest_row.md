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

### Match result (populated by the matcher service)

- `field_match_tier` (list_string) — terminal disposition of the row after all matchers run. Each value is produced by a specific phase:

| Value | Meaning | Produced by |
|---|---|---|
| `tier_1_mfr` | Direct match on `(manufacturer, manufacturer_item_number)`. Confidence 100; or 95 after discontinued retargeting. | Phase 3.3 |
| `tier_2_supplier_sku` | Direct match on existing `material_suppliers` `(supplier, supplier_item_number)`. Confidence 100; or 95 after discontinued retargeting. | Phase 3.3 |
| `tier_3_fuzzy_high` | Fuzzy match above the high threshold; auto-apply at commit. | Phase 3.4 |
| `tier_3_fuzzy_med` | Fuzzy match above the medium threshold → office review queue. **In Phase 3.3** this bucket is also where Tier 1 ambiguous matches (multiple BOS materials with the same `mfr+item#`) and the defensive Tier 2 multi-match case land — they share the same review surface. | Phase 3.3 + 3.4 |
| `tier_3_fuzzy_low` | Fuzzy match below medium threshold → discovery queue (treated as discovery for routing purposes; the matcher does not auto-apply). | Phase 3.4 |
| `discovery` | No Tier 1 / Tier 2 match AND the supplier has at least one discovery-enabled bundle. Office reviewer routes manually. | Phase 3.3 |
| `skipped_discontinued` | Matched a discontinued material with no `field_replaced_by`. `field_matched_material` is NULL — the discontinued one isn't the right answer, but there's no replacement to point at. Note prompts reviewer to consider setting `field_replaced_by` on the discontinued material if this row is a replacement candidate. | Phase 3.3 |
| `skipped_do_not_use` | Supplier on the batch is marked `field_supplier_status = 'do_not_use'`. The matcher short-circuits — no rows in the batch are matched. | Phase 3.3 |
| `skipped_excluded_bundle` | Either (a) the matched material's bundle has policy `excluded` for this supplier, or (b) no Tier 1 / Tier 2 match AND the supplier has zero discovery-enabled bundles so unmatched rows can't be routed to discovery. | Phase 3.3 |
| `error` | Row failed to parse cleanly OR the matcher threw an uncaught exception on this row. See `field_resolution_notes` for the reason. Parser produces this for: cost not a valid decimal after `$/,/space` stripping; non-empty UOM not in allowed_values (Phase 3.3 strictness — UOM is no longer silently defaulted); JSON encode failure on raw row data; uncaught throw during row save. Rows with `match_tier='error'` ARE persisted for audit but the matcher (3.3) and commit (3.6) skip them. | Phase 3.2 (parser) + Phase 3.3 (matcher) |

#### `field_match_confidence` convention (decimal 5,2)

| Value | Meaning | Phase |
|---:|---|---|
| NULL | No match attempted (parser-errored rows; rows pending match). | 3.2 / 3.3 |
| 0 | Discovery — no candidate found, awaiting human routing. | 3.3 |
| 50 | Tier 1 ambiguous OR Tier 2 defensive multi-match — routed to `tier_3_fuzzy_med` for human resolution. Confidence reflects "we found candidates but can't pick one." | 3.3 |
| 70–89 | Tier 3 medium confidence — fuzzy match above medium threshold but below high. Routes to office review. | 3.4 |
| 90–100 | Tier 3 high confidence — fuzzy match above high threshold, auto-apply at commit. | 3.4 |
| 95 | Tier 1 or Tier 2 direct match retargeted through `material.field_replaced_by` (structural inference, slight discount from 100). The original (discontinued) material's ID is recorded in `field_resolution_notes`. | 3.3 |
| 100 | Tier 1 or Tier 2 unambiguous direct match. | 3.3 |
| 1–69 (non-zero, non-NULL) | Tier 3 low confidence — fuzzy match below the medium threshold. Routes to discovery alongside no-candidate rows. | 3.4 |

- `field_matched_material` (entity_reference → material, all bundles) — the BOS material this row maps to. NULL for `discovery` and the `skipped_*` / `error` tiers.
- `field_existing_link` (entity_reference → material_suppliers, bundle: supplier) — the existing `material_suppliers` row for `(matched_material × batch's supplier)` if one already exists. NULL if a new link will be created at commit. Phase 3.3 sets this for Tier 2 matches.

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
