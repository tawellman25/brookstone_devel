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
| `field_assigned_to` | Loaded from service_term.field_default_estimator (if set) |
| `field_priority` | 'normal' (hardcoded default) |
| `field_status` | Term lookup: vid=estimate_request_status, name='New' |
| `title` | 'Estimate Request – Contract {N} – Section {N}' |

### Back-Reference Write

After the estimate_request is saved, the service:
1. Sets `contract_sections.field_estimate_request = new_request_id`.
2. Saves the section (guarded by `$savingSectionGuard` to prevent hook re-entry).
3. Logs both operations (info level) to the `contract_residential` logger channel.

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
| error | estimate_request creation failed (with exception message) |
| error | back-reference write failed |

---

## Related Modules

- `estimate_notifications` — sends assignment email when `field_assigned_to` is set on
  the newly created estimate_request.
- `contract_residential` — owns the `EstimateRequestAutoCreator` service class and
  services.yml entry (service ID: `contract_residential.estimate_request_auto_creator`).
