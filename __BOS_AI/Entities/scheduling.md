# BOS Entity — Scheduling

Entity Type ID:
- scheduling

Storage:
- ECK entity type

Bundles:
- work_order | Schedule for Work Order

---

## Purpose

The Scheduling entity represents **planned assignment and timing** of Work Orders.

Scheduling is a **planning and coordination layer**, not execution.

It answers:
- when work is planned
- who it is assigned to
- whether the schedule is firm or tentative
- how the work recurs (if applicable)

Scheduling does **not** record:
- work performed
- time worked
- completion status
- execution results

Execution is recorded on:
- Work Orders
- wo_time_clock
- other Work Order child entities

---

## Relationship to Work Orders

Authoritative relationship:
- scheduling.field_work_order → work_order

Rules:
- A Scheduling record must reference exactly one Work Order.
- A Work Order may have:
  - zero scheduling records (unscheduled)
  - one scheduling record (typical)
  - multiple scheduling records (recurring or rescheduled work)

Scheduling does not replace Work Order status.
It supplements it with planning data.

---

## Bundle: work_order — Schedule for Work Order

This bundle represents a single scheduled instance for a Work Order.

---

## Global Fields

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Scheduling Type
- title | string | Scheduled Title
- uid | entity_reference | Assigned by
- created | created | Created
- changed | changed | Changed
- default_langcode | boolean | Default translation
- path | path | URL alias

---

## Scheduling Core Fields

Assignment:
- field_assigned_to | entity_reference → user
  - Teammate assigned to perform the work.

Timing:
- field_date | smartdate | Date
  - Primary scheduled date (supports Smart Date behavior).

- field_scheduled_date_and_time | daterange
  - Scheduled start/end window.

Status flags:
- field_scheduled | boolean
  - Indicates the Work Order is scheduled.

- field_scheduled_firm | boolean
  - TRUE = firm commitment
  - FALSE = tentative / flexible

Order / priority:
- field_scheduled_oder | integer
  - Ordering within a daily or route list.
  - Lower numbers should be treated as higher priority.

Communication:
- field_notify_assigned_teammate | boolean
  - If TRUE, assigned teammate should be notified.

Notes:
- field_scheduling_note | string
  - Internal scheduling notes.

Recurrence:
- field_rrule | string_long
  - Stores recurrence rules (RFC-style RRULE).
  - Used for recurring Work Orders.

---

## Entity References (Confirmed)

- type → scheduling_type
- uid → user (Assigned by)
- field_assigned_to → user (Assigned to)
- field_work_order → work_order

---

## Scheduling Semantics (Important)

- Scheduling represents **planned intent**, not execution.
- A scheduled date does not imply work was performed.
- Work completion must be inferred from:
  - Work Order status
  - execution data (time entries, sign-off, etc.)

---

## Recurring Work Orders

When field_rrule is populated:
- Scheduling may represent:
  - repeated planned occurrences
  - seasonal or periodic work
- Each recurrence must still:
  - reference the same Work Order
  - be auditable as a scheduling decision

Execution for each occurrence is still captured on Work Orders
and their child entities.

---

## Invariants (Non-Negotiable)

- Scheduling must always reference a Work Order.
- Scheduling must never store execution data.
- Scheduling must not be treated as proof of work completion.
- Work Order execution truth lives outside Scheduling.
- Historical Scheduling records must not be retroactively altered
  to imply execution.

---

## Deletion / Archival

Default:
- Scheduling records may be deleted or updated
  while work has not yet been executed.

Rules:
- Do not delete Scheduling records that are required
  for audit or historical planning review.
- Prefer marking records as not scheduled (field_scheduled = FALSE)
  over deletion when rescheduling.

---

## Reporting Expectations

Scheduling must support reporting by:
- date
- assigned teammate
- Work Order
- firm vs tentative
- recurrence

Scheduling reports must not be used as execution reports.

---

## Summary

Scheduling is a **planning layer** in BOS.

- Contracts define intent
- Work Orders define execution
- Scheduling defines when and by whom work is planned

These responsibilities must remain separate.

