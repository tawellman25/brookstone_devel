# BOS Module — contract_residential

Module: contract_residential

Purpose:
- Enforce residential contract governance rules
- Provide office/admin editing UX for Contracts and Contract Sections
- Act as the authoritative "intent layer" controller for residential contracts
- Auto-sync property_spraying_info:weed_spraying when weed spray contract sections are saved

---

## Responsibilities

### Contract Invariants
- Enforces one Residential Contract per Property per Year
- Validation at entity validation time (no WSODs)
- Contract year auto-filled when missing

### Contract ↔ Contract Section Integrity
- Automatically links Contract Sections back to their parent Contract
- Never unlinks sections
- System-driven saves are marked to suppress audit logging

### Admin Theme Enforcement
Routes: entity.contracts.canonical, .edit_form, .add_form, .collection
Roles: administrator, site_admin, administration, site_assistant, supervisor
Theme: brookstone_admin

---

## Weed Spray Route Automation

Added: March 2026

### Function: _contract_residential_maybe_sync_weed_spray_route()

Triggered by hook_entity_insert and hook_entity_update on contract_sections.
Bundles: weed_spraying_landscape_beds, weed_spraying_of_misc_areas

Guards:
- field_do_you_want must be set
- field_contract must reference a valid contract
- Contract field_contract_year must be >= 2026

When field_do_you_want = '1' (Yes):
- Finds/creates property_spraying_info:weed_spraying for the property
- Sets field_spray_route = TRUE
- Sets field_weed_beds_contracted = TRUE (beds) OR field_weed_misc_contracted = TRUE (misc)
- Sets field_active_contract_year = contract year
- Copies field_spraying_frequency

When field_do_you_want = '2' (No):
- Sets beds or misc contracted = FALSE
- If BOTH are FALSE: sets field_spray_route = FALSE AND field_active_contract_year = NULL

### field_active_contract_year

Integer field on property_spraying_info:weed_spraying.
Stores the year (e.g. 2026) when property has a current signed contract.
Used by reconciliation view to identify non-renewals.
Backfill script required after each deploy — see weed_spray_reconciliation.md.

---

## VBO Actions — Spray Route (contract_sections entities)

| Action | Behavior |
|---|---|
| put_on_landscape_weed_spray_route_action | spray_route=1, beds_contracted=1, copies frequency |
| take_off_landscape_weed_spray_route_action | spray_route=0, beds_contracted=0 |
| put_on_misc_weed_spray_route_action | spray_route=1, misc_contracted=1, copies frequency |
| take_off_misc_weed_spray_route_action | spray_route=0, misc_contracted=0 |

Bug fixed March 2026:
- Off actions used loose == 0 comparison → fixed to strict === '2'
- SprayRouteOffMiscWeedSprayAction loaded landscape section unnecessarily → removed

## VBO Action — Spray Route Remove (property_spraying_info entities)

Action: spray_route_remove_action
Class: SprayRouteRemoveAction
Used by: admin_weed_spray_reconciliation view

Sets: field_spray_route=FALSE, beds_contracted=FALSE,
      misc_contracted=FALSE, active_contract_year=NULL

---

## Contract Status Actions

- Mark Ready to Send (1118)
- Mark Sent – Posted (1119)
- Mark Received Back (1121)
- Mark Changes Entered (1122)
- Mark Approved (1123)

Each enforces allowed-from/disallowed-from states.
Administrator role may bypass guardrails.

---

## Contract Status TIDs

| TID | Status |
|---|---|
| 1117 | Created - Updating |
| 1118 | Ready to Send |
| 1119 | Sent - Posted |
| 1120 | Client Viewed |
| 1121 | Received Back |
| 1122 | Changes Entered |
| 1123 | Approved |
| 1124 | Work Orders Created |
| 1125 | Assigned |
| 1126 | On Hold |
| 1127 | Completed for the Year |
| 1128 | Canceled |
| 1651 | Generate Work Orders |

---

## Status

Updated: March 2026
Added: Weed spray automation hook, field_active_contract_year,
SprayRouteRemoveAction, bug fix notes on Off actions.