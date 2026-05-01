# BOS — bos_teammate_operations (Module)

Module machine name: `bos_teammate_operations`
Drupal version: 10.x
Package: BOS
Phase: 2A (foundation only — no UI yet)

---

## Purpose

Foundation for the **Teammate Operations Hub** at `/admin/office/operations/teammates/`. This phase delivers no UI — only the service layer that every future hub view (variance rollup, teammate detail, active-now, weekly trend) will read from.

The hub answers a single business question for office staff: **for any given teammate on any given date, how does compensable time (what we pay them for) compare to WO time (what they were on the clock against a Work Order)?** The gap is "unaccounted time" — drive, load, dump, errands, or genuine slack.

---

## Architecture — the swappable calculation pattern

The whole point of this module is **`CompensableHoursService`**. It is a single seam designed to swap one method body when TimeTrax integration completes, without touching any dashboard.

### Phase 2 (current): assumption-based

`getCompensableHoursForUserOnDate($uid, $date)` returns either:
- `getAssumedDailyHours()` (default 8.5) — if the teammate has any closed `wo_time_clock` activity on the date
- `0.0` — if no activity (we don't pretend they worked)

The 8.5 represents a standard 7 AM – 4 PM shift minus 30 min unpaid lunch. Any `wo_time_clock` row counts as activity. The assumption is intentionally crude — Phase 2 dashboards are about surfacing variance, not about precise payroll.

### Phase 3 (future): real data

When the TimeTrax integration completes, **only the body of `getCompensableHoursForUserOnDate()` changes**. It will sum `time_clock_entry` rows for the user/date. Method signature stays identical. Every dashboard keeps working unchanged.

That swap is the entire reason this module exists at all instead of letting each dashboard compute its own variance.

---

## Public service surface

`@bos_teammate_operations.compensable_hours` — implemented at `src/Service/CompensableHoursService.php`.

| Method | Returns | Notes |
|---|---|---|
| `getCompensableHoursForUserOnDate(int $uid, string $date)` | `float` | The swap point. Phase 2: 8.5 when activity present, 0.0 otherwise. |
| `getAssumedDailyHours()` | `float` | Reads `business_setting.field_assumed_daily_hours`, defaults to 8.5. |
| `getVarianceThresholds()` | `array{green_max, yellow_max}` | Reads from business_setting; defaults 1.5 / 3.0. |
| `getWoHoursForUserOnDate(int $uid, string $date)` | `float` | Sum of `field_total_time` across closed `wo_time_clock` entries on the date. |
| `getVarianceForUserOnDate(int $uid, string $date)` | `float` | compensable − WO. Positive = unaccounted; negative = overtime. |
| `getVarianceStatus(float $variance, bool $hadActivity)` | `string` | `'na' \| 'green' \| 'yellow' \| 'red'`. Compares `abs($variance)` to thresholds. |
| `getProductivePercentageForUserOnDate(int $uid, string $date)` | `float\|null` | `wo / compensable * 100`. NULL when compensable = 0. |
| `hasWoActivityOnDate(int $uid, string $date)` | `bool` | TRUE if at least one closed entry exists for the user on the date. |

### Date and timezone handling

All `$date` arguments are local-timezone `Y-m-d` strings. Internally the service builds `[start_utc, end_utc]` for the day using `date_default_timezone_get()` and queries the datetime fields against those. Hardcoded TZ strings are deliberately avoided — BOS has historical issues with MariaDB session-tz drift, and honoring the runtime default is the documented convention.

### Closed-entry rule

Both `getWoHoursForUserOnDate()` and `hasWoActivityOnDate()` filter to rows where `field_end_time IS NOT NULL`. A teammate who is currently clocked in is **not** counted yet. This matches what office staff care about: completed shifts.

### Why `hasWoActivityOnDate()` is public (deviation from spec)

The Phase 2A spec calls this method "protected helper." It's public in the implementation because in Phase 3 the equivalence `compensable_hours > 0 ⇔ activity` breaks — once compensable hours come from an external clock, a teammate could have compensable hours on a day with no WO activity (e.g., shop time, training). Dashboards will need to ask both questions independently. Making it public now means the API stays stable across the Phase 3 swap.

---

## Configuration — business_setting fields

Three optional decimal fields on the `business_setting` config_pages bundle (machine name `business_setting`, entity type `config_pages`):

| Field | Default | Purpose |
|---|---|---|
| `field_assumed_daily_hours` | 8.5 | Used by `getCompensableHoursForUserOnDate` as the assumption value. |
| `field_variance_green_max` | 1.5 | `abs(variance) <` this → green. |
| `field_variance_yellow_max` | 3.0 | `abs(variance) ≤` this → yellow. Above → red. |

The service falls back to its constant defaults (8.5 / 1.5 / 3.0) when any field is empty. The `hook_install` populates the fields on first enable so admin sees populated values right away.

Editable at `/admin/config/system/config_pages/business_setting`.

---

## Dependencies on prior foundations

- **`time_clock_entry` ECK entity** (Phase 1A, commit `9dc58e68`) — exists and ready for Phase 3 import. Phase 2A does NOT read from it.
- **`bos_user_time_clock_mapping` module** (Phase 1A.1, commit `dd4088f8`) — gates the user-side mapping fields to teammates only. Provides the bridge that Phase 3 will use to attribute imported punches.
- **`field_external_punch_id`** (Phase 1B, commit `7fca532e`) — idempotency key for Phase 3 imports.

None of these are read by Phase 2A — they're listed here because the swap path depends on them.

See [`__BOS_AI/Strategy/timetrax_strategy.md`](../Strategy/timetrax_strategy.md) for the full strategic context on why we're using an 8.5-hour assumption now and what triggers the eventual swap.

---

## Phase 2 sequence (planned vs. built)

| Phase | Scope | Status |
|---|---|---|
| **2A** | This module — service layer + business_setting fields + install hook | ✅ built |
| 2B | Daily variance rollup view (date-pick, all-teammates table, status colors) | planned |
| 2C | Teammate detail page (single teammate, last 30 days, drill into a date) | planned |
| 2D | Hub landing page at `/admin/office/operations/teammates/` (route, menu, view-mode picker) | planned |
| 2E | "Active Now" view (who's clocked in right now, current WO context) | planned |
| 2F | Weekly trend chart (one-month rolling productivity %) | planned |

When TimeTrax integration completes (Phase 3), the swap is one method body in `CompensableHoursService::getCompensableHoursForUserOnDate()` — no other module touches.

---

## Files owned by this module

```
web/modules/custom/bos_teammate_operations/
  bos_teammate_operations.info.yml
  bos_teammate_operations.module        (intentionally empty of hooks)
  bos_teammate_operations.services.yml
  bos_teammate_operations.install        (hook_install populates defaults)
  src/
    Service/
      CompensableHoursService.php
```

Configuration owned (`config/sync/`):

```
field.storage.config_pages.field_assumed_daily_hours.yml
field.storage.config_pages.field_variance_green_max.yml
field.storage.config_pages.field_variance_yellow_max.yml
field.field.config_pages.business_setting.field_assumed_daily_hours.yml
field.field.config_pages.business_setting.field_variance_green_max.yml
field.field.config_pages.business_setting.field_variance_yellow_max.yml
```

Plus `core.extension.yml` updated to enable the module.

---

## Service injection details

```yaml
# bos_teammate_operations.services.yml
bos_teammate_operations.compensable_hours:
  class: Drupal\bos_teammate_operations\Service\CompensableHoursService
  arguments:
    - '@entity_type.manager'
    - '@config_pages.loader'
    - '@logger.factory'
```

The spec called for `entity_type.manager` and `config.factory`. Substituted `config_pages.loader` for `config.factory` — business_setting is a config_pages entity, not a Drupal Configuration object, and the existing BOS pattern (`wo_aerating`) reads it via `config_pages.loader`. Added `logger.factory` so query failures are logged rather than silently returning 0.

---

## What this module does NOT do

- **Mutate `wo_time_clock` rows** — strictly read-only.
- **Read `time_clock_entry`** — that comes in Phase 3.
- **Provide UI** — Phases 2B–2F.
- **Cache results** — at ~25 teammates × 30 days, queries are cheap. Premature optimization.
- **Per-user shift overrides** — single 8.5 default for everyone. Add later if needed.

---

## Status

**Phase 2A foundation built and deployed locally 2026-04-30 (commit pending).** Service exercised against a real teammate (Donald Shultz, uid 8206) across 5 recent dates; all return values plausible. Ready for Phase 2B to start consuming the service.
