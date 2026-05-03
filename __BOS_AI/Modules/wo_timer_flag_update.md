# BOS Module — wo_timer_flag_update

Module: wo_timer_flag_update
Package: Work Orders

## Purpose

Owns the bidirectional sync between the `work_order_timer` flag (Drupal Flag module) and `wo_time_clock:entry` records. The flag is the source of truth for "currently clocked in"; this module materializes that state into actual time-clock entries that downstream billing, variance, and audit consumers query.

Three hook implementations:

- `hook_flagging_insert()` — clock-in. Creates a new `wo_time_clock:entry` with `field_start_time = now`, `field_end_time = NULL`, owner = current user. Stores the new entry's ID on the flagging entity's `field_wo_timer_entered` field for later lookup.

- `hook_flagging_delete()` — clock-out. Loads the `wo_time_clock` referenced by `field_wo_timer_entered` and writes `field_end_time = now`. Defensive: skips the write if the entry is already closed (Phase 2c reconciliation may have pre-closed it before the cascade fired).

- `hook_preprocess_flag()` — UI. Determines flag state, sets the link text and CSS classes for the timer toggle button.

## Pre-Phase-2 invariant: explicit NULL on insert

Pre-Phase-2 (commit `e23a1153`) the insert hook explicitly passes `field_end_time => NULL` in the `wo_time_clock` create() array. This overrides the field's `default_date: 'now'` config (which would otherwise auto-populate end_time on save). Without this override, every clock-in would produce a row where `start == end` and `total_time = 0`, making the active-clock-in signal indistinguishable from "completed zero-duration entry" for every operational consumer.

`field_end_time IS NULL` is the canonical "open clock-in" signal. Code that creates `wo_time_clock` entries via any path must explicitly pass `field_end_time => NULL` to preserve this invariant.

## Phase 2c-related defensive check (commit `92c9484f`)

The clock-out hook has two defensive corrections that became necessary when Phase 2c sign-off reconciliation began closing wo_time_clock entries before the wo_lawn_mowing cascade's flag-delete fires:

1. **Skip end_time write when `field_end_time` is already populated.** The flag deletion completes normally; only the wo_time_clock mutation is conditional. This preserves the reconciliation-supplied end_time and audit prefix on the foreman's entry.

2. **Append `field_notes` correctly.** `field_notes` is single-value (cardinality 1), so the previous `appendItem('End Time entered by system')` call was silently corrupting notes on every flag-driven clock-out. Replaced with explicit `string . "\n" . append + trim` pattern that handles the empty-prefix case cleanly.

Both fixes are foundational improvements independent of Phase 2c — but Phase 2c specifically depends on fix 1 to preserve foreman reconciliation end_times across the cascade.

## Phase 2d — silent-no-op visibility (commit `ae59a12c` companion)

Phase 2d adds watchdog warning logging when `hook_flagging_delete` fires but the `field_wo_timer_entered` reference cannot resolve to a valid `wo_time_clock` entity (deleted, never created, or stale ID).

Without this logging, the failure mode is invisible: the user's clock-out tap succeeds (the flag is removed) but no end_time gets written, and no record of the failure exists. The user goes home thinking they clocked out; the office discovers the orphan during weekly variance review days later with no debugging context.

The log entry includes the `target_id` of the referenced (missing) wo_time_clock, the current user ID, the flag ID, and the work order ID. Severity is `warning` (4) — known failure mode, not a crash.

To inspect logged occurrences:

```bash
ddev drush watchdog:show --type=wo_timer_flag_update --severity=Warning
```

Or via SQL for a count over time:

```sql
SELECT COUNT(*) FROM watchdog
WHERE type = 'wo_timer_flag_update' AND severity = 4
  AND timestamp > UNIX_TIMESTAMP('2026-05-02');
```

The log entry doesn't fix the orphan — it surfaces it for investigation. Recurring occurrences indicate either a pattern of wo_time_clock deletions racing with flag deletes, or a code path creating flags without setting `field_wo_timer_entered`, or some other systematic issue worth chasing.

## Files owned by this module

```
web/modules/custom/wo_timer_flag_update/
  wo_timer_flag_update.info.yml
  wo_timer_flag_update.module
  wo_timer_flag_update.libraries.yml
  css/                    (flag-timer button styles)
  images/                 (flag-timer icons)
```

No service classes. All logic lives in the .module hook implementations.

## Status

- Pre-Phase-2 (`e23a1153`): explicit NULL end_time on clock-in insert
- Pre-Phase-2c (`92c9484f`): defensive skip on pre-closed entries + correct field_notes append
- Phase 2d (`ae59a12c`): silent-no-op visibility logging on clock-out

Updated: 2026-05-02
