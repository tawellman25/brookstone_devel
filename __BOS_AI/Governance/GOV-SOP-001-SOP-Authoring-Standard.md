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
- SOPs must use rich HTML formatting within each field's
  defined content scope
- SOPs must not bleed content across field boundaries
  (e.g. steps must not appear in field_sop_purpose)
- HTML content must use inline styles only — no class
  dependencies on external stylesheets
- Narrative prose blocks are permitted only within
  field_sop_steps to explain context before procedural steps
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

## DUAL-FORMAT STANDARD (MANDATORY — Effective April 2026)

Every SOP must be produced in two parallel formats that are
visually consistent with each other:

### Format 1 — Printed Document (.docx)
- Full visual layout with color-coded steps, tables,
  pipeline diagrams, and warning boxes
- Uses the Brookstone brand color palette (green #2E7D32,
  blue #0D47A1, gold #F57F17, red #B71C1C)
- Step tables use numbered green header rows with role
  attribution on the right
- Pipeline flows use sequential colored badges with arrows
- Warning boxes use amber background for cautions,
  red background for blockers
- Do/Don't comparison tables use red left column and
  green right column
- Generated as .docx using the BOS SOP document template
- Filed in: __BOS_AI/SOPs/[SOP_CODE]/[SOP_CODE]_v[VERSION].docx

### Format 2 — HTML Field Content
- The same visual design recreated in inline HTML + CSS
- Pasted into Drupal ECK SOP entity fields
- Each field receives the HTML content for its section only
- Inline styles only — no external CSS, no class dependencies
- Must render correctly in Drupal's Full HTML text format
- Uses the same colors, tables, and visual hierarchy as the
  printed document

### HTML Paste File Standard (MANDATORY)

Every SOP HTML output must be delivered as a paste-ready
HTML file with the following structure:

- One section per BOS SOP field
- Each section has:
  - A dark green header bar showing the field machine name
    and a hint (e.g. "HTML — paste into CKEditor Source view"
    or "Plain text — paste directly")
  - A textarea containing the raw HTML (or plain text)
    for that field — pre-selected on click
  - A blue "Copy HTML for CKEditor Source view" button
    (or "Copy Plain Text" for plain text fields) that:
    - Selects and copies the textarea content automatically
    - Changes to green "✓ Copied — now paste into BOS
      Source view" for 3 seconds to confirm the copy
    - Returns to original label after confirmation

- A clear instruction block at the top of the file
  explaining the 5-step paste process:
  1. Click the blue Copy button
  2. In BOS: open the SOP entity, find the matching field
  3. Click Source (<>) in CKEditor toolbar
  4. Select all (Ctrl+A) and paste (Ctrl+V)
  5. Click Source again to return to visual view

- field_prerequisites is a multi-value string field.
  Each prerequisite must be entered as a SEPARATE
  individual item in BOS — not as one block of text.
  The paste file must display each prerequisite as
  its own individually copyable line with its own
  "Copy" button. BOS renders these as a bulleted
  list automatically — do not add bullet characters
  in the text itself.

- File naming convention:
  [SOP_CODE]_HTML_Fields_PASTE.html
  Example: OFF-ADM-EST-001_HTML_Fields_PASTE.html

This format must be used for all SOP HTML outputs
going forward. The original _HTML_Field_Content.html
format (without copy buttons) is deprecated.

### Authoring Rule
- Both formats must be produced in the same authoring session
- Both formats must reflect identical content
- If content changes, both formats must be updated together
- The printed document is the authoritative reference
- The HTML content is the in-system staff reference
- Neither format is optional

### Field-to-Section Mapping
Each SOP field receives HTML content for its specific section:

| Field | Content |
|---|---|
| field_sop_purpose | Styled purpose box with purpose statement |
| field_sop_scope | Applies-to / Does-not-apply table |
| field_sop_responsibilities | Color-coded must/must not rules list |
| field_prerequisites | Prerequisites checklist |
| field_sop_steps | Step tables, pipeline diagrams, warning boxes, do/don't tables |
| field_sop__kpis | Styled KPI list |

field_sop_steps is the primary content field and holds the
majority of the visual content including all procedural
sections, diagrams, and reference tables.

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
- Must follow the Pre-Checks / Steps / Quality Checks /
  Completion structure
- May include pipeline flow diagrams, warning boxes,
  comparison tables, and role-attribution step tables
- Brief contextual paragraphs are permitted before
  procedural steps to orient the reader
- No content from other fields may appear here
  (purpose, scope, and responsibilities belong in their
  own fields)
- HTML formatting must use inline styles only

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

### Operational Notes and History
Notes, exceptions, review history, and audit commentary are NOT part
of authored SOP content.

These must be logged in the `sop_log` entity — not stored in the SOP
body. The SOP is the controlled document; the log is the history
around it.

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

- No mixed-field content — each field's content must stay
  within its defined scope
- Each field must stand alone and be independently pasteable
  into its Drupal ECK field
- Content must paste directly into Drupal without modification
- Prose paragraphs are permitted only in field_sop_steps to
  provide context before procedural steps
- All other fields must use lists and directive statements only
- HTML must use inline styles only — no class dependencies

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