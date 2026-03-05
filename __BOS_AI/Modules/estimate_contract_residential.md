# BOS Module — estimate_contract_residential

Module machine name: `estimate_contract_residential`
Package: Brookstone
Dependencies: `estimate:estimate`, `contract_residential:contract_residential`

---

## Purpose

Integration glue between the residential contract workflow and the estimate domain.

Creates an Estimate Request automatically when a Contract Section is marked
"Request Quote" by the office. This is the primary automated entry point for
estimate requests originating from residential contracts.

---

## Responsibilities

- Watch `contract_sections` entity insert and update hooks.
- Watch `estimate_request` entity delete hook (cleanup back-references).
- Delegate to `EstimateRequestAutoCreator` service for all business logic.
- Keep the hook file thin; no logic in the .module file.

## Non-Responsibilities

- Does not watch any other entity type.
- Does not create Estimates (only Estimate Requests).
- Does not manage Work Order creation.
- Does not handle client-facing estimate delivery.

---

## Service: `contract_residential.estimate_request_auto_creator`

Class: `Drupal\contract_residential\Service\EstimateRequestAutoCreator`

Constructor arguments: `@entity_type.manager`, `@logger.factory`

### Trigger Condition

```
contract_sections.field_do_you_want == '3'   (value: Request Quote)
```

Only fires on `contract_sections` entities. Exits immediately on any other entity type.

### Idempotency Rules

1. If `contract_sections.field_estimate_request` already has a target_id → do nothing.
2. If pointer is missing but an `estimate_request` referencing this section already exists
   → reuse it and write the pointer back to the section.
3. Otherwise → create a new `estimate_request.standard`.

A static recursion guard (`$savingSectionGuard`) prevents re-entrant saves when writing
the back-reference to the section.

### Fields Auto-Populated on estimate_request Creation

| estimate_request field | Source |
|---|---|
| `field_contract_section` | The triggering contract_sections entity id |
| `field_contract` | section.field_contract (or section.field_parent_contract fallback) |
| `field_service` | section.field_service |
| `field_property` | Loaded from contract.field_property |
| `field_owner` | Loaded from contract.field_property_owner |
| `field_priority` | 'normal' (hardcoded default) |
| `field_status` | Term lookup: vid=estimate_request_status, name='New' |
| `title` | 'Estimate Request - Pending' on create, updated to 'Estimate Request #[id]' after save |

Note: `field_assigned_to` is **not** set by the auto-creator. It comes from the entity
field default value configured in the UI (currently set to the office manager). The
`estimate_notifications` module sends the assignment email based on that default.

### Title Format

The title is set in two steps:
1. On create: `'Estimate Request - Pending'` (ID not yet available).
2. After initial save: updated to `'Estimate Request #[id]'` and resaved.

The `estimate_intake` module's `hook_entity_insert` may also set this title (for both
auto-created and manually created requests). The auto-creator checks whether the title
was already set by the hook before doing a redundant resave.

### Back-Reference Write

After the estimate_request is saved, the service:
1. Sets `contract_sections.field_estimate_request = new_request_id`.
2. Saves the section (guarded by `$savingSectionGuard` to prevent hook re-entry).
3. Logs both operations (info level) to the `contract_residential` logger channel.

### Contract Action Log Entry

On successful creation, the service writes an event to `contract_action_log` via
`ContractActionLogWriter::writeEvent()`:

| Parameter | Value |
|---|---|
| event_key | `estimate_request_created` |
| actor | `system` |
| context (JSON) | `estimate_request_id`, `service` (term name), `assigned_to` (uid if set), `url` (canonical absolute) |

The log entry uses the contract's current status as from/to (no status transition implied).
Logging failure is caught and logged as a warning — it never blocks estimate request creation.

### Delete Cleanup: `onRequestDeleted()`

When an `estimate_request` entity is deleted (via `hook_entity_delete`), the service:
1. Reads `field_contract_section` from the deleted request.
2. Loads the referenced `contract_sections` entity.
3. If `contract_sections.field_estimate_request` still points to the deleted request,
   clears the reference (sets to NULL) and saves.
4. Uses the same `$savingSectionGuard` to prevent hook re-entry.
5. Logs the cleanup (info level) or failure (error level).

### Error Handling

All entity loads and saves are wrapped in try/catch. Failures are logged at error level
but do not throw exceptions or interrupt the original entity save.

---

## Logging

Channel: `contract_residential`

| Level | Event |
|---|---|
| info | estimate_request created for section |
| info | back-reference written to section |
| info | back-reference cleared on request deletion |
| warning | contract action log write failed |
| error | estimate_request creation failed (with exception message) |
| error | back-reference write failed |
| error | back-reference cleanup failed on delete |

---

## Historical Notes

### OLD_VERSION Directory Issue (Resolved 2026-03-04)

The module directory `estimate_contract_residential_OLD_VERSION/` contained a prior
version of the module with its own service class and different behavior (did not populate
`field_property`, `field_owner`, or `field_assigned_to`). Drupal's `ExtensionDiscovery`
was silently loading the OLD_VERSION directory instead of the correct one.

Resolution: The directory was renamed to `_estimate_contract_residential_OLD_VERSION/`
(leading underscore) to exclude it from Drupal module discovery. It is dead code — do not
restore or reference.

---

## Related Modules

- `estimate_notifications` — sends assignment email when `field_assigned_to` is set on
  the newly created estimate_request.
- `estimate_intake` — populates fields via presave lookup; also sets title via
  `hook_entity_insert` for both auto-created and manual requests.
- `contract_residential` — owns the `EstimateRequestAutoCreator` service class and
  services.yml entry (service ID: `contract_residential.estimate_request_auto_creator`).
