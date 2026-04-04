# BOS Entity — sop

Entity Type ID: `sop`
Storage: ECK

## Purpose
Standard Operating Procedures. Authoritative procedural records for all BOS
service departments. SOPs define how Brookstone operates — governance SOPs
override all operational SOPs.

SOP codes are immutable once approved — enforced by `sop_code_validation`
module globally and per-bundle by `sop_office_admin`, `sop_sprinkler_maintenance`,
`sop_system_prosedures` modules.

## Bundles (11)

| Machine Name | Label | Department/Scope |
|---|---|---|
| `sop_governance` | SOP Guidelines | Meta — governs all other SOPs |
| `office_administration` | Office Administration | Office/finance/admin procedures |
| `system_procedures` | WEB Procedures | Website and system admin procedures |
| `sprinkler_maintenance` | Sprinkler Department | Sprinkler service SOPs |
| `landscaping` | Landscaping | Landscape service SOPs |
| `spray` | Spray Department | Spray service SOPs |
| `snow_removal` | Snow Removal Department | Snow removal SOPs |
| `maintenance` | Maintenance Department | Equipment/facility maintenance SOPs |
| `lighting` | Lighting Department | Lighting service SOPs |
| `safety` | Safety SOPs | Safety and compliance SOPs |
| `training` | Training | Employee training SOPs |

## Required Relationships
- `field_sop_owner` → `user` — SOP editor/owner (labeled "Editor")
- `field_parent_sop` → `sop` — optional self-referential hierarchy
- `field_related_sops` → `sop` — optional cross-references

---

## SOP Fields — Governance-Aligned Reference

Fields are grouped by function to prevent governance drift and ensure clear
separation between authored SOP content and system-level metadata.

### A. Required Authored SOP Content

These fields must be authored in the exact order defined by:

`GOV-SOP-001 — SOP Authoring Standard`

These fields represent the required procedural content of an SOP and must be
written by a user or AI during SOP creation.

Included fields:
- `field_sop_code` — SOP Code
- `title` — SOP Title
- SOP Type (Bundle)
- `field_sop_purpose` — Purpose
- `field_sop_scope` — Scope
- `field_sop_responsibilities` — Rules & Responsibilities
- `field_prerequisites` — Prerequisites
- `field_sop_steps` — Steps & Procedures
- `field_sop__kpis` — Key Performance Indicators
- `field_related_sops` — Related SOPs
- `field_sop_notes` — Notes / Exceptions

These fields are governed by GOV-SOP-001 and must follow all authoring rules,
structure, and formatting requirements.

### B. SOP Record Metadata / Lifecycle Fields

These fields exist on the SOP entity but are not part of required authored SOP
output.

They are:
- system-managed
- workflow-controlled
- admin-maintained
- or derived

Included fields:
- `field_name` — display name / short label
- `field_sop_status` — `draft` | `approved` | `active` | `archived`
- `field_sop_version` — version identifier
- `field_sop_last_reviewed` — datetime
- `field_sop_owner` — SOP editor/owner

These fields must not be included in standard SOP authoring output unless
explicitly required by workflow.

### C. Optional / Bundle-Specific Fields

These fields apply only when relevant to the SOP bundle or implementation
context.

Included fields:
- `field_sop_tools_and_resources`
- `field_service`
- `field_materials_involved`
- `field_required_positions` (training bundle only)

These fields may be included when necessary but are not part of the required
SOP authoring structure.

---

## Field Reference

### 1. `field_sop_code` — SOP Code (string)
Format: `OWNER-AREA-SERVICE-SEQUENCE` (e.g., `SOP-SCU-001`, `OFF-FIN-QBS-001`)
- Immutable once `field_sop_status` = `approved`
- Approved codes must never be changed or reinterpreted
- Also stored on `taxonomy_term.services.field_sop_code` for service-level mapping

### 2. `title` — SOP Title (base field)
Descriptive title. Format: `[SOP Code] - [Short Title]`

### 3. `field_name` — Name (string)
Display name / short label.
- Metadata / display field
- Not part of required authored SOP output unless explicitly required by workflow

### 4. `field_sop_status` — Status (list_string)
Allowed values:
- `draft`
- `approved`
- `active`
- `archived`

Status is a lifecycle control field.
- Not part of required authored SOP output unless explicitly required by workflow

### 5. `field_sop_version` — Version (string)
Version identifier (e.g., `1.0`, `2.1`)
- Document control / metadata field
- Not part of required authored SOP output unless explicitly required by workflow

### 6. `field_sop_purpose` — Purpose (text_long)
Content rule:
- State why the SOP exists and what problem it controls
- Do NOT include steps, rules, tools, or training language in this field

### 7. `field_sop_scope` — Scope (text_long)
Content rule:
- Define what the SOP applies to and what it does NOT apply to
- Identify affected departments, systems, or roles

### 8. `field_sop_responsibilities` — Rules & Responsibilities (text_long)
Content rule:
- Use enforceable language only (`must / must not`)
- Define ownership and accountability
- Do NOT include procedural steps
- Avoid `should`, `may`, or `try to`

### 9. `field_prerequisites` — Prerequisites (string)
Content rule:
- List conditions that must exist before execution
- Include required access, approvals, setup, or prior SOPs completed

### 10. `field_sop_steps` — Steps & Procedures (text_long)
Content rule — mandatory structure:
1. **Pre-Checks:** Verify prerequisites and readiness
2. **Steps:** Numbered actions in strict execution order
3. **Quality Checks:** How correctness is verified
4. **Completion:** Final confirmation and documentation

Use numbered lists.
No prose blocks.
Every step must be actionable.

### 11. `field_sop__kpis` — Key Performance Indicators (text_long)
Content rule:
- Measurable indicators only
- No narrative explanations

### 12. `field_sop_tools_and_resources` — Tools and Resources (text_long)
Optional / bundle-dependent field.
- Not present on all bundles
- Absent from `sop_governance`
- Must list required tools, systems, or assets only
- Must NOT include instructions

### 13. `field_sop_last_reviewed` — Last Reviewed (datetime)
Lifecycle / review metadata field.
- Not part of required authored SOP output unless explicitly required by workflow

### 14. `field_service` → taxonomy_term (services vocabulary)
Links the SOP to the service it governs.
- Not present on `office_administration`
- Optional / bundle-specific field

### 15. `field_materials_involved` → material
Optional / bundle-specific field.
- Not present on `office_administration`
- Not present on `system_procedures`
- Not present on `sop_governance`
- Not present on `training`

### 16. `field_parent_sop` → sop (self-referential)
Parent / child SOP relationship field.

Rules:
- Parent SOPs define scope and rules
- Parent SOPs do NOT contain deep execution steps
- Child SOPs assume the parent SOP context and must reference it explicitly

### 17. `field_related_sops` → sop
Relationship field for parent, child, and governance cross-references.

Content rule:
- List parent, child, and governance SOP relationships only
- No descriptions

### 18. `field_sop_notes` — Notes / Exceptions (text_long)
Content rule:
- Approved edge cases only
- Documented exceptions only
- No instructions
- No general commentary

### 19. Training bundle additional
- `field_required_positions` → `positions`
- Applies only to `training` bundle SOPs
- Defines which positions the training SOP applies to

---

## Governance Reference

All SOP authoring must comply with:

`GOV-SOP-001 — SOP Authoring Standard`

This document defines system structure, schema behavior, metadata boundaries,
optional fields, and bundle-specific behavior. It does not replace governance
rules for authored SOP content.

---

## Authoring Language Standards

- Use directive, unambiguous language
- Avoid `should`, `may`, or `try to`
- SOPs must be interpreted the same by a new hire, a supervisor, and an auditor
- Use bullets and numbered lists
- No prose blocks
- Content must be copy-paste ready for Drupal ECK SOP fields
- No field bleed — each field's content must stay within its defined scope

## System Priority

- Governance SOPs (`sop_governance` bundle) override all operational SOPs
- An SOP that is not field-mapped and compliant with governance rules is not complete

## Invariants

- `field_sop_code` is immutable once `field_sop_status` = `approved`
- Parent SOPs define scope; child SOPs (via `field_parent_sop`) inherit parent context
- `sop_system_prosedures` module name has a permanent directory typo (`prosedures`) — do not rename
- Do not delete approved SOPs; set `field_sop_status` to `archived` instead
- Draft SOPs may be deleted before approval

## Related Entity — sop_log

Operational notes, review history, clarification records, audit commentary,
and exception tracking must NOT be stored as SOP body content. These must be
tracked in the `sop_log` entity. See `__BOS_AI/Entities/sop_log.md`.

Updated: April 2026 — governance-aligned field grouping, sop_log entity added.