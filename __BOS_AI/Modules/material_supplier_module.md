# BOS — material_supplier (Module)

Module machine name: `material_supplier`
Drupal version: 10.x

---

## Purpose

The `material_supplier` module enforces **data integrity** and **data hygiene** for the Material ↔ Supplier link entity (`material_suppliers`, bundle `supplier`).

This module exists to prevent sourcing data from drifting over time and to encode operational guardrails that cannot be reliably enforced through UI configuration alone.

Specifically, it protects against:

* duplicate Material–Supplier link records
* broken unit math caused by missing pack quantities
* multiple preferred suppliers for the same material
* polluted Supplier Item Numbers caused by pasted labels or descriptions

It also provides an audit command for ongoing verification.

---

## Entity Scope

Applies **only** to:

* Entity Type ID: `material_suppliers`
* Bundle: `supplier`

This module does **not** act on:

* `material` entities
* `supplier` entities
* Work Orders or historical execution data

Snapshot costing rules remain authoritative at the Work Order level.

---

## Enforced Rules (Authoritative)

### 1) Uniqueness — Material + Supplier (Hard Block)

For `material_suppliers:supplier` records:

* There must be **exactly one** record per `(field_material, field_supplier)` pair.
* Attempts to create duplicates are blocked at save-time with a validation error.

---

### 2) Pack Quantity Requirement (Hard Block)

If the following fields exist on the entity:

* `field_order_uom`
* `field_cost_uom`
* `field_pack_quantity`

Then:

* When `field_order_uom` and `field_cost_uom` are both set **and differ**,
* `field_pack_quantity` must be set and must be **greater than zero**.

This prevents silent unit conversion errors.

---

### 3) Preferred Supplier Constraint (Hard Block)

If `field_preferred_supplier` exists and is set to TRUE:

* Only **one** supplier may be marked as preferred per material.
* Additional preferred selections are blocked until the existing one is cleared.

---

### 4) Supplier Item Number Normalization (Auto-clean)

On save, if `field_supplier_item_number` exists:

The value is normalized as follows:

* Trim leading and trailing whitespace
* Collapse internal whitespace sequences to a single space
* Strip common pasted prefixes (case-insensitive), including:

  * `Item #`
  * `Item Number`
  * `SKU`
  * `Part #`
  * `Part Number`
* After prefix removal, strip common separators (`:`, `-`, `#`) and normalize again

Example:

* `Item # RBL15F` → `RBL15F`

This correction is automatic and non-destructive.

---

## Drush Tooling

### Audit Command

Command:

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

This command is intended for periodic operational checks and cleanup.

---

## Files Owned by This Module

```
web/modules/custom/material_supplier/
  material_supplier.info.yml
  material_supplier.module
  drush.services.yml
  src/Commands/MaterialSupplierCommands.php
```

Key hooks implemented:

* `material_supplier_entity_validate()` — rules 1, 2, and 3
* `material_supplier_entity_presave()` — rule 4

---

## Install & Usage

Enable the module:

```
drush en material_supplier -y
drush cr
```

Run the audit:

```
drush ms-audit
```

---

## Operational Guidance

* Use `material_suppliers` records as the **authoritative sourcing layer** for each material.
* Do not store per-material SKUs or pricing directly on `supplier` entities.
* Do not retroactively alter historical Work Order data.

This module is intentionally conservative and should be treated as a **settled guardrail layer** within BOS.
