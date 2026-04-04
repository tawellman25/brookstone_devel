# BOS Governance SOP — GOV-SOP-001
# SOP Authoring Standard (ENFORCEMENT VERSION)

---

## PURPOSE
This SOP defines the required structure, rules, and enforcement standards for all Standard Operating Procedures (SOPs) within the Brookstone Operations System (BOS).

This SOP exists to eliminate ambiguity, prevent structural drift, and ensure all SOPs are consistent, enforceable, and system-compatible.

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

- All SOPs must follow this standard exactly.
- SOPs must be authored field-by-field using BOS field structure.
- SOPs must be copy-paste ready into Drupal ECK fields.
- SOPs must use directive language only ("must / must not").
- SOPs must not contain narrative or unstructured content.
- Parent and Child SOP hierarchy must be enforced.
- SOP Codes must follow the defined format and are immutable once approved.
- Any SOP not compliant with this standard is invalid and must not be used.

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
11. Notes / Exceptions

Deviation from this order is not allowed.

---

## SOP FIELD DEFINITIONS & RULES

### SOP Code (`field_sop_code`)
- Format: OWNER-AREA-SERVICE-SEQUENCE
- Example: SOP-SCU-001
- Must be unique
- Immutable once approved

---

### SOP Title (`title`)
- Format: [SOP Code] - [Short Title]
- Must be descriptive and concise

---

### SOP Type (Bundle)
- Must match an existing ECK SOP bundle
- Examples:
  - landscaping
  - spray
  - sprinkler_maintenance
  - office_administration
  - system_procedures
  - lighting
  - maintenance
  - snow_removal
  - safety
  - training

---

### Purpose (`field_sop_purpose`)
- Must state why the SOP exists
- Must define the problem it controls
- Must NOT include:
  - steps
  - rules
  - tools
  - training language

---

### Scope (`field_sop_scope`)
- Must define what the SOP applies to
- Must define what it does NOT apply to
- Must identify affected departments, roles, or systems

---

### Rules & Responsibilities (`field_sop_responsibilities`)
- Must use "must / must not" language only
- Must define ownership and accountability
- Must NOT include steps or execution detail

---

### Prerequisites (`field_prerequisites`)
- Must list required conditions before execution
- Examples:
  - access
  - approvals
  - prior SOP completion
  - system setup

---

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

---

### Key Performance Indicators (`field_sop__kpis`)
- Must be measurable
- Must be objective
- No explanations or narrative

---

### Related SOPs (`field_related_sops`)
- Must list:
  - parent SOPs
  - child SOPs
  - governance SOPs
- No descriptions allowed

---

### Notes / Exceptions (`field_sop_notes`)
- Only approved edge cases
- Only documented exceptions
- No instructions
- No general commentary

---

### Tools & Resources (`field_sop_tools_and_resources`)
- Optional field
- Must list required tools, systems, or assets
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
- Allowed: "must", "must not"
- Not allowed: "should", "may", "try to"
- Must be interpretable the same by:
  - new hire
  - supervisor
  - auditor

---

## SYSTEM PRIORITY

- Governance SOPs override all operational SOPs
- This SOP (GOV-SOP-001) is authoritative for all SOP creation

---

## COMPLIANCE

An SOP is considered COMPLETE only if:
- All required fields are present
- Field order is correct
- Content follows all rules
- Structure is enforced
- It is copy-paste ready

Any SOP that does not meet these requirements is INVALID.

---
