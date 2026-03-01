# BOS Chemical Pricing Rules (Authoritative)

This document defines how BOS determines **current/default chemical cost**
and how that cost is **snapshotted** for Work Orders.

Scope:
- Chemical entity (ECK)
- wo_chemicals_used child entity
- Cost integrity and historical accuracy

---

## Core Principle

Chemical entities store **current/default pricing**.  
Work Orders store **historical pricing**.

Once a Work Order is completed, chemical costs must **never change implicitly**.

---

## Source of Truth

### Current / Default Cost (Chemical Entity)

Entity:
- chemical

Field:
- field_material_cost (decimal)

Meaning:
- The current/default unit cost of the chemical.
- Used for estimating and for snapshotting at time of application.

Rules:
- This field may change over time.
- Changes affect **future** Work Orders only.
- It must not be used directly for historical reporting.

---

### Historical Cost (Work Order Snapshot)

Entity:
- wo_chemicals_used

Field:
- field_chemical_cost (decimal)

Meaning:
- Snapshot of unit cost **at time of use**.
- This is the authoritative source for:
  - job costing
  - billing
  - compliance
  - audits

Invariant:
- field_chemical_cost must be populated when a chemical is recorded on a Work Order.
- After Work Order completion:
  - field_chemical_cost must not change
  - except via explicit admin correction

---

## Snapshot Workflow (Required Behavior)

When a chemical is selected for a Work Order:

1. Read:
   - chemical.field_material_cost
2. Write:
   - wo_chemicals_used.field_chemical_cost
3. Calculate:
   - wo_chemicals_used.field_subtotal
     using snapshot cost × quantity / rate

Chemical pricing must never be calculated live from the Chemical entity after this step.

---

## Unit of Measure Governance

Field on Chemical:
- field_unit_of_measure_fertilizer (list_string)

Rules:
- Unit of measure must align with:
  - application rate
  - tank-mix logic
  - subtotal calculations on wo_chemicals_used
- wo_chemicals_used quantity/rate fields must be interpreted consistently
  with the Chemical’s unit of measure.

---

## Compliance Considerations

Many chemicals are regulated.

Rules:
- Pricing changes must not affect historical compliance records.
- Historical chemical usage must always reflect:
  - the chemical used
  - the cost at time of use
  - the rate/quantity applied
- SDS and product labels must remain accessible
  even if a chemical is later discontinued.

---

## Discontinued Chemicals

Preferred approach:
- Mark chemicals as discontinued (future enhancement if needed).
- Discontinued chemicals:
  - must not be selectable for new Work Orders
  - must remain available for historical reference

---

## Data Integrity Rules (Non-Negotiable)

- Chemical.field_material_cost = current/default cost
- wo_chemicals_used.field_chemical_cost = historical snapshot
- No retroactive recalculation of completed Work Orders
- Admin corrections must be explicit and traceable
- Execution history must never depend on current Chemical pricing
