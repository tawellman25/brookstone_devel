# BOS Entity — estimate_items

Entity Type ID: `estimate_items`
Storage: ECK

## Purpose
- Line items for an estimate. Four bundles covering the four cost categories: labor, materials, equipment, and subcontractor.
- `line_total = quantity × unit_price × (1 + markup)`. Labor has no markup.
- Totals roll up to `estimate.field_estimate_total` automatically via `estimate_items` module.

## Bundles
`labor`, `materials`, `equipment`, `subcontractor`

## Required Relationships
- `field_estimate` → `estimate` (all bundles — required parent)
- `field_material` → `material` (materials bundle)
- `field_equipment` → `equipment` (equipment bundle)
- `field_supplier` → `supplier` (subcontractor bundle)
- `field_labor_type` → `taxonomy_term` (labor bundle)
- `field_phase` → `taxonomy_term` (all bundles — estimate phase)

## Key Fields (all bundles)
- `field_quantity` — units (hours for labor, days/hours for equipment/sub, quantity for materials)
- `field_unit_price` — rate per unit
- `field_markup` — markup percentage (not present on labor)
- `field_cost_subtotal` — quantity × unit_price
- `field_line_total` — cost_subtotal × (1 + markup); equals cost_subtotal for labor
- `field_pricing_class` — list_string: how this line is classified for pricing
- `field_phase` → taxonomy term: estimate phase this item belongs to

### Bundle-specific
- `labor`: `field_labor_class`, `field_rate_per_ksqft` (production rate per 1,000 sq ft)
- `equipment`: `field_equipment_for` → taxonomy term (what the equipment is used for)
- `subcontractor`: `field_supplier` → `supplier`

## Invariants
- Labor items never have markup — `field_markup` is not present on the `labor` bundle.
- `field_line_total` is computed — do not write directly.
- Roll-up to `estimate.field_estimate_total` is managed by `estimate_items` module.
- `field_phase` and `field_pricing_class` are required for phased/classified estimates.

## Deletion / Archival
- Line items may be deleted while estimate is in draft.
- Do not delete from accepted/converted estimates without admin override.

---

# BOS Entity — estimate_notes

Entity Type ID: `estimate_notes`
Storage: ECK

## Purpose
- Notes attached to an estimate. Append-only in practice.

## Bundles
`note` (single bundle)

## Required Relationships
- `field_estimate` → `estimate`

## Key Fields
- `field_note` — long text note body
- `uid` (base) — author
- `created` (base) — timestamp

## Invariants
- Do not edit notes after creation — append new notes instead.
- Do not delete notes from accepted/converted estimates.

## Deletion / Archival
- Do not delete from accepted or converted estimates. Operational history.

---

# BOS Entity — estimate_action_log

Entity Type ID: `estimate_action_log`
Storage: ECK

## Purpose
- Append-only audit log for estimate and estimate request lifecycle events.
- Two bundles: `log` tracks estimate stage transitions; `request_log` tracks estimate request status transitions.

## Bundles
- `log` — Estimate Log: tracks stage changes on `estimate` entities
- `request_log` — Request Log: tracks status changes on `estimate_request` entities

## Required Relationships
- `log`: `field_estimate` → `estimate`
- `request_log`: `field_request` → `estimate_request`
- Both: `uid` (base) → `user` (who triggered the action)

## Key Fields (both bundles)
- `field_action` — string: action taken (e.g., `stage_change`, `status_change`, `admin_override`)
- `field_admin_override` — boolean: whether this was an admin-forced action
- `field_context` — long text: additional context about the action

### log bundle
- `field_from_stage` → `taxonomy_term` — previous estimate stage
- `field_to_stage` → `taxonomy_term` — new estimate stage

### request_log bundle
- `field_from_status` → `taxonomy_term` — previous request status
- `field_to_status` → `taxonomy_term` — new request status

## Invariants
- Append-only. Never edit or delete log entries.
- Created automatically by code on estimate/request lifecycle transitions.
- `field_admin_override = true` must be flagged when a transition bypasses normal workflow guards.

## Deletion / Archival
- Do not delete. Permanent audit trail.
