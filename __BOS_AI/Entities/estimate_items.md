# BOS Entity — estimate_items

Entity Type ID: estimate_items
Storage: ECK entity type
Bundles: labor | materials | equipment | subcontractor

---

## Purpose

Line items for an estimate. All reference parent estimate via field_estimate.
Totals roll up to estimate.field_estimate_total via estimate_items module.

---

## Global Fields (All 4 Bundles)

- field_estimate | entity_reference | Estimate (required parent)
- field_phase | entity_reference | Phase (taxonomy: estimate_phase)
- field_pricing_class | list_string | included / optional / internal_only
- field_quantity | decimal | Quantity
- field_unit_price | decimal | Unit Price
- field_cost_subtotal | decimal | Cost Subtotal (computed)
- field_line_total | decimal | Line Total (computed)

---

## Bundle: labor

Additional fields:
- field_labor_type | entity_reference | Labor Type (services taxonomy)
- field_labor_class | list_string | crew / skilled
- field_crew_size | integer | Number of Men
- field_hours_per_day | decimal | Hours Per Day (default: 8)
- field_labor_days | integer | Labor Days
- field_rate_per_ksqft | decimal | Rate Per KSqFt

### Labor Presave Compute

Step 1 — field_quantity from components (if all three set):
  field_quantity = field_crew_size × field_hours_per_day × field_labor_days

Step 2 — totals:
  field_cost_subtotal = field_quantity × field_unit_price
  field_line_total = field_cost_subtotal (NO markup on labor)

### Form Order
1. field_estimate (pre-populated via URL)
2. field_phase
3. field_pricing_class
4. field_labor_type
5. field_labor_class
6. field_crew_size
7. field_hours_per_day
8. field_labor_days
9. field_quantity (computed, read-only)
10. field_unit_price
11. field_cost_subtotal (computed, disabled)
12. field_line_total (computed, disabled)

---

## Bundle: materials

Additional fields:
- field_material | entity_reference | Material entity
- field_markup | decimal | Markup (0.1–2.0; e.g. 0.25 = 25%)

### Materials Presave Compute

Step 1 — auto-populate unit price (only when empty/zero):
  If field_unit_price empty AND field_material set:
    Load material → read field_cost_integer → set field_unit_price
  Manual entries preserved.

Step 2 — totals:
  field_cost_subtotal = field_quantity × field_unit_price
  field_line_total = field_cost_subtotal × (1 + field_markup)

### Material Cost JSON Endpoint

Route: /bos/api/material-cost/{material_id}
Controller: estimate_items\Controller\MaterialCostController
Returns: {"cost": 12.50}
Access: authenticated users
Note: Returns {"cost": 0} for missing field_cost_integer (4 live material bundles
      — annuals, plants, sod, trees — had this field added March 2026)

---

## Bundle: equipment

Additional fields:
- field_equipment | entity_reference | Equipment entity
- field_equipment_for | entity_reference | Equipment For (taxonomy)
- field_markup | decimal | Markup

Compute:
  field_cost_subtotal = field_quantity × field_unit_price
  field_line_total = field_cost_subtotal × (1 + field_markup)

---

## Bundle: subcontractor

Additional fields:
- field_supplier | entity_reference | Supplier/subcontractor entity
- field_markup | decimal | Markup

Compute:
  field_cost_subtotal = field_quantity × field_unit_price
  field_line_total = field_cost_subtotal × (1 + field_markup)

---

## Pricing Class

| Value | Included in Total? | Client Visible? |
|---|---|---|
| included | YES | YES |
| optional | NO | YES (separately) |
| internal_only | NO | NO |

Rollup: SUM(field_line_total WHERE field_pricing_class = 'included')

---

## EVA Views (on Estimate Display)

Views: estimate_items_labor, estimate_items_materials,
       estimate_items_equipment, estimate_items_subcontractor

Each view has displays: landscaping, sprinkler_installation
Each display has:
- Contextual filter: field_estimate target ID
- Filter: field_pricing_class NOT IN internal_only
- Header: "+ Add [Item Type]" modal link
- Empty area: fake table header + modal link
- Footer: "+ Add Another [Item Type]" modal link

Add item URL pattern:
/admin/content/estimate_items/add/{bundle}
  ?edit[field_estimate][widget][0][target_id]={estimate_id}
  &destination=/estimate/{estimate_id}

field_estimate must be in ACTIVE form display (not Disabled) for pre-population.

---

## Invariants

- field_line_total is computed — do not write directly
- field_cost_subtotal is computed — do not write directly
- field_quantity on labor is computed from crew components when all three are set
- Labor items never have markup
- field_phase and field_pricing_class required for correct rollup

---

## Status

Updated: March 2026
Added: field_crew_size, field_hours_per_day, field_labor_days on labor,
materials unit price auto-populate, EVA views documentation.