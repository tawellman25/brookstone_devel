# BOS — material_suppliers Entity

Entity Type ID: `material_suppliers`
Bundle: `supplier`

> Doc regenerated from active config + code on 2026-04-25.

---

## Purpose

The `material_suppliers` entity represents the **authoritative sourcing relationship** between a single Material and a single Supplier.

Each record answers the operational question:

> "Where can we buy this specific material, under what terms, who is preferred when sourcing, and what does it cost from this vendor?"

This entity exists to capture supplier-specific knowledge that would otherwise live in staff memory, and to support reliable sourcing decisions when availability, pricing, or suppliers change.

---

## Entity Role in BOS

* `material` defines **what the item is**, plus the current cost/price BOS uses for estimating and WO snapshots.
* `supplier` defines **who we buy from** (vendor account-level data).
* `material_suppliers` defines **how and where a specific material can be sourced**, including the per-vendor unit cost.

`material_suppliers` is the **junction authority** for Material ↔ Supplier relationships. It is also the **source of truth feeding the material's current cost** via the `material` module's auto-sync logic (see "Pricing Auto-Sync" below).

---

## Scope

### In scope (must live here)

* Per-supplier unit cost (`field_supplier_unit_cost`)
* Per-supplier item numbers / SKUs
* Per-supplier product website link
* Pricing context (effective date, source, notes)
* Ordering mechanics (order unit, cost UOM, pack quantity, MOQ)
* Supplier preference and priority per material
* Per-link supplier status override

### Out of scope (must NOT live here)

* Inventory quantities or stock-on-hand
* Vendor-wide account terms or contacts (live on `supplier`)
* Material master definitions (live on `material`)
* Work Order costing snapshots (live on `wo_material_list_item`)

---

## Fields (verified from active config)

### Relationship (required)

| Machine name     | Label    | Type                          | Cardinality | Description                                  |
| ---------------- | -------- | ----------------------------- | ----------- | -------------------------------------------- |
| `field_material` | Material Item | Entity reference → `material` | 1           | The material this supplier can provide.      |
| `field_supplier` | Supplier | Entity reference → `supplier` | 1           | The supplier that can provide this material. |

### Supplier Identification

| Machine name                       | Label                 | Type            | Cardinality | Description                                                            |
| ---------------------------------- | --------------------- | --------------- | ----------- | ---------------------------------------------------------------------- |
| `field_supplier_item_number`       | Supplier Item Number  | string          | 1           | Vendor SKU. Auto-normalized on save (see `material_supplier` module).   |
| `field_supplier_website_item_link` | Supplier Website Item | link            | 1           | Direct link to this item in the supplier's catalog or ordering portal. |

### Pricing (per-supplier)

| Machine name                 | Label                | Type           | Allowed values                                              | Description                                                     |
| ---------------------------- | -------------------- | -------------- | ----------------------------------------------------------- | --------------------------------------------------------------- |
| `field_supplier_unit_cost`   | Supplier Unit Cost   | decimal (10,2) | —                                                           | **Cost per Cost UOM from this supplier. Feeds `material.field_cost_integer` via auto-sync.** Validated > 0 if set. |
| `field_cost_unit_of_measure` | Cost Unit of Measure | list_string    | each, case, box, bag, roll                                  | Unit this cost applies to.                                       |
| `field_price_effective_date` | Price Effective Date | datetime       | —                                                           | When this price was last confirmed.                              |
| `field_price_source`         | Price Source         | list_string    | invoice, quote, catalog, website, phone, wo_entry           | Where the price came from. `invoice` is set automatically by the `wo_material_price_sync` module when a WO line entry includes an invoice number; `wo_entry` is set when the WO line had no invoice number (the crew typed a price without showing one). |
| `field_price_notes`          | Price Notes          | string_long    | —                                                           | Free-text pricing conditions/constraints.                        |

### Ordering Mechanics

| Machine name                 | Label                  | Type        | Allowed values                                                                    | Description                                                            |
| ---------------------------- | ---------------------- | ----------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `field_order_unit`           | Order Unit             | list_string | each, case, box, bag, roll, spool, bundle, pallet, crate, tray                    | How this item must be ordered.                                          |
| `field_pack_quantity`        | Pack Quantity          | integer     | —                                                                                 | Cost UOM units per ordered pack. **Required when `field_order_unit` ≠ `field_cost_unit_of_measure`** (validation hard-blocks save). |
| `field_minimum_order_quantity` | Minimum Order Quantity | integer   | —                                                                                 | MOQ. Validated > 0 if set.                                              |
| `field_ordering_notes`       | Ordering Notes         | text_long   | —                                                                                 | Item-specific ordering instructions/quirks.                             |

### Supplier Preference & Priority

| Machine name                     | Label                    | Type        | Allowed values                            | Description                                                    |
| -------------------------------- | ------------------------ | ----------- | ----------------------------------------- | -------------------------------------------------------------- |
| `field_preferred_supplier`       | Preferred Supplier       | boolean     | —                                         | Marks the preferred source. Only one per material (validation hard-blocks save). |
| `field_supplier_priority`        | Supplier Priority        | integer     | —                                         | Tie-breaker. Lower number = higher sourcing priority.            |
| `field_supplier_status_override` | Supplier Status Override | list_string | inherit, active, limited, do_not_use      | Per-link override of supplier-level status. `do_not_use` blocks save. |

> **Note:** No fields named `field_lead_time_days`, `field_cost_uom`, `field_min_order_quantity`, or `field_order_uom` exist on this bundle. The actual machine names are listed above.

---

## Enforced Invariants

Implemented in `material.module` (`material_entity_validate()`):

1. **Required references** — `field_material` and `field_supplier` must be set.
2. **Uniqueness** — exactly one record per `(field_material, field_supplier)` pair.
3. **Pack Quantity Rule** — when `field_order_unit` and `field_cost_unit_of_measure` differ, `field_pack_quantity` must be set and > 0.
4. **MOQ Sanity** — if `field_minimum_order_quantity` is set, must be > 0.
5. **Cost Sanity** — if `field_supplier_unit_cost` is set, must be numeric and > 0.
6. **Effective Status Block** — if effective status (override OR supplier-level fallback) is `do_not_use`, save is blocked.
7. **Preferred Supplier Singleton** — only one preferred supplier per material (enforced by `material_supplier.module`).
8. **SKU Normalization** — `field_supplier_item_number` is auto-cleaned on save (enforced by `material_supplier.module`).

> **Effective status resolution** lives in `Drupal\material\Service\MaterialSourcing::getEffectiveSupplierStatus()`:
> 1. If link override is set and not `inherit`, use it.
> 2. Otherwise fall back to `supplier.field_supplier_status`.
> 3. Otherwise NULL.

---

## Pricing Auto-Sync (Critical)

Whenever a `material_suppliers` record is **inserted, updated, or deleted**, `material.module` triggers `material_sync_material_pricing_from_supplier_links()`. This:

1. Loads ALL supplier links for the related material.
2. Filters out links with no/zero `field_supplier_unit_cost` and any link whose effective status = `do_not_use`.
3. Computes the **MAX (most expensive)** unit cost across remaining eligible links.
4. Writes that max to `material.field_cost_integer`.
5. Multiplies by `business_setting.field_markup` and writes to `material.field_installed_price` (if both fields exist).
6. Saves the material entity (only if values actually changed).

**Why MAX and not MIN/AVG?** The business prices jobs at worst-case cost so margins survive when the cheaper supplier is out of stock.

**Safety:** If no eligible supplier costs exist, the material is **not zeroed out** — existing values are left alone.

**Never touches:** Work Order snapshot data on `wo_material_list_item`.

---

## Work Order Interaction

* `material_suppliers` records are **not** used to retroactively calculate Work Order costs.
* Work Orders snapshot material cost at execution time into `wo_material_list_item.field_material_cost`.
* Pricing here drives sourcing decisions and the material's current/default cost — but NEVER historical WO totals.

---

## Usage Guidance

* Each Material should have one or more `material_suppliers` records representing sourcing options.
* Office staff should consult this entity when:
  * a supplier is out of stock
  * prices change (update `field_supplier_unit_cost` + `field_price_effective_date`)
  * ordering responsibilities change
  * a supplier becomes problematic (override status to `limited` or `do_not_use`)
* Run `drush ms-audit` periodically (alias for `drush material-supplier:audit`) to catch duplicate links, missing pack quantities, and suspicious SKU values.

---

## Status

Entity, validation, and auto-sync are **production-ready**. The MAX-cost auto-sync to `material.field_cost_integer` and `material.field_installed_price` is the load-bearing piece that downstream estimating depends on.

Future enhancements (cost history table, supplier performance metrics, lead-time field) should extend this model without altering the MAX-cost sync semantics.
