# BOS Entities — Policy (Cross-Cutting Rules)

This file defines rules that apply to all BOS entities.
Entity files must follow these rules.

---

## Documentation Rules

- Every entity file must include:
  - Entity Type ID (machine name)
  - Bundle(s)
  - Purpose (1-3 bullets)
  - Required Relationships (reference fields that must exist)
  - Key Fields (global fields that drive behavior)
  - Invariants (must/never rules)
  - Deletion/Archival stance
  - Integration fields (external IDs if applicable)

- Do not list every field. Only fields that define:
  - Relationships
  - Workflow behavior
  - Access/security behavior
  - Integration behavior

- Keep each entity file short and authoritative.

---

## Data Model Rules

### Relationships
- All core relationships must be explicit entity reference fields.
- Avoid storing duplicated copies of related data (derive or reference instead).
- Relationships must have a documented “source of truth”.

### Ownership
- Ownership rules must be explicit:
  - Who “owns” the record
  - Who can edit it
  - Who can view it
- Do not infer ownership from names or paths.

### States / Status
- If an entity has a lifecycle, document:
  - allowed states
  - allowed transitions
  - what becomes immutable and when

### Auditing
- Operational history must be preservable:
  - prefer append-only notes/logs over overwriting history
  - record “who/when” for key events where possible

---

## Deletion / Archival Rules

- Default stance: do not delete operational history.
- Prefer:
  - “Archived” status
  - “Inactive” flags
  - retention rules
- If deletion is allowed:
  - it must be role-restricted
  - it must be documented
  - it must handle dependencies explicitly (no surprise cascades)

---

## Integration Rules

- Accounting (e.g., QuickBooks) is downstream from BOS operational truth.
- External IDs may be stored for mapping.
- External systems must not become the logic authority for BOS workflows.

---

## Naming Rules (Entity Docs)

- Use the entity type ID as the file’s primary identifier.
- Use BOS language:
  - Refer to the platform as “BOS”
  - Avoid “ERP” outside technical/admin docs

