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

## SOP Fields — Authoring Reference

Fields are listed in the order they should be authored. Each field has
specific content rules that must be followed.

### 1. `field_sop_code` — SOP Code (string)
Format: `OWNER-AREA-SERVICE-SEQUENCE` (e.g., `SOP-SCU-001`, `OFF-FIN-QBS-001`)
- Immutable once `field_sop_status` = `approved`. Enforced in code.
- Approved codes must never be changed or reinterpreted.
- Also stored on `taxonomy_term.services.field_sop_code` for service-level mapping.

### 2. `title` — SOP Title (base field)
Descriptive title. Format: `[SOP Code] - [Short Title]`

### 3. `field_name` — Name (string)
Display name / short label.

### 4. `field_sop_status` — Status (list_string)
Allowed values: `draft` | `approved` | `active` | `archived`

### 5. `field_sop_version` — Version (string)
Version identifier (e.g., `1.0`, `2.1`).

### 6. `field_sop_purpose` — Purpose (text_long)
**Content rule:** State why the SOP exists and what problem it controls.
Do NOT include steps, rules, tools, or training language in this field.

### 7. `field_sop_scope` — Scope (text_long)
**Content rule:** Define what the SOP applies to and what it does NOT apply to.
Identify affected departments, systems, or roles.

### 8. `field_sop_responsibilities` — Rules & Responsibilities (text_long)
**Content rule:** Use enforceable language only ("must / must not").
Define ownership and accountability. Do NOT include procedural steps.
Avoid "should," "may," or "try to."

### 9. `field_prerequisites` — Prerequisites (string)
**Content rule:** List conditions that must exist before execution —
required access, approvals, setup, or prior SOPs completed.

### 10. `field_sop_steps` — Steps & Procedures (text_long)
**Content rule — mandatory structure:**
1. **Pre-Checks:** Verify prerequisites and readiness.
2. **Steps:** Numbered actions in strict execution order.
3. **Quality Checks:** How correctness is verified.
4. **Completion:** Final confirmation and documentation.

Use numbered lists. No prose blocks. Every step must be actionable.

### 11. `field_sop__kpis` — Key Performance Indicators (text_long)
**Content rule:** Measurable indicators only. No narrative explanations.

### 12. `field_sop_tools_and_resources` — Tools and Resources (text_long)
Not present on all bundles (absent from `sop_governance`).

### 13. `field_sop_last_reviewed` — Last Reviewed (datetime)

### 14. `field_service` → taxonomy_term (services vocabulary)
Links the SOP to the service it governs. Not present on `office_administration`.

### 15. `field_materials_involved` → material
Not present on `office_administration`, `system_procedures`, `sop_governance`, `training`.

### 16. `field_parent_sop` → sop (self-referential)
**Parent vs Child SOP rules:**
- Parent SOPs define scope and rules — do NOT contain deep execution steps.
- Child SOPs assume the parent SOP context and must reference it explicitly.

### 17. `field_related_sops` → sop
List parent, child, and governance SOP relationships only. No descriptions.

### training bundle additional
- `field_required_positions` → `positions` — which positions this training SOP applies to

---

## Authoring Language Standards

- Use directive, unambiguous language.
- Avoid "should," "may," or "try to."
- SOPs must be interpreted the same by a new hire, a supervisor, and an auditor.
- Use bullets and numbered lists. No prose blocks.
- Content must be copy-paste ready for Drupal ECK SOP fields.
- No "field bleed" — each field's content must stay within its defined scope.

## System Priority

- Governance SOPs (`sop_governance` bundle) override all operational SOPs.
- An SOP that is not field-mapped and compliant with these rules is not complete.

## Invariants
- `field_sop_code` is immutable once `field_sop_status` = approved. Enforced in code.
- Parent SOPs define scope; child SOPs (via `field_parent_sop`) inherit.
- `sop_system_prosedures` module name has a permanent directory typo (`prosedures`) — do not rename.
- Do not delete approved SOPs. Set `field_sop_status` to `archived` instead.
- Draft SOPs may be deleted before approval.

Updated: April 2026 — merged SOP authoring rules with entity field reference.
