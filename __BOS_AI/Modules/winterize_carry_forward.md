# Winterizing Schedule Carry-Forward (`bos:winterize:*`)

**Added 2026-08-21. In `bos_scheduling`.** Two Drush commands that propose next
season's `sprinkler_winterizing` schedules from prior-season history and, on a
second explicit run, apply the reviewed proposals as real `scheduling:work_order`
records. It creates ONLY scheduling records + flips `work_order.field_scheduled`
— it never creates/modifies/cancels a Work Order, Property, or Contract, and
never writes `field_status` (that's `wo_schedule`'s job via `wo_status_updates`).

## Commands

```
drush bos:winterize:plan  [--target-year=2026] [--source-years=2025,2024] [--out=<path>]
drush bos:winterize:apply --file=<csv> --actor=<uid> [--target-year=2026] [--limit=N]
```

- **`plan`** is read-only — writes one full CSV + a focused `*_REVIEW.csv`, prints
  a summary. Writes no entity; safe on live.
- **`apply`** re-reads the reviewed CSV (the edited file is the authority — it does
  NOT recompute), re-validates every row against live, and creates records via the
  shared writer. `--actor` is required and must be a real office user; uid 1 (the
  superuser) is rejected by default so a batch is not lazily attributed to it —
  pass `--allow-superuser` to permit `--actor=1` when uid 1 is the real person
  consciously owning the run (uid 1 = Todd Wellman on this install). The run
  switches to that account so access + attribution are real. `--limit=N` does a
  cautious first pass. Writes `*_applied.csv` (wo_id, scheduling_id, result, reason).

## How a proposal is built (`plan`)

For each target-year winterizing WO with **no** scheduling record (excluding
Canceled 1098 + done states 1097/1283/1281/1504):

- **Prior counterpart — recency wins, never averaged.** `--source-years` is an
  ordered fallback chain. The first year with a usable record supplies the date,
  tech, and order; later years are used only for coverage and a `year_check`
  note. Per year, the season window is Aug 15 → Dec 31; the authority is the
  **scheduling record's `field_date`**, not the WO created date; the
  highest-scheduling-id record wins a reschedule.
- **Date — same nth weekday of the same month.** 2nd Tuesday of Oct 2025 → 2nd
  Tuesday of Oct 2026. A 5th-weekday that doesn't exist in the target month falls
  back to the last occurrence (`ordinal_5_fallback`). All math via `DrupalDateTime`
  in site tz — never `FROM_UNIXTIME`.
- **Tech — the ACTUAL signer** (`wo_complete_info.field_signed_off_by`), falling
  back to the planned assignee (`scheduling.field_assigned_to`). Chosen from the
  §0.5 probe: planned vs actual diverge 23% (the planned tech is often a
  placeholder). Dead/inactive/missing techs → left blank (unassigned bucket).
- **Route order — the DRIVEN order**, precedence: sign-off timestamp
  (`field_date_completed`) → earliest clock start → Complete status-update ts →
  planned order → none. Chosen from the probe: sign-off timestamps are real-time,
  not batch-entered (only 1% of day-tech groups clustered sub-2-min), and agree
  with clock+status. Renumbered dense 1..N within each (proposed date, tech) group.

### Action classification (auto/manual split)

- **`schedule`** — confident rows (valid date + tech) auto-apply. Dead-tech rows
  also `schedule` but UNASSIGNED (blank tech → calendar's unassigned bucket).
- **`review`** — held for manual scheduling ONLY for a genuine date conflict: a
  Sunday (`weekend`) or a `closure`. **Holidays are non-blocking** (crews work
  them, e.g. Columbus Day) — flagged `holiday_collision`, still scheduled.
- **`skip`** — new customers with no prior AND no proximity neighbour.

### Proximity fill (new customers)

A no-prior (new-customer) row is placed on its **nearest confidently-scheduled
property's day** (haversine over `properties.field_geofield`, ≤ 10 mi),
UNASSIGNED, flagged `proximity_fill` with the distance in `note`. So new
customers land tentatively next to their route neighbours instead of being
scheduled from scratch; > 10 mi / no GPS stays `skip`.

Everything scheduled is `field_scheduled_firm = FALSE` (soft proposals) — the
supervisor confirms/assigns/moves on the calendar. `field_notify_assigned_teammate
= FALSE` (no batch notifications).

## CSV

One row per candidate; sorted by proposed date → route order → nickname. Key
columns: `proposed_date`, `proposed_tech_*`, `proposed_route_order`, `action`
(schedule|review|skip — editable), `flags`, `note`, `year_check` (agree / differs:
… — informational, recency still wins), plus the full prior/alt provenance.
`apply` processes only `action=schedule`; the office can promote a review row by
flipping its `action`.

**Flag vocabulary:** `no_prior_wo`, `proximity_fill`, `holiday_collision`
(informational), `closure_collision` (blocks), `weekend` (blocks),
`ordinal_5_fallback`, `mixed_order_signal`, `batch_signoff`, `tech_inactive`/
`tech_missing`/`tech_deleted`, `multiple_prior_schedules`, `multiple_prior_wos`,
`no_route_order`.

## The shared writer

`bos_scheduling.schedule_writer` (`ScheduleWriter`) is the single creation path,
extracted verbatim from `SprinklerSchedulingController::save()`; the bulk
scheduler was refactored onto it (behavior unchanged). It writes `field_date`
(duration 1439 all-day) only — `wo_schedule`'s presave Smart-Date→DateRange sync
back-fills the legacy `field_scheduled_date_and_time`, and `custom_date_all_day`
sets the flag, so those are never hand-written. Idempotency: skips a WO that
already has a (non-deleted) scheduling record, so a second `apply` of the same CSV
is a no-op.

## Two-step workflow (deploy)

Code-only (`bos_scheduling` + one verifier script). No cim/DB migration.

```
# on live, after scp + drush cr:
drush bos:winterize:plan                      # → /tmp/winterize_plan_<yr>_<ts>.csv + _REVIEW.csv
#   review the *_REVIEW.csv (only rows needing a look); edit dates/techs/action as desired
drush bos:winterize:apply --file=<csv> --actor=<office-uid> --limit=10   # cautious first pass
drush php:script web/scripts/verify_winterize_carry_forward.php          # + eyeball the calendar
drush bos:winterize:apply --file=<csv> --actor=<office-uid>              # the rest
drush php:script web/scripts/verify_winterize_carry_forward.php
```

## Verification

`web/scripts/verify_winterize_carry_forward.php` (idempotent, read-only): no WO
with >1 schedule; command records in-window + duration 1439; `field_scheduled`
flipped; nth-weekday rule holds on a sample; no excluded-status WO scheduled;
dense 1..N route order per (date,tech); `http_kernel` 200 on `/teammates/calendar`
+ the sprinkler scheduling page.

## Probe (`web/scripts/probe_winterize_order_signal.php`)

Read-only §0.5 diagnostics that chose the order + tech signals. Re-runnable to
re-check the signals against a new season's data before trusting the carry-forward.
