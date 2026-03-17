# BOS Business Process — Weed Spray Route Reconciliation

Documents the annual process and supporting automation for maintaining the weed spray route — which properties receive weed spraying services and at what frequency.

---

## Core Entity: `property_spraying_info.weed_spraying`

One entity per property that receives or has ever received weed spraying. Key fields:

| Field | Type | Purpose |
|---|---|---|
| `field_property` | entity_reference | Parent property (required) |
| `field_spray_route` | boolean | Currently on active spray route |
| `field_active_contract_year` | integer | Year for which contract is active (set to NULL if removed) |
| `field_weed_beds_contracted` | boolean | Landscape beds weed spray contracted this year |
| `field_weed_misc_contracted` | boolean | Misc areas weed spray contracted this year |
| `field_beds_spraying_frequency` | entity_reference | Beds spray frequency (taxonomy) |
| `field_misc_spraying_frequency` | entity_reference | Misc areas spray frequency (taxonomy) |
| `field_route_order` | integer | Order on spray route |
| `field_spray_map` | text | Spray map notes |
| `field_spray_notes` | text | General spray notes |
| `field_last_amount_applied` | decimal | Last chemical amount applied |
| `field_last_applied_by` | entity_reference | User who last applied |
| `field_last_applied_date` | date | Date of last application |

`field_active_contract_year` is the key reconciliation field — it is set when a property is added to the route for a given year, and cleared (NULL) when removed.

---

## Automatic Sync (Hook-Based)

Module: `contract_residential`
Function: `_contract_residential_maybe_sync_weed_spray_route()`

**Triggers:** Any insert or update of:
- `contract_sections.weed_spraying_landscape_beds`
- `contract_sections.weed_spraying_of_misc_areas`

**Year guard:** Only processes contracts with `field_contract_year >= 2026`. Older contracts are ignored.

**Flow:**
1. Reads `field_do_you_want` from the section (1 = Yes, anything else = No)
2. Loads parent contract via `field_contract`
3. Checks contract year
4. Loads property from contract
5. Finds or creates `property_spraying_info.weed_spraying` for that property
6. Updates fields based on bundle and do_you_want value:

**weed_spraying_landscape_beds + Yes:**
- `field_weed_beds_contracted = TRUE`
- `field_spray_route = TRUE`
- `field_active_contract_year = contract_year`
- `field_beds_spraying_frequency = section.field_spraying_frequency` (if set)

**weed_spraying_landscape_beds + No:**
- `field_weed_beds_contracted = FALSE`
- If `field_weed_misc_contracted` is also FALSE: `field_spray_route = FALSE`, `field_active_contract_year = NULL`
- (Beds removal only removes from route if misc is also not contracted)

**weed_spraying_of_misc_areas + Yes:**
- `field_weed_misc_contracted = TRUE`
- `field_spray_route = TRUE`
- `field_active_contract_year = contract_year`
- `field_misc_spraying_frequency = section.field_spraying_frequency` (if set)

**weed_spraying_of_misc_areas + No:**
- `field_weed_misc_contracted = FALSE`
- If `field_weed_beds_contracted` is also FALSE: `field_spray_route = FALSE`, `field_active_contract_year = NULL`

**Entity creation:** If no `property_spraying_info.weed_spraying` exists for the property and `do_you_want = 1`, the entity is created. If `do_you_want != 1` and no entity exists, the hook returns without creating one.

Logs: channel `contract_residential`, notice level.

---

## Manual VBO Actions (Bulk Reconciliation)

These actions operate on `contracts` entities (from the contracts VBO view) and are the manual counterparts to the automatic hook-based sync.

### SprayRouteOnLandscapeWeedSprayAction (`put_on_landscape_weed_spray_route_action`)
- Reads `contract.field_weed_spraying_of_landscape` → loads contract section
- Checks `field_do_you_want = 1` (No = blocks with error; unset = blocks with error)
- Reads `field_spraying_frequency` from section
- Finds or creates `property_spraying_info.weed_spraying` for the contract's property
- Sets `field_spray_route = 1`, `field_weed_beds_contracted = 1`, `field_beds_spraying_frequency`
- If already on route: updates frequency and shows "already on route" error (but still saves)

### SprayRouteOffLandscapeWeedSprayAction (`spray_route_off_landscape_weed_spray_action`)
- Similar pattern, sets `field_weed_beds_contracted = 0`; only removes from route if misc is also not contracted

### SprayRouteOnMiscWeedSprayAction / SprayRouteOffMiscWeedSprayAction
- Same pattern targeting `contract.field_weed_spraying_of_misc_areas` and `field_weed_misc_contracted`

### SprayRouteRemoveAction (`spray_route_remove_action`)
Type: `property_spraying_info` (operates directly on the entity, not via contract)

Clears all route fields in one action:
- `field_spray_route = FALSE`
- `field_weed_beds_contracted = FALSE`
- `field_weed_misc_contracted = FALSE`
- `field_active_contract_year = NULL`

Logs: channel `contract_residential`, notice level, includes property ID.

---

## Views

Three views support weed spray management:

| View machine name | Purpose |
|---|---|
| `admin_weed_spray_route` | Current active spray route (filter: `field_spray_route = TRUE`) |
| `admin_weed_spray_reconciliation` | Reconciliation view — shows all weed spray properties with contract status; used for annual reconciliation |
| `admin_weed_spray_billing` | Billing view for weed spray services |

---

## Annual Reconciliation Process

At the start of each season (typically early spring):

1. Run `admin_weed_spray_reconciliation` view to compare `field_active_contract_year` against the current contract year.
2. Properties with `field_active_contract_year < current_year` are candidates for removal — their contracts from the previous year had weed spray, but a new year contract has not yet been processed.
3. Use `SprayRouteRemoveAction` VBO action to bulk-clear stale properties from the route.
4. As new contracts are entered and sections saved, the automatic hook-based sync adds properties back to the route with the current year.

Properties with `field_spray_route = TRUE` but `field_active_contract_year = NULL` or year mismatch should be investigated — they may represent legacy data or manually added properties.

---

## Integration Notes

- The automatic hook-based sync (`_contract_residential_maybe_sync_weed_spray_route`) was added in 2026 as the authoritative path for new contracts.
- The manual VBO actions (`SprayRouteOn/Off*`) predate the automatic sync and remain available for bulk operations and for contracts that were created before the automation was active.
- For contracts with `field_contract_year < 2026`, the automatic sync is intentionally bypassed — use the manual VBO actions for those.
- `field_active_contract_year` is the primary flag for annual reconciliation — it represents the year for which the contract was confirmed, not just whether the property is currently on the route.
