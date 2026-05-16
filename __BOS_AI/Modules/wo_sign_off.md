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

### Simple vs Complex bundle classification

`COMPLEX_BUNDLES` constant + `isComplexBundle($entity_type, $bundle): bool` method partition the in-scope bundles by missing-entry handling policy:

- **Simple** (default — silent-create on save): `complete`, `clean_up_crew`, `fertilizing_crew`, `lawn_mowing` (both wo_tasks_list and wo_complete_info paths), `spray_crew`. Single-block work where the whole crew works the same window. A computed default (earliest existing clock-in on the WO, fallback to WO created timestamp) approximates reality closely enough for routine sign-off.
- **Complex** (per-row UI required): `irrigation_crew`, `landscape_crew`. Multi-cycle work where teammates work different windows on the same WO (one tech 8-12, another 1-3, another 2-6). A single default would be wildly wrong for half the crew, so the form requires explicit per-person start/end times for missing entries.

Orphan close-out is universal across both classifications. Only missing-entry handling diverges.

To reclassify a bundle, move it in/out of the `COMPLEX_BUNDLES` array. Both the form alter (build helper, validate handler, submit handler) and the Refresh button visibility branch off `isComplexBundle()` — single source of truth.

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

### Population semantics

Phase 2 reconciliation handlers (Phase 2b for the wo_complete_info path; Phase 2c for the wo_tasks_list:lawn_mowing path):
- Save an existing `wo_time_clock` entry with `field_end_time` populated and the appropriate `field_closed_signoff_*` field set to the parent sign-off entity ID
- Create a new `wo_time_clock` entry with the appropriate `field_created_signoff_*` field set to the parent sign-off entity ID
- Always set `$entity->_signoff_reconciliation = TRUE` before save to bypass the Phase 1 guard 4 (Invoiced/Paid lock)

Audit notes are also auto-prepended to `field_notes` with the format `"[Closed/Created by {signer_name} at sign-off, MM/DD/YYYY h:i AM/PM] {user note}"`.

### New-entity audit field population (Phase 2b-fix)

For NEW wo_complete_info entities (the common case — first sign-off save), the parent's entity ID isn't assigned until after the entity save commits, but the reconciliation submit handler runs BEFORE that save. The handler can't set `field_closed_signoff_complete` / `field_created_signoff_complete` inline because there's no parent ID to reference yet.

Solution: the submit handler stashes the IDs of touched wo_time_clock entries onto two transient properties on the parent entity (`_reconciled_closed_ids` and `_reconciled_created_ids`). `wo_sign_off_entity_insert()` fires after the entity has an ID, reads the stashed arrays, and back-fills the audit reference field on each entry via a second save (passing through Phase 1's `_signoff_reconciliation` flag to bypass the lock guard).

For EXISTING wo_complete_info entities being re-saved, the parent ID is already populated when the submit handler runs. The audit field gets set inline during the first wo_time_clock save and the post-save hook is a no-op (no stashed IDs).

Audit notes (in `field_notes`) preserve signer name + timestamp regardless of which path runs — those work uniformly for new and existing entities.

---

## Reconciliation UX (Phase 2 simplification)

The Phase 2b/2c flows initially used per-row inputs for both orphan close-out (set end_time, optional note, optional "mistake — delete") and missing-entry creation (set start_time, end_time, optional note). User feedback during real-world testing surfaced that this was over-engineered for the most common case — a teammate or foreman saving the form *at the moment of clock-out*. The answer to "what's everyone's end time" is just "now", and most teammates already have an open clock-in from clicking the timer flag at the start of work.

The simplification: silent close orphans across all bundles, and split missing-entry handling by bundle complexity.

### Universal: orphans close silently

Any roster member with at least one open `wo_time_clock` entry on the WO is an "orphan" — close all their open entries with `end_time = now` and a standardized audit note (`[Closed by {signer} at sign-off, MM/DD/YYYY h:i AM/PM]`). Multi-cycle teammates may have multiple open entries (the categorize fn loads ALL `notExists('field_end_time')` matches, not just the most recent) — every one closes.

No per-row inputs. The build helper renders an inline preview note: *"Open clock-ins will be closed when you save: Russell, Jonathan."* — visible confirmation of what's about to happen, but no action required from the user. Messenger reports the result after save with the actual end_time stamp.

To set a non-`now` end_time on an orphan, or to delete an erroneous open clock-in, edit the `wo_time_clock` entry directly via the standalone form. The trade-off (rare custom-end-time case → standalone form) was accepted in favor of zero-friction routine sign-off.

### Categorization rule (multi-cycle correctness)

`_wo_sign_off_categorize_roster()` checks for **open entries first**. A teammate with 3 closed entries (e.g., morning + afternoon + evening cycles) plus 1 open entry (forgot the final clock-out) is categorized as orphan, not clean. Pre-simplification logic checked closed-first and would have silently ignored the open entry, leaving permanently corrupt data on sprinkler/landscape WOs. Categorization order:

1. Any open entries → orphan (load all of them)
2. No open entries, but ≥1 closed → clean
3. No entries at all → missing

### Simple bundles: silent-create missing with defaults

`complete`, `clean_up_crew`, `fertilizing_crew`, `lawn_mowing` (both paths), `spray_crew` — single-block work, whole crew works the same window. Missing entries silent-create with computed defaults:

- `start_time` = earliest existing clock-in on this WO (any teammate, closed or open). Falls back to WO `created` timestamp when no other entries exist. This anchors newly-created entries to the rest of the crew's day rather than fabricating an arbitrary number.
- `end_time` = now (form save time)
- Audit note: `[Created by {signer} at sign-off, MM/DD/YYYY h:i AM/PM]`

The build helper renders a preview: *"New clock-in entries will be created when you save: Mike. Times are estimated; edit the time clock entries afterward if any need adjustment."* — accurate disclosure that the times are inferred, not measured.

Foreman accepts the default times by clicking Save once. To adjust times, edit the wo_time_clock entry afterward via standalone form.

### Complex bundles: per-row UI for missing

`irrigation_crew`, `landscape_crew` — multi-cycle work, teammates work different windows on the same WO. A single default time would be wrong for half the crew, so the form requires explicit per-person start/end times for missing entries. The build helper renders one fieldset per missing roster member with empty `start_time` and `end_time` datetime inputs (no defaults — foreman must enter actual times).

The reconciliation validate handler enforces:
- `start_time` and `end_time` both required
- Neither in the future (5-minute grace, configurable via `WO_TOTAL_TIME_FUTURE_GRACE_MINUTES`)
- `end_time >= start_time`

If validation passes, the submit handler creates entries from the row data exactly as entered.

### Refresh button — complex bundles only

The form alter injects a "Refresh reconciliation list" button on complex bundles, **always visible** (not conditioned on rows existing). When the foreman edits the roster after page load, clicking Refresh triggers an AJAX round-trip that rebuilds the wrapper with rows for any newly-added missing crew. Single save.

Implementation note: the button is nested INSIDE the wrapper container (`$form['signoff_reconciliation']['refresh']`) rather than as a top-level form element. Top-level placement with `#group => 'content'` works for `#type=container` (the wrapper itself) but does NOT route `#type=button` into the field_layout content region — buttons end up at the bottom of the form regardless of weight. As a child of the wrapper the button inherits the wrapper's correct position.

The wrapper anchors to `field_how_many_trucks_taken`'s weight on wo_complete_info forms (`anchor_weight - 0.1`) so the reconciliation block sits directly above that field. Falls back to `roster_weight + 0.2` for forms without that anchor field (e.g., wo_tasks_list:lawn_mowing — but that path is simple, so reconciliation has no rows anyway).

### Two-save fallback (complex bundles only)

If a foreman on a complex bundle adds crew without clicking Refresh and submits, the validate handler detects missing-without-rows and triggers `$form_state->setRebuild(TRUE)` with a friendly messenger warning: *"Crew roster updated. Enter start and end times for the new entries below, then save again."* Page re-renders with rows for the new uids; foreman fills in times and saves again.

This is the only path that can require a second save. Foremen who click Refresh after editing the roster avoid it entirely. Simple bundles never trigger this path because they have no rows.

## Lawn Mowing Path (Phase 2c)

The lawn mowing sign-off flow runs through `wo_tasks_list:lawn_mowing` rather than `wo_complete_info`. The foreman opens the wo_tasks_list edit form, fills out tasks, populates `field_mowing_who_on_site`, and toggles `field_completed = TRUE` (which gets saved as the falsy "field_completed = FALSE" trigger condition that fires the wo_lawn_mowing cascade). Phase 2c intercepts at this same form before the cascade fires.

Lawn_mowing is classified **simple** — single-block crew work — so it uses the silent-close + silent-create defaults flow described above. No per-row UI, no Refresh button, no two-save dance.

### Cascade ordering — critical invariant

The `wo_lawn_mowing` cascade (`hook_ENTITY_TYPE_update` on `wo_tasks_list`) does the following in order on `field_completed` becoming falsy:

1. Unflag `work_order_timer` for the foreman (writes `time()` to the open `wo_time_clock`'s `field_end_time` via `wo_timer_flag_update_flagging_delete`)
2. Set WO status to 1097 (Complete)
3. Create `wo_complete_info:lawn_mowing`
4. Create `wo_status_updates` entry
5. Set `wo_tasks_list.field_completed = TRUE`

Phase 2c reconciliation must run **before** step 1, otherwise the foreman's open clock-in gets auto-closed by the unflag with `time()` — which would clobber any reconciliation-supplied end_time on the foreman's entry.

The reconciliation submit handler is prepended to `$form['actions']['submit']['#submit']`, so it runs BEFORE the entity save commits. The cascade fires in `hook_ENTITY_TYPE_update` (after save), so reconciliation is guaranteed to be complete when the cascade begins.

### Dependency on `wo_timer_flag_update` defensive skip (commit `92c9484f`)

Even with reconciliation running before the cascade, the cascade's flag delete still fires. `wo_timer_flag_update_flagging_delete` would have unconditionally overwritten `field_end_time` with `time()` (clobbering the reconciliation value) and called `appendItem()` on the single-value `field_notes` field (silently corrupting the audit prefix).

Commit `92c9484f` adds a defensive check: if `field_end_time` is already populated, the hook skips both the end_time write AND the field_notes append. The flag deletion itself still completes; only the wo_time_clock mutation is conditional.

This means Phase 2c's foreman-reconciliation end_time and audit prefix survive the cascade cleanly. **Phase 2c depends on commit `92c9484f` being deployed** — without it, the foreman's reconciliation values would be silently overwritten.

### Hard validation on `field_mowing_who_on_site`

Long-standing bug closed by Phase 2c: an empty `field_mowing_who_on_site` makes the wo_lawn_mowing billing math collapse to zero (`men_on_site = 0` → `totalTime = 0`). Phase 2c adds two layers of enforcement:

- **Form-layer:** `_wo_sign_off_assert_mowing_roster_populated()` is prepended to `$form['#validate']` and runs FIRST (before the reconciliation validate). If `field_mowing_who_on_site` has no entries, the form returns an error: *"Please indicate who was on the crew before completing this mow."* Reconciliation work doesn't happen for empty rosters.
- **Defense-in-depth presave:** `wo_sign_off_wo_tasks_list_presave()` also throws `EntityStorageException` if `field_mowing_who_on_site` is empty when `field_completed` is being set falsy. Catches programmatic / REST / VBO writes that bypass the form.

### Audit fields use the `_tasks` variants

`field_closed_signoff_tasks` and `field_created_signoff_tasks` (rather than `_complete` variants) — same Phase 2a infrastructure, different target reference. No stash-and-replay needed (unlike Phase 2b-fix on new wo_complete_info) because `wo_tasks_list:lawn_mowing` entities are always existing edits during sign-off — the WOLawnMowingTaskController creates them when the workflow starts; the foreman edits them later.

### Defense-in-depth presave guard

`wo_sign_off_wo_tasks_list_presave()` (`hook_ENTITY_TYPE_presave` for wo_tasks_list) fires only when:
- bundle is `lawn_mowing`
- `field_completed` is being set falsy (the cascade trigger condition)
- `_signoff_reconciliation_in_progress` is NOT set on the entity

Catches programmatic writes that bypass the form layer. Same `_wo_sign_off_assert_roster_complete()` helper as the Phase 2b guard — that helper is polymorphic across both entity types via the WoCrewRosterService.

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

## Deferred items

- **Phase 2c lawn_mowing form Behavior C upgrade path.** Currently uses Behavior B (no Refresh button on the mowing form). If field-tablet usage data shows the no-auto-update friction is operationally meaningful, the upgrade is `#after_build` with `#ajax` on `autocompleteclose` event for the `field_mowing_who_on_site` widget. Defer until usage warrants. See [deferred_work.md item 6](../Governance/deferred_work.md#6-phase-2c-lawn_mowing-form--behavior-c-upgrade-path).
- **`snow_removal` sign-off architecture.** `wo_complete_info:snow_removal` is explicitly excluded from Phase 2 reconciliation (deferred to fall 2026). See [deferred_work.md item 7](../Governance/deferred_work.md#7-snow_removal-sign-off-architecture).
- **`special_mowing` reconciliation.** `wo_tasks_list:special_mowing` excluded from Phase 2c per the diagnostic heuristic (8 entries, structurally divergent cascade). Revisit fall 2026 if usage grows. See [deferred_work.md item 8](../Governance/deferred_work.md#8-special_mowing-reconciliation).

---

## 2026-05-16 revisions

**Crew-count multiplier removed.** `field_total_time = $timeSpent *
$totalMen` at `wo_sign_off.module:149` (and the equivalent in
`wo_lawn_mowing.module`) is gone — now just `$timeSpent`. Phase 2c
reconciliation creates one `wo_time_clock` entry per crew member, so
the sum already equals total man-hours; the multiplier double-counted.
`wo_estimate.module`'s identical-shape multiplier was left alone (dead
bundle). 62 already-affected WOs backfilled on live (Pattern-B / TC≥2;
Pattern-A single-entry WOs intentionally left — their value reflected
the original intended approximation).

**Orphan handling now splits by form type:**
- **wo_complete_info (Sign-Off form):** per-entry editable end_time
  fieldset — the foreman confirms/corrects each forgotten clock-out.
  Prefill: latest existing end on the WO if after the orphan's start,
  else start + 1 hr.
- **wo_tasks_list (Task List form, mowing):** silent close at
  `end = now` — the foreman's Save *is* the clock-out; no second
  form. (Reverted from the prompt per Todd's call; the single-entry
  cap in `wo_total_time` is the backstop for the forgot-overnight
  case there.)

**Invoiced-transition guard moved out.** "Cannot mark Invoiced unless
prior status was Complete" lives in `wo_shared` (`wo_shared.md`), not
here.

**Add-form reconciliation fix.** The per-row time fields never
rendered on the *Add* wo_complete_info form (new entity → no
`field_work_order`/roster on entity; `getValues()` empty at rebuild
build-time). Fixed by stashing the validate-handler-resolved
`{wo_id, roster}` in `$form_state` (`_wo_signoff_ctx`) and reading it
in the fieldset builder. See the form-rebuild gotcha in
`drupal_bos_gotchas.md`. Commits `3e3ba64b`, `235707d9`.

---

## Status

- Pre-Phase 2: existing wo_complete_info presave/update/delete behavior
- Phase 2a: WoCrewRosterService + four audit fields, no behavioral change
- Phase 2b: wo_complete_info form alter + reconciliation + presave guard
- Phase 2b-fix: audit field population on new wo_complete_info entities
- Phase 2c: wo_tasks_list:lawn_mowing form alter + reconciliation + hard validation on field_mowing_who_on_site + presave guard
- Phase 2d: wo_timer_flag_update silent-no-op logging (separate module — see `__BOS_AI/Modules/wo_timer_flag_update.md`)
- **Phase 2 simplification (2026-05-03):** silent close orphans across all bundles; per-bundle missing handling (silent-create defaults for simple bundles, per-row UI for complex). Categorization fixed for multi-cycle WOs (open-before-closed; close ALL open entries). Refresh button nested in wrapper for correct field_layout positioning. Apply-to-all and per-orphan UI removed.
- **2026-05-16:** multiplier removed; orphan handling split by form type (wo_complete_info per-row prompt vs wo_tasks_list silent); Invoiced guard moved to wo_shared; Add-form reconciliation fixed via form-state stash. See "2026-05-16 revisions" above.

Updated: 2026-05-16
