# BOS Vocabulary — Season

Vocabulary ID:
- season

---

## Purpose

The **Season** vocabulary defines the canonical BOS seasons used across the system.

Seasons are used to:
- standardize seasonal intent in Contracts and Contract Sections
- drive Work Order descriptions and planning
- support seasonal scheduling logic and filtering
- provide consistent reporting and grouping across services

BOS uses **one shared Season set** across all services.
Services filter/select from this shared set via Views and reference widgets.

---

## Terms (Authoritative)

tid | Name | Weight
---|---|---
1327 | Pre-season | -10
1100 | Spring | 1
1101 | Summer | 2
1102 | Fall | 3
1103 | Winter | 4

Ordering rule:
- Display order must follow term weight ascending.

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

Revision metadata (present; standard):
- revision_id
- revision_created
- revision_user
- revision_log_message
- changed
- default_langcode
- revision_default
- revision_translation_affected

Custom fields:
- field_icon | image | Icon
- field_services_performed | entity_reference | Services Performed

---

## Usage Rules

- The Season vocabulary is shared globally across BOS.
- Service-specific season selection must be implemented by filtering the Season term list (e.g., via Views-driven entity reference widgets).
- Seasons must not be duplicated per service.

---

## Meaning of Terms

- Pre-season
  - Used for pre-service preparation or early-season actions before “Spring”.
  - Weight is intentionally negative to surface first in ordered lists.

- Spring / Summer / Fall / Winter
  - Canonical operational seasons used for planning and reporting.

---

## Invariants (Non-Negotiable)

- There must be exactly one term for each canonical season (no duplicates).
- Term IDs may vary between environments; machine identity is by term name + vocabulary.
- Weights define ordering and must remain stable.
- Season selection is intent/planning metadata and must not be treated as execution proof.

---

## Reporting Expectations

Seasons must support reporting by:
- service
- contract year
- scheduled work (planning)
- completed work (execution), derived from Work Orders and child entities

Season-based execution reporting must be derived from Work Orders, not from Contracts alone.
