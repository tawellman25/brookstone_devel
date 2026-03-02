# BOS Estimate Module — Current Architecture & Operational State

Authoritative snapshot of the Estimate module as of this stabilization phase.

---

# 1. Module Purpose

The `estimate` module governs the full estimating domain within BOS:

* Estimate Requests (intake layer)
* Estimates (service/component-specific quotes)
* Estimate Items (labor/materials/equipment/subcontractor pricing engine)
* Revision chains (per Request + per Component)
* Controlled conversion to Work Orders
* Guardrails preventing duplicate or invalid conversions

It does **not** contain Contract logic.
It does **not** auto-create Contacts or Properties.
It does **not** calculate totals in multiple places.

Domain separation is enforced.

---

# 2. Entity Architecture

## 2.1 Estimate Request (`estimate_request`, bundle: standard)

Role: Intake container / opportunity umbrella.

One Estimate Request → Many Estimates.

### Key Fields

* `field_owner`
* `field_contact`
* `field_property`
* `field_contract`
* `field_contract_section`
* `field_service`
* `field_priority`
* `field_status`
* `field_client_requested`
* `field_requestor_name`
* `field_requestor_phone`
* `field_requestor_email`
* `field_requestor_address`
* `field_estimates`

### Governance

* Intake must not be blocked if Property does not exist.
* Property creation is explicit, not automatic.
* Contact creation is explicit, not automatic.
* No data mutation without user intent.

Optional readiness gate:

* A configurable status (`estimate_request_ready_status_tid`) may enforce required fields before allowing Estimate generation.
* This gate is inactive unless configured.

---

## 2.2 Estimate (`estimate`, multiple bundles)

Role: Component-specific quote.

Each Estimate:

* References exactly one Estimate Request (`field_estimate_request` required)
* Has its own stage lifecycle
* Has its own revision chain
* Converts independently to a Work Order

### Required Core Fields

* `field_estimate_request`
* `field_stage`
* `field_estimate_total`
* `field_work_order`
* `field_estimate_type`

### Revision Fields

* `field_revision_of`
* `field_revision_number`
* `field_is_current_revision`

### Revision Rules

Revision chains are scoped by:

```
(estimate_request_id + estimate_type_id)
```

Rules:

* First estimate in chain → `revision_number = 1`
* Revisions increment sequentially
* Only one estimate per chain may have `field_is_current_revision = TRUE`
* Only current revision may convert to Work Order

---

## 2.3 Estimate Items (`estimate_items`)

Bundles:

* labor
* materials
* equipment
* subcontractor

### Required Fields

* `field_estimate`
* `field_quantity`
* `field_unit_price`
* `field_cost_subtotal`
* `field_line_total`

Optional:

* `field_markup` (decimal; supports either 10 or 0.10 input)
* `field_phase`
* `field_pricing_class`

### Pricing Rules

For each Estimate Item:

```
subtotal = quantity × unit_price
line_total = subtotal × (1 + normalized_markup)
```

* Markup normalization allows either `10` or `0.10` input.
* Labor bundle does not include markup.

### Totals Rule (Single Source of Truth)

```
estimate.field_estimate_total =
  SUM(estimate_items.field_line_total
      WHERE field_estimate = estimate_id
      AND field_pricing_class != internal_only)
```

Totals are never manually entered.

---

# 3. Conversion System

## 3.1 Service: `estimate.work_order_converter`

Responsible for:

* Validating stage (must equal Accepted TID from config)
* Validating current revision
* Preventing duplicate Work Orders
* Creating Work Order of matching bundle
* Linking both directions:

  * `estimate.field_work_order`
  * `work_order.field_estimate`

Additional mapping:

* `work_order.field_contact`
* `work_order.field_contract`
* `work_order.field_property`
* `work_order.field_service`
* `work_order.field_estimated_price` = `estimate.field_estimate_total`

Conversion must:

* Be explicit
* Never auto-run silently
* Never create duplicate WOs

---

# 4. Configuration

## `estimate.settings`

Must exist in active configuration.

Contains:

* `accepted_stage_tid`
* `declined_stage_tid`
* (optional) `estimate_request_ready_status_tid`

On fresh installs:

* Defaults are provided via `config/install`.

On existing installs:

* Values must be set manually using `drush config:set`.

No taxonomy term IDs are hardcoded in PHP logic.

---

# 5. Guardrails Verified

* No WSOD on enable
* Container compiles
* Services resolve correctly
* No duplicate Work Orders
* Conversion idempotent
* Totals roll up from items

Pending full verification:

* End-to-end Estimate Request → Estimate → Items → Accepted → Convert

---

# 6. Explicit Non-Behavior (Intentional)

The module does NOT:

* Auto-create Properties
* Auto-create Contacts
* Overwrite Contact data
* Hardcode taxonomy term IDs in logic
* Restrict `estimate_request.field_estimates` by bundle

---

# 7. Known Hardening Areas

To fully production-harden:

* Add "Accepted Estimates Missing Work Order" view
* Add duplicate address detection before Property creation
* Add structured logging for conversion failures
* Add automated test for idempotent conversion
* Add totals recalculation test on item delete

---

# 8. Current Status

Module is operational.
No active WSOD.
Conversion service functional.
Stage gating config-driven.
Revision chains enforced.
Totals engine deterministic.

System ready for full real-world Estimate flow testing.

---

# 9. Estimate Bundle Core Field Parity (as of 2026-03-02)

All 35 estimate bundles (34 service-specific + landscaping) now have all 8 core fields:

| Field | Purpose |
|---|---|
| `field_assigned_to` | Estimator (user, teammates role; views handler: teammate_user_reference) |
| `field_estimate_request` | Parent intake request (required; target bundle: standard) |
| `field_estimate_total` | Computed dollar total (decimal; prefix '$ '; rolls up from estimate_items) |
| `field_is_current_revision` | Boolean flag; only one revision per chain may be TRUE |
| `field_revision_number` | Integer; starts at 1, increments per revision |
| `field_revision_of` | Self-referential entity_reference to previous revision in chain |
| `field_stage` | Estimate lifecycle stage (taxonomy_term: estimate_stage vocab) |
| `field_work_order` | Linked Work Order after conversion (same-named WO bundle target per estimate bundle) |

Default value on `field_assigned_to`: uid 04140ad1-bc72-49a6-b12f-19e53aaf21b1.
Default value on `field_stage`: term uuid 863a00e0-b80a-4712-a4d3-b175f7bea5fd (estimate_stage vocab).

Exception — `estimate.pinyon_pine_ips_beetle`:
- `field_work_order` targets `work_order.pinion_pine_ips_beetle` (legacy WO bundle spelling).
- See CLAUDE.md Known Issues for rename tracking.

Note: `field_estimates` on `estimate_request.standard` now allows all 34 service-specific
estimate bundles (updated from landscaping-only).

---

# 10. Estimate Workflow Entry Points

## Entry Point A: Contract Path (Auto-Created)

Trigger: Office user sets contract_sections.field_do_you_want = '3' (Request Quote).

Automated sequence:
1. hook_entity_insert / hook_entity_update fires on contract_sections.
2. EstimateRequestAutoCreator (estimate_contract_residential module) creates
   an estimate_request.standard with all available fields pre-populated from
   the section and its parent contract (see estimate_request.md for field mapping).
3. field_assigned_to is set from service_term.field_default_estimator if present.
4. Back-reference written to contract_sections.field_estimate_request.
5. estimate_notifications module fires; sends assignment email to field_assigned_to
   user if field_assigned_to was just set (insert with value, or empty → value on update).

Office steps after auto-creation:
- Review the new Estimate Request.
- Create one or more Estimates (per service component) linked to the Request.
- Build Estimate Items (labor, materials, equipment, subcontractor) on each Estimate.
- Send to client for acceptance.

## Entry Point B: Manual Office Entry

Office user navigates to /estimate_request/add and creates a request directly.
All fields populated manually. No auto-creation side effects.
Assignment email fires via estimate_notifications if field_assigned_to is set.

---

# 11. Known Gaps (Estimate Workflow — As of 2026-03-02)

The following workflow steps exist in design but are not yet implemented in code:

| Gap | Description |
|---|---|
| Estimate creation from request | No automated creation of estimate entities from an estimate_request; manual only |
| Status transitions | No formal state machine; field_status is manually set |
| WO conversion on acceptance | estimate.work_order_converter service exists but no UI trigger or acceptance workflow driving it |
| Client acceptance UI | No client-facing portal page or email link to accept/decline an estimate |
| Client notification on estimate delivery | No outbound email to client when an estimate is ready for review |
| Re-assignment notification | estimate_notifications only handles initial assignment (empty → value); reassignment email not yet implemented |

---

End of document.
