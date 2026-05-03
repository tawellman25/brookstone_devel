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
| **2A** | Service layer + business_setting fields + install hook | ✅ built |
| **2B** | Daily Variance dashboard + Time Clock data hygiene check | ✅ built |
| **2B.1** | Data quality boundary (configurable cutoff date for reliable data) | ✅ built |
| **2C** | Per-teammate detail page (day-by-day breakdown + WO drill-down) | ✅ built |
| **2D** | Hub landing page at `/admin/office/operations/teammates` (stats + nav + recent anomalies) | ✅ built |
| 2E | "Active Now" view (who's clocked in right now, current WO context) | planned |
| 2F | Weekly trend chart (one-month rolling productivity %) | planned |

When TimeTrax integration completes (Phase 3), the swap is one method body in `CompensableHoursService::getCompensableHoursForUserOnDate()` — no other module touches.

---

## Phase 2B — Daily Variance dashboard

URL: **`/admin/office/operations/teammates/variance`**
Permission: `_role: administrator+site_admin+administration+supervisor+site_assistant` (office tier; teammates explicitly excluded — they don't see other teammates' productivity).

### Columns

| # | Column | Source |
|---|---|---|
| 1 | Teammate | links to user edit form (Phase 2C will swap to a teammate detail page) |
| 2 | Department | `teammate_profile.field_assigned_crew` → `crew_types.label` |
| 3 | Days Active | count of dates in range with closed WO activity |
| 4 | Compensable hrs | sum of `getCompensableHoursForUserOnDate()` |
| 5 | WO hrs | sum of `getWoHoursForUserOnDate()` |
| 6 | Total Variance | sum of `getVarianceForUserOnDate()` |
| 7 | Avg Daily Var | total variance ÷ days active — color-coded green/yellow/red via `getVarianceStatus()` |
| 8 | Productive % | `(WO ÷ Compensable) × 100` — color-coded inversely on the same thresholds |

### Filters (GET-encoded so URLs are bookmarkable)

- Start date / End date (default: last 30 days)
- Crew / Department (dropdown of `crew_types` entities, "All" by default)
- Teammate (entity autocomplete, scoped to `teammates` role)
- Show inactive — checkbox; default OFF (inactive = no closed WO activity in range)

### Sort

All 8 columns are sortable via header link. **Default sort: Productive % ascending** (lowest at top — the conversation list).

### Color coding

CSS classes on the `Avg Daily Var` and `Productive %` cells: `bos-variance-green` / `yellow` / `red` / `na`. Subtle backgrounds + readable text — admin dashboard, not a stoplight. Defined in `css/bos-teammate-operations.css`.

### Phase 2B placeholder

The Teammate column links to the user edit form — this is a placeholder until Phase 2C delivers a real per-teammate detail page. Other dashboards (active-now, weekly trend) similarly use this controller's view as the entry point.

---

## Phase 2B — Time Clock data hygiene check

URL: **`/admin/office/operations/teammates/variance/data-check`**
Same permission as the main view. Linked from the variance dashboard summary line.

Diagnostic-only — does NOT modify or delete any rows. Surfaces five categories of suspicious `wo_time_clock` records:

1. **Negative `field_total_time`** — broken duration math.
2. **Implausibly long shifts** — `field_total_time > 16` hrs.
3. **Future `field_start_time`** — clock punched in the future.
4. **Forgotten clock-outs** — `field_end_time` IS NULL and `field_start_time` is more than 7 days old.
5. **Time travel** — `field_end_time` before `field_start_time`.

Each section shows row id, teammate, start/end times, total time, and a snippet of notes. Cleanup is a per-row manual decision; this page exists to make the cleanup queue visible.

---

## Phase 2B.1 — Data Quality Boundary

Time-clock discipline at Brookstone has a clear historical seam:

- **Pre-2025** — previous owner. Different processes, different software conventions. Wholly untrustworthy for variance work.
- **2025** — Brookstone's first year. Time clock was in use but enforcement of clock-in/clock-out discipline was inconsistent. Data is mostly correct but missing punches and stale entries are frequent.
- **2026 onwards** — discipline established. This is the only fully reliable window.

The variance dashboards used to average all three eras together, which made the productivity numbers look uniformly worse than reality and washed out the signal Todd needed. Phase 2B.1 introduces a single configurable **data quality boundary**: dashboards default to data on or after the boundary, but every pre-boundary date remains queryable with a clear warning.

### How it works

A new business_setting field — **`field_data_quality_boundary_date`** — holds the cutoff. Default `2026-01-01`. Adjustable at `/admin/config/system/config_pages/business_setting`.

A new service method exposes it:

```php
$boundary = $svc->getDataQualityBoundary();
// → DrupalDateTime at midnight in the site default timezone
```

Daily Variance dashboard behavior:

- **No query string** → start date is `max(today − 30 days, boundary)`. So the default 30-day view never crosses the boundary.
- **Explicit pre-boundary start** → an amber banner at the top of the page reads: *"⚠ You are viewing data from before the data quality boundary (yyyy-mm-dd). Variance numbers may be unreliable due to inconsistent time clock discipline before this date. Adjust the start date to yyyy-mm-dd or later for reliable data."*
- The start-date picker shows *"(recommended: yyyy-mm-dd or later)"* as helper text.
- Pre-boundary dates are **not blocked** — they're available with the warning, on purpose.

Data hygiene check page behavior:

- Two counts at the top: **Active anomalies** (since boundary) and **Historical anomalies** (before boundary).
- Default view shows only active anomalies (the actionable cleanup queue).
- A toggle link surfaces the historical set when needed.
- Forgotten-clock-out rows with empty start_time are always counted as active (they need attention regardless of when they began).

### Why a single boundary, not multi-tier

Multi-tier (e.g., red/yellow/green by year) was considered and rejected: it adds UI noise without clarifying decisions. A single cutoff that says "trust nothing before this date by default" is sharper, and adjustable as the team's understanding evolves. If Brookstone later wants to extend trust further back, it's one field edit.

### Phase 2C and beyond

Future phases (per-teammate detail, hub landing, active-now, weekly trend) all consume the same service. They will inherit boundary-aware defaults automatically — `getDataQualityBoundary()` is the single source of truth. No dashboard should reach back before the boundary except by explicit user action.

---

## Phase 2C — Per-Teammate Variance Detail page

URL: **`/admin/office/operations/teammates/variance/{user}`**
Same role gate as the rollup. Reached by clicking a teammate's name on the rollup (the link forwards the rollup's current date range), or by direct URL.

### Sections

- **Header card** — large display name; department label (`teammate_profile.field_assigned_crew`); role badges (everything except `authenticated`); back link to the rollup (preserves date range); profile link to `/user/{uid}/edit`.
- **Filter form** — start/end date, "days with anomalies only", "days with activity only". GET-submission so the URL is bookmarkable. Same boundary helper text as the rollup.
- **Boundary warning banner** — same amber banner used on the rollup when an explicit pre-boundary start is selected.
- **Summary stat-card grid** (8 cards) — Days w/Activity (N of M), Total Compensable, Total WO, Total Variance, Avg Productive %, Best Day (date + %), Worst Day (date + %), Active Anomalies in range. Variance and Productive % cards color-coded via the same threshold logic as the rollup.
- **Daily breakdown table** — one row per day in range, sorted date-descending. Columns: Date (Mon yyyy-mm-dd), Activity (✓/blank), Comp Hrs, WO Hrs, Variance (color-coded), Productive %, WOs Touched, Anomaly (⚠ if any of that day's WO entries flag).
- **Per-row expansion** — `<details>`/`<summary>` (no JS). Expanding a day surfaces a sub-table of that day's `wo_time_clock` entries: WO id (linked), title, start/end (local-tz formatted), hours, anomaly note. Open punches show a red "OPEN ⚠" label in the End column.

### What gets queried

Per-day calls to `CompensableHoursService` for compensable / WO / variance / status. Per-row anomaly classification through `AnomalyDetectionService::detectAnomalies($entry)`. Total per-user anomaly count via `countAnomaliesForUser()` (boundary-aware by default).

Page render time on a 30-day window is ~0.1–0.7 s per teammate depending on how many entries they have — well under the 3 s benchmark.

### Phase 2D and beyond

The hub landing page (Phase 2D) will surface this view as the canonical drill-down from any future variance / active-now / weekly-trend dashboard.

---

## Phase 2D — Teammate Operations Hub landing page

URL: **`/admin/office/operations/teammates`**
Same role gate as the rest of the suite. New canonical front door for the variance suite. Direct links to `/variance` and `/variance/data-check` still work for bookmark compatibility — the hub is just the recommended starting point.

### Six Today-at-a-Glance stat cards

| Card | What it measures |
|---|---|
| **Active Teammates Today** | Distinct teammates with at least one `wo_time_clock` entry whose `field_start_time` is today (local TZ). |
| **Active WOs Now** | Distinct work_order entities with at least one currently-open punch (start set, end NULL). |
| **Active But No Open WO** | Teammates active today minus teammates currently clocked into a WO. Soft-yellow when > 0. |
| **Active Anomalies (since boundary)** | `AnomalyDetectionService` total across all 5 types since the data quality boundary. Amber when > 0; green when 0. Linked to data-check page. |
| **Avg Productive % (last 7 days)** | Per-teammate productive % computed across each user's last-7-day window (skipping no-activity days), then averaged across teammates. Uses **hub-specific thresholds** distinct from the per-day variance bands: red < 50%, yellow 50–70%, green > 70%. |
| **Lowest Productive % (30 days)** | Lowest individual teammate productivity in the boundary-aware 30-day window, displayed as `Name: pct%`. Always red (it's the bottom-of-the-list spotlight). Linked to the rollup pre-sorted ascending. |

### Variance Suite navigation grid

Six cards. Three ACTIVE (linked) and three PLANNED (visually muted, no link, status badge):

- ACTIVE: Daily Variance, Data Hygiene Check, Active Now (Phase 2E)
- PLANNED — Phase 2F: Weekly Trends
- PLANNED — Tier 2: Team Roster, Today's Schedule

### Recent Active Anomalies snippet

Up to 5 most recent active (post-boundary) anomalies in a compact table: date, teammate (linked to detail), anomaly type, brief detail. "View all N active anomalies →" link below pointing at the data-check page. When zero anomalies exist, a green "✓ No active anomalies" banner replaces the table.

### Boundary footer

Single-line transparency note at the bottom: "Data quality boundary: MM/DD/YYYY. Records before this date are considered legacy and excluded from default views. Adjust at /admin/config/system/config_pages/business_setting if needed."

### Date formatting

All user-facing dates in this module render in US format (`MM/DD/YYYY` for date-only, `MM/DD/YYYY h:i AM/PM` for datetime). ISO `YYYY-MM-DD` is for storage and URL query parameters only. The convention applies project-wide — see CLAUDE.md → "Date Formatting". Each controller carries small `formatDateUs()` / `formatDateTimeUs()` helpers as the canonical implementation.

### Why hub-specific productivity thresholds

Per-day variance bands (configurable in business_setting) measure single-day deviation from the 8.5-hour assumption. Team-average productivity is a different statistic — averaging produces a smoother distribution where 50/70/90% become the meaningful breakpoints. Hardcoded `TEAM_AVG_RED = 50.0` and `TEAM_AVG_YELLOW = 70.0` constants live on the hub controller; they're rarely going to need adjustment, and putting them in business_setting would invite confusion with the per-day thresholds.

### Menu placement

The hub and its two children live under the existing **Operations** parent at `/admin/operations` in the `admin` menu — not under `system.admin`. The Operations link is a UI-managed `menu_link_content` (uuid `6663ea83-4467-4783-bda0-20deaba2216b`); the hub references it as `parent: 'menu_link_content:6663ea83-4467-4783-bda0-20deaba2216b'` and explicitly sets `menu_name: admin` on all three entries so the subtree lives together. This matches the pattern used by other BOS admin hubs (e.g., `estimate_board.links.menu.yml`).

```
admin (menu)
└── Operations                          (/admin/operations)
    └── Teammate Operations             (bos_teammate_operations.hub)
        ├── Daily Variance              (bos_teammate_operations.variance_daily)   weight 0
        ├── Active Now                  (bos_teammate_operations.active_now)       weight 5
        └── Time Clock Data Check       (bos_teammate_operations.variance_data_check) weight 10
```

The page paths (`/admin/office/operations/teammates`, `/variance`, `/variance/data-check`) are independent of the menu placement — bookmarks stay valid. The detail route at `/admin/office/operations/teammates/variance/{user}` deliberately has no menu entry (it's reached by clicking a teammate name on the rollup or hub).

---

## Phase 2E — Active Now view

URL: **`/admin/office/operations/teammates/active`**
Same role gate as the rest of the suite. Operational snapshot answering "right now, who is working on what?" — the question variance dashboards (which look backward) cannot answer.

Read-only against existing `wo_time_clock` data. No service swaps, no auto-refresh, no JS polling, no caching, no TimeTrax dependency. Render on each page load.

### Two stacked sections

**Section 1 — Currently Clocked In (primary).** Every open punch in the system: `field_start_time IS NOT NULL` AND `field_end_time IS NULL`, regardless of how long ago it started. Columns: Teammate (linked to per-teammate detail), Department, Work Order (linked to admin edit), Clocked In At (MM/DD/YYYY h:i AM/PM), Duration ("X hr Y min"), Status indicator. Default sort: clock-in time DESC. Empty state: "✓ No teammates currently clocked into a WO."

**Section 2 — Today's Activity (secondary).** Every teammate who has had ANY `wo_time_clock` activity today (closed entries that started today plus currently-open entries from any time). Columns: Teammate, Department, WOs Touched Today (distinct count), Total Closed Hrs (sum of `field_total_time` for entries closed-today), Currently On (linked WO if open, else "—"), Last Activity (h:i AM/PM — date implied by section context). Default sort: last activity DESC. Empty state: "No teammate activity recorded today yet."

### Status indicators (Section 1)

Visual cue based on duration since clock-in:

| Color | Range | Meaning |
|---|---|---|
| 🟢 Green | < 8 hours | Likely currently working |
| 🟡 Yellow | 8 – 16 hours | Long shift in progress, or possibly stale |
| 🔴 Red | > 16 hours | Almost certainly stale / forgotten clock-out |

Thresholds are hardcoded constants on the controller (`GREEN_MAX = 8 * 3600`, `YELLOW_MAX = 16 * 3600`). They are operational visual cues, not business rules — distinct from the Phase 1 `field_long_shift_hours` threshold (which gates form-layer save validation). Keeping them out of business_setting avoids confusion with the per-day variance bands.

### Filters

- **Department** — dropdown sourced from `crew_types` ECK entity, defaults to "All Departments". Filters via `teammate_profile.field_assigned_crew` lookup. Applies to BOTH sections simultaneously.
- **Group by department** — checkbox, defaults to UNCHECKED. When checked, both sections regroup with department headings + per-group counts.

GET-submission so filters live in the URL — bookmarkable, shareable.

### Stale punches — accepted, not filtered

Some "currently clocked in" rows on live are forgotten clock-outs from days or weeks ago. Phase 2E does NOT filter or bucket them away. The page shows reality as the data presents it — the Status column's red dot makes the staleness visible, and the AnomalyDetectionService `open_stale` category surfaces them on the data-check page for cleanup. Filtering them off would hide the data hygiene problem the variance suite is built to expose.

### Teammate resolution priority

Each row's teammate is resolved by `field_teammate` first (the explicit roster reference, populated going forward by `wo_total_time`'s presave auto-sync — commit `69d6f3cc`). Falls back to entity owner uid when `field_teammate` is empty. Pre-2026 historical entries with empty `field_teammate` still display correctly via the fallback.

---

## AnomalyDetectionService (extracted in Phase 2C)

Phase 2B's data-check page contained inline anomaly detection logic (5 type-specific entity queries). Phase 2C needed the same logic to flag individual rows on the teammate detail page — extracting it into a service eliminated duplication.

`@bos_teammate_operations.anomaly_detection` — `src/Service/AnomalyDetectionService.php`. Public methods:

| Method | Purpose |
|---|---|
| `detectAnomalies(EntityInterface $entry)` | Single-entity check. Returns array of `{type, message, severity}` descriptors, empty when clean. |
| `getAnomalyTypes()` | Canonical list of 5 anomaly type machine names → human labels. |
| `findAnomaliesByType(string $type)` | All-users entries matching one anomaly type. Used by the data-check page. |
| `countAnomaliesForUser(int $uid, string $start, string $end, bool $boundaryAware = TRUE)` | Number of anomalous entries for a teammate in a date range. Used by the detail page summary. |
| `getAnomalousEntriesForUser(int $uid, string $start, string $end, bool $boundaryAware = TRUE)` | The actual matching entries. Used by future phases (e.g., weekly trend). |

Five canonical anomaly types: `negative_hours`, `implausible_long`, `future_start`, `open_stale` (no end_time), `time_travel` (end < start).

**Thresholds live in business_setting** (Phase 0.5):

| Field | Default | Purpose |
|---|---|---|
| `field_long_shift_hours` | 16.00 | Long-shift cutoff used by `implausible_long` detection |
| `field_stale_clock_out_days` | 7 | Open-stale cutoff used by `open_stale` detection |

`AnomalyDetectionService` reads these at runtime via `@config_pages.loader`. Private const fallbacks (`HOURS_LONG = 16.0`, `DAYS_STALE = 7`) only engage if the business_setting fields are unavailable. Anomaly type labels rendered by `getAnomalyTypes()` interpolate the live values, so the dashboard stays in sync with configured thresholds.

`wo_total_time` (Phase 1) reads `field_long_shift_hours` independently via its own `@config_pages.loader` injection, deliberately avoiding a dependency from a foundational module on this analytics module. Both classes hold their own private fallback constant for resilience.

The data-check page (Phase 2B/2B.1) was refactored to call `findAnomaliesByType()` instead of running its own queries. Same behavior, no duplicated rules.

---

## Files owned by this module

```
web/modules/custom/bos_teammate_operations/
  bos_teammate_operations.info.yml
  bos_teammate_operations.module        (intentionally empty of hooks)
  bos_teammate_operations.services.yml
  bos_teammate_operations.install        (hook_install populates defaults)
  bos_teammate_operations.routing.yml    (Phase 2B — 2 admin routes)
  bos_teammate_operations.links.menu.yml (Phase 2B — top-level admin menu)
  bos_teammate_operations.libraries.yml  (Phase 2B — variance_dashboard library)
  css/
    bos-teammate-operations.css          (Phase 2B — variance status colors + table)
  src/
    Controller/
      VarianceDailyController.php           (Phase 2B — main view + data-check page;
                                              Phase 2C — refactored to use AnomalyDetectionService;
                                              teammate column links to detail page)
      VarianceTeammateDetailController.php  (Phase 2C — single-teammate drill-down)
      TeammateOperationsHubController.php   (Phase 2D — hub landing page;
                                              Phase 2E — Active Now card promoted ACTIVE)
      ActiveNowController.php               (Phase 2E — operational snapshot view)
    Form/
      VarianceDailyFilterForm.php           (Phase 2B — GET filter form for rollup)
      VarianceTeammateDetailFilterForm.php  (Phase 2C — GET filter form for detail page)
      ActiveNowFilterForm.php               (Phase 2E — GET filter form: dept + group toggle)
    Service/
      CompensableHoursService.php
      AnomalyDetectionService.php           (Phase 2C — extracted from inline data-check logic)
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

**Phase 2A foundation built 2026-04-30** (commit `8d98ba2a`). Service exercised against a real teammate (Donald Shultz, uid 8206) across 5 recent dates; all return values plausible.

**Phase 2B Daily Variance + Data Hygiene Check built 2026-05-01.** Default 30-day view renders 15 active teammates in ~1.2 s. Color coding visible in the live dataset (significant red coverage; small yellow band; no green in the 30-day window — the dashboard is doing exactly what it was built to do). The data-check page surfaces 281 anomalous `wo_time_clock` rows across 5 categories — that is the pre-Phase 3 cleanup backlog, not a regression introduced by this module.

**Phase 2B.1 Data Quality Boundary built 2026-05-01.** Default boundary `2026-01-01` (Brookstone's discipline cutoff). Active vs historical anomaly split: 19 active vs 262 historical. The boundary made the boss-facing productivity numbers tighter and more honest — recent-only data shows that the underperformance signal is real, not a 5-year averaging artifact.

**Phase 2C Per-Teammate Variance Detail page built 2026-05-01.** AnomalyDetectionService extracted from inline data-check logic; data-check page refactored to call it. Detail page renders in 0.1–0.7 s for a 30-day window. Verified end-to-end against three teammates: Donald Shultz (17/31 active days, no anomalies), Gerald Reeves (6/31, no anomalies), Steven Lischke (11/31, **2 days flagged with `implausible_long` anomaly** — explains his 151.1% productivity in the rollup; his >16 hr shifts are inflating WO hours).

**Phase 2D Teammate Operations Hub built 2026-05-02.** Hub renders in ~1.6 s on live data. Six stat cards live on first read: 0 active teammates today (no one clocked in yet), 72 active WOs now (most are forgotten clock-outs), 0 active-but-no-open-WO, 19 active anomalies since boundary, 5.8% team avg productive % over last 7 days (red — significant decline from 30-day baseline of 42%), and Gerald Reeves at 10.1% as the lowest 30-day performer.

**Menu nested under `/admin/operations` (commit `8c752524`).** The hub link was initially placed under `system.admin`; immediately moved to nest under the existing UI-managed Operations parent so it joins Services / Equipment / System Content / Training / Team Structure as siblings rather than a top-level admin entry. Daily Variance and Time Clock Data Check follow as children of the hub.

**MM/DD/YYYY date format established as project convention (commits `19f27523`, `cedef3de`).** All four pages (hub, rollup, detail, data-check) render dates in US format; storage and URL params stay ISO. Documented in CLAUDE.md → "Date Formatting".

**Phase 2E Active Now built 2026-05-03.** Operational snapshot view at `/admin/office/operations/teammates/active`. Two stacked sections — Currently Clocked In + Today's Activity — with department filter and group-by-department toggle. Status indicator dots (green / yellow / red) make stale forgotten clock-outs visually obvious without filtering them out. Hub card promoted from PLANNED to ACTIVE; menu entry added between Daily Variance (weight 0) and Time Clock Data Check (weight 10).

Ready for Phase 2F (Weekly Trends).
