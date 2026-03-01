# Landscaping Estimate Bundle Specification

## Purpose
The Landscaping Estimate bundle represents construction-style landscape work executed under the single Work Order bundle `landscaping`.

It is:
- Component-driven
- Internally itemized
- Client-facing lump sum
- Revision-controlled
- Phase-aware
- Pricing-class controlled
- Immediately rollup-calculated via Estimate module service

All monetary calculations live in `estimate_items`.

---

# Architectural Principles

- Estimate Request owns intake/contact data.
- Estimate owns pricing lifecycle and conversion.
- Estimate Items own all monetary calculations.
- No pricing fields exist directly on the Estimate entity.
- No reverse reference field (`field_line_items`) exists on Estimate.
- Conversion to Work Order uses the selected Service term’s `field_service_bundle`.
- Estimate totals are recalculated immediately on estimate_items insert/update/delete.

---

# Required Fields

## Structural
- `field_estimate_request`
- `field_stage`
- `field_estimate_total` (rollup output only)
- `field_work_order`
- `field_revision_of`
- `field_revision_number`
- `field_is_current_revision`
- `field_assigned_to`
- `title`

## Component Identity
- `field_estimate_type`
  - References Services taxonomy
  - Selection handler: `landscaping_component_references`
  - Restricted to children of Landscaping
  - Used for:
    - Revision chain scoping
    - Component tracking
    - Work Order bundle mapping

Revision chain scope:

```
(estimate_request_id + field_estimate_type)
```

## Structured Scope
- `field_scope_elements` (Required)
  - Multi-value term reference → `scope_elements`
  - Dynamically filtered based on selected Service
  - Acts as checklist and scope narrowing tool

- `field_scope_summary` (Required)
  - Long text
  - Client-facing narrative summary

---

# Optional Operational Field

- `field_requires_subs` (Boolean)
  - Indicates subcontractor involvement

---

# Scope Elements Architecture

## Taxonomy: `scope_elements`
Represents structured checklist items per component.

Examples:
- Demolition / Removal
- Base Prep
- Geotextile
- Pavers
- Irrigation Tie-in
- Cleanup

## On Services Taxonomy Terms

### `field_allowed_scope_elements`
- Multi-value term reference → `scope_elements`
- Defines allowed scope elements per Service term

## Form Behavior

When `field_estimate_type` changes:
- AJAX rebuild restricts `field_scope_elements` options
- Only elements allowed by selected Service term appear

If no Service selected:
- Scope field disabled

---

# Default Estimate Item Templates

## On Services Taxonomy Terms

### `field_default_estimate_item_temp`
- Type: Entity reference revisions (Paragraph)
- Target: `estimate_item_template`
- Cardinality: Unlimited

Defines starter `estimate_items` lines generated when a Landscaping estimate is created.

Templates generate ONLY when:
- Estimate has zero estimate_items

Future regeneration must be explicit (no silent overwrites).

---

# Paragraph Type: `estimate_item_template`

Fields:

### `field_item_bundle`
List (text):
- labor
- materials
- equipment
- subcontractor

Required

### `field_item_title`
Plain text
Required

### `field_phase`
Term reference → `estimate_phase`
Required
Default: Phase 1

### `field_pricing_class`
List (text):
- included
- optional
- internal_only

Required
Default: included

### `field_default_quantity`
Decimal
Optional

### `field_default_unit_price`
Decimal
Optional

### `field_default_cost_subtotal`
Decimal
Optional

### `field_default_markup_percent`
Decimal
Optional
Default: 10

---

# Estimate Items Bundles

Bundles:
- labor
- materials
- equipment
- subcontractor

All bundles include:
- `field_estimate`
- `field_phase`
- `field_pricing_class`
- `field_quantity`
- `field_unit_price`
- `field_cost_subtotal`
- `field_line_total`

Subcontractor bundle isolates third-party work for margin clarity.

---

# Pricing Model (Implemented in Code)

Immediate rollup occurs on:
- estimate_items insert
- estimate_items update
- estimate_items delete

Canonical rule:

```
estimate.field_estimate_total =
  SUM(estimate_items.field_line_total
      WHERE field_estimate = estimate_id
        AND field_pricing_class = 'included')
```

Rules:
- Optional items excluded from total
- Internal-only items excluded from total
- Internal-only not shown to clients

---

# Client Display Rules

Client view shows:
- Included phase subtotals
- Grand total (included only)
- Optional totals separately

Client view does NOT show:
- Internal-only items
- Cost subtotals
- Markup percentages

---

# What This Bundle Is

- Structured
- Component-driven
- Checklist-guided
- Template-supported
- Revision-safe
- Work Order convertible
- Margin-controlled
- Immediately consistent via rollup service

---

# What This Bundle Is Not

- Formula-based
- Intake duplicate
- Manual total override surface
- Multi-component container

---

# Future Expansion Path

Possible enhancements:
- Scope-element-driven auto-template generation
- Explicit “Regenerate Templates” action
- Phase acceptance logic
- Component-level automation

Architecture supports expansion without refactor.

---

Status: Landscaping bundle aligned with implemented rollup engine and template architecture. Production-stable.
