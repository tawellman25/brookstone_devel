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
- field_assigned_to    → read from service_term.field_default_estimator (if set)
- field_priority       → 'normal' (hardcoded default)
- field_status         → 'New' (term lookup by name in estimate_request_status vocab)
- title                → 'Estimate Request – Contract {N} – Section {N}'

Idempotency rules:
- If contract_sections.field_estimate_request already set → do nothing.
- If pointer missing but a request exists for this section → reuse it and write pointer back.
- Static recursion guard prevents re-entrant saves when writing the back-reference.

### 2. Manual Office Entry

An office user creates an estimate_request directly via the admin UI.
All fields populated manually. No auto-creation side effects.

---

## Governance

- One Estimate Request is the umbrella for all estimates on a given service opportunity.
- field_estimates allows all 34 estimate bundles (field updated 2025-12-06).
- Intake must not be blocked if Property does not exist; Property creation is explicit.
- Contact creation is explicit; no auto-creation from request fields.
- field_assigned_to triggers the estimate_notifications email when populated
  (insert: if set; update: empty → value transition only).
