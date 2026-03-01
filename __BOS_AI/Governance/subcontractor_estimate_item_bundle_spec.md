# Subcontractor Estimate Item Bundle Specification

## Purpose
The Subcontractor bundle represents third-party labor or specialty services performed by external vendors under an estimate.

This bundle isolates subcontracted cost from internal labor, materials, and equipment for margin clarity and reporting integrity.

---

# Bundle Machine Name

`subcontractor`

---

# Required Fields

## Core Pricing Fields
- `field_estimate` (Required reference to Estimate)
- `field_cost_subtotal` (Decimal)
- `field_markup_percent` (Decimal, Required, Default = 10)
- `field_line_total` (Calculated)
- `field_phase` (Term reference → estimate_phase)
- `field_pricing_class` (List: included, optional, internal_only)

## Supplier Reference
- `field_supplier` (Relabeled: Subcontractor (Supplier))

### Help Text (Recommended)

Select the external subcontractor performing this work. Use only for third-party vendors billing Brookstone. Internal crew work must use the Labor bundle. Cost entered represents subcontractor base cost; markup is applied at the line level.

---

# Pricing Logic

Line total calculation:

```
line_total = field_cost_subtotal * (1 + (field_markup_percent / 100))
```

- Markup is stored as percentage (10 = 10%)
- Do not enter .10
- `field_line_total` must not be manually edited

---

# Automatic Label Pattern

```
Subcontractor – {Phase} – {Pricing Class}
```

Examples:
- Subcontractor – Phase 1 – Included
- Subcontractor – Optional – Optional

---

# Usage Guidelines

Use this bundle for:
- Masonry subcontractors
- Electrical subcontractors
- Concrete subcontractors
- Appliance installers
- Specialty trades

Do not use for:
- Internal labor
- Material purchases without third-party labor
- Equipment rental only (use equipment bundle)

---

# Governance Rules

- Markup is required
- Default markup = 10%
- Internal-only items must not carry client-facing pricing
- Supplier selection should reference approved vendors only

---

Status: Production-ready. Designed for margin clarity and future supplier reporting.

