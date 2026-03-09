# BOS Entity — Contract Sections

Entity Type ID:
- contract_sections

Storage:
- ECK entity type

---

## Purpose

Contract Sections capture **service-specific intent** inside a Contract.

Each bundle is intentionally separate because the service requires:
- different scope inputs
- different estimating inputs
- different client-facing questions
- different expected outcomes

There is no attempt to combine or generalize these bundles.

Contract Sections are intent-only. Execution is recorded on Work Orders.

---

## Relationship to Contracts (Important)

Contract Sections are linked to Contracts in two ways:

1) Contract → Contract Section
   - Some Contract bundles (notably `residential`) reference Contract Sections
     via explicit entity reference fields (section “slots”), such as:
       - field_spring_cleanup
       - field_fall_cleanup
       - field_aerating_of_lawn
       - etc.

2) Contract Section → Contract
   - Every Contract Section also stores its parent Contract via:
     - field_contract (entity_reference → contracts)

Invariant:
- If a Contract references a Contract Section via a section slot field,
  the referenced Contract Section **must** point back to the same Contract
  in `contract_sections.field_contract`.

This bidirectional relationship is intentional and must remain consistent.


## Bundles (Machine Name | Label)

aerating_of_lawn | Aerating of Lawn
aspen_twig_gall_control | Aspen Twig Gall Control
christmas_decorations | Christmas Decorations
cooley_spruce_gall_treatment | Cooley Spruce Gall Treatment
deciduous_bore_treatment | Deciduous Bore Treatment
deer_protection_wire | Deer Protection Wire
dethatching_of_lawn_areas | Dethatching of Lawn Areas
dormant_oil_spray | Dormant Oil Spray
fall_cleanup | Fall Cleanup
fertilizing_of_shrubs_and_trees | Fertilizing of shrubs and trees
grub_prevention_on_lawn | Grub Prevention on Lawn
ips_beetle_on_pinion_pine | Ips beetle on Pinion Pine
irrigation_check_ups | Irrigation Check Ups
irrigation_shut_down | Irrigation Shut Down
irrigation_start_up | Irrigation Start Up
lawn_fertilizing | Lawn Fertilizing & Broadleaf Control
lawn_mowing_and_trimming | Lawn Mowing and Trimming
pre_emergent | Pre-emergent
spring_cleanup | Spring Cleanup
summer_hedge_shrub_pruning | Summer Hedge & Shrub Pruning
trunk_bore_prevention | Trunk Bore Prevention
weed_spraying_landscape_beds | Weed spraying of landscape bed areas
weed_spraying_of_misc_areas | Weed Spraying of Misc Areas
winter_pruning | Winter Pruning

---

## Required Relationships (Global)

Every Contract Section must have:

- field_contract (entity_reference → contracts)
  - Parent Contract.

- field_service (entity_reference → Services taxonomy)
  - Required.
  - Must be restricted to Services where:
    - Services.field_work_order_service = TRUE
  - Services is the authority for mapping to Work Orders via:
    - Services.field_service_bundle

---

## Global Client Intent Fields (Global)

Every Contract Section must have:

- field_do_you_want (list_string)
  - The client’s opt-in/selection for this section.

- field_estimate (string)
  - Contract-level estimate or estimate text/value (intent).
  - This is not execution cost.

- field_last_year (boolean)
  - Informational history flag (intent context only).

- field_client_notes (string)
  - Client-provided notes for this section (intent context only).

---

## Work Order Linkage Fields (Global)

Every Contract Section includes:

- field_work_order (entity_reference → work_order)
  - Optional linkage to the “primary” Work Order for this section.
  - This is a pointer, not execution history.

Some bundles also include additional Work Order pointers for multi-stage services:

- field_2nd_work_order (entity_reference → work_order)
- field_3rd_work_order (entity_reference → work_order)
- field_4th_work_order (entity_reference → work_order)

Observed usage:
- christmas_decorations: field_work_order (put up) + field_2nd_work_order (take down)
- deer_protection_wire: field_work_order (put up) + field_2nd_work_order (take down)
- ips_beetle_on_pinion_pine: spring + summer + fall + early spring
- lawn_fertilizing: spring + summer + fall + early spring

Rules:
- These Work Order references must always reference Work Orders for the same Contract/Property context.
- These fields must never store totals, costs, or “what happened”.

---

## Chemical / Fertilizer Intent Fields (Bundle-Specific)

Some bundles include planning/estimate-level chemical fields:

- field_chemicals_used (entity_reference)
- field_gallons_used (integer)
- field_pounds_used (decimal)
- field_service_application_notes (string)

These fields represent intent/estimation and instructions, not execution.

Execution must be recorded on Work Orders via:
- wo_chemicals_used
- wo_spraying_conditions (if used)

---

## Cleanup / Pruning Scope Fields (Bundle-Specific)

These bundles capture scope/budget intent:

- field_man_hours (decimal)
- field_set_your_budget (decimal)
- field_specific_plants (string)

Used on:
- spring_cleanup
- fall_cleanup
- summer_hedge_shrub_pruning
- christmas_decorations (man hours + budget)
- others where applicable

---

## Frequency / Season Fields (Bundle-Specific)

Examples:
- irrigation_check_ups:
  - field_check_up_frequency

- lawn_mowing_and_trimming:
  - field_mowing_frequency
  - field_mow_rate

- lawn_fertilizing:
  - field_fertilizer_app_season

- aerating_of_lawn:
  - field_aerating_season

- pre_emergent:
  - field_pre_emergent_areas
  - field_pre_emergent_season
  - field_other_areas

- weed_spraying_*:
  - field_spraying_frequency

These fields are intentionally bundle-specific.

---

## Invariants (Non-Negotiable)

- Bundles remain separate; they are not to be combined.
- Contract Sections represent intent, not execution.
- field_contract is required.
- field_service is required and must reference a Work Order Service.
- Contract Sections must not store:
  - actual time
  - actual materials used
  - actual chemicals applied
  - compliance history
  - execution totals

Execution truth lives in:
- Work Orders
- wo_time_clock
- wo_material_list + wo_material_list_item
- wo_chemicals_used

---

## Deletion / Archival

Default:
- Do not delete Contract Sections for non-draft Contracts.

Rules:
- Contract Sections must not be deleted if they are linked to Work Orders.
- Prefer Contract lifecycle (Expired/Cancelled) over deletion.

---

## Field Inventory Notes (Observed Consistency)

Global fields confirmed across the sampled bundles:
- field_contract
- field_service
- field_work_order
- field_do_you_want
- field_estimate
- field_last_year
- field_client_notes

Multi-stage Work Order linkage fields exist only where needed:
- field_2nd_work_order / field_3rd_work_order / field_4th_work_order
