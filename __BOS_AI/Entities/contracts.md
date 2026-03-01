# BOS Entity — Contracts

Entity Type ID:
- contracts

Storage:
- ECK entity type

Bundles:
- residential
- snow_removal
- commercial

Implementation Status:
- residential: implemented and enforced
- snow_removal: in progress
- commercial: in progress

---

## Purpose

Contracts represent the **agreement and intent** between Brookstone Outdoors
and a client for work to be performed at a property.

Contracts define:
- what services are agreed to
- the scope and intent of those services
- client approvals and signatures
- contract lifecycle state

Contracts do **not** record execution.
Execution is always recorded on Work Orders and their child entities.

---

## Contract Template Model

BOS uses a **template-style Contract model**:

- Each Contract bundle defines its own schema.
- The Residential Contract bundle is a fully defined template.
- Service commitments are embedded via **explicit section slot fields**.
- Other bundles (Snow Removal, Commercial) will adopt similar patterns as completed.

This design is intentional and client-facing.

---

## Relationship to Contract Sections

Entity:
- contract_sections (ECK)

### Residential Bundle — Slot-Based Linking

The `contracts:residential` bundle references Contract Sections via
explicit entity reference fields (“section slots”), such as:

- field_aerating_of_lawn
- field_fall_cleanup
- field_pre_emergent
- field_summer_hedge_shrub_pruning
- field_weed_spraying_of_landscape
- field_weed_spraying_of_misc_area
- (and other service-specific slots)

Each slot references a single Contract Section entity of the corresponding bundle.

### Section → Contract Pointer

Every Contract Section also stores its parent contract via:
- contract_sections.field_contract (entity_reference → contracts)

### Invariant (Non-Negotiable)

If a Contract references a Contract Section via a section slot field,
that Contract Section **must** reference the same Contract via `field_contract`.

This bidirectional relationship is required for data integrity.

---

## Bidirectional Link Enforcement (Residential)

For the `contracts:residential` bundle, bidirectional integrity is enforced in code:

Module:
- `contract_residential.module`

Behavior:
- On contract insert and update:
  - Any Contract Section referenced by a residential slot field will have
    `contract_sections.field_contract` automatically set to the Contract ID.
- Existing links are preserved:
  - The module does **not** unlink sections.
  - The module does **not** overwrite `field_contract` if it already points
    to a different Contract.
  - Conflicts are logged for review.

This enforcement guarantees consistency without destructive side effects.

---

## Relationship to Services (Authoritative Mapping)

Contract Sections must reference exactly one Service via:
- contract_sections.field_service (entity_reference → Services taxonomy)

Services taxonomy is the **single source of truth** for Work Order mapping:

- services.field_work_order_service = TRUE required
- services.field_service_bundle = work_order bundle machine name

Mapping flow:

Contract Section
→ Service
→ service_bundle
→ Work Order bundle


Contracts themselves never map directly to Work Orders.

---

## Bundle: residential (Residential Contract)

### Core Fields
- field_property (entity_reference → properties)
- field_property_owner (entity_reference)
- field_contract_status (entity_reference)
- field_contract_year (integer)
- field_contract_submitted (boolean)
- field_client_entered_date (timestamp)
- field_client_signature (string)
- field_ip_address_of_submitter (string)
- field_paper_contract_pdf (file)

### Legacy / Import Fields
- field_old_contract_id (integer)
- field_old_nid (integer)
- feeds_item (feeds_item)

### Residential Section Slots
Each of the following fields references a `contract_sections` entity:

- field_aerating_of_lawn
- field_aspen_twig_gall_control
- field_christmas_decorations
- field_cooley_spruce_gall_treatme
- field_deciduous_bore_treatment
- field_deer_protection_wire_for_t
- field_dethatching_of_lawn_areas
- field_dormant_oil_spray
- field_fall_cleanup
- field_fertilizing_trees_shrubs
- field_grub_prevention_on_lawn
- field_ips_beetle_on_pinion_pine
- field_irrigation_check_ups
- field_irrigation_shut_down
- field_irrigation_start_up
- field_lawn_fertilizing_broadleaf
- field_lawn_mowing_and_trimming
- field_pre_emergent
- field_spring_cleanup
- field_summer_hedge_shrub_pruning
- field_trunk_bore_prevention
- field_weed_spraying_of_landscape
- field_weed_spraying_of_misc_area

### Residential-Only Options
- field_weeds_in_shrubs_removal (list_string)

These fields together define the complete residential service agreement.

---

## Bundle: snow_removal (Snow Removal) — In Progress

Current fields:
- field_property (entity_reference → properties)
- field_contract_year (integer)
- field_contract_submitted (boolean)
- field_client_entered_date (timestamp)
- field_client_signature (string)
- field_per_push_rate (decimal)
- field_shoveling_labor_included (boolean)

Planned direction:
- Adopt explicit service/section commitments consistent with Residential.
- Maintain strict intent vs execution separation.

---

## Bundle: commercial (Commercial) — In Progress

Current state:
- Base/system fields only.

Planned direction:
- Define commercial-specific service commitments.
- Follow same BOS principles:
  - Contracts/Sections = intent
  - Work Orders = execution

---

## Lifecycle Rules (All Bundles)

- Contracts are not deleted by default.
- Lifecycle is managed via status (Draft / Active / Expired / Cancelled).
- Only Active contracts may generate new Work Orders.

---

## Deletion & Archival

- Do not delete contracts with history.
- Hard delete allowed only for empty drafts
  (no sections, no Work Orders).
- Prefer lifecycle status changes over deletion.

---

## Invariants (Non-Negotiable)

- Contracts represent intent, not execution.
- Residential contracts use explicit section slot fields.
- Contract → Section and Section → Contract links must match.
- Services taxonomy is the authority for Work Order mapping.
- Execution data must never be stored in Contracts.

## Contract Section Audit Logging

BOS creates an append-only audit trail for **Contract Sections**.

- **Scope:** Contract Sections only (not Contracts).
- **Who:** stored as the Log entry Author (base `uid`).
- **When:** stored as the Log entry Created timestamp (base `created`).
- **What:** stored as `field_action` (insert/update/delete), `field_section_bundle`, and `field_changed_fields` (JSON list of changed field machine names).

### Implementation
- Audit entity type: `contract_sections_audit_log`
- Bundle: `log`
- Created by module: `contract_sections_audit`
- Log entries are system-generated and must not be created/edited/deleted manually.

See: `contract_section_audit.md` for entity details, invariants, permissions, and troubleshooting.

## Admin Theme Enforcement on Contract Routes

BOS enforces the **admin theme** on Contract routes for designated office/admin roles.

### Purpose
- Ensure staff edit Contracts and Contract Sections in a controlled, admin-only UI.
- Prevent accidental editing in the public/front theme.
- Keep client-facing presentation and office workflows clearly separated.

### Implementation
- Implemented via a **Theme Negotiator** in the `contract_residential` module.
- The negotiator switches the active theme **by route name**, not by URL path.
- Pathauto aliases do not affect this behavior.

### Affected Routes
The admin theme is forced on the following Contract routes:
- `entity.contracts.canonical`
- `entity.contracts.edit_form`
- `entity.contracts.add_form`
- `entity.contracts.collection`
- Any custom routes prefixed with `contract_residential.*`

### Role-Based Application
The admin theme is applied only when the current user has **one of the following roles**:
- `administrator`
- `site_admin`
- `administration` (Office Admin)
- `site_assistant`
- `supervisor`

All other roles (including `client`, `user`, `teammates`, and `authenticated`) continue to see Contracts using the front theme.

### Active Admin Theme
- Admin theme machine name: `brookstone_admin`

### Technical Notes
- Theme switching is based on **route names**, not aliases.
- Pathauto has no impact on theme negotiation.
- The service is registered as:
  - `contract_residential.contract_theme_negotiator`
- Cache rebuild is required after modifying the theme negotiator or services file.

### Governance
- This is a **presentation rule**, not an access control rule.
- Permissions still govern who may view or edit Contracts.
- Theme enforcement is intended to reduce UI ambiguity, not replace permissions.

## Contract Status Lifecycle

Contracts move through a defined lifecycle represented by the
`contract_status` taxonomy.

These statuses describe the **business state** of a Contract and are used for:
- office processing
- reporting
- automation triggers

### Status Definitions

- 1117 — Created - Updating
- 1118 — Ready to Send
- 1119 — Sent - Posted
- 1120 — Client Viewed
- 1121 — Received Back
- 1122 — Changes Entered
- 1123 — Approved
- 1124 — Work Orders Created
- 1125 — Assigned
- 1126 — On Hold
- 1127 — Completed for the Year
- 1128 — Canceled

### Lifecycle Intent

The intended progression is generally:

Created → Ready → Sent → Received → Changes Entered → Approved → Work Orders Created → Assigned → Completed

Exceptions:
- Contracts may be placed **On Hold** from most stages.
- Contracts may be **Canceled** when appropriate.
- Completed Contracts represent a terminal state.

### Governance

- Status values represent **intent and processing state**, not execution.
- Work Order creation and execution must never be inferred solely from status.
- Enforcement of valid transitions is implemented by contract-specific modules
  (see `contract_residential.md` for Residential behavior).



## Admin Contract Editing UX (Office / Admin)

BOS provides a dedicated **office/admin editing experience** for Contracts and their Contract Sections.

### Admin View Mode
- Contracts render using a dedicated **Admin view mode** for office/admin roles.
- This view mode is not used for public or client-facing displays.

### Contract Sections Management
- Contract Sections are presented to staff as a **table/list view**, not as individual embedded fields.
- Each row represents one Contract Section (service intent).
- Editing is performed via **modal dialogs**, keeping staff anchored on the Contract page.

### Modal Editing Behavior
- Clicking “Edit” on a Contract Section opens the section edit form in a modal dialog.
- Saving a section returns the user to the Contract page.
- System-driven section saves (e.g., contract sync) do not generate audit noise.

### Theme Enforcement
- When viewing or editing Contracts, the **admin theme** is enforced for office/admin roles.
- Public/front themes are never used for Contract editing by staff.
- Pathauto aliases do not affect this behavior.

See: `contract_residential.md` for implementation details and governance.
