# BOS Entity — Estimate

Entity Type ID:
- estimate

Storage:
- ECK entity type

Bundles: 34 total (see below)

---

## Architecture Notes

- Estimates are revision-controlled via `field_revision_of` + `field_revision_number` + `field_is_current_revision`
- Only the current revision (`field_is_current_revision = TRUE`) converts to a Work Order
- `field_stage` controls the estimate lifecycle (Draft → Presented → Accepted → Declined)
- `field_work_order` links the accepted estimate to its resulting Work Order
- `field_estimate_total` is a rollup computed from `estimate_items` line totals
- Estimate items (`estimate_items` entity) reference back to estimate via `field_estimate`

---

## Global Fields (Present on ALL 34 bundles)

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Type
- title | string | Title
- uid | entity_reference | Entered By
- created | created | Entered on
- changed | changed | Updated
- default_langcode | boolean | Default translation
- path | path | URL alias

Common operational fields:
- field_assigned_to | entity_reference | Assigned To
- field_contract_section | entity_reference | Contract Section
- field_estimate_request | entity_reference | Estimate Request
- field_estimate_total | decimal | Estimate Total
- field_is_current_revision | boolean | Is Current Revision
- field_revision_number | integer | Revision Number
- field_revision_of | entity_reference | Revision Of
- field_stage | entity_reference | Stage
- field_work_order | entity_reference | Work Order

---

## Bundle Extensions (Fields beyond global set)

### landscaping | Landscaping

Pipeline/deposit fields (added Phase 1 — Project Pipeline):
- field_contract_signed | boolean | Contract Signed
- field_deposit_received | boolean | Deposit Received
- field_deposit_amount | decimal | Deposit Amount
- field_deposit_date | datetime | Deposit Date
- field_linked_work_order | entity_reference | Linked Work Order

Scope/component fields:
- field_estimate_type | entity_reference | Landscape Component
- field_estimated_duration_days | integer | Estimated Duration (Days)
- field_requires_subs | boolean | Requires Subs
- field_scope_elements | entity_reference | Scope Elements
- field_scope_summary | text_long | Scope Summary

Invariants:
- field_linked_work_order is read-only on form — set by WoProjectPipelineService only
- WO auto-creation triggers when field_contract_signed = TRUE AND field_deposit_received = TRUE
- One WO per estimate enforced by duplicate guard in WoProjectPipelineService

### sprinkler_installation | Sprinkler Installation

Pipeline/deposit fields (added Phase 1 — Project Pipeline):
- field_contract_signed | boolean | Contract Signed
- field_deposit_received | boolean | Deposit Received
- field_deposit_amount | decimal | Deposit Amount
- field_deposit_date | datetime | Deposit Date
- field_linked_work_order | entity_reference | Linked Work Order

Invariants:
- Same pipeline behavior as landscaping bundle
- WO auto-creation triggers when field_contract_signed = TRUE AND field_deposit_received = TRUE
- One WO per estimate enforced by duplicate guard in WoProjectPipelineService

### aerating | Aerating

- field_access_constraints | ? | Access Constraints
- field_overseed | boolean | Overseed
- field_rate_per_sq_ft | decimal | Rate Per Sq Ft
- field_season | entity_reference | Season
- field_seed_cost | decimal | Seed Cost
- field_seeding_rate | decimal | Seeding Rate
- field_turf_sq_ft | integer | Turf Sq Ft

---

## Bundles with Global Fields Only (31 bundles)

The following 31 bundles contain only the global field set documented above.
No bundle-specific fields have been added.

aspen_twig_gall | Aspen Twig Gall
backflow_testing | Backflow Testing
christmas_decorations | Holiday Decorations
cooley_spruce_gall | Cooley Spruce Gall
deciduous_bore | Deciduous Bore
deer_prevention | Deer Prevention
dethatching | Dethatching
dormant_oil | Dormant Oil
exterior_lighting | Exterior Lighting
fall_cleanup | Fall Cleanup
fertilizing | Fertilizing
fertilizing_trees_and_shrubs | Fertilizing Trees and Shrubs
grub_prevention | Grub Prevention
landscape_lighting | Landscape Lighting
lawn_mowing | Lawn Mowing
misc_services | Misc Services
pinion_pine_ips_beetle | Pinyon Pine Ips Beetle
pre_emergent | Pre-emergent
snow_removal | Snow Removal
special_mowing | Special Mowing
spring_cleanup | Spring Cleanup
sprinkler_backflow | Sprinkler Backflow
sprinkler_check_up | Sprinkler Check-Up
sprinkler_design | Sprinkler Design
sprinkler_repair | Sprinkler Repair
sprinkler_start_up | Sprinkler Start-Up
sprinkler_winterizing | Sprinkler Winterizing
summer_pruning | Summer Pruning
trunk_bore | Trunk Bore
weed_pulling | Weed Pulling
weed_spraying | Weed Spraying

Note: cooley_spruce_gall has slightly different base field labels
("Authored by"/"Changed" vs "Entered By"/"Updated") — minor inconsistency
from original bundle creation. Does not affect functionality.

---

## Revision Chain Scope

For landscaping bundle:
- Revision chain is scoped by: estimate_request_id + field_estimate_type
- field_is_current_revision = TRUE identifies the active revision
- Only active revisions are eligible for WO conversion

---

## WO Auto-Creation (landscaping + sprinkler_installation only)

Trigger: estimate postsave hook in wo_landscaping / wo_sprinkler_installation modules
Service: WoProjectPipelineService (wo_project_pipeline module)

Conditions:
- field_contract_signed = TRUE
- field_deposit_received = TRUE
- field_linked_work_order IS EMPTY (duplicate guard)

On trigger:
- Creates work_order in Accepted status (TID 1503)
- Populates: field_estimate, field_property, field_contact, field_service,
  field_estimated_price, field_work_todo_description
- Creates wo_material_list (bundle: material_list) for the WO
- Transfers estimate_items:materials → wo_material_list_item:items
  - Snapshots field_cost_integer from material entity
  - Calculates field_subtotal = cost × quantity
  - Calculates field_subtotal_w_markup = subtotal × business_setting.field_markup
- Writes field_linked_work_order back to estimate

---

## Estimate Items Relationship

estimate_items entity references estimate via field_estimate.
Four bundles: labor | materials | equipment | subcontractor

Line total formula:
- line_total = quantity × unit_price × (1 + markup)
- Labor has no markup
- Totals roll up to estimate.field_estimate_total automatically

---

## Governance Rules

1. field_estimate_total is a rollup — never set manually
2. field_linked_work_order is read-only on form — never set manually
3. field_is_current_revision must be maintained by revision logic only
4. Accepted estimates must not be edited — create a revision instead
5. field_work_order is set on conversion — not before
6. Only estimate_items with pricing_class = included contribute to estimate total
7. internal_only pricing class items are excluded from client-facing displays

---

## Status

Updated: March 2026 — Phase 1 Project Pipeline
Added: field documentation for all 34 bundles, pipeline fields on
landscaping + sprinkler_installation, WO auto-creation documentation,
estimate items relationship, governance rules.