# BOS Module — contract_residential

Module:
- contract_residential

Purpose:
- Enforce residential contract governance rules.
- Provide office/admin editing UX for Contracts and Contract Sections.
- Act as the authoritative “intent layer” controller for residential contracts.

---

## Responsibilities

### Contract Invariants
- Enforces **one Residential Contract per Property per Year**.
- Validation occurs at entity validation time (no WSODs).
- Contract year is normalized and auto-filled when missing.

### Contract ↔ Contract Section Integrity
- Automatically links Contract Sections back to their parent Contract.
- Never unlinks sections.
- Never overwrites a section linked to a different Contract.
- System-driven section saves are explicitly marked to suppress audit logging.

### Admin Theme Enforcement
- Uses a **Theme Negotiator** to force the admin theme on Contract routes.
- Theme switching is based on **route names**, not URL paths.
- Pathauto aliases do not affect behavior.

#### Affected Routes
- `entity.contracts.canonical`
- `entity.contracts.edit_form`
- `entity.contracts.add_form`
- `entity.contracts.collection`
- Any routes prefixed with `contract_residential.*`

#### Applicable Roles
Admin theme is applied only for users with one of the following roles:
- `administrator`
- `site_admin`
- `administration`
- `site_assistant`
- `supervisor`

Admin theme machine name:
- `brookstone_admin`

### Contract Section Editing UX
- Contract Sections are edited **from the Contract context**, not as standalone pages.
- Sections are listed via a View in the Contract Admin view mode.
- Editing occurs in **modal dialogs** using AJAX.
- Staff remain on the Contract page throughout editing.

### Audit Interaction
- This module does **not** create audit records.
- It sets a request-scoped suppression flag during system-driven updates.
- Audit behavior is owned by `contract_sections_audit`.

---

## Governance Rules

- This module enforces **business rules**, not just UI behavior.
- Theme enforcement is **presentation-only** and does not replace access control.
- Permissions still govern who may view or edit Contracts.
- Contract Sections represent **intent**, never execution.

---

## Non-Responsibilities

The following are explicitly out of scope for this module:
- Client self-editing workflows
- Bulk Contract Section actions
- Approval/review workflows
- Work Order creation or execution logic
- Audit record storage or display

---

## Future Extensions (Non-Binding)

- Client-side Contract Section editing (permission-gated).
- Bulk Contract Section actions (View-based).
- Inline warnings when a Property already has a Contract for a given year.

These must be implemented in separate modules or branches.
