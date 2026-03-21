# BOS Module — estimate

Module: estimate
Package: BOS Custom

Purpose:
- Scope code uniqueness constraint on taxonomy_term:service_scope_elements
- Estimate request intake soft warnings
- Estimate items pricing math (presave)
- Estimate total rollup triggers
- Revision enforcement trigger
- Convert to Work Order entity operation
- Contact/Owner card display on all estimate bundles

---

## hook_entity_presave

Single presave hook handling three flows:

### 1. estimate_request: soft intake warnings
Calls _estimate_request_add_intake_warnings() — never blocks save.
Warns if missing: contact info, property, owner, services.
Skipped on CLI runs (drush, cron).

### 2. estimate_items: pricing engine
Fields required: field_quantity, field_unit_price, field_cost_subtotal, field_line_total

Calculation:
- subtotal = quantity × unit_price
- markup normalized: if > 1, divide by 100 (accepts "10" or "0.10")
- line_total = subtotal × (1 + markup)
- Negative quantities/prices clamped to 0

### 3. estimate: revision enforcement + defaults
- Auto-sets field_assigned_to to current user if empty
- Auto-syncs field_estimate_type from bundle's matching services term
  (skips landscaping containers — pipeline sets field_estimate_type = 364)
- Calls EstimateRevisionManager::enforce()

---

## EstimateRevisionManager::enforce() (March 2026)

Static processing guard added to prevent re-entry loop.

Guard key:
- New entities: spl_object_id($estimate) prefixed with 'new_'
- Existing entities: string of entity ID

Every early return unsets the guard key.

Problem solved:
unsetOtherCurrents() calls $e->save() on related estimates which
re-triggers presave → enforce() → unsetOtherCurrents() loop.
Guard prevents re-entry for the same entity ID.

---

## field_estimate_type Auto-Sync (March 2026)

Added to presave for the estimate entity block.

Behavior:
- Queries services taxonomy for term where field_service_bundle = entity bundle
- Sets field_estimate_type to matching term ID
- Skips landscaping containers (field_is_container = TRUE)

Field is hidden from all form displays — set programmatically only.
Not required on any bundle.

Bundle → TID mapping:
- hard_scape → 1771
- hydro_seeding → 381
- patios → 384
- planting → 1772
- retaining_walls → 383
- rock_work → 385
- rough_grading → 1770
- sodding → 386
- tree_shrub_planting → 394
- water_features → 378
- xeriscaping → 382
- landscaping → 364
- aerating → 389
- weed_spraying → 414

---

## Contact/Owner Card (March 2026)

### hook_entity_extra_field_info()

Registers extra field 'estimate_contact_card' on ALL 45 estimate bundles.
Label: "Contact / Owner"
Weight: -10
Visible: TRUE

### hook_entity_view()

Guards:
- Entity type must be estimate
- Display must have estimate_contact_card component enabled
- estimate.field_estimate_request must be set

Priority cascade:
1. field_owner on estimate_request → Owner card
2. field_contact on estimate_request → Contact card
3. field_requestor_* on estimate_request → Requestor card
4. None found → "No contact information available"

Owner card shows: name (linked), address, primary phone, email
Contact card shows: name, address, phone, email
Requestor card shows: name, address, phone, email from requestor fields

CSS classes: er-card, er-card--owner, er-card--contact, er-card--requestor

All 45 estimate bundle displays have group_client_information
(details format, always open) containing estimate_contact_card.

---

## Estimate Total Rollup

hook_entity_insert/update/delete fire on estimate_items changes.
Calls EstimateTotalCalculator::recalculate() service.

---

## Auto-Labels (March 2026)

Pattern: [estimate:type]
Resolves to bundle label (e.g. "Rock Work", "Patios", "Landscaping")
No ID number in title — clean, scannable.

All 45 bundles use this pattern.
Other estimate entity types (estimate_notes, estimate_request,
estimate_tasks) use their own patterns — not changed.

---

## Status

Updated: March 2026
Added: Contact/Owner card, field_estimate_type auto-sync,
EstimateRevisionManager static guard, auto-label simplification.
