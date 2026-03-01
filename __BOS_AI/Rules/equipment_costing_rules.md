# BOS Equipment Costing Rules (Authoritative)

This document defines how BOS handles **equipment costing and billing**
for Work Orders, including internal cost tracking and billable rates.

Scope:
- Equipment entity (ECK)
- Work Order equipment usage (e.g. wo_rental_equipment or equivalent)
- Historical accuracy and reporting integrity

---

## Core Principle

Equipment is an **asset**, not a consumable.

Equipment entities store:
- current/default rates and costs

Work Orders must preserve:
- historical usage context
- stable costing for completed work

---

## Source of Truth

### Current / Default Rates (Equipment Entity)

Entity:
- equipment

Relevant fields (vary by bundle):
- field_rate (decimal) — Hourly Work Order Rate
- field_internal_cost_rate (decimal) — Internal Cost Rate
- field_operating_cost_per_hour (decimal) — Operating Cost per Hour
- field_billable (boolean)

Meaning:
- These fields represent the **current/default values** for equipment.
- They may change over time as costs, rates, or policies change.

Rules:
- These fields are authoritative only for **new** Work Order usage.
- They must not be used directly for historical reporting on completed Work Orders.

---

## Work Order Equipment Usage (Historical Snapshot)

Equipment usage on Work Orders should be recorded via a child entity
(e.g. `wo_rental_equipment` or similar).

Expected snapshot fields (on the WO equipment usage record):
- equipment reference (entity_reference → equipment)
- usage duration (hours, time range, or units)
- snapshot billable rate
- snapshot internal cost rate
- calculated subtotal(s)

Invariant:
- Snapshot values on Work Order equipment usage records are the
  **source of truth** for historical costing and billing.

---

## Snapshot Workflow (Required Behavior)

When equipment is added to a Work Order:

1. Read from Equipment entity:
   - field_rate
   - field_internal_cost_rate
   - field_operating_cost_per_hour (if applicable)
   - field_billable

2. Write to Work Order equipment usage record:
   - snapshot billable rate
   - snapshot internal cost rate
   - snapshot operating cost (if used)

3. Calculate:
   - billable subtotal
   - internal cost subtotal

After this point:
- Equipment entity values must not affect this Work Order.

---

## Billable vs Non-Billable Equipment

Field:
- equipment.field_billable (boolean)

Rules:
- If FALSE:
  - Equipment usage must not contribute to customer billing.
  - Internal cost tracking may still occur.
- If TRUE:
  - Equipment usage may contribute to Work Order billing totals.

This flag must be evaluated at snapshot time and preserved on the usage record.

---

## Completion & Immutability

Once a Work Order is marked **Completed**:

- Equipment usage records become read-only.
- Snapshot rates and costs must not change.
- Any corrections must be:
  - explicit
  - admin-only
  - traceable

Silent recalculation is prohibited.

---

## Reporting Expectations

Equipment costing must be reportable by:
- Work Order
- Equipment asset
- Date range
- Internal cost vs billable revenue
- Equipment type (vehicle, trailer, heavy equipment, etc.)

Reports must rely on **snapshot data**, not live Equipment entity values.

---

## Deletion / Archival Rules

Equipment entities:
- Must not be deleted if referenced by any Work Orders.
- Should be archived via status/date fields (sold, inactive).

Work Order equipment usage records:
- Must never be orphaned.
- Must persist for historical reporting.

---

## Non-Negotiable Invariants

- Equipment entity values = current/default
- Work Order usage values = historical truth
- No retroactive recalculation
- Billable logic must be snapshotted
- Admin corrections must be explicit and auditable
