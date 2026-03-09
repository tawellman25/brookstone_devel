# BOS Entity — contract_action_log

Entity Type ID: `contract_action_log`
Storage: ECK

## Purpose
- Append-only audit log for contract lifecycle status transitions.
- Records who performed a status change, from/to states, and whether it was an admin override.

## Bundles
`log` (single bundle)

## Required Relationships
- `field_contract` → `contracts`
- `uid` (base) → `user` (who performed the action)

## Key Fields
- `field_action` — string: action name/type
- `field_actor` — list_string: actor role context (e.g., office, admin)
- `field_admin_override` — boolean: whether normal workflow guards were bypassed
- `field_context` — long text: additional context
- `field_from_status` → `taxonomy_term` — contract status before the action
- `field_to_status` → `taxonomy_term` — contract status after the action

## Invariants
- Append-only. Never edit or delete log entries.
- Created automatically by contract action classes in `contract_residential` module.
- `field_admin_override = true` must be set when a transition bypasses workflow validation.

## Deletion / Archival
- Do not delete. Permanent audit trail.

---

# BOS Entity — contract_notes

Entity Type ID: `contract_notes`
Storage: ECK

## Purpose
- Notes attached to a contract. Append-only in practice.

## Bundles
`note` (single bundle)

## Required Relationships
- `field_contract` → `contracts`

## Key Fields
- `field_note` — long text note body
- `uid` (base) — author
- `created` (base) — timestamp

## Invariants
- Do not edit notes after creation — append new notes instead.
- Do not delete notes from active or completed contracts.

## Deletion / Archival
- Do not delete. Operational history.
