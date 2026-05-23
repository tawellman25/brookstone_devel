# BOS Module — wo_total_time

Module: wo_total_time
Package: Work Orders

Purpose:
- Custom field type: `wo_total_time` — stores computed decimal hours
- Computes `field_total_time` from start/end datetime fields on `wo_time_clock` entities
- Enforces presave-layer data integrity invariants on `wo_time_clock` entries (Phase 1 of the wo_time_clock anomaly prevention work)
- Provides form-layer UX (inline error messages + soft long-shift confirmation) on the wo_time_clock add/edit form
- Triggers WO billing recalculation when time clock entries are saved/updated
- Reads `field_long_shift_hours` from `business_setting` for the soft >threshold confirmation cutoff (defaults to 16.0 via private fallback constant)

Dependencies:
- drupal:field
- config_pages:config_pages

---

## hook_entity_presave

Fires on `wo_time_clock` entities.

Behavior, in order:

### 1. Phase 1 data integrity guards

`_wo_total_time_validate_time_clock_entry()` runs FIRST. Throws `\Drupal\Core\Entity\EntityStorageException` when any of five guards fail. The exception aborts the save and bubbles to the caller — catches manual edits, REST writes, imports, and any future programmatic save path.

| # | Guard | Condition | Applies to | Bypass |
|---|---|---|---|---|
| 1 | END BEFORE START | `field_end_time < field_start_time` | insert + update | none |
| 2 | START IN THE FUTURE | `field_start_time > now + 5 min grace` | insert + update | none |
| 3 | END IN THE FUTURE | `field_end_time > now + 5 min grace` | insert + update | none |
| 4 | PARENT WO LOCKED | WO status TID is Invoiced (1281) or Paid (1504) | **update only** | `'administer eck entities'` permission OR `_signoff_reconciliation` flag |
| 5 | CANCELED WO | WO status TID is Canceled (1098) | insert + update | `'administer eck entities'` permission |

Guard 4 only applies on update — legitimate insert paths exist for adding new entries on closed WOs via admin tools. Guard 5 applies to both because no new entries should land on a canceled WO.

The 5-minute future-time grace accommodates clock skew between server/client and the wo_timer_flag_update flow's `time()`-based timestamps.

Long-shift (>16 hour) checking is deliberately NOT a presave guard — it lives in the form-layer validate handler with a soft confirmation flow, so legitimate long shifts are entry-able without admin intervention.

### 2. field_total_time computation (existing behavior, preserved)

- Reads `field_start_time` and `field_end_time`
- Computes difference in hours (rounded to 2 decimal places)
- Sets `field_total_time` on the time clock entry
- If either time is missing: sets `field_total_time = NULL`

### 3. Manual entry ownership reassignment (existing behavior, preserved)

- On POST requests where `field_teammate` differs from entity owner
- Updates entity owner to match `field_teammate`
- Logs the UID change

Phase 2 will skip this block when the `_signoff_reconciliation` context flag is set on the entity, to allow sign-off-time reconciliation to write entries owned by the actual teammate without reassignment side effects. Phase 1 leaves a TODO comment marking the spot.

### 4. Reverse auto-sync: field_teammate from uid (added 2026-05-02)

- On any save path (not POST-gated): if `field_teammate` is empty AND `uid` is non-anonymous, set `field_teammate = uid`
- Does NOT fire when `field_teammate` is already populated (preserves legitimate office-staff-on-behalf attribution where uid != field_teammate)
- Does NOT fire when uid is anonymous (uid 0)

This is the symmetric sibling of the existing forward-sync (which goes `uid := field_teammate`). It addresses the dual-field drift pattern where teammates manually entered their own time via the standalone form and didn't notice the `field_teammate` field — leaving the entry with `uid` populated but `field_teammate` empty. Without this sync, Phase 2 reconciliation and per-teammate variance queries can't find the entry.

The forward and reverse syncs are mutually exclusive — only one fires per save (forward gates on `!isEmpty`, reverse gates on `isEmpty`).

Backfill: 72 post-boundary entries (start_time ≥ 2026-01-01) corrected at the time the guard was added. 2 entries failed backfill due to existing `end_time < start_time` data corruption (Phase 1 guard 1 blocked the save) — surfaced via AnomalyDetectionService's `time_travel` category for separate manual review. ~9,043 pre-boundary historical entries left as-is (deferred decision).

---

## Phase 2 hook point: `_signoff_reconciliation` context flag

When set as a property on the entity (`$entity->_signoff_reconciliation = TRUE`) before save, guard 4 (PARENT WO LOCKED for Invoiced/Paid) is skipped. This lets sign-off-time reconciliation legitimately write entries on closing WOs.

Phase 1 reads the flag in guard 4 but never sets it. Phase 2's reconciliation code will set it before save and unset it after.

---

## hook_form_alter — Phase 1 Layer B

`wo_total_time_form_alter()` activates on any form whose underlying entity is a `wo_time_clock`. Detection by entity type rather than form ID, so this works for the standalone add/edit form, future inline entity form embeds, and any composed forms.

Two responsibilities:

### Long-shift confirmation checkbox

**Consolidated with the persistent `field_time_limit_override` checkbox (2026-05-23).** When `field_time_limit_override` is present on the form — which is every `wo_time_clock` entry under the normal form display — the form-only `long_shift_confirmed` is **not added**, so the user sees only one "yes this is intentional" control instead of two near-duplicates. The validator accepts a tick of either checkbox as confirming the long-shift case.

Fallback: if `field_time_limit_override` is *not* on the form (theoretical edge case — no current form display omits it), `long_shift_confirmed` is rendered at `#weight 50` so it lands just above the form's Save/Delete actions (default `#weight 100`) rather than orphaned below them.

### `_wo_total_time_form_validate` handler

Custom validate handler appended to `$form['#validate']`. Mirrors presave guards 1–3 (end<start, future start, future end) as friendly inline `setErrorByName()` errors before submit completes — better UX than a hard exception. Then enforces the soft >threshold confirmation requirement.

If the would-be duration is over `field_long_shift_hours` AND **neither** `field_time_limit_override` nor (in the fallback) `long_shift_confirmed` is checked, the user gets an error directing them to check the appropriate box and re-submit. The error is routed to whichever checkbox is actually on the form, and the message names that checkbox by its on-screen label. The presave layer has no >threshold guard, so a confirmed long shift saves through.

The presave-layer guards still backstop the form layer (defense in depth) — a malformed form submission, REST write, or import bypassing the form layer will still be rejected at presave.

---

## hook_entity_insert / hook_entity_update

Both hooks call `_wo_total_time_trigger_wo_recalc()`.

### _wo_total_time_trigger_wo_recalc()

Purpose:
When a wo_time_clock entry is saved, trigger the parent WO to
recalculate its billing totals.

Guards:
- Entity type must be wo_time_clock
- field_work_order must be set
- Static processing guard prevents infinite loops (keyed by WO ID)
- WO status must NOT be in protected statuses

Protected statuses (no recalc) — single source of truth via `_wo_total_time_get_protected_status_tids()`:
- Invoiced: TID 1281
- Paid: TID 1504
- Canceled: TID 1098

Behavior:
- Loads parent WO via field_work_order
- Checks WO status against protected list
- If not protected: calls `$wo->save()` which triggers presave billing hooks
- Unsets static guard on completion

Use case:
Office staff correcting a clock-in/out time (e.g., 3:00 AM entered
instead of 3:00 PM) — saving the correction now automatically
recalculates the WO's labor total and grand total. (Phase 1 enforces additional integrity rules — see above.)

---

## Threshold reading: `_wo_total_time_get_long_shift_hours()`

Reads `field_long_shift_hours` from `business_setting` via the injected `@config_pages.loader` service. Falls back to private constant `WO_TOTAL_TIME_DEFAULT_LONG_SHIFT_HOURS = 16.0` if the field is missing or empty.

This deliberately uses an independent injection rather than going through `bos_teammate_operations` — `wo_total_time` is foundational and shouldn't depend on an analytics module. Both modules read the same business_setting field with the same fallback default; AnomalyDetectionService and wo_total_time each hold their own private constant for resilience.

---

## Single-entry duration cap (Guard 6) — 2026-05-16

`_wo_total_time_validate_time_clock_entry` gained **Guard 6**: a single
`wo_time_clock` entry (one clock-in → clock-out) whose duration exceeds
the per-bundle cap is rejected unless `field_time_limit_override` is
checked (or an admin bypass applies). Rationale: no legitimate
continuous session on one WO runs that long — a real long day is
multiple WOs and/or breaks, which are separate clock-in/out cycles =
separate entries.

- Caps live on `business_setting`: `field_max_entry_hours` (default
  **4**, standard bundles) and `field_max_entry_hours_long` (default
  **14**, long-job bundles). Resolver: `_wo_total_time_get_max_entry_hours($wo_bundle)`,
  with code-default constants when the config field is empty.
- Long-job bundles (hardcoded `WO_TOTAL_TIME_LONG_JOB_BUNDLES`):
  `landscaping`, `sprinkler_repair`, `sprinkler_installation`.
- Deliberately does **not** honor the `_signoff_reconciliation`
  bypass — the Task List silent-close path must be subject to the cap
  (that path produced the WO 49723 / Tanner-style runaway entries).
- When the override is accepted (or admin), an idempotent
  `[Time limit override: …]` audit note is appended to `field_notes`.
- Field-instance creation hit the cim silent-skip bug; created via the
  direct entity-API workaround with per-environment UUIDs (sync YAMLs
  carry local UUIDs, live carries its own — see the UUID-drift gotcha).

### Billing red-alert preprocess

`wo_total_time_preprocess_views_view_field()` on the `admin_billing`
view (`/admin/office/work-orders/billing`): when a WO's
`field_total_time` exceeds the long-shift threshold
(`field_long_shift_hours`, 16) the Hours cell renders as a red ⚠
badge. Detection-only, non-blocking — a visibility net so office
staff can't skim past an implausible labor figure before invoicing.

### Crew-count multiplier removed

The pre-2026-05 `field_total_time = sum × crew_count` multiplier was
removed from `wo_sign_off.module:149` and `wo_lawn_mowing.module`
(see `wo_sign_off.md`). Phase 2c reconciliation creates one
`wo_time_clock` entry per crew member, so the sum already represents
total man-hours; the multiplier double-counted. 62 affected WOs
backfilled on live (Pattern-B / TC≥2 only).

---

## Deferred items

- **Logger notice change-log discrepancy.** [`CLAUDE.md` Change Log](../../CLAUDE.md#change-log) entry dated 2026-03-12 says debug logging was removed from this module, but the actual code still has `\Drupal::logger('wo_total_time')->notice()` calls in the manual-entry ownership-reassignment block. Either the cleanup was reverted or the change-log was wrong. See [deferred_work.md item 3](../Governance/deferred_work.md#3-wo_total_time-logger-notice-change-log-discrepancy).
- **Auto lunch/break deduction** — see [deferred_work.md item 16](../Governance/deferred_work.md). The single-entry cap mitigates runaway entries but doesn't address legitimate long sessions that should have an unpaid lunch deducted.

## Status

Updated: 2026-05-16 (single-entry cap + override + billing red-alert; multiplier removal)
Prior: 2026-05-02 (Phase 1 — wo_time_clock data integrity guards)
Prior milestone: March 2026 (WO billing recalc trigger on time clock save)
