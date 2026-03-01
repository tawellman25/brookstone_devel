# BOS — material_suppliers Entity

Entity Type ID: `material_suppliers`
Bundle: `supplier`

---

## Purpose

The `material_suppliers` entity represents the **authoritative sourcing relationship** between a single Material and a single Supplier.

Each record answers the operational question:

> “Where can we buy this specific material, under what terms, and who is preferred when sourcing?”

This entity exists to capture supplier-specific knowledge that would otherwise live in staff experience or memory and to support reliable sourcing decisions when availability, pricing, or suppliers change.

---

## Entity Role in BOS

* `material` defines **what the item is**
* `supplier` defines **who we buy from**
* `material_suppliers` defines **how and where a specific material can be sourced**

This entity is the **junction authority** for Material ↔ Supplier relationships.

---

## Scope

### In scope (must live here)

* Supplier-specific item numbers / SKUs
* Supplier-specific product links
* Supplier-specific unit cost and pricing context
* Ordering mechanics (order unit, pack quantity, minimums)
* Item-level lead time
* Supplier preference and priority per material
* Item-specific ordering notes

### Out of scope (must NOT live here)

* Inventory quantities or stock-on-hand
* Vendor-wide account terms or contacts (live on `supplier`)
* Material master definitions (live on `material`)
* Work Order costing snapshots or historical execution data

---

## Fields

### Relationship (required)

| Machine name     | Label    | Type                          | Cardinality | Description                                  |
| ---------------- | -------- | ----------------------------- | ----------- | -------------------------------------------- |
| `field_material` | Material | Entity reference → `material` | 1           | The material this supplier can provide.      |
| `field_supplier` | Supplier | Entity reference → `supplier` | 1           | The supplier that can provide this material. |

---

### Supplier Identification

| Machine name                       | Label                 | Type            | Cardinality | Description                                                            |
| ---------------------------------- | --------------------- | --------------- | ----------- | ---------------------------------------------------------------------- |
| `field_supplier_item_number`       | Supplier Item Number  | Text (plain)    | 1           | Supplier-specific SKU or item number for this material.                |
| `field_supplier_website_item_link` | Supplier Website Item | Link (external) | 1           | Direct link to this item in the supplier’s catalog or ordering portal. |

---

### Pricing (supplier-specific)

| Machine name                 | Label                | Type           | Cardinality | Description                                                     |
| ---------------------------- | -------------------- | -------------- | ----------- | --------------------------------------------------------------- |
| `field_supplier_unit_cost`   | Supplier Unit Cost   | Decimal (10,2) | 1           | Cost per Cost UOM from this supplier. Used for comparison only. |
| `field_cost_uom`             | Cost Unit of Measure | List (text)    | 1           | Unit this cost applies to (each, case, box, roll, etc.).        |
| `field_price_effective_date` | Price Effective Date | Date           | 1           | Date this price was last confirmed.                             |
| `field_price_source`         | Price Source         | List (text)    | 1           | Source of the price (invoice, quote, catalog, website, phone).  |
| `field_price_notes`          | Price Notes          | Long text      | 1           | Pricing conditions or constraints.                              |

---

### Ordering Mechanics

| Machine name               | Label                  | Type        | Cardinality | Description                                         |
| -------------------------- | ---------------------- | ----------- | ----------- | --------------------------------------------------- |
| `field_order_uom`          | Order Unit             | List (text) | 1           | How this item must be ordered from this supplier.   |
| `field_pack_quantity`      | Pack Quantity          | Integer     | 1           | Number of Cost UOM units per ordered pack.          |
| `field_min_order_quantity` | Minimum Order Quantity | Integer     | 1           | Minimum quantity required when ordering.            |
| `field_lead_time_days`     | Lead Time (Days)       | Integer     | 1           | Typical lead time for this item from this supplier. |
| `field_ordering_notes`     | Ordering Notes         | Long text   | 1           | Item-specific ordering instructions or quirks.      |

---

### Supplier Preference & Priority

| Machine name                     | Label                    | Type        | Cardinality | Description                                                    |
| -------------------------------- | ------------------------ | ----------- | ----------- | -------------------------------------------------------------- |
| `field_preferred_supplier`       | Preferred Supplier       | Boolean     | 1           | Marks this supplier as the preferred source for this material. |
| `field_supplier_priority`        | Supplier Priority        | Integer     | 1           | Lower number = higher sourcing priority.                       |
| `field_supplier_status_override` | Supplier Status Override | List (text) | 1           | Override supplier status for this material only.               |

---

## Enforced Invariants (via material_supplier module)

The following rules are enforced at save-time by the `material_supplier` module and are considered **authoritative**:

1. **Uniqueness**

   * Exactly one record per `(field_material, field_supplier)` pair.

2. **Pack Quantity Rule**

   * If Order Unit and Cost UOM differ, Pack Quantity must be set and > 0.

3. **Preferred Supplier Rule**

   * Only one Preferred Supplier may exist per Material.

4. **Supplier Item Number Normalization**

   * Common pasted prefixes (Item #, SKU, Part #) are automatically stripped.
   * Whitespace is normalized on save.

---

## Work Order Interaction

* `material_suppliers` records are **not** used to retroactively calculate Work Order costs.
* Work Orders snapshot material cost at execution time.
* Pricing here is used for sourcing decisions and defaults only.

---

## Usage Guidance

* Each Material should have one or more `material_suppliers` records representing sourcing options.
* Office staff should consult this entity when:

  * a supplier is out of stock
  * prices change
  * ordering responsibilities change
* This entity should be reviewed periodically using the audit tooling in the `material_supplier` module.

---

## Status

This entity and its guardrails are considered **production-ready** and safe to build upon.

Further enhancements (cost history, supplier performance metrics) should extend this model without altering its core invariants.
