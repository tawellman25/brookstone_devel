# BOS Governance SOP — GOV-SOP-001
# SOP Authoring Standard (ENFORCEMENT VERSION)

---

## PURPOSE
This SOP defines the required structure, rules, and enforcement standards for
all Standard Operating Procedures (SOPs) within the Brookstone Operations
System (BOS).

This SOP exists to eliminate ambiguity, prevent structural drift, and ensure
all SOPs are consistent, enforceable, and system-compatible.

---

## SCOPE
Applies to:
- All SOPs created in BOS (all bundles)
- All departments
- All users creating or editing SOPs
- All AI-generated SOP content

Does NOT apply to:
- Informal notes
- Training discussions outside SOP entities

---

## RULES & RESPONSIBILITIES
- All SOPs must follow this standard exactly
- SOPs must be authored field-by-field using BOS field structure
- SOPs must be copy-paste ready into Drupal ECK fields
- SOPs must use directive language only (`must / must not`)
- SOPs must not contain narrative or unstructured content
- Parent and Child SOP hierarchy must be enforced
- SOP Codes must follow the defined format and are immutable once approved
- Any SOP not compliant with this standard is invalid and must not be used

---

## REQUIRED SOP OUTPUT ORDER (MANDATORY)

All SOPs must be authored and delivered in the following exact order:

1. SOP Code
2. SOP Title
3. SOP Type (Bundle)
4. Purpose
5. Scope
6. Rules & Responsibilities
7. Prerequisites
8. Steps & Procedures
9. Key Performance Indicators
10. Related SOPs

Deviation from this order is not allowed.

Note: "Notes / Exceptions" was removed from the required output order.
Operational notes, review history, clarifications, exception requests,
and audit commentary must be tracked in the `sop_log` entity, not in
the SOP body. See `__BOS_AI/Entities/sop_log.md`.

---

## AUTHORING BOUNDARY

GOV-SOP-001 governs authored SOP content only.

The BOS SOP entity includes additional fields that are not part of the required
authored SOP output. These include:
- system metadata
- lifecycle status fields
- administrative fields
- optional fields
- bundle-specific fields

Those fields are defined and governed in:

`__BOS_AI/Entities/sop.md`

They must not be included in SOP authoring output unless explicitly required by
workflow.

---

## REQUIRED vs NON-REQUIRED FIELDS

Only the fields defined in the REQUIRED SOP OUTPUT ORDER are mandatory for SOP
authoring.

All other SOP entity fields are considered:
- system-managed
- optional
- metadata-driven
- or workflow-controlled

Including non-required fields in SOP output without purpose is not allowed.

---

## SOP FIELD DEFINITIONS & RULES

### SOP Code (`field_sop_code`)
- Format: `OWNER-AREA-SERVICE-SEQUENCE`
- Must be unique
- Immutable once approved

### SOP Title (`title`)
- Format: `[SOP Code] - [Short Title]`
- Must be descriptive and concise

### SOP Type (Bundle)
- Must match an existing ECK SOP bundle

Allowed bundles:
- `sop_governance`
- `office_administration`
- `system_procedures`
- `sprinkler_maintenance`
- `landscaping`
- `spray`
- `snow_removal`
- `maintenance`
- `lighting`
- `safety`
- `training`

### Purpose (`field_sop_purpose`)
- Must state why the SOP exists
- Must define the problem it controls
- Must NOT include:
  - steps
  - rules
  - tools
  - training language

### Scope (`field_sop_scope`)
- Must define what the SOP applies to
- Must define what it does NOT apply to
- Must identify affected departments, roles, or systems

### Rules & Responsibilities (`field_sop_responsibilities`)
- Must use `must / must not` language only
- Must define ownership and accountability
- Must NOT include steps or execution detail

### Prerequisites (`field_prerequisites`)
- Must list required conditions before execution

Examples:
- access
- approvals
- prior SOP completion
- system setup

### Steps & Procedures (`field_sop_steps`)
Must follow this exact structure:

#### Pre-Checks
- Verify all prerequisites are met

#### Steps
- Numbered actions
- Strict execution order
- Each step must be actionable

#### Quality Checks
- Define how correctness is verified

#### Completion
- Define final confirmation
- Define required documentation or system updates

Rules:
- No paragraphs
- No narrative blocks
- No skipped structure

### Key Performance Indicators (`field_sop__kpis`)
- Must be measurable
- Must be objective
- No explanations
- No narrative

### Related SOPs (`field_related_sops`)
- Must list:
  - parent SOPs
  - child SOPs
  - governance SOPs
- No descriptions allowed

### Notes / Exceptions
This field has been removed from the required SOP output order.

Operational notes, exception requests, review history, and audit
commentary must be logged in the `sop_log` entity — not stored in
the SOP body. The SOP is the controlled document; the log is the
history around it.

See `__BOS_AI/Entities/sop_log.md` for the log entity specification.

### Tools & Resources (`field_sop_tools_and_resources`)
- Optional field
- May be included only when tools, systems, or assets materially affect execution
- Must list required tools, systems, or assets only
- Must NOT include instructions

---

## PARENT vs CHILD SOP ENFORCEMENT

### Parent SOPs
- Must define:
  - scope
  - rules
  - structure
- Must NOT contain detailed execution steps

### Child SOPs
- Must reference a Parent SOP (`field_parent_sop`)
- Must assume all parent rules apply
- Must contain execution steps

Violation of this structure results in SOP rejection.

---

## COPY-PASTE ENFORCEMENT

- No paragraphs
- No narrative blocks
- No mixed-field content
- Each field must stand alone
- Content must paste directly into Drupal without modification

Non-compliant SOPs are invalid.

---

## LANGUAGE STANDARDS

- Use directive language only
- Allowed:
  - `must`
  - `must not`
- Not allowed:
  - `should`
  - `may`
  - `try to`

SOP content must be interpreted the same by:
- a new hire
- a supervisor
- an auditor

---

## SYSTEM PRIORITY

- Governance SOPs override all operational SOPs
- This SOP (`GOV-SOP-001`) is authoritative for all SOP creation

---

## COMPLIANCE

An SOP is considered COMPLETE only if:
- All required authored fields are present
- Field order is correct
- Content follows all rules
- Structure is enforced
- It is copy-paste ready

Any SOP that does not meet these requirements is INVALID.

---

## System Reference

This SOP operates within the BOS SOP entity defined in:

`__BOS_AI/Entities/sop.md`

---