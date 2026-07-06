# bos_teammate_hours — "Time on Jobs" (teammate self-service hours)

**Package:** BOS Teammates
**Shipped:** 2026-07-05 (live)

## What it is

A crew-facing widget that shows a teammate **their own** clocked Work Order
hours for a calendar week (Sunday–Saturday), grouped by day, with per-day and
week totals. Each entry links its Work Order and shows the property nickname
and the clock-in/out time range.

It is the "see what I've clocked into, and how much" view — the read-only
self-service surface over the `wo_time_clock` data that `wo_clock` captures.

## Where it lives

- A single Block plugin, `teammate_time_on_jobs`
  (`src/Plugin/Block/TeammateTimeOnJobsBlock.php`).
- Renders on the **teammate profile page** — the user canonical page
  `/user/{uid}` (aliased **`/teammates/{name}`**, e.g. `/teammates/sam-more`).
  Placed in the **`brookstone_olivero`** theme, region `content`, weight `-17`
  (top), visibility `request_path: /user/*`. The build() short-circuits unless
  the route is `entity.user.canonical` **and** the page-owner has the
  `teammates` role, so it never appears on office/admin user pages.
  > **Note:** there is **no bare `/teammates` page** in BOS — a teammate's
  > profile is `/user/{uid}`, aliased `/teammates/{name}`. An earlier build
  > targeted `request_path: /teammates` (copied from the existing
  > `teammate_profile_*` blocks, which carry the same dead visibility) and so
  > rendered nowhere. Target `/user/*`, not `/teammates`.
- Styled to match the My Schedule crew-card pattern
  (`bos_scheduling/css/my_schedule.css`): white card, 2px `#ddd` border, 6px
  radius, left accent bar.

## Deliberate scope / non-goals

- **Reads the page-owner, not the viewer.** The block shows the hours of the
  teammate **whose profile is being viewed** (the route `user` param). On a
  teammate's own page that's their own hours; teammates can't reach other
  teammates' profiles (`access user profiles` permission), so it stays
  effectively self-only for crew, while supervisors/office viewing a teammate
  see that teammate's hours. The **supervisor** "view
  anyone's hours" counterpart lives in `bos_teammate_operations` — the
  per-teammate variance detail page
  (`/admin/office/operations/teammates/variance/{user}`), which shows the same
  per-day WO entries **plus GPS distance-from-property** (`In 📍` / `Out 📍`,
  Google-Maps-linked, ≥500 ft flagged) over an adjustable date range. GPS
  appears only there, never on this crew self-view.
- **No GPS.** The `field_clock_in_location` / `field_clock_out_location` /
  `field_*_distance_ft` data is intentionally **not** rendered here — GPS stays
  an admin/supervisor-only signal (per the `wo_clock` design). It is still
  captured on every punch; it just doesn't appear on the crew's own page.
- **No dollar figures.** These are **WO clocked hours**, not billable hours and
  not compensable/paid hours (TimeTrax remains the payroll system of record —
  see `__BOS_AI/Strategy/timetrax_strategy.md`). The widget is labeled "My
  Hours" / "hrs" and never implies pay or invoice amounts.

## Data model

- Reads `wo_time_clock` entries (bundle `entry`) filtered on **`field_teammate`**
  (the reliable "who worked" reference — not `uid`, which is the entry author).
- Week bounds are computed in the site timezone (`America/Denver`) then
  converted to UTC to query the stored `field_start_time`
  (`Y-m-d\TH:i:s`, UTC).
- Duration comes from `field_total_time`. **Open entries** (no
  `field_end_time`) are flagged "In progress" and **excluded from totals** so a
  running punch never silently inflates the numbers (the day total shows a `+`
  marker when an open entry is present).

## Navigation

`?week=-1` / `?week=1` shift the calendar week; `?week=0` (default) is the
current week. A "Jump to this week" link appears when viewing a past/future
week. "Next" is disabled past the current week.

## Caching

`#cache`: contexts `user` + `url.query_args:week`, tag `wo_time_clock_list`
(invalidates when any entry changes), `max-age` 300s (totals drift as the day
progresses).

## Date formatting

Follows the BOS US-format rule: day headers `D m/d/Y` (e.g. `Thu 07/02/2026`);
entry rows show time-only `g:i A` (the day header supplies the date context).
