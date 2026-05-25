# BOS Entity — material_price_history

Entity Type ID: `material_price_history`
Bundle: `entry` (single bundle)
Storage: ECK

---

## Purpose

Append-only audit trail of every material price change tied to a specific
`(material, supplier)` pair. Each entry captures a snapshot of:

- the prior cost (if any),
- the new cost being recorded,
- the percent delta,
- where it came from (WO entry, manual edit, invoice, auto-created),
- its lifecycle status (applied, flagged for review, approved, rejected),
- who triggered it,
- the supplier invoice number (when provided),
- the supplier SKU (when provided),
- the WO that triggered it (when WO-driven),
- and Office Manager review notes after approval/rejection.

This entity exists so the office can answer:
- "How did this material's price for Vendor X change over the last year?"
- "Which crew members are entering off-catalog prices, and how often are they justified by an invoice?"
- "Which price changes did we approve, who approved them, and on what date?"

---

## Entity Role in BOS

* `material` defines **what the item is** (current cost is a derived value).
* `material_suppliers` defines **how and where to source it** (with current per-vendor cost).
* `material_price_history` records **every change to a per-vendor cost over time**, plus the office's review decision when one was required.

`material_price_history` is **NEVER** consulted for live pricing — it is purely an audit log. Live pricing flows from `material_suppliers.field_supplier_unit_cost` → `material.field_cost_integer` (via the MAX-cost auto-sync in `material.module`).

---

## Bundle: entry

### Required Fields

| Machine name      | Type                          | Description                                                 |
| ----------------- | ----------------------------- | ----------------------------------------------------------- |
| `field_material`  | entity_reference → material   | The material whose price changed.                           |
| `field_supplier`  | entity_reference → supplier   | The vendor (supplier) the new price is for.                 |
| `field_new_cost`  | decimal (10,2)                | The new cost being recorded.                                |
| `field_source`    | list_string                   | Origin of the change (see allowed values below).            |
| `field_status`    | list_string                   | Lifecycle status (see allowed values below).                |
| `field_user`      | entity_reference → user       | The user who triggered the entry.                           |

### Optional Fields

| Machine name                      | Type                              | Description                                                                                                            |
| --------------------------------- | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `field_old_cost`                  | decimal (10,2)                    | Prior `material_suppliers.field_supplier_unit_cost` for this pair. NULL when no prior cost existed (auto_created path). |
| `field_delta_percent`             | decimal (5,2)                     | `((new - old) / old) * 100`. NULL when no baseline.                                                                     |
| `field_supplier_invoice_number`   | string (max 64)                   | Vendor invoice/receipt number captured from the WO line (or the manual entry). NULL when none provided.                |
| `field_supplier_item_number`      | string (max 255)                  | Vendor SKU snapshot at entry time. Does NOT update if the office later edits the supplier catalog SKU.                 |
| `field_wo_reference`              | entity_reference → work_order     | The WO that triggered this entry. NULL for manual entries.                                                              |
| `field_change_notes`              | string_long                       | Auto-generated free text describing the change (e.g. "Updated from WO #48422. Invoice #INV-001.").                      |
| `field_review_notes`              | string_long                       | Office Manager's notes from the approve/reject decision. NULL until reviewed.                                          |
| `field_reviewed_by`               | entity_reference → user           | The Office Manager (or admin) who decided. NULL until reviewed.                                                        |
| `field_reviewed_on`               | datetime                          | When the review was performed. NULL until reviewed.                                                                    |
| `field_ingest_batch`              | entity_reference → supplier_price_ingest_batch | **Added 2026-05-25 (Phase 3.1).** Set when this entry originated from a supplier price ingest. Links to the batch that produced it. NULL for all non-ingest sources. Form-display placement: inside the existing "Source / Origin" fieldset group, immediately after `field_wo_reference`. View display: visible as inline reference label. **Live as of Phase 3.6** — `IngestCommitter` → `PriceSyncService::ingestRow()` → `PriceHistoryWriter::write($..., ingest_batch_id: $batch_id)` populates this on every feed-driven entry. |

### `field_source` Allowed Values

| Value                  | Meaning                                                                          |
| ---------------------- | -------------------------------------------------------------------------------- |
| `wo_entry`             | Crew typed a different price on a WO line.                                       |
| `manual`               | Office staff edited the supplier link directly (not via the WO flow).            |
| `invoice`              | Entered from a supplier invoice (reserved for office data entry).                |
| `auto_created`         | The (material, vendor) pair didn't exist; the system created the link.           |
| `catalog`              | Price obtained from a supplier catalog (printed or PDF). *Added 2026-05-25.*    |
| `quote`                | Price obtained from a supplier quote (custom pricing). *Added 2026-05-25.*       |
| `website`              | Price obtained from a supplier website (manual lookup). *Added 2026-05-25.*       |
| `phone`                | Price obtained by phone call to supplier. *Added 2026-05-25.*                   |
| `feed_import_auto`     | Applied by the supplier-price-ingest pipeline without office review (Tier 1, Tier 2, or Tier 3 high-confidence matches). `field_ingest_batch` is always populated. *Added 2026-05-25 (Phase 3.1).* |
| `feed_import_reviewed` | Applied by the supplier-price-ingest pipeline after Office Manager review (Tier 3 medium-confidence or discovery resolution). `field_ingest_batch` is always populated. *Added 2026-05-25 (Phase 3.1).* |

**Stability invariant:** value order in the underlying `list_string` field storage YAML must remain stable. `wo_entry`, `manual`, `invoice`, `auto_created`, `catalog`, `quote`, `website`, `phone`, `feed_import_auto`, `feed_import_reviewed` are the canonical order. Do NOT reorder or relabel existing values — production rows depend on the stored value strings.

### `field_status` Allowed Values

| Value          | Meaning                                                                                          |
| -------------- | ------------------------------------------------------------------------------------------------ |
| `applied`      | Catalog updated (passed all guardrails).                                                          |
| `flagged_high` | >+10% increase, awaiting Office Manager review. Catalog NOT updated.                              |
| `auto_created` | New (material, vendor) pair, awaiting Office Manager review (the link was created with this entry's cost). |
| `approved`     | Office Manager approved a previously flagged entry; catalog updated at approval time.            |
| `rejected`     | Office Manager rejected a previously flagged entry; catalog NOT changed.                          |
| `resolved`     | Reserved for future use (e.g., bulk close of stale entries).                                      |

---

## Invariants (Non-Negotiable)

1. **Append-only.** Entries are written by `PriceHistoryWriter` and updated only by the Office Manager review flow (which sets `field_status`, `field_review_notes`, `field_reviewed_by`, `field_reviewed_on`). All other fields are immutable post-write.
2. **Never used for live pricing.** Live cost flows through `material_suppliers` → `material.field_cost_integer`. Reading `material_price_history` to compute current pricing is forbidden.
3. **No retroactive backfill.** History builds forward from the moment `wo_material_price_sync` was enabled. Earlier supplier link changes have no entries.
4. **Snapshot at entry time.** `field_supplier_invoice_number` and `field_supplier_item_number` reflect what the crew member entered on the WO line at the time. If the office later edits the supplier link's SKU, the history entry's SKU does not change.
5. **No deletion in normal operation.** The entity supports delete via API/admin only — never via UI for end users.
6. **Feed-import entries always carry a batch reference.** When `field_source` is `feed_import_auto` or `feed_import_reviewed`, `field_ingest_batch` MUST be populated. The supplier-price-ingest pipeline enforces this at write time (Phase 3.2+). Audits should treat any feed_import_* row with NULL `field_ingest_batch` as a data integrity defect.

---

## Form Display

Three fieldset groups (see `core.entity_form_display.material_price_history.entry.default`):

1. **Change Details** (open) — material, supplier, invoice #, SKU #, old cost, new cost, delta %.
2. **Source / Origin** (open) — source, status, WO reference, user, change notes.
3. **Office Review** (collapsed) — review notes, reviewed by, reviewed on.

Office Managers do not normally edit entries via this form — they use the **Material Price Review Queue** view (`/admin/materials/price-review`) which routes them through the dedicated `PriceReviewApproveForm` / `PriceReviewRejectForm` flow built into the `wo_material_price_sync` module.

---

## Permissions

| Role            | Access                                                  |
| --------------- | ------------------------------------------------------- |
| `administrator` | Full CRUD via the is_admin flag.                        |
| `site_admin`    | Create / view / edit (no delete).                        |
| `administration` | Create / view / edit (no delete) + `review material price changes` for the dashboard. |
| All others      | No access.                                              |

The `review material price changes` permission gates `/admin/materials/price-review` and the Approve / Reject form routes.

---

## Where Entries Come From

- **`PriceSyncService::process()`** in the `wo_material_price_sync` module — called from `hook_ENTITY_TYPE_insert/update` on `wo_material_list_item`. Writes one entry per save when the price differs from the prior known cost. See `__BOS_AI/Modules/wo_material_price_sync.md` for the full decision tree.
- **`PriceReviewApproveForm` / `PriceReviewRejectForm`** — these UPDATE existing entries to set the review fields; they do not create new ones.

No other code path writes to this entity. Entries should never be created directly from views, form alters, or migration scripts unless explicitly designed (e.g., a future bulk-import).

---

## Status

**Production-ready.** Entity, form/view displays, permissions, and the review dashboard are deployed and in use. The Office Manager review queue at `/admin/materials/price-review` is the canonical entry point for reviewing flagged entries.
