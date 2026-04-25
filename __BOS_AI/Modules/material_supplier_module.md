# BOS — material_supplier (Module)

Module machine name: `material_supplier`
Drupal version: 10.x

> Doc regenerated from active code on 2026-04-25. Findings flagged inline.

---

## Purpose

The `material_supplier` module enforces **data integrity** and **data hygiene** on `material_suppliers:supplier` link records. It exists alongside the `material` module which owns the bulk of validation and the price auto-sync.

This module specifically protects against:

* duplicate Material–Supplier link records
* multiple preferred suppliers for the same material
* polluted Supplier Item Numbers caused by pasted labels or descriptions

It also provides the `drush ms-audit` command for ongoing verification.

---

## Entity Scope

Applies **only** to:

* Entity Type ID: `material_suppliers`
* Bundle: `supplier`

This module does **not** act on:

* `material` entities
* `supplier` entities
* Work Orders or historical execution data

---

## Hooks Implemented

| Hook | Purpose |
|------|---------|
| `material_supplier_entity_validate()` | Uniqueness check, Preferred-supplier singleton, Pack Quantity check (see drift note) |
| `material_supplier_entity_presave()` | Supplier Item Number normalization |

---

## Enforced Rules (this module)

### 1) Uniqueness — Material + Supplier (Hard Block)

* Exactly one `material_suppliers:supplier` record per `(field_material, field_supplier)` pair.
* Validation error blocks save when a duplicate is attempted.

### 2) Preferred Supplier Singleton (Hard Block)

* Only one `material_suppliers:supplier` record per material may have `field_preferred_supplier = TRUE`.
* Validation error blocks save when a second preferred is set.

### 3) Supplier Item Number Normalization (Auto-clean, Presave)

On save, `field_supplier_item_number` is normalized:

* Trim leading/trailing whitespace
* Collapse internal whitespace to a single space
* Strip common pasted prefixes (case-insensitive): `Item #`, `Item Number`, `SKU`, `Part #`, `Part Number`, `Item No`, `Part No`
* After prefix removal, strip leading separators (`:`, `-`, `#`) and re-normalize

Example: `Item # RBL15F` → `RBL15F`

Empty values are preserved as NULL (not empty string).

---

## Dual Validation: this module vs material.module

`material.module` ALSO implements `material_entity_validate()` for `material_suppliers` and covers a broader set of rules. **There is intentional / accidental overlap.** Current state of where each rule actually fires:

| Rule | This module | material.module |
|------|-------------|-----------------|
| Required references (material, supplier) | — | ✅ |
| Uniqueness | ✅ | ✅ (duplicate check) |
| Preferred supplier singleton | ✅ | — |
| Pack Quantity required when units differ | — | ✅ |
| MOQ > 0 sanity | — | ✅ |
| Supplier unit cost > 0 sanity | — | ✅ |
| Effective status `do_not_use` blocks save | — | ✅ |
| SKU normalization (presave) | ✅ | — |

**Recommendation when changing validation:** decide which module owns each rule and remove the duplicate. Today, neither module is broken, but uniqueness errors may surface twice (one from each hook) on duplicate save attempts.

---

## Drush Tooling

### Audit Command

```
drush material-supplier:audit
```

Alias:

```
drush ms-audit
```

The audit reports:

* duplicate Material–Supplier link records
* missing pack quantities where Order UOM ≠ Cost UOM
* suspicious Supplier Item Number values (URLs, emails, long pasted descriptions)

Intended for periodic operational checks and cleanup.

---

## Files Owned by This Module

```
web/modules/custom/material_supplier/
  README.md
  material_supplier.info.yml
  material_supplier.module
  drush.services.yml
  src/Commands/MaterialSupplierCommands.php
```

---

## What This Module Does NOT Do

These belong elsewhere — do not add them here:

* **Material price auto-sync** — owned by `material.module → material_sync_material_pricing_from_supplier_links()`. Recalculates `material.field_cost_integer` as MAX of eligible supplier unit costs on every link insert/update/delete.
* **Effective status resolution** — owned by `Drupal\material\Service\MaterialSourcing::getEffectiveSupplierStatus()`.
* **Supplier-side validation** — `supplier` entity is not in scope.
* **Work Order snapshot mutation** — never. Snapshots are immutable.

---

## Install & Usage

```
drush en material_supplier -y
drush cr
drush ms-audit
```

---

## Operational Guidance

* Use `material_suppliers` records as the **authoritative sourcing layer** for each material.
* Per-vendor unit costs entered here automatically drive the material's `field_cost_integer` (MAX-cost rule, see `material.md`).
* Do not store per-material SKUs or pricing directly on `supplier` entities.
* Do not retroactively alter historical Work Order data.

---

## Status

Production-ready. Dead pack-qty validation block (referenced obsolete field names `field_order_uom` / `field_cost_uom`) was removed on 2026-04-25; equivalent enforcement remains in `material.module`. Future refactor could consolidate the remaining duplicate uniqueness check into a single module.
