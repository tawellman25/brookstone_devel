# BOS View — property_estimates

View ID: property_estimates
Label: Property - Estimates
Base entity: estimate
Path: /properties/%properties/estimates
Menu: Tab — "Estimates" (weight 1, appears after Work Orders)

---

## Purpose

Shows all estimates linked to a property via the estimate_request
relationship chain. Provides estimators and office staff a quick
view of all estimate history for a property.

---

## URL Pattern

```
/properties/{property_id}/estimates
```

Example:
```
/colorado/delta-county/delta/81416/680-cypress-wood-ln/estimates
```

---

## Relationship Chain

estimate → field_estimate_request → estimate_request → field_property → properties

Contextual filter resolves property ID from URL path alias.

---

## Fields

| Field | Label | Notes |
|---|---|---|
| title | Estimate | Linked to /estimate/{id} |
| type | Type | Bundle label |
| field_stage | Stage | Taxonomy term |
| field_assigned_to | Estimator | Linked user |
| field_estimate_total | Total | $X,XXX.XX format |
| created | Created | Short date format |

---

## Filters (Exposed)

- Type (bundle) — multi-select
- Stage — taxonomy multi-select

---

## Sort

Created date descending (newest first)

---

## Access

Same roles as property_work_orders:
administrator, site_admin, administration, supervisor, teammates

---

## Pager

25 items per page, full pager

---

## No Results Text

"No estimates found for this property."

---

## Related Views

- property_work_orders — Work Orders tab at /properties/%/work-orders
- Both appear as tabs on the property page alongside Contracts, Sprinklers, Ownership

---

## Status

Created: March 2026
