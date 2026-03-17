# BOS Module — estimate_intake

Auto-populates BOS record fields on `estimate_request.standard` entities from requestor intake data, assigns canonical titles, and auto-creates `estimate` entities for each service requested.

Module machine name: `estimate_intake`
Package: Brookstone
Dependencies: `estimate:estimate`, `drupal:user`

---

## Purpose

Handles the **manual office entry** path — when an office user creates an estimate request and fills in requestor fields (address, name, phone, email). The module runs lookups on presave so fields are populated before the entity is written to the database.

On insert, it also auto-creates one `estimate` entity per service term that has `field_estimate_service = TRUE`, and optionally creates an `estimate_tasks` entity for each estimate if that bundle is supported.

---

## Responsibilities

- `hook_module_implements_alter` — ensures this module's `entity_presave` and `entity_insert` hooks run before the `estimate` module.
- `hook_entity_presave` on `estimate_request.standard` — orchestrates field lookups from requestor intake data.
- `hook_entity_insert` on `estimate_request.standard` — assigns canonical title, then auto-creates estimate entities per service.

---

## Service: `estimate_intake.intake_lookup`

Class: `Drupal\estimate_intake\Service\EstimateRequestIntakeLookup`

Constructor arguments: `@entity_type.manager`, `@logger.factory`

### `orchestrate(string $address, string $first_name, string $last_name, string $phone, string $email): array`

Main entry point. Returns:
```php
[
  'properties'      => EntityInterface[],  // matching property entities (up to 20)
  'owner_uid'       => int|null,           // owner UID from ownership_record
  'contact_id'      => int,               // matched or created contact ID (0 if none)
  'contact_created' => bool,              // TRUE if a new contact was created
]
```

Calls `findProperties()`, `findLatestOwner()`, and `findOrCreateContact()` in sequence.

### `findProperties(string $address): EntityInterface[]`

LIKE query on `properties.property.field_street_address`. Returns up to 20 matches.

### `findLatestOwner(int $property_id): ?int`

Loads the most recent `ownership_record.record` for the property (sorted by ID descending). Returns `field_property_owner` target UID, or NULL if no record found.

### `findOrCreateContact(string $first_name, string $last_name, string $phone, string $email): array`

Match priority:
1. Email match — direct query on `contacts.contact.field_email`
2. Phone match — two-step: find `phone_number.contacts` by `field_phone_number`, then find `contacts.contact` referencing those phone entities
3. No match — create a new `contacts.contact` (and `phone_number.contacts` sub-entity if phone provided)

Phone numbers normalized to digits only (`normalizePhone()`) before lookup and before creating the phone_number entity.

Returns `['id' => int, 'created' => bool]`.

---

## Hook Behavior

### `hook_module_implements_alter`

Moves `estimate_intake` to the front of the implementation list for both `entity_presave` and `entity_insert`. This ensures fields are populated before the `estimate` module's warning builder checks them.

### `hook_entity_presave` (`estimate_request.standard`)

1. Reads requestor fields: `field_requestor_address`, `field_requestor_name`, `field_requestor_phone`, `field_requestor_email`
2. Returns early if all four are empty
3. Splits name on first space into first/last
4. Calls `orchestrate()` with all five values
5. Populates fields **only if not already set** (manually entered values win):
   - `field_property` — set if exactly one property match found; skipped if multiple matches (warning message shown)
   - `field_owner` — set from ownership_record for the matched or pre-existing property
   - `field_contact` — set from matched or newly created contact
6. Displays Drupal status messages summarizing what was matched/created

### `hook_entity_insert` (`estimate_request.standard`)

**Title assignment:**
Assigns `Estimate Request #[id]` if the current title is empty, `'Standard'`, or `'Estimate Request - Pending'`. Custom titles entered by users are preserved. This covers both manually created requests and auto-created requests from `estimate_contract_residential` (which use `'Estimate Request - Pending'` as the temporary title).

**Estimate auto-creation** (calls `_estimate_intake_create_estimate()`):

Loops over all values in `field_service`:
1. Loads each service taxonomy term
2. Checks `field_service_bundle` — skips if empty
3. Checks `field_estimate_service` — skips if FALSE or empty
4. Validates that the estimate bundle exists
5. Creates `estimate` entity:
   - `type` = bundle from `field_service_bundle`
   - `title` = `'Estimate - Pending'` (overwritten to `'Estimate #[id]'` after save)
   - `field_estimate_request` = request entity
   - `field_revision_number` = 1
   - `field_is_current_revision` = TRUE
   - `field_assigned_to` from term's `field_default_estimator` (if set)
6. Saves estimate, updates title to `'Estimate #[id]'`, saves again
7. Appends estimate to `estimate_request.field_estimates`, saves request
8. Calls `_estimate_intake_create_estimate_task()` for this estimate

**estimate_tasks auto-creation** (calls `_estimate_intake_create_estimate_task()`):

Only creates tasks for bundles in the supported list:
```
aerating, aspen_twig_gall, backflow_testing, cooley_spruce_gall,
deciduous_bore, deer_prevention, dethatching, dormant_oil,
fertilizing, fertilizing_trees_and_shrubs, lawn_mowing,
pinyon_pine_ips_beetle, pre_emergent, special_mowing,
sprinkler_start_up, sprinkler_winterizing, summer_pruning,
trunk_bore, winter_pruning
```

For supported bundles:
- Validates `estimate_tasks` bundle exists
- Checks for existing `estimate_tasks` with same `field_estimate` + `type` (no duplicates)
- Creates `estimate_tasks` entity: `type = bundle`, `title = 'ET-Pending'`, `field_estimate = estimate_id`
- Task creation failure is caught and logged but never blocks estimate creation

---

## Non-Responsibilities

- Does not watch any entity type other than `estimate_request`
- Does not manage Work Order creation (that is `wo_project_pipeline`)
- Does not handle the contract-path auto-creation (that is `estimate_contract_residential`)
- Does not create container estimates for landscaping (that is `wo_project_pipeline.pipeline_service::maybeCreateContainerEstimate()`)

---

## Logging

Channel: `estimate_intake`

| Level | Event |
|---|---|
| `info` | Estimate created (ID, bundle, request ID) |
| `info` | estimate_task created (task ID, bundle, estimate ID) |
| `notice` | Skipping estimate: field_estimate_service empty or disabled |
| `warning` | Estimate bundle does not exist |
| `warning` | estimate_tasks bundle does not exist |
| `error` | Estimate auto-creation failed (exception message) |
| `error` | estimate_task creation failed (exception message) |

---

## Related Modules

- `estimate` — owns the `estimate_request` entity type; runs after `estimate_intake` in hook execution order
- `wo_project_pipeline` — handles landscaping container estimate creation from estimate_request on insert; complements estimate_intake (both fire on estimate_request insert, but for different purposes)
- `estimate_contract_residential` — auto-creates estimate requests from contract sections; those requests pass through `estimate_intake`'s insert hook for title assignment and estimate creation
- `estimate_notifications` — sends assignment email when `field_assigned_to` is set on the estimate_request (independent of this module)
- `est_*` modules (19 modules) — per-bundle estimate_tasks calculation modules that compute `field_estimate_total` on the tasks entity
