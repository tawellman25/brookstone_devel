# BOS Entity — Estimate

Entity Type ID:
- estimate

Storage:
- ECK entity type

Bundles: 45 total (34 original + 11 new landscape component bundles)

---

## Architecture Notes

- Estimates are revision-controlled via `field_revision_of` + `field_revision_number` + `field_is_current_revision`
- Only the current revision (`field_is_current_revision = TRUE`) converts to a Work Order
- `field_stage` controls the estimate lifecycle (New → In Preparation → Estimate Sent → Accepted → Declined etc.)
- `field_work_order` links the accepted estimate to its resulting Work Order
- `field_estimate_total` is a rollup computed from `estimate_items` line totals
- Estimate items (`estimate_items` entity) reference back to estimate via `field_estimate`

---

## Estimate Stage TIDs

| TID | Stage |
|---|---|
| 1412 | New |
| 1413 | Contacted |
| 1414 | Appointment Set |
| 1415 | In Preparation |
| 1416 | Estimate Sent |
| 1417 | Pending |
| 1418 | Accepted |
| 1419 | Declined |
| 1420 | Under Review |
| 1421 | Client Feedback |

---

## Global Fields (Present on ALL bundles)

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

## Landscape Project Bundle — landscaping | Landscaping

This bundle serves as the PROJECT CONTAINER for multi-component landscape jobs.
It is NOT a component estimate — it is the client-facing project record.

Auto-created by `wo_project_pipeline` when Landscaping (TID 364) is selected
on an Estimate Request. The `estimate_intake` module creates the entity;
`wo_project_pipeline` promotes it to container by setting `field_is_container = TRUE`
and `field_estimate_type = 364`.

Pipeline/deposit fields:
- field_is_container | boolean | Is Container Estimate
- field_parent_estimate | entity_reference | Parent Estimate (NULL on container)
- field_estimate_type | entity_reference | Landscape Component (set to TID 364 on container)
- field_signing_deposit_received | boolean | Signing Deposit Received
- field_signing_deposit_date | datetime | Signing Deposit Date
- field_mobil_deposit_received | boolean | Mobilization Deposit Received
- field_mobil_deposit_date | datetime | Mobilization Deposit Date
- field_mobil_deposit_amount | decimal | Mobilization Deposit Amount

Scope/project fields:
- field_scope_summary | text_long | Scope Summary
- field_scope_elements | entity_reference | Scope Elements
- field_estimated_duration_days | integer | Estimated Duration (Days)
- field_requires_subs | boolean | Requires Subs

Invariants:
- field_is_container = TRUE identifies this as a project container
- WO auto-creation triggers when field_mobil_deposit_received = TRUE
- Pipeline creates one WO per child component estimate
- Container stage → Accepted (1418) when WOs are created
- field_work_order is NOT used on container — WOs are on child components

### Landscaping Sub-type Components (use landscaping bundle)

"Upgrade" service term (TID 380) maps to landscaping bundle.
These are renovation/upgrade jobs that don't have their own dedicated bundle.
field_estimate_type identifies the specific component type.
field_is_container = FALSE, field_parent_estimate = container ID.

---

## Landscape Component Bundles

These 11 bundles represent dedicated landscape service components.
Each has the same 21 fields as the landscaping bundle.
Each can be standalone OR a component under a landscaping container.

When used as a component:
- field_parent_estimate → references container landscaping estimate
- field_is_container = FALSE
- field_stage starts at In Preparation (1415)
- field_scope_summary pre-populated with: "Client is requesting a Landscaping
  project with [Component Name] included. Please review and update."

### Component Bundle Fields (all 11 share these beyond global set)

- field_is_container | boolean | Is Container Estimate
- field_parent_estimate | entity_reference | Parent Estimate
- field_estimate_type | entity_reference | Landscape Component
- field_signing_deposit_received | boolean | Signing Deposit Received
- field_signing_deposit_date | datetime | Signing Deposit Date
- field_mobil_deposit_received | boolean | Mobilization Deposit Received
- field_mobil_deposit_date | datetime | Mobilization Deposit Date
- field_mobil_deposit_amount | decimal | Mobilization Deposit Amount
- field_scope_summary | text_long | Scope Summary
- field_scope_elements | entity_reference | Scope Elements
- field_estimated_duration_days | integer | Estimated Duration (Days)
- field_requires_subs | boolean | Requires Subs

### Component Bundles

| Bundle | Label | Service TID | Standalone? |
|---|---|---|---|
| rough_grading | Rough Grading | 1770 | Yes |
| hard_scape | Hard Scape | 1771 | Yes |
| planting | Planting | 1772 | Yes |
| patios | Patios | 384 | Yes |
| retaining_walls | Retaining Walls | 383 | Yes |
| rock_work | Rock Work | 385 | Yes |
| water_features | Water Features | 378 | Yes |
| tree_shrub_planting | Tree & Shrub Planting | 394 | Yes |
| sodding | Sodding | 386 | Yes |
| hydro_seeding | Hydro Seeding | 381 | Yes |
| xeriscaping | Xeriscaping | 382 | Yes |

---

## Sprinkler Installation Bundle

Standalone job OR component under a landscaping container.

Fields beyond global set:
- field_parent_estimate | entity_reference | Parent Estimate
- field_scope_summary | text_long | Scope Summary
- field_signing_deposit_received | boolean | Signing Deposit Received
- field_signing_deposit_date | datetime | Signing Deposit Date
- field_contract_signed | boolean | Contract Signed (standalone trigger)

WO trigger (standalone): field_contract_signed = TRUE AND field_signing_deposit_received = TRUE
WO trigger (as component): triggered by parent container mobilization deposit

---

## Lighting Bundles

| Bundle | Label | Service TID |
|---|---|---|
| exterior_lighting | Exterior Lighting | 1648 |
| landscape_lighting | Landscape Lighting | 1647 |

---

## Aerating Bundle

Fields beyond global set:
- field_access_constraints | text | Access Constraints
- field_overseed | boolean | Overseed
- field_rate_per_sq_ft | decimal | Rate Per Sq Ft
- field_season | entity_reference | Season
- field_seed_cost | decimal | Seed Cost
- field_seeding_rate | decimal | Seeding Rate
- field_turf_sq_ft | integer | Turf Sq Ft

---

## Bundles with Global Fields Only (28 bundles)

aspen_twig_gall | backflow_testing | christmas_decorations | cooley_spruce_gall
deciduous_bore | deer_prevention | dethatching | dormant_oil | fall_cleanup
fertilizing | fertilizing_trees_and_shrubs | grub_prevention | lawn_mowing
misc_services | pinion_pine_ips_beetle | pre_emergent | snow_removal
special_mowing | spring_cleanup | sprinkler_backflow | sprinkler_check_up
sprinkler_design | sprinkler_repair | sprinkler_start_up | sprinkler_winterizing
summer_pruning | weed_pulling | weed_spraying

---

## Landscape Component References View

View: landscaping_component_references
Filter: field_landscape_component = TRUE on services taxonomy

| TID | Term | Bundle |
|---|---|---|
| 378 | Water Features | water_features |
| 380 | Upgrade | landscaping |
| 381 | Hydro Seeding | hydro_seeding |
| 382 | Xeriscaping | xeriscaping |
| 383 | Retaining Walls | retaining_walls |
| 384 | Patios | patios |
| 385 | Rock Work | rock_work |
| 386 | Sodding | sodding |
| 392 | Installation | sprinkler_installation |
| 394 | Tree & Shrub Planting | tree_shrub_planting |
| 1647 | Landscape Lighting | landscape_lighting |
| 1648 | Exterior Lighting | exterior_lighting |
| 1770 | Rough Grading | rough_grading |
| 1771 | Hard Scape | hard_scape |
| 1772 | Planting | planting |

---

## WO Auto-Creation

### Landscaping Container → Multiple Component WOs

Service: WoProjectPipelineService::createWoFromLandscaping()
Conditions: bundle=landscaping, field_is_container=TRUE, field_mobil_deposit_received=TRUE

Per child component:
- Creates WO (bundle matches child, status TID 1503)
- field_service from child's field_estimate_type term
- Transfers materials with 1.30x markup
- Sets child field_work_order, stage → 1418
- Sets container stage → 1418

### Sprinkler Installation → Single WO

Service: WoProjectPipelineService::createWoFromSprinklerInstallation()
Conditions: field_contract_signed=TRUE, field_signing_deposit_received=TRUE, field_work_order empty

---

## Business Setting Fields

- field_signing_deposit_amount | decimal | default: 500
- field_mobilization_deposit_pct | decimal | default: 50
- field_change_order_threshold_pct | decimal | default: 20
- field_markup | decimal | Materials Markup Multiplier (e.g. 1.30)

---

## Governance Rules

1. field_estimate_total is a rollup — never set manually
2. field_work_order on components set by pipeline service only
3. Container estimates never have line items
4. Component estimates always have field_parent_estimate set
5. field_is_container and bundle together identify role

---

## Status

Updated: April 2026 — Estimate Board rebuild, scope elements, auto-convert on acceptance

---

## Backlog: Estimate → Contract Conversion Action

**Priority:** High
**Status:** Not started
**Added:** 2026-04-19

### Problem
When a recurring service estimate is accepted (mowing, spraying, pre-emergent, check-ups, etc.), there is no automated path from the accepted estimate into a residential contract. Office staff must manually create the contract and re-enter scope and pricing from the estimate. This creates data integrity risk and is the primary gap between BOS's estimate system and its contract system.

### Governance Rule (Authoritative)
Estimates and contracts are separate entities serving different purposes and must never be conflated:

- **Estimate** = Proposal / Pricing model. Flexible, editable, non-binding. Sales-driven. May be revised freely.
- **Contract** = Agreement / Commitment. Structured, enforced, immutable after approval. Operations-driven. Drives Work Orders.

Estimates must NEVER directly generate Work Orders.
Only Contracts should generate Work Orders (via the existing contract_sections → WO machinery).

**Exception:** The landscaping and sprinkler_installation bundles use a deposit-based WO auto-creation path (field_contract_signed + field_deposit_received) which functions as a lightweight contract acknowledgment for design-build work. This path is intentional and should be preserved.

### Required Feature: "Convert to Contract" Action

When an estimate request reaches "Accepted" status on the board, a "Convert to Contract" button should appear on the estimate request page. Clicking it:

1. Creates a residential Contract for the property (or links to the existing active contract if one exists for this year)
2. Creates a Contract Section for each accepted estimate, populated with:
   - field_service from the estimate's service
   - Pricing from field_estimate_total
   - Scope from field_scope_summary
   - Back-reference: contract_sections.field_estimate_request → this estimate request
3. Sets the estimate request status to "Converted"
4. Links the contract back to the estimate request

### Services Where This Applies
Recurring services that should flow through contracts:
- Weekly Lawn Mowing
- Weed Spraying
- Pre-emergent
- Sprinkler Check-Ups
- Fertilizing
- Fertilizing Trees and Shrubs
- Aerating
- Dethatching
- Spring/Fall Cleanup
- Snow Removal

### Services That Keep Direct WO Path (no change needed)
- Landscaping (deposit-based WO auto-creation)
- Sprinkler Installation (deposit-based WO auto-creation)

### Implementation Notes
- The conversion must be explicit (button click) — never silent or automatic
- Must be idempotent — clicking twice must not create duplicate contract sections
- Must check for existing active contract before creating new one
- Contract section back-reference (field_estimate_request) enables the auto-created estimate request path to detect existing requests on renewal
- This feature belongs in a new module: estimate_contract_bridge or as an extension of estimate_contract_residential