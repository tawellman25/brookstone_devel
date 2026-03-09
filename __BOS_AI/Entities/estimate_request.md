# estimate_request

## Entity Type
- **Machine name:** `estimate_request`
- **Provider:** ECK
- **Bundles:** `standard`

## Purpose
Intake container for the estimating workflow. One estimate_request can generate multiple estimates (one per service). Links owner, contact, property, and requested services.

## Key Relationships
- `field_owner` → `user` (client role)
- `field_contact` → `contacts.contact`
- `field_property` → `properties`
- `field_contract` → `contracts`
- `field_service` → taxonomy `services` (multi-value)
- `field_estimates` → `estimate` (multi-value, reverse reference)

---

## Fields (standard bundle)

=== standard | Standard ===
id | integer | ID
uuid | uuid | UUID
langcode | language | Language
type | entity_reference | Type
title | string | Title
uid | entity_reference | Authored by
created | created | Authored on
changed | changed | Changed
default_langcode | boolean | Default translation
field_assigned_to | entity_reference | Assigned To (estimator, teammates role)
field_client_requested | text_long | Client Requested the following:
field_contact | entity_reference | Contact
field_contract | entity_reference | Contract
field_contract_section | entity_reference | Contract Section
field_estimates | entity_reference | Estimates (all 34 estimate bundles allowed)
field_owner | entity_reference | Owner
field_priority | list_string | Priority
field_property | entity_reference | Property
field_requestor_address | string | Address
field_requestor_email | email | Email
field_requestor_name | string | Name
field_requestor_phone | telephone | Phone
field_service | entity_reference | Service Estimate(s) Requested
field_status | entity_reference | Status

---

## Title Format

All estimate requests use the title format `Estimate Request #[id]`.

This is set automatically by `estimate_intake` module's `hook_entity_insert`. Titles
matching empty, `'Standard'`, or `'Estimate Request - Pending'` are replaced with the
canonical format after the entity receives its ID. Custom titles entered by users are
preserved.

---

## Entry Points

### 1. Contract Path (Auto-Created)

Trigger: Contract Section field_do_you_want = '3' (Request Quote).
Module: estimate_contract_residential → EstimateRequestAutoCreator service.

Fields auto-populated at creation:
- field_contract_section → the triggering contract_sections entity id
- field_contract       → copied from section.field_contract
- field_service        → copied from section.field_service
- field_property       → loaded from contract.field_property
- field_owner          → loaded from contract.field_property_owner
- field_priority       → 'normal' (hardcoded default)
- field_status         → 'New' (term lookup by name in estimate_request_status vocab)
- title                → 'Estimate Request #[id]' (set after save)

Note: `field_assigned_to` comes from the entity field default value (configured in UI),
not from the service taxonomy term. The `estimate_notifications` module sends the
assignment email based on that default.

Idempotency rules:
- If contract_sections.field_estimate_request already set → do nothing.
- If pointer missing but a request exists for this section → reuse it and write pointer back.
- Static recursion guard prevents re-entrant saves when writing the back-reference.

### 2. Manual Office Entry

An office user creates an estimate_request directly via the admin UI.
All fields populated manually. No auto-creation side effects.

The `estimate_intake` module fires on presave to auto-populate BOS record links:
- `field_property` — matched from `field_requestor_address` via LIKE query on
  `properties.property.field_street_address`. Only auto-set if exactly one match.
- `field_owner` — loaded from the latest `ownership_record.record` for the matched property.
- `field_contact` — matched by email (direct field) or phone (two-step via `phone_number`
  sub-entity). If no match found, a new `contacts.contact` + `phone_number.contacts`
  sub-entity is created.

Only empty fields are populated — manual entries always win. Phone numbers are normalized
to digits only before lookup and storage.

Assignment email fires via estimate_notifications if field_assigned_to is set.

---

## Governance

- One Estimate Request is the umbrella for all estimates on a given service opportunity.
- field_estimates allows all 34 estimate bundles (field updated 2025-12-06).
- Intake must not be blocked if Property does not exist; Property creation is explicit.
- Contact auto-creation is handled by `estimate_intake` only when requestor fields are
  populated and no existing contact matches by email or phone.
- field_assigned_to triggers the estimate_notifications email when populated
  (insert: if set; update: empty → value transition only).
