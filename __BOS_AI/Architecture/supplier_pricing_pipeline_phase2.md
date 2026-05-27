# Supplier Pricing Pipeline — Phase 2 Architecture

**Status:** Draft for review.
**Author:** Claude (architect role).
**Date:** 2026-05-24.
**Predecessors:** Two diagnostic reports (`sku_fill_report_2026-05-24.md`, `manufacturer_fill_report_2026-05-24.md`). Conversation log preserved in chat history.

---

## 1. Architectural Frame

### 1.1 The reframing

This is not a "price update pipeline for an already-populated catalog." The `material_suppliers` table holds only 283 rows for 5 suppliers against a 2,930-entry catalog and 15+ in-scope bundles. The two highest-priority suppliers (SiteOne, Denver Brass) have zero links.

This is a **catalog-population engine that maintains itself through recurring ingest**. The first ingest from each priority supplier creates the bulk of that supplier's `material_suppliers` links. Subsequent ingests update prices on those links and surface new products as they appear.

### 1.2 Why this works given current catalog state

The manufacturer-item-number diagnostic confirmed **100% bridge coverage** across the four priority bundles (irrigation, pvc, galv, brass). Every material has at least one identifying field populated. The pipeline does not need to start cold — it just needs to use the right match keys in the right order.

### 1.3 What we keep from existing infrastructure

- `material_suppliers` entity (junction with per-vendor cost, UOM, SKU, ordering mechanics) — unchanged.
- `material.module` MAX-cost auto-sync — unchanged; new pipeline writes to `material_suppliers` and lets existing sync handle the rest.
- `material_price_history` audit log — extended with two new `field_source` values.
- `wo_material_price_sync` review queue at `/admin/materials/price-review` — extended to receive entries from the new pipeline.
- `PriceSyncService` — reused as the catalog-mutation layer; the new ingest pipeline calls it.

The new pipeline does **not** replace any of this. It feeds the existing review/audit infrastructure with a new source of events.

---

## 2. Data Model Additions

### 2.1 New entity: `supplier_price_ingest_batch`

ECK entity. One record per ingest run. Represents the act of importing a CSV from a supplier.

| Field | Type | Purpose |
|---|---|---|
| `field_supplier` | entity_reference → supplier | Which supplier this batch is from |
| `field_source_file` | file | The uploaded CSV/XLSX |
| `field_source_filename` | string | Original filename for audit |
| `field_uploaded_by` | entity_reference → user | Who uploaded |
| `field_uploaded_on` | datetime | When uploaded |
| `field_status` | list_string | `pending_dry_run`, `dry_run_complete`, `awaiting_approval`, `approved`, `committed`, `rejected`, `failed` |
| `field_dry_run_report` | text_long | JSON or structured text of the dry-run match results |
| `field_committed_by` | entity_reference → user | Who approved & committed |
| `field_committed_on` | datetime | When committed |
| `field_row_count_total` | integer | Total rows in source |
| `field_row_count_tier1` | integer | Auto-matched on manufacturer item # |
| `field_row_count_tier2` | integer | Auto-matched on existing supplier SKU |
| `field_row_count_tier3_high` | integer | Fuzzy matched, high confidence (auto-applied) |
| `field_row_count_tier3_med` | integer | Fuzzy matched, medium confidence (review queue) |
| `field_row_count_discovery` | integer | No match — discovery queue |
| `field_row_count_skipped` | integer | Skipped due to discontinued, do_not_use status, etc. |
| `field_notes` | text_long | Office Manager notes on the batch |

**Bundle:** `batch` (single bundle).
**Path alias pattern:** `/admin/materials/supplier-ingest/batch/{id}`.

### 2.2 New entity: `supplier_price_ingest_row`

ECK entity. One record per row in the source CSV. Created during dry-run, persisted for audit.

| Field | Type | Purpose |
|---|---|---|
| `field_batch` | entity_reference → supplier_price_ingest_batch | Parent batch |
| `field_row_number` | integer | Row index in source file (for traceability) |
| `field_raw_data` | text_long | Original CSV row as JSON |
| `field_supplier_sku` | string | Parsed supplier SKU from row |
| `field_manufacturer_item_number` | string | Parsed manufacturer item # from row |
| `field_manufacturer_name` | string | Parsed manufacturer name from row (string, not reference) |
| `field_description` | text | Parsed item description |
| `field_unit_cost` | decimal(10,2) | Parsed unit cost |
| `field_cost_uom` | list_string | Parsed UOM (each, case, box, bag, roll, etc.) |
| `field_pack_quantity` | integer | Parsed pack qty if present |
| `field_match_tier` | list_string | `tier_1_mfr`, `tier_2_supplier_sku`, `tier_3_fuzzy_high`, `tier_3_fuzzy_med`, `tier_3_fuzzy_low`, `discovery`, `skipped_discontinued`, `skipped_do_not_use`, `error` |
| `field_match_confidence` | decimal(5,2) | 0.00–100.00 confidence score |
| `field_matched_material` | entity_reference → material | The material this row matched (NULL for discovery) |
| `field_existing_link` | entity_reference → material_suppliers | The existing `material_suppliers` row if found (NULL if new link will be created) |
| `field_row_status` | list_string | `dry_run`, `committed`, `discovery_pending`, `discovery_resolved`, `rejected` |
| `field_resolution_notes` | text_long | Office Manager notes when resolving a discovery or fuzzy-match row |
| `field_resolution_action` | list_string | `created_link`, `updated_link`, `created_new_material_and_link`, `linked_to_existing_material`, `rejected`, `noop` |
| `field_resolved_by` | entity_reference → user | Reviewer |
| `field_resolved_on` | datetime | When resolved |

**Bundle:** `row` (single bundle).
**Volume:** Each batch creates one row per source line. A 4,000-row SiteOne ingest = 4,000 entities. This is intentional and not a concern — ECK handles this volume comfortably and the audit trail value is high. Periodic archival can be added later if needed.

### 2.3 New entity: `supplier_ingest_config`

ECK entity. Per-supplier configuration controlling how their CSVs are parsed and routed.

| Field | Type | Purpose |
|---|---|---|
| `field_supplier` | entity_reference → supplier | Which supplier this config applies to |
| `field_active` | boolean | Whether this supplier is currently importable |
| `field_column_mapping` | text_long | JSON: maps CSV column headers → BOS field names |
| `field_default_cost_uom` | list_string | Default UOM if not in source |
| `field_bundle_policy` | text_long | JSON: per-bundle policy (matched_only / discovery / both / excluded) |
| `field_fuzzy_match_threshold_high` | decimal(5,2) | Confidence ≥ this auto-applies (default: 90.0) |
| `field_fuzzy_match_threshold_med` | decimal(5,2) | Confidence ≥ this goes to review (default: 70.0) |
| `field_notes` | text_long | Free-text per-supplier ingest notes |

**Bundle:** `config` (single bundle).
**Uniqueness invariant:** one config per supplier. Enforced in module presave.

### 2.4 New field on `material`: `field_replaced_by`

Self-referential entity reference. Points from a discontinued material to its current-generation equivalent.

- Type: `entity_reference` → `material`
- Cardinality: 1
- Present on bundles: all hard-goods + plant bundles where discontinuation is a real concept (irrigation, pvc, brass, copper, galv, electric, poly, pumps, backflow, landscape, pavers, supplies, xmas, plants, shrubs, trees, annuals — basically all except bulk_material/mulch/decorative_rock/sod).
- Display: surfaced on material view page as "Replaced by: [link]" when populated. Surfaced on estimate line item add-form as a warning ("This material is discontinued — current replacement is X. Use replacement instead?") when an estimator selects a discontinued material.

**Backfill:** Empty on day one. Apprentice and office staff populate it over time as they encounter discontinued items. Phase 2 ships the field and the surfacing UI; the apprentice's eventual catalog cleanup work fills it in.

### 2.5 Extensions to `material_price_history`

Add two values to `field_source`:

- `feed_import_auto` — applied by ingest pipeline without review (Tier 1, Tier 2, or Tier 3 high-confidence).
- `feed_import_reviewed` — applied by ingest pipeline after Office Manager review (Tier 3 medium-confidence or discovery resolution).

Add reference field:

- `field_ingest_batch` → `supplier_price_ingest_batch` (NULL except for ingest-originated entries) — traces which batch produced each history entry.

No other changes to the price history entity. Existing decision tree continues to work.

### 2.6 Extensions to `material_suppliers`

No new fields. The entity is already sufficient. The pipeline writes to existing fields.

### 2.7 New permission

`administer supplier price ingest` — required to upload CSVs, view dry-run reports, approve commits, resolve discovery rows. Initially granted to `administration` role (same role that gets `review material price changes`).

---

## 3. Ingest Service Architecture

### 3.1 The five-stage pipeline

A CSV from a supplier moves through five stages. Each stage has a clear precondition and postcondition. The pipeline can pause at any stage boundary.

```
[Upload]
   ↓
[Stage 1: Parse]         CSV → supplier_price_ingest_row entities (status: dry_run)
   ↓
[Stage 2: Match]         Each row tagged with match tier + confidence + target material/link
   ↓
[Stage 3: Dry-Run Report]  Batch status: dry_run_complete. Reviewer sees the proposed actions.
   ↓
[Stage 4: Approve]       Reviewer approves the batch. Batch status: approved.
   ↓
[Stage 5: Commit]        Pipeline mutates material_suppliers, writes material_price_history.
                         Batch status: committed.
```

### 3.2 Stage 1: Parse

**Input:** Uploaded CSV/XLSX file + `supplier_ingest_config.field_column_mapping`.

**Process:**
1. Open file, validate it's a readable CSV or XLSX.
2. Read header row. Validate against the supplier's column mapping.
3. For each data row, extract: supplier SKU, manufacturer item #, manufacturer name, description, unit cost, cost UOM, pack quantity.
4. Create one `supplier_price_ingest_row` per CSV row with `field_row_status = 'dry_run'`.
5. Skip rows missing any of (supplier SKU OR manufacturer item # OR description) AND missing unit cost — these are unusable.

**Output:** N `supplier_price_ingest_row` entities, batch status = `pending_dry_run`.

**Service:** `Drupal\supplier_price_ingest\Service\IngestParser`.

### 3.3 Stage 2: Match (the cascading matcher)

**Input:** All `supplier_price_ingest_row` entities for a batch.

For each row, attempt match tiers in order. Stop at first match.

#### Tier 1 — Manufacturer Item Number Match

**Precondition:** Row has non-empty `field_manufacturer_item_number` AND `field_manufacturer_name`.

**Process:**
1. Resolve the manufacturer name to a manufacturer entity (case-insensitive title match). If no match, skip tier.
2. Query for any `material` entity where `field_manufacturer = matched_mfr_entity` AND `field_manufacturer_item_number = row_mfr_item_number` (trimmed, case-insensitive).
3. If exactly one match → Tier 1 hit, confidence = 100.
4. If multiple matches → Tier 1 ambiguous, confidence = 50, route to medium-confidence review.
5. If zero matches → fall through to Tier 2.

**Discontinued handling:** If matched material has `field_discontinued = TRUE`:
- If `field_replaced_by` is set → re-target the match to the replacement material, confidence = 95 (slight discount because the substitution is structural inference).
- If `field_replaced_by` is empty → flag as `skipped_discontinued`, do not match, do not write. Surface in dry-run report as: *"Row {N} matches discontinued material {X} (no replacement defined). Suggest reviewing whether SiteOne row {description} is a replacement."*

#### Tier 2 — Existing material_suppliers SKU Match

**Precondition:** Row has non-empty supplier SKU.

**Process:**
1. Query `material_suppliers` for any row where `field_supplier = batch_supplier` AND `field_supplier_item_number = row_supplier_sku` (trimmed, case-insensitive).
2. If exactly one match → Tier 2 hit, confidence = 100. Target = that `material_suppliers` row's material. `field_existing_link` = that row.
3. If multiple matches → impossible by design (`material_suppliers` has unique constraint on `(material, supplier)`); but defensively, treat as ambiguous, confidence = 50, route to medium-confidence review.
4. If zero matches → fall through to Tier 3.

This tier becomes more useful over time as ingests populate the catalog. On the first SiteOne ingest, it will hit zero. By the third ingest, it will catch most rows.

**Discontinued handling:** Same as Tier 1.

#### Tier 3 — Fuzzy Description + UOM + Bundle Match

**Precondition:** Always attempted as fallback. Confidence-scored.

**Process:**
1. Determine the **candidate target bundle** for this row. Algorithm:
   - If row description contains strong bundle signal keywords (e.g., "PVC tee", "brass nipple", "rotor sprinkler"), assign to that bundle.
   - If unclear, attempt match against multiple plausible bundles.
   - Bundle policy from `supplier_ingest_config.field_bundle_policy` determines whether to attempt match against this bundle at all (matched_only / discovery / both / excluded).
2. Within the candidate bundle(s), score each existing material against the row using:
   - **Description token overlap** (weighted Jaccard similarity on tokenized descriptions, after normalizing whitespace, punctuation, common abbreviations like `"1/2\""` ↔ `"1/2 in"`).
   - **UOM match** (exact = +20 points, compatible = +10, mismatch = -10).
   - **Size match** (extract sizes from description with regex; exact size match = +30 points).
   - **Manufacturer match if present** (+25 points if both row and material have manufacturer set and they match, even if item number didn't match).
3. Sum to a confidence score, normalized 0–100.
4. Route by score:
   - ≥ supplier's `field_fuzzy_match_threshold_high` (default 90) → Tier 3 high-confidence. Auto-applied at commit.
   - ≥ supplier's `field_fuzzy_match_threshold_med` (default 70) → Tier 3 medium-confidence. Review queue.
   - < medium threshold → fall through to discovery.

**Discontinued handling:** Discontinued materials are excluded from fuzzy match candidate pool entirely. If the best fuzzy match would have been a discontinued material, the row falls to discovery — let the reviewer decide whether it's a replacement candidate.

#### Tier 4 — Discovery

**Precondition:** No higher tier matched, OR the supplier's bundle policy for the candidate bundle is `discovery`.

**Process:**
- `field_match_tier = 'discovery'`.
- `field_matched_material = NULL`.
- Confidence = 0.
- Row will be presented in the discovery queue for office staff to decide: create new material, link to existing material, or reject.

#### Skip cases

Some rows are skipped entirely (not matched, not discovered):

- Row's parsed supplier status (from row data, if present) = `discontinued` or `out_of_stock` → `skipped_discontinued`.
- Batch's supplier has `field_supplier_status = 'do_not_use'` → entire batch fails at upload, not at match.
- Row's parsed bundle isn't in the supplier's policy AND policy isn't `both` → `skipped_excluded_bundle`.

**Service:** `Drupal\supplier_price_ingest\Service\IngestMatcher`.

### 3.4 Stage 3: Dry-Run Report

**Input:** All matched/categorized rows in a batch.

**Output:** A structured report saved to `supplier_price_ingest_batch.field_dry_run_report` AND surfaced as an admin page at `/admin/materials/supplier-ingest/batch/{id}/dry-run`.

**Report contents:**

```
=== Ingest Batch #1247 — SiteOne (2026-06-15) ===
Source file: siteone_pricing_2026_06.csv (4,127 rows)
Uploaded by: Office Manager Sarah
Uploaded on: 2026-06-15 09:14

MATCH SUMMARY
─────────────────────────────────────────────────
Tier 1 (mfr item #):       2,341  ✓ will auto-apply
Tier 2 (supplier SKU):       456  ✓ will auto-apply
Tier 3 high confidence:      287  ✓ will auto-apply
Tier 3 medium (review):      198  ⚠ requires review
Discovery (new items):       612  ⚠ requires review
Skipped (discontinued):      133  → no action
Skipped (other):              45  → no action
Errors:                       55  → see error list
─────────────────────────────────────────────────
TOTAL ROWS:                4,127

PRICE CHANGE IMPACT (Tier 1 + 2 + 3-high only)
─────────────────────────────────────────────────
New supplier links to create:        2,847
Existing links to update with price changes:  237
  - within ±10% threshold:   189 ✓ will apply
  - >+10% (flagged for review):     48 ⚠ will queue in /admin/materials/price-review

SAMPLE — Tier 1 matches (10 of 2,341)
─────────────────────────────────────────────────
Row 12: Hunter PGP-04 → matches material "Hunter PGP-04 Rotor 4in"
        New price: $14.85/each. No existing SiteOne link — will create.
Row 13: Hunter PGP-ADJ → matches material "Hunter PGP Adjustable Rotor"
        New price: $16.20/each. No existing SiteOne link — will create.
...

SAMPLE — Tier 3 medium-confidence matches needing review (10 of 198)
─────────────────────────────────────────────────
Row 847: "1/2" Sch 40 PVC Tee Slip x Slip x Slip" → Tier 3 score 78
        Best candidate: "PVC Tee 1/2in Sch 40 SxSxS" (material #3421)
        Confidence: 78% (description token overlap 0.72, UOM match, size match)
        Action required: Confirm match or reject

DISCOVERY (612 rows) — top bundles
─────────────────────────────────────────────────
landscape:        287 rows (no match in BOS catalog)
bulk_material:    156 rows
decorative_rock:   89 rows
misc:              52 rows
pavers:            28 rows

[Approve and Commit] [Reject Batch] [Export Detailed Report]
```

### 3.5 Stage 4: Approve

**Input:** Reviewer clicks "Approve and Commit" on the dry-run report.

**Process:**
- Validate batch is in `dry_run_complete` status.
- Set batch status to `awaiting_approval`, then immediately `approved` on confirmation.
- Capture `field_committed_by`, `field_committed_on`.
- Trigger commit.

If reviewer clicks "Reject Batch":
- Set batch status to `rejected`.
- All `supplier_price_ingest_row` entities updated to `field_row_status = 'rejected'`.
- No mutations to `material_suppliers` or `material_price_history`.
- Batch and rows retained for audit.

### 3.6 Stage 5: Commit

**Input:** Approved batch.

**Process for each row in the batch (loop, with progress logging):**

| Row's match tier | Action |
|---|---|
| Tier 1 (mfr match, single, confidence 100) | Call `PriceSyncService::ingestRow($material, $supplier, $row_data, source='feed_import_auto')`. This either creates a new `material_suppliers` link (if none exists for the pair) or updates the existing one through the same decision tree as WO entries (≤10% applies, >10% flags for review). |
| Tier 1 ambiguous (multiple matches) | Already routed to review — should not reach commit phase in this batch. Skip. |
| Tier 2 (supplier SKU match) | Same as Tier 1 — call `PriceSyncService::ingestRow` with the existing material_suppliers row's material. |
| Tier 3 high-confidence | Same as Tier 1. |
| Tier 3 medium-confidence | Should not reach commit phase — these are routed to review queue and resolved through that workflow. Skip in commit loop. |
| Discovery | Should not reach commit phase — same as above. Skip. |
| Skipped | No action. |

For each row that *does* mutate, write a `material_price_history:entry` with:
- `field_source = 'feed_import_auto'` (or `'feed_import_reviewed'` if it came through a reviewed path)
- `field_ingest_batch` set to this batch
- All other history fields as currently populated by `PriceSyncService`

**Postcondition:** Batch status = `committed`. All committed rows have `field_row_status = 'committed'`.

**Service:** `Drupal\supplier_price_ingest\Service\IngestCommitter`.

### 3.7 PriceSyncService extensions

The existing `PriceSyncService` is the catalog-mutation authority. The new pipeline must call it, not write to `material_suppliers` directly.

**New public method:**

```php
public function ingestRow(
  MaterialInterface $material,
  SupplierInterface $supplier,
  array $row_data,
  string $source
): IngestRowResult;
```

**Behavior:**
- Find or create the `material_suppliers` row for `(material, supplier)`.
- If creating: populate from `$row_data` (cost, UOM, SKU, pack qty, mfr item # if material doesn't have one), status = `applied`.
- If updating: apply the same `THRESHOLD_PERCENT = 10.0` decision tree as WO-driven sync. ≤10% applies; >10% flags for review.
- Write `material_price_history:entry` with `field_source = $source`.
- Return result: `applied | flagged_high | auto_created | rejected | error` + reason.

This keeps the existing review queue at `/admin/materials/price-review` as the single, unified point of human oversight for catalog price changes — regardless of whether the change came from a WO entry or a feed ingest. **One review queue, two upstream sources.**

---

## 4. Office Manager Dashboards

Three new admin surfaces, plus extensions to one existing one.

### 4.1 Batch Manager — `/admin/materials/supplier-ingest/batches`

View listing all ingest batches. Columns: ID, Supplier, Status, Uploaded On, Uploaded By, Total Rows, Auto-applied, Pending Review, Discovery, Operations.

Operations per row: View Dry Run, Approve, Reject, View Committed, Re-run Matcher (rare admin action).

Filters: supplier, status, date range.

### 4.2 Discovery Queue — `/admin/materials/supplier-ingest/discovery`

View listing all `supplier_price_ingest_row` entities with `field_row_status = 'discovery_pending'`.

Columns: Batch, Supplier, Row #, Description, Supplier SKU, Mfr Item #, Mfr Name, Unit Cost, UOM, Suggested Bundle, Operations.

Operations per row:

- **Create New Material** — opens a pre-filled material creation form (bundle selected based on row analysis, fields pre-populated from row data). On save, auto-creates the `material_suppliers` link.
- **Link to Existing Material** — opens an autocomplete search. Reviewer selects a material, system creates the `material_suppliers` link.
- **Mark as Replacement** — opens an autocomplete to find a discontinued material; sets `field_replaced_by` on the discontinued material to point at either an existing material the reviewer specifies or a new one created from this row.
- **Reject** — row marked rejected, no mutation.

Sortable, filterable by bundle and supplier. Bulk actions: bulk-reject obvious junk rows.

### 4.3 Fuzzy Match Review — `/admin/materials/supplier-ingest/fuzzy-review`

View listing all rows where `field_match_tier = 'tier_3_fuzzy_med'` and `field_row_status = 'dry_run'` or `'discovery_pending'`.

Columns: Batch, Supplier, Row Description, Best Candidate Match, Confidence, Operations.

Operations:

- **Confirm Match** — accepts the proposed match. Commits as if it were Tier 1.
- **Override Match** — autocomplete to select a different material. Commits with the chosen material.
- **Send to Discovery** — treats as no match.
- **Reject** — same as discovery.

### 4.4 Existing Price Review Queue — `/admin/materials/price-review`

No UI changes. The queue now receives entries with `field_source = 'feed_import_auto'` or `'feed_import_reviewed'` (in addition to existing `wo_entry`, `invoice`, etc.). The existing approve/reject forms work unchanged because they operate on `material_price_history:entry` regardless of source.

One small enhancement worth adding: a source filter (currently the dashboard shows everything). With feed ingests, an Office Manager might want to filter to just feed-originated entries during a post-ingest review session.

---

## 5. Estimator-Facing Surface

### 5.1 The "Refresh Prices" button on the estimate page

Add a button to the estimate edit/view page: **Refresh Material Prices**.

**Behavior:**
1. Iterates over all `estimate_items:materials` rows for this estimate.
2. For each row, loads the referenced material's current `field_cost_integer`.
3. Compares to the line item's `field_unit_price`.
4. Shows a diff modal: "3 line items have catalog prices that have changed since this estimate was last priced. Review and update?"
5. Modal shows: Material name, current line price, current catalog price, delta %, checkbox to update.
6. Estimator selects which to update, clicks Apply.
7. Selected line items' `field_unit_price` is updated. Line totals and estimate total recompute via existing `estimate_items` module.

This is opt-in, per-line, never automatic. Accepted/converted estimates are read-only for this action (no refresh on locked estimates).

### 5.2 Discontinued material warning on estimate line add

When an estimator adds a material line item and selects a material with `field_discontinued = TRUE`:

- Form-level warning displayed: *"⚠ This material is discontinued."*
- If `field_replaced_by` is populated: *"Recommended replacement: [link to replacement material]. Use replacement instead?"* with a one-click button to swap the selection.
- Save is allowed (estimator may legitimately want to reference the old part for compatibility documentation), but the warning is logged on the estimate line as a soft flag.

### 5.3 Stale price indicator on material display

On the material view page, display the last `field_price_effective_date` from its highest-priority active supplier link. If older than 90 days, render in amber. If older than 180 days, render in red.

On estimate line items, when viewing the estimate, surface the same indicator next to each material name. Estimators see at a glance which line items are based on stale data.

---

## 6. Per-Supplier Bundle Policy Configuration

The default matrix locked in conversation (50-entry threshold) is the seed data. Stored in `supplier_ingest_config.field_bundle_policy` as JSON:

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

Policy values:
- `matched_only` — attempt Tier 1, 2, 3; unmatched rows are skipped (not sent to discovery). Reduces noise on mature bundles.
- `discovery` — attempt Tier 1, 2, 3; unmatched rows go to discovery queue.
- `both` — currently undefined behavior; reserved.
- `excluded` — rows targeting this bundle are dropped at Stage 1 (don't even create ingest rows). Used for plants/shrubs/trees/etc.

Per-supplier overrides supported. SiteOne might have `landscape: discovery` while a Denver-Brass-only supplier might have `landscape: excluded` because they don't carry it.

The bundle determination happens during Stage 2 fuzzy matching (Tier 3 bundle inference). Tier 1 and Tier 2 matches inherit the bundle from the matched material.

---

## 7. Risks and Trade-offs

### 7.1 Risk: Bad data in a supplier CSV pollutes the catalog

**Mitigation:** The dry-run + approval gate is the primary defense. No batch ever auto-commits — every batch requires a human review.

**Secondary defense:** The existing >10% price-change review gate in `PriceSyncService` catches anomalous price moves even within an approved batch. If SiteOne accidentally sends a Hunter rotor at $1.50 instead of $15.00, the >10% gate flags it for separate review.

**Residual risk:** A reviewer rubber-stamps a batch without scrutiny. Operational discipline. SOP authoring will need to make the review responsibilities clear.

### 7.2 Risk: First SiteOne ingest creates duplicates if catalog has near-duplicate materials

**Mitigation:** Tier 1 (manufacturer item number) is exact-match — duplicates only occur if BOS has two materials with the same manufacturer + item #, which is itself a catalog defect to be cleaned up. Tier 2 (supplier SKU) has the same exactness. Tier 3 fuzzy matching could produce false positives, which is why it routes to review at medium confidence.

**Tooling addition:** A post-Phase-2 nice-to-have — a "potential duplicate material" diagnostic that finds materials with identical manufacturer + item # in BOS. Apprentice cleanup task.

### 7.3 Risk: Catalog acquisition via Claude in Chrome is fragile

**Reality:** Confirmed by you. No rep CSV path. Manual portal navigation per supplier. This is a real operational burden.

**Mitigation:** The pipeline is designed to accept *any* CSV that conforms to a supplier's column mapping. Apprentice can do a SiteOne portal scrape monthly (or quarterly during slow periods), drop the CSV in, run ingest. The pipeline doesn't care whether the CSV came from an API, a rep email, or a Chrome scrape.

**Cadence:** Realistically, full-catalog ingests will be **quarterly per supplier**, not monthly. Targeted partial ingests (one bundle category at a time) can happen as needed.

### 7.4 Risk: Fuzzy matching is wrong more often than it's useful

**Mitigation:** Conservative default thresholds (90 high, 70 medium). Anything below 70 goes to discovery, not auto-applied. Office Manager has full veto power on every fuzzy match before it commits.

**Iteration plan:** After 2–3 ingests, review how often fuzzy matches were confirmed vs. rejected. Tune thresholds per supplier. Add per-bundle threshold overrides if patterns emerge.

### 7.5 Risk: Discovery queue overwhelms the office on first SiteOne ingest

**Plausible scenario:** First SiteOne ingest produces 600+ discovery rows in the `landscape` and `bulk_material` bundles. Office can't process them all in a week.

**Mitigation:** Discovery queue is **append-only and patient**. There's no deadline. Office can process at sustainable pace (10-20 rows/day). Subsequent ingests don't re-create rows that are already in the queue (deduplication by supplier + row content hash).

**Acceptance criterion:** The system tolerates a discovery backlog gracefully. No batch fails or stalls because the discovery queue is long. Estimators get value from matched rows immediately; discovery is incremental gravy.

### 7.6 Trade-off: Cost vs. completeness for Tier 3 fuzzy matching

Fuzzy matching with multi-factor confidence scoring is the most complex part of the architecture. There's a temptation to skip it and route everything that doesn't match Tier 1/Tier 2 directly to discovery.

**Why we keep it:** Without Tier 3, the first SiteOne ingest of the `brass` bundle (18% mfr item # fill) would send 109 of 133 entries to discovery. Most of those would be matchable by description + size + UOM — the office would just do the matching by hand in the discovery queue. Tier 3 does that matching in code with confidence scoring and a review pass; saves a lot of manual review work.

**If implementation cost balloons:** Ship Phase 2 with Tier 1 + Tier 2 + Discovery only. Add Tier 3 as a follow-on. The architecture supports this — Tier 3 is purely additive.

### 7.7 Trade-off: `field_replaced_by` is built but largely empty on day one

The field exists. The surfacing UI works. But until office staff populate it, the discontinued-material warnings on estimates won't suggest replacements. They'll just warn.

**Acceptable:** Even an unfilled warning ("this is discontinued") is better than silent estimator pricing of obsolete parts. The system degrades gracefully.

---

## 8. What is NOT in Phase 2

Explicitly deferred. Document so we don't reopen these in Phase 3 without intent:

- **ACE / Home Depot ingest** — handled by existing WO-driven `PriceSyncService`. No automated pipeline.
- **Plant supplier ingest** — out of scope. Plants stay manual.
- **API integrations** — no current API access available from priority suppliers.
- **Email IMAP polling** — manual office upload only for v1.
- **Playwright scraper infrastructure** — no scraper farm. Claude in Chrome sessions only.
- **Manufacturer alias resolution** — manual handling via review queue.
- **Backfill of `field_replaced_by`** — apprentice/office task over time.
- **Backfill of `field_manufacturer_item_number` on brass/electric/misc/copper** — apprentice task; finite (289 entries).
- **Discontinued-material WO usage cleanup** — separate later project.
- **Bulk import duplicate-material detection** — diagnostic tool, post-Phase-2.
- **Inventory / stock-on-hand tracking** — explicitly out of scope per existing `material_suppliers` doc.
- **QuickBooks sync of pricing changes** — explicitly downstream per existing entity governance.

---

## 9. Decisions Requiring Your Confirmation

Before Phase 3 sequencing, confirm or push back on the following architectural choices:

| # | Decision | My recommendation | Your call |
|---|---|---|---|
| D1 | Build all 5 stages (Parse → Match → Dry-Run → Approve → Commit) in v1 | Yes — all five are load-bearing | |
| D2 | Build Tier 3 fuzzy matching in v1 | Yes — saves significant manual review work; bounded scope | |
| D3 | One unified review queue at `/admin/materials/price-review` for both WO-driven and feed-driven flagged entries | Yes — single point of human oversight | |
| D4 | `field_replaced_by` on all hard-goods + plant bundles, surfaced as warnings on estimate forms | Yes — minor scope add, high downstream value | |
| D5 | Discovery queue is permanent infrastructure with no deadline / SLA | Yes — patient queue, processed at sustainable pace | |
| D6 | Per-supplier bundle policy stored as JSON in `field_bundle_policy` rather than separate child entities | Yes — simpler, sufficient for foreseeable use | |
| D7 | Plants/shrubs/trees/annuals/sod bundles fully `excluded` from ingest pipeline | Yes — separate workstream | |
| D8 | First Phase 2 supplier configured: SiteOne. Second: CPS. Third: Denver Brass. | Order can flex based on which catalog you can acquire first | |

---

## 10. Phase 3 Preview (not yet sequenced)

Phase 3 will break Phase 2 into build chunks. Initial decomposition:

- **Phase 3.1** — Data model additions (entities, fields, config). Pure schema work. Ships in DDEV first, then live during an off-hours window.
- **Phase 3.2** — `IngestParser` service + `supplier_ingest_config` admin form. Allows configuring a supplier without yet ingesting. Tested by uploading a CSV that just parses and creates `supplier_price_ingest_row` entities.
- **Phase 3.3** — `IngestMatcher` Tier 1 + Tier 2 only. Skip Tier 3 in first build. Validates end-to-end flow on the simplest matches.
- **Phase 3.4** — `IngestMatcher` Tier 3 fuzzy matching.
- **Phase 3.5** — Dry-Run report UI + approval gate.
- **Phase 3.6** — `IngestCommitter` + `PriceSyncService::ingestRow` extension.
- **Phase 3.7** — Office Manager dashboards (batch manager, discovery queue, fuzzy review).
- **Phase 3.8** — Estimator surface ("Refresh Prices" button, discontinued warnings, stale price indicators).
- **Phase 3.9** — `field_replaced_by` field + display layer.
- **Phase 3.10** — First end-to-end SiteOne ingest in DDEV. Bug-fix sprint.
- **Phase 3.11** — Deploy to live; first production SiteOne ingest under controlled conditions.
- **Phase 3.12** — SOP authoring (see Phase 4).

Each phase is its own Claude Code prompt with explicit deliverables, completion checks, and a pause/report gate before the next phase begins.

---

## 11. Phase 4 Preview — SOP Flags

The following workflows will need SOPs authored once built. Listed here so they're not forgotten:

- **SOP: Supplier Catalog Acquisition** — how the apprentice/office scrapes a supplier portal via Claude in Chrome, validates the CSV, and prepares it for upload. (Office Admin bundle.)
- **SOP: Supplier Price Ingest — Upload and Review** — how the Office Manager uploads a CSV, reviews the dry-run report, approves or rejects a batch. (Office Admin bundle.)
- **SOP: Discovery Queue Resolution** — how the office resolves discovery rows: create new material, link to existing, mark as replacement, reject. (Office Admin bundle.)
- **SOP: Fuzzy Match Review** — how the office confirms, overrides, or rejects fuzzy matches. (Office Admin bundle.)
- **SOP: Estimator — Refreshing Material Prices on Open Estimates** — when and how to refresh, and how to handle the diff modal. (Office Admin or Estimating bundle.)
- **SOP: Discontinued Materials — Marking and Replacement Spec** — how the office marks a material discontinued and populates `field_replaced_by`. (Office Admin bundle.)
- **SOP: Supplier Catalog Cleanup — Apprentice Bootstrap** — the 289-entry manufacturer-item-number backfill protocol. (Office Admin bundle.)

Each SOP follows GOV-SOP-001 dual-format standard (`.docx` + paste-ready HTML).

---

## 12. Lessons Learned — Field Discoveries

This section captures matcher behavior observations that surfaced during the
first weeks of real-world ingest (SiteOne batches 205, 276, 318 in particular).
These are not new requirements — they're guardrails for future maintainers,
and rules-of-thumb for configuring new suppliers.

### 12.1 The Tier 1 empty-field guard is load-bearing

`IngestMatcher::attemptTier1()` opens with:

```php
if ($mfrName === '' || $itemNum === '') {
  return NULL;
}
```

This guard is the single biggest determinant of Tier 1 hit rate. If
`field_manufacturer_item_number` is empty on the parsed row, Tier 1 cannot
fire at all — the row goes straight to Tier 2, then Tier 3, then Discovery,
with no audit-trail explanation of "Tier 1 was attempted but found nothing"
because Tier 1 was never attempted.

**Implication:** `field_mapping` completeness on
`supplier_ingest_config.field_column_mapping` is a Tier 1 precondition.
A supplier whose CSV doesn't expose a manufacturer-item-# column needs the
1:many destination shape (see §12.2). Without it, every row falls past
Tier 1 silently.

Anyone refactoring the matcher must preserve this guard or replace it with
explicit "skip Tier 1, log why" telemetry. Removing the guard without that
replacement breaks the audit-note contract.

### 12.2 1:many destination is the default for supplier-as-mfr-SKU catalogs

Most irrigation distributors (SiteOne, Ewing, Horizon, etc.) use the
manufacturer's own SKU as their internal SKU. Their CSV has one identifier
column that doubles as both supplier SKU and manufacturer item number.
Map it 1:many:

```json
"supplier_item_number": ["field_supplier_sku", "field_manufacturer_item_number"]
```

This is the **default mapping pattern** for irrigation suppliers, not a
special case. Treat the 1:1 shape as the exception (reserved for
distributors who genuinely maintain their own SKU namespace distinct from
the manufacturer item number — relatively rare in irrigation, more common
in chemicals, hardscape, and own-brand consumer lines).

When onboarding a new supplier, default to 1:many for the SKU column unless
the catalog clearly has separate `supplier_sku` and `mfr_item_number`
columns. The cost of 1:many when not needed is zero (Tier 2 lookup uses the
same value); the cost of 1:1 when the supplier reuses the manufacturer SKU
is a Tier 1 short-circuit and full Discovery routing.

### 12.3 Historical Discovery rejections from the empty-field bug

Batches **205** and **276** contain Discovery rejections that were caused
specifically by the pre-fix 1:1 column mapping leaving
`field_manufacturer_item_number` empty (see §12.1). The matcher logic that
ran was correct; the column-mapping config was incomplete.

The fix (commit `2f723fc7`, 2026-05-26) shipped 1:many destination support;
batch 318 — first re-ingest of the same SiteOne data after the fix —
matched all four HE-VAN rows cleanly via Tier 1.

**For future use:** if dry-run hit rates need a boost on a slow afternoon,
batches 205 and 276 are good candidates for re-ingest against the current
matcher. Expect a meaningful chunk of their previously-rejected rows to
auto-match retroactively. This is not a required cleanup; the records are
still useful as audit history of the pre-fix state.

### 12.4 Tier 2 with zero hits is healthy behavior

When Tier 1 is configured correctly (manufacturer + item-# both populated
on every row), Tier 2 will frequently see zero hits per batch. This is
**correct**, not a dead tier.

Tier 2 earns its keep in three scenarios:

1. **Supplier-specific SKU that diverges from the manufacturer SKU.**
   Common at SiteOne for own-brand items and packaged kits where the
   distributor SKU bears no relationship to any single manufacturer SKU.
2. **Non-irrigation categories.** Hardscape, chemicals, supplies, and
   bulk material catalogs frequently use distributor-internal SKUs with
   no manufacturer-item-# at all. Tier 1 short-circuits cleanly via
   §12.1; Tier 2 carries the supplier-side memory.
3. **Re-ingests after a manufacturer reassignment.** If a BOS material's
   `field_manufacturer` is later corrected (e.g., a product reattributed
   to its actual manufacturer), Tier 1 against the new manufacturer
   misses, but the prior Tier 2 supplier link still matches.

Do not interpret Tier 2 = 0 hits in a single batch as a configuration
problem. It usually means Tier 1 caught everything cleanly.

---

## End of Phase 2 Architecture Draft

Awaiting your review and decisions on items D1–D8 in §9.
