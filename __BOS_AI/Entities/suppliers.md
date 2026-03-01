# BOS Entity — Supplier

Entity Type ID:
- supplier

Storage:
- ECK entity type

---

## Purpose

Supplier entities represent **vendor master records** in BOS.

Suppliers define:
- who we buy from
- how we order
- logistics and payment defaults
- vendor status and governance

Suppliers do **not** store material-specific pricing or SKUs. Those belong to the Material–Supplier link entity.

---

## Bundles (Machine Name | Label)

supplier | Supplier

---

## Required Relationships

None.

Supplier is a standalone vendor master. All material-specific relationships are handled via `material_suppliers`.

---

## Key Fields (Authoritative)

These fields define Supplier behavior and operational use. Cosmetic or purely informational fields are omitted.

### Identity

- title
  - Internal display name / nickname (system-managed)

- field_supplier_name
  - Legal / formal business name

---

### Ordering & Account

- field_account_number
  - Vendor account number used for purchasing and invoicing

- field_ordering_method
  - Primary ordering channel
  - Values: phone | email | portal | rep

- field_ordering_email
  - Email used to submit orders

- field_ordering_portal_url
  - Vendor online ordering portal

- field_purchase_notes
  - Internal purchasing instructions and quirks

---

### Logistics

- field_delivery_available
  - Whether supplier offers delivery

- field_standard_lead_time_days
  - Typical lead time for stocked items

- field_shipping_notes
  - Freight, shipping, or delivery notes

- field_pickup_location_notes
  - In‑person pickup instructions (counter, hours, location)

---

### Payment

- field_payment_terms
  - Standard vendor payment terms
  - Values: due_on_receipt | net_15 | net_30 | credit_card | ach

- field_payment_notes
  - Internal accounting or billing notes

---

### Status & Governance

- field_supplier_status
  - Controls operational availability
  - Values:
    - active — normal use
    - limited — restricted or situational use
    - do_not_use — blocked from new purchasing

---

## Existing Supporting Fields

These fields already exist and support Supplier usage but do not drive core behavior:

- field_address
- field_contacts
- field_phone_numbers
- field_website
- field_logo
- field_supplier_type
- field_notes

---

## Invariants (Non‑Negotiable)

- Supplier stores **vendor‑level defaults only**.
- Supplier must **never** store:
  - material‑specific pricing
  - supplier SKUs per material
  - preferred supplier per material

- Supplier status must be respected by:
  - material selection
  - purchasing workflows
  - future automation

- Material sourcing logic must flow through `material_suppliers`.

---

## Deletion / Archival

Default:
- Suppliers are **not deleted**.

Preferred:
- Use `field_supplier_status = do_not_use` to retire vendors.

Hard delete:
- Admin‑only
- Allowed only if Supplier is not referenced anywhere.

---

## Integration Notes

- Accounting systems (e.g. QuickBooks) are downstream.
- External IDs may be added later if required.
- Supplier is a **Supporting Entity**, not a BOS Core Entity.

---

## Out of Scope

This entity does **not** cover:
- pricing per material
- supplier priority per material
- fallback logic when suppliers are out of stock

Those are handled by:
- `material_suppliers` (Material ↔ Supplier link entity)

---

## Status

- Supplier schema: **Active / Evolving**
- Module ownership: **Deferred**
- Field creation: **UI‑managed, config‑exported**

---

## Next Related Work

- Define `material_suppliers` fields
- Add supplier pricing + priority per material
- Build Material‑centric supplier views for office staff

