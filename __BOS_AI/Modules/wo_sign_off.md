# BOS Module — wo_sign_off

Module: wo_sign_off
Package: Work Orders

## Purpose

Owns sign-off-time business logic for work orders:

1. **Existing (pre-Phase 2):** When `wo_complete_info` is saved, transitions the parent work order to Complete (1097) or Canceled (1098), calculates trip fee, calculates total time, sends cancellation email if applicable. Reverts the WO to In Progress (1092) and clears billing fields when `wo_complete_info` is deleted.

2. **Phase 2a (this commit):** Adds `WoCrewRosterService` and four audit fields on `wo_time_clock:entry` as foundational infrastructure for crew-scoped time entry reconciliation. No behavioral change yet.

3. **Phase 2b (planned):** Form alter on the six `wo_complete_info` sign-off bundles — at sign-off time, ensure every roster member from `field_those_on_crew` has a complete `wo_time_clock` entry on the parent WO. Three handled cases per roster member: clean (no action), orphaned (signer closes), missing (signer creates).

4. **Phase 2c (planned):** Same reconciliation pattern on `wo_tasks_list:lawn_mowing`, intercepting before the wo_lawn_mowing cascade fires.

5. **Phase 2d (planned):** Logging cleanup for the silent-no-op case in `wo_timer_flag_update_flagging_delete`.

---

## WoCrewRosterService (Phase 2a)

`@wo_sign_off.crew_roster` — `src/Service/WoCrewRosterService.php`

Single source of truth for "who was on this WO" by sign-off context. Phase 2 reconciliation handlers and the defense-in-depth presave guard both query through this service so the in-scope-bundle list and the field-name-per-context routing stay in one place.

### Public API

| Method | Returns | Purpose |
|---|---|---|
| `getCrewForWorkOrder(int $wo_id, string $signoff_entity_type, string $signoff_bundle)` | `int[]` | Read roster from a saved sign-off entity by looking up via `field_work_order`. Returns empty array when no such sign-off entity exists yet (e.g., new entity in form). |
| `normalizeRosterFromFormState(array $form_state_values, string $signoff_entity_type, string $signoff_bundle)` | `int[]` | Read roster from in-flight form state values. Use during form lifecycle (alter/validate/submit) where user-edited values may differ from persisted values. |
| `isInScope(string $signoff_entity_type, string $signoff_bundle)` | `bool` | Cheap predicate for short-circuiting outside scope. |

Both read methods deduplicate user IDs and return integers.

### Routing logic

| Sign-off entity_type | Bundles in scope | Roster field |
|---|---|---|
| `wo_complete_info` | `complete`, `landscape_crew`, `clean_up_crew`, `fertilizing_crew`, `irrigation_crew`, `spray_crew` | `field_those_on_crew` |
| `wo_tasks_list` | `lawn_mowing` | `field_mowing_who_on_site` |
| anything else | (out of scope) | (returns empty) |

`wo_complete_info:snow_removal` and `wo_tasks_list:special_mowing` are deliberately excluded from Phase 2 even though `field_those_on_crew` / `field_mowing_who_on_site` exist on those bundles. Snow removal deferred to fall 2026; special_mowing deferred per Phase 2 diagnostic (8 entries; structural divergence from wo_lawn_mowing's cascade pattern).

The bundle lists are exposed as public class constants `COMPLETE_INFO_BUNDLES` and `TASKS_LIST_BUNDLES` so callers can reuse them for their own scope checks (e.g., `hook_form_alter` early returns).

---

## Audit fields on wo_time_clock:entry (Phase 2a)

Four single-value entity_reference fields tracking which sign-off entity closed or created each `wo_time_clock` entry. All hidden on form display, visible on view display so audit context is surfaced when staff inspect a time entry.

| Field machine name | Targets | Populated when |
|---|---|---|
| `field_closed_signoff_complete` | `wo_complete_info` (six in-scope bundles) | Phase 2b reconciliation closes an orphan during a `wo_complete_info` sign-off |
| `field_closed_signoff_tasks` | `wo_tasks_list` (lawn_mowing only) | Phase 2c reconciliation closes an orphan during a `wo_tasks_list:lawn_mowing` sign-off |
| `field_created_signoff_complete` | `wo_complete_info` (six in-scope bundles) | Phase 2b reconciliation creates a new entry for a missing roster member during a `wo_complete_info` sign-off |
| `field_created_signoff_tasks` | `wo_tasks_list` (lawn_mowing only) | Phase 2c reconciliation creates a new entry for a missing roster member during a `wo_tasks_list:lawn_mowing` sign-off |

### Naming note

Field machine names omit "by" to fit Drupal's 32-character field name limit (`field_created_by_signoff_complete` would be 33 chars). Field labels and help text retain the natural-language wording — "Closed by sign-off (complete info)" / "Created by sign-off (complete info)" / etc. — where character limits don't apply.

### Bundle target restriction

Each audit field's `target_bundles` is restricted to the Phase 2 in-scope bundles for its target entity type. The `_complete` fields can only reference the six wo_complete_info sign-off bundles (excluding lawn_mowing, snow_removal). The `_tasks` fields can only reference `wo_tasks_list:lawn_mowing` (excluding special_mowing). This enforces data integrity at the storage layer — programmatic populates with out-of-scope target bundles fail validation.

### Population semantics (Phase 2b/2c will implement)

Phase 2 reconciliation handlers will:
- Save an existing `wo_time_clock` entry with `field_end_time` populated and the appropriate `field_closed_signoff_*` field set to the parent sign-off entity ID
- Create a new `wo_time_clock` entry with the appropriate `field_created_signoff_*` field set to the parent sign-off entity ID
- Always set `$entity->_signoff_reconciliation = TRUE` before save to bypass the Phase 1 guard 4 (Invoiced/Paid lock)

Audit notes are also auto-prepended to `field_notes` with the format `"[Closed/Created by {signer_name} at sign-off, MM/DD/YYYY h:i AM/PM] {user note}"`.

---

## Existing presave / update / delete behavior (pre-Phase 2)

### `hook_entity_presave` for wo_complete_info

Gates on the same six in-scope bundles. When the entity is saved:
- If `field_canceled` is TRUE: sets WO to Canceled (1098), sends cancellation email, returns early
- Otherwise: sets WO to Complete (1097), calculates trip fee from zipcode, calculates total time = sum of `wo_time_clock.field_total_time` × `field_those_on_crew->count()`

Phase 2b will add a defense-in-depth check at the top of this hook that rejects the save if any roster member lacks a complete entry, unless `_signoff_reconciliation_in_progress` is set on the entity.

### `hook_entity_update` for wo_complete_info

Triggers a parent WO save (which fires the WO presave hook chain).

### `hook_entity_delete` for wo_complete_info

Reverts WO to In Progress (1092) and clears all billing totals. Does NOT touch `wo_time_clock` entries on delete.

---

## Files owned by this module

```
web/modules/custom/wo_sign_off/
  wo_sign_off.info.yml
  wo_sign_off.module                    (existing presave/update/delete; Phase 2 form alters add here)
  wo_sign_off.services.yml              (Phase 2a — registers WoCrewRosterService)
  src/Service/
    WoCrewRosterService.php             (Phase 2a)
```

Field config files (in `config/sync/`):

```
field.storage.wo_time_clock.field_closed_signoff_complete.yml
field.storage.wo_time_clock.field_closed_signoff_tasks.yml
field.storage.wo_time_clock.field_created_signoff_complete.yml
field.storage.wo_time_clock.field_created_signoff_tasks.yml
field.field.wo_time_clock.entry.field_closed_signoff_complete.yml
field.field.wo_time_clock.entry.field_closed_signoff_tasks.yml
field.field.wo_time_clock.entry.field_created_signoff_complete.yml
field.field.wo_time_clock.entry.field_created_signoff_tasks.yml
```

Plus updates to `core.entity_form_display.wo_time_clock.entry.default.yml` (4 audit fields registered as hidden) and `core.entity_view_display.wo_time_clock.entry.default.yml` (4 audit fields with `entity_reference_label` formatter at weights 10-13).

---

## Status

- Pre-Phase 2: existing wo_complete_info presave/update/delete behavior
- Phase 2a (this commit): WoCrewRosterService + four audit fields, no behavioral change
- Phase 2b (next): wo_complete_info form alter + reconciliation
- Phase 2c (after): wo_tasks_list:lawn_mowing form alter + reconciliation
- Phase 2d (cleanup): wo_timer_flag_update silent-no-op logging

Updated: 2026-05-02 (Phase 2a)
