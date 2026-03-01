# BOS Data-Flow Map (Operational + Public Catalog)

This map shows how BOS data flows from public-facing service content
to contracts (intent) to work orders (execution) and their child records.

Legend:
- (ECK) = ECK entity type
- (Tax) = taxonomy
- → = primary reference / data flow
- [snapshot] = value copied at time of use for historical accuracy

---

## 1) Public Website → Services Catalog (Tax)

Public Pages:
- Public service pages are driven by Services taxonomy terms (Tax: services).
- Not every public service is a Work Order service.

Services (Tax: services)
- field_work_order_service (boolean)
  - TRUE: can generate Work Orders
  - FALSE: grouping/marketing-only
- field_service_bundle (string)
  - Work Order bundle machine name (required when WO=TRUE)

Flow:
Public content (pages) → Services terms

---

## 2) Properties Anchor Everything (ECK)

Properties (ECK: properties)
- The physical location where work happens.
- Crew-facing name: field_nickname (mutable, does not change URL).
- Location grouping: field_zipcode_reference (ECK Zipcode).
- Maps: field_geofield.

Flow:
Property is the anchor for all execution:
Properties → Work Orders

---

## 3) Contracts Define Intent (ECK)

Contracts (ECK: contracts)
- Agreement owner: client/profile
- Agreement scope: property
- Lifecycle: Draft/Active/Expired/Cancelled

Flow:
Contracts → Contract Sections

---

## 4) Contract Sections Capture Service-Specific Intent (ECK)

Contract Sections (ECK: contract_sections)
- One section = one service commitment (bundle per service type)
- References Contract:
  - field_contract → contracts
- References Service (authoritative mapping):
  - field_service → Services term (filtered to WO=TRUE)
- May link to Work Orders (optional linkage, not execution):
  - field_work_order, field_2nd_work_order, field_3rd_work_order, field_4th_work_order

Key mapping flow (authoritative):
contract_sections.field_service
  → services.field_service_bundle
    → work_order.bundle

Flow:
Contracts → Contract Sections → Service → Work Order bundle

---

## 5) Work Orders Record Execution (ECK)

Work Orders (ECK: work_order)
Required:
- field_property → properties
- field_service → Services term
- field_status → Status reference

Optional:
- field_contract → contract (when applicable)
- field_scheduled (boolean scheduling flag)
- totals and flags used for reporting/billing exports

Invariant:
- work_order.bundle must match
  work_order.field_service.term.field_service_bundle

Flow:
Contract intent may generate Work Orders:
Contract Sections → Work Orders
Execution always ties back to Property:
Properties → Work Orders

---

## 6) Work Order Child Entities (Execution Detail)

Work Orders stay lean by using child entity types that reference the Work Order.

### Time Tracking (ECK: wo_time_clock)
wo_time_clock.field_work_order → work_order
wo_time_clock.field_teammate → user
- field_start_time / field_end_time
- field_total_time

Roll-up:
work_order.field_total_time
  = SUM(wo_time_clock.field_total_time)

---

### Materials Used (ECK: wo_material_list + wo_material_list_item)

wo_material_list.field_work_order → work_order
wo_material_list_item.field_list_id → wo_material_list

Stocked materials:
- wo_material_list_item.field_parts_used → material (ECK)
- wo_material_list_item.field_material_cost [snapshot] unit cost at time of use

Purchased materials:
- wo_material_list_item stores purchase name/supplier/receipt
- still uses field_material_cost [snapshot]

Roll-up:
Work Order material totals are derived from item subtotals.

---

### Chemicals Used (ECK: wo_chemicals_used)

wo_chemicals_used.field_work_order → work_order
wo_chemicals_used.field_chemical → chemical (ECK)
wo_chemicals_used.field_chemical_cost [snapshot] unit cost at time of use

Tank mix / application fields vary by bundle.

Roll-up:
Work Order chemical totals are derived from chemical subtotals.

---

### Equipment Usage (ECK: wo_rental_equipment or equivalent)

WO equipment usage record → work_order
WO equipment usage record → equipment (ECK)
- snapshot rates/costs recommended for historical accuracy

Roll-up:
Equipment subtotals contribute to Work Order totals if billable.

---

### Notes / Tasks / Status Timeline (ECK)
- wo_notes → work_order
- wo_tasks_list → work_order
- wo_status_updates → work_order
- wo_complete_info → work_order
- wo_material_dumping → work_order

Rule:
- Child records must never be orphaned.
- Completed Work Orders restrict edits (admin-only corrections).

---

## 7) Pricing Snapshot Rules (Historical Integrity)

Material (ECK: material)
- current/default cost stored on Material entity
Work Order usage snapshots:
- wo_material_list_item.field_material_cost [snapshot]

Chemical (ECK: chemical)
- current/default cost stored on Chemical entity: field_material_cost
Work Order usage snapshots:
- wo_chemicals_used.field_chemical_cost [snapshot]

Equipment (ECK: equipment)
- current/default rates/costs stored on Equipment entity
Work Order usage snapshots:
- equipment usage record stores snapshot rates/costs [snapshot]

Invariant:
- Completed Work Orders must never be retroactively repriced.

---

## 8) End-to-End Flow Summary

Public Catalog:
Services (Tax)
  → (optional) public pages and grouping

Intent:
Properties (ECK)
  + Contracts (ECK)
    → Contract Sections (ECK)
      → Service (Tax)
        → Work Order bundle (config)

Execution:
Work Orders (ECK)
  → Time (wo_time_clock)
  → Materials (wo_material_list + items)
  → Chemicals (wo_chemicals_used)
  → Equipment usage (wo_rental_equipment)
  → Notes / Tasks / Status timeline

Outputs:
- Scheduling views
- Crew execution
- Billing exports / costing
- Compliance history

