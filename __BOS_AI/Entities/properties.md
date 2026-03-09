# BOS Entity — Properties

Entity Type ID:
- properties

Bundle(s):
- property

Purpose:
- Canonical record of a physical location where work is performed.
- Anchor for Work Orders, Contracts, and operational history.
- Persists across ownership changes.
- Provides geographic, operational, and workflow context for crews and office staff.

---

## Required Relationships (must exist)

- field_primary_contact_ref (entity_reference)
  - Primary contact for the property.

- field_contacts (entity_reference)
  - Additional contacts tied to the property.

- field_zipcode_reference (entity_reference → Zipcode ECK)
  - Canonical geographic grouping used for:
    - URL structure
    - Area-based navigation
    - Crew-friendly browsing by location
  - Example URL pattern:
    - `/colorado/montrose-county/montrose/81401/402-s-2nd-st`

## Core Human Identifier (Crew-Facing)

- field_nickname (string)
  - Primary human-facing name for the property.
  - Used by crews and staff to identify locations in a way that makes operational sense.
  - Intentionally mutable over time as:
    - ownership changes
    - site usage changes
    - client naming conventions evolve

Design intent:
- field_nickname is the **crew-facing identifier**
- URL aliases and internal IDs remain stable
- Renaming a property must never break:
  - URLs
  - Work Orders
  - Contracts
  - Historical reporting

Examples:
- “St. Mary’s – North Wing”
- “St. Mary’s – Old ER”
- “Mercy Hospital – Admin Building”

Invariant:
- field_nickname may change.
- path and internal identifiers must not change as a result.


---

## External App Integration

- field_client_app (entity_reference → Client App ECK)
  - References a Client App entity containing:
    - App name
    - Login credentials (shared where applicable)
    - Usage notes
  - Some commercial properties require check-ins or logging within third-party apps.
  - A single Client App entity may be referenced by multiple properties.

Invariant:
- If a property requires an external app workflow, field_client_app must be populated.

- field_app_note (text_long)
  - Field/mobile-specific notes for crews regarding the use of the app for this particular property.

---

## Address & Location (Canonical)

- title (string)
  - Human-friendly property identifier.

- field_street_address (string)
  - Street-level address component.

- field_full_address (string)
  - Composite address field used to simplify Views and display logic.
  - Must stay in sync with component address fields.

- field_geofield (geofield)
  - Canonical latitude/longitude used exclusively for:
    - Mapping
    - Pin-based property/service/city/county views
  - Not used for sorting or routing logic.

---

## Identification & Reference

- field_import_id (string)
  - Legacy import identifier (migration/reference only).

- field_county_account_number (string)
  - County account reference.

- field_parcel_number (string)
  - County parcel reference.

---

## Operational Behavior Flags

- field_call_ahead (boolean)
  - Indicates crews must call ahead before arrival.

- field_cod_customer (boolean)
  - Marks COD behavior for this property.

- field_why_cod_for_this_property (string_long)
  - Required explanation when COD flag is set.

- field_no_services (boolean)
  - Property is not eligible for services.

- field_why_no_services (string_long)
  - Required explanation when No Services is set.

- field_must_use_client_app (boolean)
  - Forces external app workflow when present.

Invariant:
- If field_cod_customer = TRUE, field_why_cod_for_this_property must be populated.
- If field_no_services = TRUE, field_why_no_services must be populated.

---

## Contract Context

- field_latest_contract (entity_reference → Contract)
  - Points to the most recent Contract associated with the property.
  - Used for:
    - Quick access in Views
    - Property-level context
  - Originally introduced to work around Views limitations.

Notes:
- This field should remain an entity reference.
- If currently misconfigured as integer, it should be corrected to entity_reference.
- This field is a convenience pointer, not the source of truth (Contracts remain authoritative).

---

## Notes & Media (Supporting)

- field_work_order_note (string)
  - Global note surfaced on all Work Orders for this property.

- field_property_description (text_with_summary)
  - Public facing narrative description.

- field_aerial_view (image)
- field_front_view (image)
- field_maps (image)

---

## Invariants (must / never rules)

- A Property must be uniquely identifiable by internal ID and address.
- Properties persist across ownership and contract changes.
- Properties must not be hard-deleted if referenced by:
  - Work Orders
  - Contracts
  - Notes
  - Media
- Address + Zipcode reference + Geofield must remain logically consistent.
- Composite fields (field_full_address) must reflect underlying address data.
- field_nickname is the authoritative crew-facing name for a Property.
- Changing field_nickname must never alter:
  - the property URL
  - references from Work Orders or Contracts
  - historical records


---

## Deletion / Archival

Default:
- Archive via service flags (field_no_services), not deletion.

Hard Delete:
- Restricted to admins.
- Allowed only if no operational history exists.

---

## Reporting Expectations

- Work Orders must be reportable by Property without joining Contracts.
- Properties must be groupable by:
  - Zipcode
  - City
  - County
- Map-based reporting must rely solely on field_geofield.

---

## Full Field Inventory (Appendix)

(System fields omitted for brevity; see field list snapshot if needed.)
