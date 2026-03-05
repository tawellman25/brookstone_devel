# BOS Module — estimate_intake

Module machine name: `estimate_intake`
Package: Brookstone
Dependencies: `estimate:estimate`, `drupal:user`

---

## Purpose

Auto-populates BOS record fields on `estimate_request.standard` entities from
requestor intake information (address, name, phone, email) during presave. Also
assigns the canonical title format on entity insert.

This module handles the **manual office entry** path — when an office user creates
an estimate request directly and fills in requestor fields. The module runs its
lookups on presave so fields are populated before the entity is written to the
database.

---

## Responsibilities

- `hook_entity_presave` on `estimate_request.standard` — orchestrate field lookups.
- `hook_entity_insert` on `estimate_request.standard` — assign canonical title.
- `hook_module_implements_alter` — ensure presave and insert hooks run before
  the `estimate` module (suppresses false "missing field" warnings).
- Delegate all lookup and creation logic to `EstimateRequestIntakeLookup` service.

## Non-Responsibilities

- Does not watch any entity type other than `estimate_request`.
- Does not create Estimates (only populates fields on Estimate Requests).
- Does not manage Work Order creation.
- Does not handle the contract-path auto-creation (that is `estimate_contract_residential`).

---

## Service: `estimate_intake.intake_lookup`

Class: `Drupal\estimate_intake\Service\EstimateRequestIntakeLookup`

Constructor arguments: `@entity_type.manager`, `@logger.factory`

### Methods

#### `orchestrate(string $address, string $first_name, string $last_name, string $phone, string $email): array`

Main entry point called from `hook_entity_presave`. Returns:

```php
[
  'properties'      => EntityInterface[],  // matching property entities
  'owner_uid'       => int|null,           // owner user ID from ownership_record
  'contact_id'      => int,               // matched or created contact ID (0 if none)
  'contact_created' => bool,              // TRUE if a new contact was created
]
```

Calls `findProperties()`, `findLatestOwner()`, and `findOrCreateContact()` in sequence.

#### `findProperties(string $address): EntityInterface[]`

LIKE query on `properties.property.field_street_address`. Returns up to 20 matches.

#### `findLatestOwner(int $property_id): ?int`

Loads the most recent `ownership_record.record` for the given property ID
(sorted by ID descending). Returns the `field_property_owner` target UID, or
NULL if no record found.

#### `findOrCreateContact(string $first_name, string $last_name, string $phone, string $email): array`

Match priority:
1. Email match — direct query on `contacts.contact.field_email`.
2. Phone match — two-step: find `phone_number.contacts` entities by `field_phone_number`,
   then find `contacts.contact` referencing those phone entities.
3. No match — create a new `contacts.contact` (and `phone_number.contacts` sub-entity
   if phone provided).

Phone numbers are normalized to digits only (`normalizePhone()`) before lookup
and before creating the phone_number entity.

Returns `['id' => int, 'created' => bool]`.

---

## Hook Behavior

### hook_entity_presave (`estimate_request.standard`)

1. Reads requestor fields: `field_requestor_address`, `field_requestor_name`,
   `field_requestor_phone`, `field_requestor_email`.
2. Exits early if all four are empty.
3. Splits name on first space into first/last.
4. Calls `orchestrate()` with all five values.
5. Populates fields **only if not already set** (manual entries always win):
   - `field_property` — set if exactly one property match found.
   - `field_owner` — set from ownership_record for the matched (or pre-set) property.
   - `field_contact` — set from matched or newly created contact.
6. Displays status messages summarizing what was matched/created.

### hook_entity_insert (`estimate_request.standard`)

Assigns the canonical title `Estimate Request #[id]` if the current title is
empty, `'Standard'`, or `'Estimate Request - Pending'`. Custom titles entered
by users are preserved.

This hook also covers auto-created requests from `estimate_contract_residential`,
which use the temporary title `'Estimate Request - Pending'` at creation time.

### hook_module_implements_alter

Moves `estimate_intake` to the front of the implementation list for both
`entity_presave` and `entity_insert` hooks. This ensures fields are populated
before the `estimate` module's warning builder checks them.

---

## Logging

Channel: `estimate_intake`

| Level | Event |
|---|---|
| info | Contact created from estimate intake (with contact ID and name) |
| error | Contact creation failed (with exception message) |

---

## Related Modules

- `estimate` — owns the `estimate_request` entity type; runs after `estimate_intake`
  in hook execution order.
- `estimate_contract_residential` — auto-creates estimate requests from contract
  sections; those requests also pass through `estimate_intake`'s insert hook
  for title assignment.
- `estimate_notifications` — sends assignment email when `field_assigned_to` is
  set on the estimate_request (independent of this module).
