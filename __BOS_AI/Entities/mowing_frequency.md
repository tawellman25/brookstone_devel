# BOS Vocabulary — Mowing Frequency

Vocabulary ID:
- mowing_frequency

---

## Purpose

The **Mowing Frequency** vocabulary defines the standard mowing cadence options used across BOS.

It is used to:
- define mowing commitment in Contract Sections
- influence Work Order scheduling logic
- group recurring mowing routes
- support reporting by service intensity

This vocabulary represents **intent and scheduling cadence**, not execution proof.

---

## Terms (Authoritative)

tid | Name | Weight
---|---|---
1107 | Weekly | 0
1108 | Every Other Week | 1
1111 | Two Times a Month | 2
1109 | Every 3 Weeks | 3
1110 | Once a Month | 4
1112 | On Call | 5

Ordering Rule:
- Terms must be displayed in ascending weight order.
- Weight defines logical mowing frequency progression.

---

## Term Fields

System/base:
- tid | integer | Term ID
- uuid | uuid | UUID
- vid | entity_reference | Vocabulary
- name | string | Name
- description | text_long | Description
- weight | integer | Weight
- parent | entity_reference | Term Parents
- path | path | URL alias
- status | boolean | Published

Revision metadata:
- revision_id
- revision_created
- revision_user
- revision_log_message
- changed
- default_langcode
- revision_default
- revision_translation_affected

No custom fields are currently defined for this vocabulary.

---

## Operational Meaning of Terms

- Weekly
  Standard weekly mowing service.

- Every Other Week
  Biweekly mowing.

- Two Times a Month
  Twice monthly mowing (calendar-based, not fixed interval).

- Every 3 Weeks
  21-day cadence mowing.

- Once a Month
  Monthly mowing.

- On Call
  Non-recurring mowing triggered by request.

---

## Usage in BOS

Primary usage:
- `contract_sections:lawn_mowing_and_trimming`
  - field_mowing_frequency → mowing_frequency

Scheduling behavior:
- Frequency informs scheduling recurrence logic.
- "On Call" must not auto-generate recurring scheduling rules.
- Recurring logic should derive from frequency, not hard-coded assumptions.

---

## Invariants (Non-Negotiable)

- There must be no duplicate cadence definitions.
- Weight ordering must reflect mowing intensity progression.
- Frequency selection defines scheduling intent only.
- Execution completion must be derived from Work Orders, not frequency terms.

---

## Reporting Expectations

Mowing Frequency must support reporting by:
- Property
- Contract
- Crew load planning
- Route density
- Service intensity

Frequency must never be treated as proof that work occurred.

