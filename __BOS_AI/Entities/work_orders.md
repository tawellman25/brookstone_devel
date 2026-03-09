# BOS Entity — Work Orders

Entity Type ID:
- work_order

Storage:
- ECK entity type

Bundle Strategy:
- One ECK entity type (`work_order`)
- Bundles represent the service/task type (what work is being performed)
- Lifecycle/state is not bundle-driven

---

## Bundles (Service / Task Types)

Machine Name | Label
--- | ---
aerating | Aerating
aspen_twig_gall | Aspen Twig Gall
christmas_decorations | Holiday Decorations
cooley_spruce_gall | Cooley Spruce Gall
deciduous_bore | Deciduous Bore
deer_prevention | Deer Prevention
dethatching | Dethatching
dormant_oil | Dormant Oil
fall_cleanup | Fall Cleanup
fertilizing | Fertilizing
fertilizing_trees_and_shrubs | Fertilizing Trees and Shrubs
grub_prevention | Grub Prevention
in_house_tasks | In House Tasks
landscaping | Landscaping
lawn_mowing | Lawn Mowing
landscape_lighting | Landscape Lighting
exterior_lighting | Exterior Building Lighting
estimate | Estimate
misc_services | Misc Services
pinion_pine_ips_beetle | Pinyon Pine Ips Beetle
pre_emergent | Pre-emergent
snow_removal | Snow Removal
special_mowing | Special Mowing
spring_cleanup | Spring Cleanup
backflow_testing | Backflow Testing
sprinkler_check_up | Sprinkler Check-Up
sprinkler_design | Sprinkler Design
sprinkler_installation | Sprinkler Installation
sprinkler_repair | Sprinkler Repair
sprinkler_start_up | Sprinkler Start-Up
sprinkler_winterizing | Sprinkler Winterizing
summer_pruning | Pruning
trunk_bore | Trunk Bore
weed_pulling | Weed Pulling
weed_spraying | Weed Spraying
winter_pruning | Winter Pruning

---

## Purpose

- Canonical execution record of operational work performed.
- Represents what happened, where, when, by whom, and with what.
- Supports operational reporting, scheduling, billing exports, and audit history.

---

## Required Relationships (must exist)

- field_property (entity_reference → properties)
  - The property where work is performed.

- field_service (entity_reference → Service)
  - The BOS service classification for the Work Order.

- field_status (entity_reference → Status)
  - Lifecycle status (Scheduled/In Progress/Completed/etc. as defined in Status entity/vocab).

---

## Optional but Common Relationships

- field_contract (entity_reference → Contract)
  - Contract context when work is contract-driven.

- field_supervisor (entity_reference → User)
  - Supervisor for accountability/approval flow.

- field_estimate (entity_reference → Estimate)
  - Estimate context when work is estimate-driven.

---

## Scheduling

- field_scheduled (boolean)
  - Indicates the Work Order is scheduled.
  - Note: boolean scheduling flags are allowed, but status should remain the true lifecycle driver.

---

## Time & Totals (Operational + Billing Context)

Time:
- field_total_time (decimal)
  - Canonical time recorded against the Work Order.

Billing/Export Context:
- field_estimated_price (decimal)
- field_trip_fee (decimal)
- field_dump_fee_total (decimal)
- field_rental_total (decimal)
- field_labor_total (decimal)
- field_material_chemical_total (decimal)
- field_billing_adjustment (decimal)
- field_wo_total (decimal)

Billing Notes:
- field_billing_notes (string)

Invoice State Flags:
- field_invoiced (boolean)
- field_printed (boolean)

Invariant:
- Completed Work Orders must not allow silent mutation of totals that have been exported/invoiced.
- If adjustments occur post-export, they must be explicit and role-restricted.

---

## Work Description & Notes

- field_work_todo_description (text_long)
  - Work scope / instructions for the crew.

- field_work_order_notes (comment)
  - Execution notes and history.

---

## Identifiers

- field_work_order_id (integer)
  - BOS-visible Work Order ID (separate from entity `id`).

Invariant:
- field_work_order_id must be stable and never reused.

---

## External App / Client Check-in Support

- field_client_app_wo_number (string)
  - Work order/check-in reference required by a client’s external app (when applicable).

---

## Bundle Extensions (Bundle-Specific Fields)

These fields are not assumed global; they are documented here as bundle extensions.

### aerating
- field_aeration_season (entity_reference)
- field_task_rate (decimal) — Aeration Rate
- field_current_turf_sq_footage (integer)
- field_trucks (integer)

Note:
- If any of the above are truly global (used outside aerating), move them to the appropriate global sections.

---

## Deletion / Archival

Default:
- Do not delete completed Work Orders.

Allowed (restricted):
- Draft/voided Work Orders with no operational history.

Preferred:
- Status-based archival over deletion.

---

## Invariants (must / never rules)

- A Work Order must always reference exactly one Property.
- Work Orders represent execution, not intent (Contracts represent intent).
- Completed Work Orders are logically immutable.
- URLs and IDs must remain stable.
- Property Nickname (Property `field_nickname`) must be surfaced prominently for crews, without affecting URLs.

---

## Field Inventory (from bundle sample)

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Type
- title | string | Title
- uid | entity_reference | Entered by
- created | created | Entered on
- changed | changed | Changed
- default_langcode | boolean | Default translation
- path | path | URL alias

Bundle sample fields (aerating):
- field_aeration_season | entity_reference | Aeration Season
- field_billing_adjustment | decimal | Billing Adjustment
- field_billing_notes | string | Billing Notes
- field_client_app_wo_number | string | Client App WO Number
- field_contract | entity_reference | Contract
- field_current_turf_sq_footage | integer | Current Turf sq. footage
- field_dump_fee_total | decimal | Dump Fee Total
- field_estimated_price | decimal | Estimated Price
- field_invoiced | boolean | Invoiced
- field_labor_total | decimal | Labor
- field_material_chemical_total | decimal | Materials Total
- field_printed | boolean | Printed
- field_property | entity_reference | Property
- field_rental_total | decimal | Rental Total
- field_scheduled | boolean | Scheduled
- field_service | entity_reference | Service
- field_status | entity_reference | Status
- field_supervisor | entity_reference | Supervisor
- field_task_rate | decimal | Aeration Rate
- field_total_time | decimal | Total Time
- field_trip_fee | decimal | Trip Fee
- field_trucks | integer | Trucks
- field_wo_total | decimal | Total
- field_work_order_id | integer | Work Order ID
- field_work_order_notes | comment | Notes
- field_work_todo_description | text_long | Work To Be Done

## Dependent Entities (Work Order Children)

BOS uses multiple supporting ECK entity types that reference a Work Order.
These are “child records” that extend a Work Order without bloating the Work Order entity.

General Rules:
- Each child entity must reference exactly one Work Order.
- Child entities may be created/updated during execution, including after scheduling.
- Once the parent Work Order is Completed, child entity edits are restricted to:
  - notes / attachments
  - role-limited administrative corrections
- Deletion must be controlled:
  - Deleting a Work Order must explicitly handle children (no surprise cascades).
  - Child records must not be orphaned.

### Child Entity Types

#### Material Tracking (Two-Level Child Model)

  BOS models Work Order materials using a parent list entity and item entities.

  Entities:
  - wo_material_list
    - Purpose: container/list for materials associated with a Work Order.
    - Relationship:
      - wo_material_list → work_order (required)

  - wo_material_list_item
    - Purpose: individual material line item entry (actual usage).
    - Relationship:
      - wo_material_list_item → wo_material_list (required)
      - wo_material_list_item → material (required)

  Behavior:
  - Users select a Material (entity type: material) and enter a quantity used.
  - Unit price is sourced from the referenced Material entity.
  - Line totals are calculated from:
    - unit price (from Material) × quantity used
  - Work Order material totals may roll up from these line items.

  Invariants:
  - wo_material_list_item must always reference exactly one wo_material_list.
  - wo_material_list_item must always reference exactly one material.

  Pricing (Historical Snapshot)

  - Unit cost/price is sourced from the referenced Material entity at time of entry.
  - That value is written into a dedicated decimal field on `wo_material_list_item`
  to preserve historical pricing.
  - Line totals are calculated from:
  - unit price snapshot (decimal field) × quantity used

  Source of Truth:
  - Material entity is the source of truth for “current/default price”.
  - `wo_material_list_item` unit price snapshot is the source of truth for
  “price at time of use” on that Work Order.

  Invariant:
  - Once a Work Order is Completed, the unit price snapshot on each
  `wo_material_list_item` must not change except via admin correction.

  Deletion / Archival:
  - Do not allow orphaned items:
    - deleting a wo_material_list must handle its wo_material_list_item children explicitly.
  - Completed Work Orders should treat material items as immutable (except admin corrections).

  Reporting Expectations:
  - Materials used reports must be derivable from wo_material_list_item.
  - Work Order material totals must be reproducible from recorded items (no “magic totals”).

- wo_chemicals_used
  - Purpose: record chemicals/products applied during spraying-related work.

- wo_complete_info
  - Purpose: store completion metadata specific to a Work Order bundle/workflow.

- wo_rental_equipment
  - Purpose: record equipment/rental equipment used on a Work Order.

- wo_material_dumping
  - Purpose: record dump loads, dumping materials, and dumping-related totals/metadata.

- wo_notes
  - Purpose: structured notes attached to a Work Order (distinct from comment field usage).

- wo_spraying_conditions
  - Purpose: record conditions relevant to spraying (weather, wind, temp, etc.) for compliance/quality.

- wo_status_updates
  - Purpose: append-only status/event timeline for Work Orders (progress tracking + audit trail).

- wo_tasks_list
  - Purpose: checklist/task items tied to a Work Order (execution guidance and completion).

- wo_time_clock
  - Purpose: time punches / time tracking entries tied to a Work Order (source for totals).

### Ownership & Access (baseline expectation)

- Parent Work Order drives access:
  - If a user can view the Work Order, they can view its children.
  - If a user can edit the Work Order during execution, they can add/edit relevant children.

- Post-completion:
  - Most child entities should become read-only except role-limited corrections.

### Reporting Expectations

- Work Order totals may be derived from children (e.g., time clock, materials, dumping).
- Reporting must not require children to exist for the Work Order to remain valid.
  - Children extend; they do not define existence.

