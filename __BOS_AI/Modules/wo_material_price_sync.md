# BOS — wo_material_price_sync (Module)

Module machine name: `wo_material_price_sync`
Drupal version: 10.x
Package: `Brookstone Outdoors`
Dependencies: `eck`, `material`, `material_supplier`

---

## Purpose

When a crew member enters a price on a WO material line that differs from
the catalog cost, this module:

1. Requires the **Bought From** vendor to be set (block at save).
2. Captures the supplier invoice number and the vendor SKU as evidence.
3. Decides whether to update the per-vendor `material_suppliers` row
   automatically, hold it for office review, or auto-create a new
   `(material, vendor)` pairing.
4. Writes an append-only `material_price_history:entry` row recording
   what happened, who did it, and from which WO.

This is the *only* automation path that mutates `material_suppliers` rows
from the WO flow. Manual edits via the supplier catalog still go through
the standard `material_supplier` module rules unchanged.

---

## Entity Scope

Acts on:

* `wo_material_list_item:items` — the trigger entity (validate / presave / insert / update)
* `material_suppliers:supplier` — the catalog row updated or auto-created
* `material_price_history:entry` — the audit row written every non-skip pass

Reads from:

* `material:*` (current `field_cost_integer` for change detection)
* `work_order` (status — pricing is locked once Complete)
* `wo_material_list` (parent list → WO id resolution)

Never touches:

* `wo_material_list_item.field_material_cost` — line snapshot is immutable.
* `material.field_cost_integer` directly — only via `material_suppliers` →
  `material.module` MAX-cost auto-sync.

---

## Hooks Implemented

| Hook | Purpose |
|------|---------|
| `wo_material_price_sync_entity_validate()` | Vendor required when price differs from catalog. Adds a `ConstraintViolation` on `field_purchased_supplier`. |
| `wo_material_price_sync_wo_material_list_item_presave()` | Defensive whitespace trim on `field_supplier_invoice_number` and `field_supplier_item_number`. Empty-string normalized to NULL. |
| `wo_material_price_sync_wo_material_list_item_insert()` | Runs `PriceSyncService::process()` on new lines. |
| `wo_material_price_sync_wo_material_list_item_update()` | Runs `PriceSyncService::process()` on edited lines. |
| `wo_material_price_sync_form_alter()` | Attaches invoice copy-down JS, applies `#states` visibility to the three "post-cost" fields, and defaults `field_purchased_supplier` from the material's preferred supplier when empty. |

---

## Services

### `wo_material_price_sync.price_sync` → `PriceSyncService`

Holds the decision tree. Two public entry points:

* `validate(EntityInterface, ConstraintViolationListInterface)` — called from `hook_entity_validate`. Pure validation, no side effects.
* `process(EntityInterface)` — called from insert/update hooks. Performs the catalog mutation and writes history.

### `wo_material_price_sync.history_writer` → `PriceHistoryWriter`

Single-purpose helper that appends a `material_price_history:entry` row.
Always populates `field_user`, builds the auto-title, and tolerates NULL
old cost / delta. **Never updates an existing row** — append-only by design.

---

## Decision Tree (PriceSyncService::process)

Locked in code; do not reorder without spec sign-off.

```
1. shouldProcess()? — skip if:
     - not a wo_material_list_item:items entity
     - parent WO is Complete (1097)            ← pricing locked
     - field_parts_used empty                  ← non-stocked / purchased path
     - field_material_cost empty               ← nothing entered
2. hasPriceChanged()?
     - new entity:    entered_cost != material.field_cost_integer
     - existing:      entered_cost != $entity->original cost
     - tolerance: |a-b| > 0.005
   If unchanged → silent return.
3. Vendor required (field_purchased_supplier).
   Already enforced by validate(); defensive guard repeated in process().
4. Find material_suppliers row for (field_parts_used, field_purchased_supplier):
     a. NO row exists                                → autoCreatePair()
        Creates new ms_row with entered cost.
        Writes history: source=auto_created, status=auto_created.
     b. Row exists, no prior cost (field_supplier_unit_cost NULL/0) → firstCostRecorded()
        Updates ms_row with entered cost.
        Writes history: source=wo_entry, status=applied.
     c. Row exists, baseline > 0 → compute delta_pct = ((entered - baseline) / baseline) * 100
          delta_pct >= +10.0  → flagHigh()
            Catalog NOT updated.
            Writes history: source=wo_entry, status=flagged_high.
          delta_pct <  +10.0  → applyChange()
            Updates ms_row with entered cost.
            Writes history: source=wo_entry, status=applied.
5. wo_material_list_item snapshot is NEVER mutated by this module.
```

### Threshold

`PriceSyncService::THRESHOLD_PERCENT = 10.0`. Symmetric: any `delta_pct ≥ +10.0%` flags. Decreases of any size always apply (cheaper price = good news).

### `field_price_source` resolution

`priceSourceFor($invoice_number)` returns:

* `'invoice'` when an invoice number was provided
* `'wo_entry'` when the crew left it blank

`'wo_entry'` is a custom allowed value added to `material_suppliers.field_price_source`. Do not remove it without auditing prior history.

### Supplier SKU on the catalog row

`maybeSetSupplierItemNumber()` only writes the SKU to the `material_suppliers` row when the row's existing SKU is **empty**. Manual office edits to a vendor SKU are never overwritten by WO entries. The history entry, by contrast, always snapshots whatever the crew typed (see history doc).

---

## Validation Behavior

`hook_entity_validate` adds a constraint violation when:

* the entity is a `wo_material_list_item:items` row,
* a price change is detected (vs catalog or vs original),
* AND `field_purchased_supplier` is empty.

The violation message is:

> Bought From vendor is required when the material price is changed from catalog. Please select the vendor this material was purchased from.

Block is at the entity-validate layer (not form-validate) so it applies to API saves and inline_entity_form embeds equally.

---

## Form Behavior (`hook_form_alter`)

Triggered on **any form** containing a `field_supplier_invoice_number` widget — works for the standalone `wo_material_list_item` form, the `inline_entity_form` embed, and any future composed form.

1. **JS attach** — adds the `wo_material_price_sync/invoice_copy_down` library.
2. **`#states` visibility** — three fields hide until `field_material_cost` is filled:
   * `field_purchased_supplier`
   * `field_supplier_invoice_number`
   * `field_supplier_item_number`
   This is **purely client-side**. We use Drupal core `#states` rather than the `conditional_fields` contrib module because `conditional_fields` strips submitted values on the server side when its evaluation thinks the dependee is empty — that broke editing already-saved lines.
3. **Default vendor** — when the line item already has `field_parts_used` set and `field_purchased_supplier` is empty, default the vendor to the material's preferred supplier (`material_suppliers.field_preferred_supplier = TRUE`).

---

## JavaScript: invoice copy-down

`js/invoice-copy-down.js` (library: `invoice_copy_down`).

On forms with multiple line items: the **first** invoice number entered is propagated to subsequent **empty** invoice number fields. Brief yellow highlight on auto-filled fields. Uses `core/once` to prevent rebinding on AJAX rebuilds.

This is a UX accelerator, not a guardrail. Crews can always overwrite the copied value before saving.

---

## Office Manager Review Dashboard

### View

`/admin/materials/price-review` (config: `views.view.material_price_review_queue`).

* Default filter: `field_status IN (flagged_high, auto_created)` — **not exposed** (silences a Drupal core `FilterPluginBase` `Undefined array key 'status'` warning at line 1609).
* Grouped invoice filter: "Has invoice" / "Missing invoice".
* Columns: Material, Supplier, Invoice #, Supplier SKU #, Old Cost, New Cost, Delta %, Status, Source, WO #, Triggered By, Date, **Operations** (Approve / Reject buttons).
* The `id` field MUST be defined in the YAML before the operations field — the `{{ id }}` token is resolved in the column-definition order.

### Permission

`review material price changes` (declared in `wo_material_price_sync.permissions.yml`). Granted to the `administration` role.

### Approve / Reject Forms

Routed in `wo_material_price_sync.routing.yml`:

* `wo_material_price_sync.approve` → `Drupal\wo_material_price_sync\Form\PriceReviewApproveForm`
* `wo_material_price_sync.reject`  → `Drupal\wo_material_price_sync\Form\PriceReviewRejectForm`

Both forms:

* require the `review material price changes` permission,
* render a summary card (material, supplier, invoice, old/new/delta),
* require a **review notes** textarea.

**Approve** — updates the `material_suppliers` row (price, effective date, source, notes), saves it (firing `material.module` MAX-sync), then sets the history entry's `field_status = approved` plus reviewer/notes/timestamp.

**Reject** — leaves the catalog UNCHANGED. Only updates the history entry's `field_status = rejected` plus reviewer/notes/timestamp.

A yellow warning banner appears on the approve form when no invoice number was provided on the original entry.

---

## Files Owned by This Module

```
web/modules/custom/wo_material_price_sync/
  wo_material_price_sync.info.yml
  wo_material_price_sync.module
  wo_material_price_sync.libraries.yml
  wo_material_price_sync.permissions.yml
  wo_material_price_sync.routing.yml
  wo_material_price_sync.services.yml
  js/invoice-copy-down.js
  src/Service/PriceSyncService.php
  src/Service/PriceHistoryWriter.php
  src/Form/PriceReviewApproveForm.php
  src/Form/PriceReviewRejectForm.php
```

Configuration owned (in `config/sync/`):

```
eck.eck_entity_type.material_price_history.yml
eck.eck_type.material_price_history.entry.yml
field.storage.material_price_history.field_*.yml         (× 14 fields)
field.field.material_price_history.entry.field_*.yml     (× 14 fields)
core.entity_form_display.material_price_history.entry.default.yml
core.entity_view_display.material_price_history.entry.default.yml
views.view.material_price_review_queue.yml
```

The `wo_entry` allowed value on `material_suppliers.field_price_source` is shared with `material_supplier` module config but only this module writes it.

---

## Architectural Decisions (Why)

### `#states` over `conditional_fields`

`conditional_fields` strips submitted values server-side when its dependee evaluator returns empty. Editing a saved line item with a price already entered triggered the strip on partial reloads, silently discarding the invoice number and SKU. `#states` is purely client-side and never mutates submitted values.

### `hook_entity_validate` over thrown exceptions

A `ConstraintViolation` surfaces inline next to the missing field with no fatal stack trace. Throwing from presave loses the form context.

### Append-only history vs in-place edits to `material_suppliers`

The catalog row is the **current** truth. The history row is the **why** — including rejected attempts that the catalog never reflected. Both are needed.

### One write per save (de-duped via `hasPriceChanged`)

A WO save that doesn't touch the price never enters the decision tree. The "silent path" matters: 90%+ of WO line saves are unchanged catalog prices, and we don't want history flooded with no-op entries.

### Vendor SKU split: history vs catalog

History snapshot is immutable (what the crew typed). Catalog SKU is editable by the office and not overwritten by WO entries (manual edits stick). `maybeSetSupplierItemNumber()` enforces this — only fills the catalog SKU when it's empty.

---

## Known Issues / Workarounds

* **`Undefined array key 'status'` in `FilterPluginBase` line 1609** — Drupal core reads `$input[identifier]` without `isset()` for a default-but-not-exposed filter. Workaround: keep the status filter un-exposed (reviewers don't need to change it from the page anyway).
* **First-cost path now appends to `field_price_notes`** — earlier versions of `firstCostRecorded()` didn't update notes, so the catalog row's notes field stayed stale relative to the actual update. Fixed 2026-04-26.

---

## Install & Usage

```
ddev drush en wo_material_price_sync -y
ddev drush cim -y     # imports entity, fields, view, allowed-value updates
ddev drush cr
```

Office Manager grants the `review material price changes` permission to the `administration` role (or any custom reviewer role).

---

## What This Module Does NOT Do

* **Mutate `wo_material_list_item.field_material_cost`** — snapshot is immutable.
* **Recompute `material.field_cost_integer`** — only via `material.module` MAX-sync.
* **Validate `material_suppliers` business rules** — owned by `material_supplier` and `material` modules.
* **Backfill historical entries** — history builds forward from the moment this module was enabled.
* **Email or notify on flagged entries** — Office Manager polls the dashboard.
* **Touch chemical pricing** — not in scope. Chemicals follow their own rules.

---

## Status

**Production-ready** — deployed 2026-04-26. All four decision branches exercised on live; review queue in active use.
