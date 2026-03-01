# Contract Sections Audit Module

## Module Name
`contract_sections_audit`

## Purpose
Provides an **append-only, system-generated audit trail** for changes made to `contract_sections` entities.  
This module records **who changed what, and when**, without relying on redirects, UI workflows, or client-side behavior.

Audit logging is **entity-lifecycle driven**, not UI-driven.

---

## Core Responsibilities
- Log changes to `contract_sections` entities on:
  - create
  - update
  - delete
- Record:
  - **Who** made the change (entity owner / uid)
  - **When** the change occurred (created timestamp)
  - **What changed** (filtered, admin-friendly bullet list)
  - **Which section** was changed (entity reference)
- Remain independent of:
  - UI routes
  - AJAX / modal workflows
  - redirects or destination logic

---

## What This Module Does NOT Do
- Does NOT manage UI or Views
- Does NOT refresh pages or blocks
- Does NOT depend on form submit handlers
- Does NOT allow manual creation, editing, or deletion of audit records
- Does NOT log system-driven sync operations (explicitly suppressed)

UI concerns belong in `contract_sections_ui`.

---

## Entity Details

### Audit Entity Type
- Entity type: `contract_sections_audit`
- Bundle: `log`
- Base fields:
  - `uid` (author)
  - `created` (timestamp)
  - `changed`
  - `title` (enabled base field)

### Bundle Fields
| Field | Type | Purpose |
|-----|------|--------|
| `field_contract_section` | entity_reference | Points to the affected `contract_sections` entity |
| `field_action` | list_string | insert / update / delete |
| `field_section_bundle` | string | Snapshot of section bundle |
| `field_changed_fields` | long text | Plain-text bullet list of meaningful changes |

---

## Audit Trigger Mechanism

Audit logging is triggered via:
- `hook_entity_insert()`
- `hook_entity_update()`
- `hook_entity_delete()`

on entity type:
```
contract_sections
```

### Important Implementation Detail
When `$entity->original` is unavailable (e.g. AJAX modal save), the module:
- Loads the unchanged entity via `loadUnchanged()`
- Diffs fields manually
- Logs changes reliably regardless of UI path

---

## Suppression Logic (Critical)

The ONLY suppression mechanism is an **explicit request flag**:

```
contract_sections_audit_suppress
```

Set by:
- `contract_residential` during system-driven section sync

Audit module behavior:
- If the flag is present → audit logging is skipped
- If not present → audit logging proceeds

Audit logic **never checks**:
- current route
- request type (AJAX vs HTML)
- destination
- path alias

---

## Field Change Filtering

### Hard Excluded Fields (Never Logged)
- System fields: `uid`, `created`, `changed`, `uuid`, `path`, `type`
- Feeds metadata
- Work order pointers
- Execution data (chemicals, gallons, man hours)
- Structural wiring fields

These are excluded at the **audit writer**, not in Views.

---

## Admin-Friendly Labels

The module maps verbose client-facing field labels to short admin labels:

Example:
- `field_do_you_want` → Included / Not Included
- `field_mowing_frequency` → How Often
- `field_aerating_season` → Season

Labels are rendered as **plain-text bullets**:

```
• Included / Not Included
• Season
• How Often
```

No HTML is stored; compatible with plain long text fields.

---

## Permissions (Recommended Final State)

| Permission | Roles |
|----------|-------|
| Create | None |
| Edit | None |
| Delete | None |
| View | Admin / Office |

Audit logs are **read-only telemetry**, not content.

---

## Known Pitfalls (Resolved)

- Missing audit rows during AJAX modal saves  
  → Fixed by loading unchanged entity when original is missing
- Memory exhaustion on edit form  
  → Fixed by using autocomplete widget for section reference
- Missing section reference  
  → Fixed by removing bundle restriction on reference field
- Title-related 500 errors  
  → Resolved by running entity schema updates and aligning base field config

---

## Related Modules

- `contract_residential`
  - Sets suppression flag during system sync
- `contract_sections_ui`
  - Owns modal editing, AJAX submit, and View refresh
- `auto_entitylabel`
  - Must NOT run on audit entity/bundle

---

## Design Principle

> Audit logs record **human intent**, not system noise.

If a change does not represent human accountability, it does not belong in the audit trail.
