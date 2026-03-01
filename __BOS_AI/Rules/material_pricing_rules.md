# BOS Material Pricing Rules (Authoritative)

This document defines how BOS determines the **current/default unit cost**
for Materials, and how that cost is snapshotted into Work Order material usage.

Scope:
- Material entity (ECK) bundles
- wo_material_list_item snapshot fields

---

## Core Principle

Materials store **current/default pricing**.
Work Orders store **historical pricing**.

Work Orders must never recalculate historical costs from current Material pricing.

---

## Work Order Snapshot (Source of Truth for History)

Entity:
- wo_material_list_item (bundle: items)

Fields:
- field_material_cost (decimal) — unit cost snapshot at time of use
- field_quantity (integer)
- field_subtotal (decimal)
- field_subtotal_w_markup (decimal)

Invariant:
- Once the parent Work Order is Completed:
  - field_material_cost must not change except admin correction
  - field_subtotal values must not be recomputed from current Material pricing

---

## Material Current Pricing (Source of Truth for “Now”)

### Standard Cost Field (Primary)

For most non-plant/hardgoods bundles, BOS uses:
- field_cost_integer (decimal)

Despite the label, this field is treated as the **current/default unit cost**.

Bundles using this as default unit cost include:
- brass
- copper
- electric
- galv
- irrigation
- landscape
- misc
- pavers
- poly
- pumps
- pvc
- xmas
- decorative_rock (also has field_price; see below)

Rule:
- When a stocked material is selected for a Work Order, BOS must:
  - read the current unit cost from the Material entity
  - write it into wo_material_list_item.field_material_cost

---

## Retail / Installed Pricing (Not Used for Cost Snapshot)

Some bundles have pricing fields intended for quoting/marketing:

- field_installed_price (decimal) — “Our Installed Price” / “Price”
- field_price (decimal) — often retail/unit price for some bundles (e.g., rock)
- field_retail_price_disclaimer (string)

Rules:
- These fields must not be used as the default cost for job costing
  unless explicitly documented as an exception.
- Job costing snapshots should be based on unit cost, not installed/retail pricing.

---

## Bundle Exceptions / Special Cases

### decorative_rock (Rock)
Fields present:
- field_cost_integer (decimal) — cost basis (preferred)
- field_price (decimal) — public/retail price (not the cost snapshot)
- field_unit_of_measure (list_string)
- field_est_wt_per_yard, field_yard_per_ton (conversion support)

Rule:
- Default unit cost snapshot should use field_cost_integer unless BOS explicitly treats field_price as cost.

---

## Plant-Type Bundles (Cost May Be Missing)

Bundles:
- annuals
- plants
- shrubs
- trees
- sod

Observed:
- Some plant bundles do not currently include a dedicated “unit cost” field.
- shrubs includes field_cost_integer and field_price (good)
- trees/sod appear minimal (supplier only)

Rules:
- If a plant-type bundle is used for Work Order costing, it must have a defined unit cost field.
- Until then:
  - stocked usage must be limited to bundles with reliable unit cost
  - or unit cost must be entered manually into wo_material_list_item.field_material_cost

Recommended (future):
- Add a consistent unit cost field to trees and sod if they are used in costing.
- Keep the “snapshot at time of use” rule unchanged.

---

## Unit of Measure Governance

Field:
- material.field_unit_of_measure (list_string)

Rule:
- When a bundle supports unit-of-measure costing, unit of measure must be populated and consistent.
- wo_material_list_item quantity meaning must match the Material unit of measure.

---

## Price Maintenance Flags

Field:
- material.field_price_updated (boolean)

Meaning:
- Internal flag for price review workflow.
- Does not affect snapshot rules.

---

## Data Integrity Rules (Non-Negotiable)

- Material entity pricing may change.
- Work Order snapshots must preserve historical unit cost.
- No retroactive recalculation of completed Work Orders.
- Admin corrections are allowed but must be explicit (no silent changes).

