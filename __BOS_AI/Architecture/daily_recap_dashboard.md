# Daily Recap Dashboard — Build Plan (Gate 1)

- **Feature:** Admin dashboard at `/admin/operations/daily-recap`
- **Gate 0 feasibility:** `__BOS_AI/Reports/daily_recap_feasibility_2026-06-26.md` (verdict: READY, no stops)
- **Date:** 2026-06-27 · **Status:** plan for approval — nothing built yet
- **Precedent to mirror:** `bos_teammate_operations` (controller-rendered ops dashboard, `formatDateUs()` / `formatDateTimeUs()` helpers, `/admin/office/operations/...`)

---

## 1. What it shows

1. **Yesterday's completions** — every WO completed yesterday (all bundles, **including
   zero-dollar** ones), as a list: WO#, service, property, department, $ value, completed-at.
2. **Value generated per department** for three windows: **Yesterday**, **Week-to-date**,
   **Month-to-date** — one row per department, $ summed from `field_wo_total`, plus a
   **completions count** alongside each $ (so volume is visible even where $ is $0).

Revenue basis: `work_order.field_wo_total`. Department: `work_order.field_service` →
`services` term → `field_department` → `department`. Warrantied WOs (status **1283**) excluded
everywhere. Completion anchored on `wo_complete_info.field_date_completed`.

---

## 2. KEY DECISION — how to represent lawn mowing revenue

Gate 0 found mowing's value is **not on the WO at all**: of 324 completed mows in 30 days,
**0** have `field_task_rate` and only 7 have `field_wo_total`. Mowing is contract-billed; the
only per-mow figure is `contract_sections.lawn_mowing_and_trimming.field_mow_rate`
("Final Mow Rate"), reached via property → contract section.

**Recommended — Option A (v1):** show each department's $ from `field_wo_total` **plus a
completions count**. Mowing/landscape-crew will read low-$ / high-count, with a footnote:
"Mowing is contract-billed; per-mow value not in WO totals." Honest, ships now, no new
lookups.

**Option B (fast-follow, Gate 2+):** derive mowing $ = `field_mow_rate` × completed mows via
the property's lawn-mowing contract section. More accurate, but needs a **coverage check** on
`field_mow_rate` first (not all properties may have it) and a property→contract-section
traversal. Recommend shipping A, then adding B once `field_mow_rate` coverage is confirmed.

> **This is the one decision needed before Gate 2.** Default to A unless you want B in v1.

---

## 3. Data model / query approach (Entity API, no SQL)

**Window boundaries** (in **site tz `America/Denver`**, DST-aware — *not* the MariaDB MST
session; we read the `timestamp` int via Entity API):
- `yesterday` = [00:00:00 yesterday, 23:59:59 yesterday]
- `WTD` = [start of current week, now] — **week start = Sunday** (confirm; easy to flip to Monday)
- `MTD` = [first of month 00:00:00, now]
Each boundary → Unix timestamp for comparison against `field_date_completed`.

**Per window, the pipeline:**
1. `entityQuery('wo_complete_info')` where `field_date_completed BETWEEN [start,end]`.
2. Load → resolve parent WO via `field_work_order`; drop orphans (Gate 0: 0 orphans in 30d).
3. **Dedupe by WO id** — a WO can have multiple `wo_complete_info`; count its `field_wo_total`
   **once** (use the WO's value, keyed by WO id), so a re-saved sign-off can't double-count.
4. **Exclude** WOs where `field_status == 1283` (Warrantied) — at the WO level, since
   warrantied WOs *do* carry dated complete_info (Gate 0 §4).
5. Resolve department: `WO.field_service` → term → `field_department` → department label;
   unmapped (only **In House Tasks**, tid 403) → **"Unassigned"** bucket.
6. Sum `$` = `field_wo_total` (0 if the bundle lacks the field — `exterior_lighting`,
   `landscape_lighting`); increment that department's **count**.

**Performance:** MTD is the widest window (subset of ~772/30d); loading a few hundred
entities and grouping in PHP is fine for an admin page. No caching needed for v1 (or a short
per-request static cache).

---

## 4. Module + page

- **New module `bos_daily_recap`** (package: Work Orders / Operations). Cleaner than bolting
  onto `bos_teammate_operations` (different path root, distinct purpose), but mirror its
  conventions: a controller that builds render arrays, small `formatDateUs()` /
  `formatDateTimeUs()` helpers on the controller (per the BOS Date Formatting standard —
  `MM/DD/YYYY` / `MM/DD/YYYY h:i A`, site tz).
- **Route:** `/admin/operations/daily-recap` → `DailyRecapController::view`. Admin theme
  (office roles). **Permission:** a new `view daily recap` perm granted to
  `administration` / `supervisor` / `site_admin` / `administrator` (confirm role set).
- **Render:** controller-built render array, not a View (the cross-entity joins + window math
  + warranty exclusion + dedupe are awkward in Views). Reuse the **status-card / table**
  styling per `Governance/ui_patterns.md`; a compact per-department table per window + the
  yesterday completions list reads well.
- A `DailyRecapService` (or just controller methods) computes the three window summaries +
  the yesterday list, so logic is testable and the controller stays thin.

---

## 5. Edge cases / rules

- **Dedupe WOs** (multiple complete_info) — count revenue once per WO (see §3.3).
- **Zero-dollar completions are included** (mowing, no-spray visits, lighting) — the count
  reflects them; $ is $0. This is intended ("all completions").
- **Warranty** excluded via WO `field_status == 1283` only.
- **Unassigned** bucket for In House Tasks (and any future unmapped WO-service).
- **Always-$0 bundles** (`exterior_lighting`, `landscape_lighting`) contribute count, $0.
- **Week start** (Sun vs Mon) — pick one; Sunday assumed.
- **Timezone** — all window math via `date_default_timezone_get()` (America/Denver). Do not
  rely on the MariaDB session tz (we don't use SQL).

---

## 6. Gate 2 (build) outline

1. Scaffold `bos_daily_recap` module (.info.yml, routing.yml, permissions.yml).
2. `DailyRecapService::summaries()` — yesterday/WTD/MTD per-department $ + counts (the §3
   pipeline) and `yesterdayCompletions()` — the per-WO list.
3. `DailyRecapController::view()` — render arrays + `formatDateUs` helpers; attach a small CSS.
4. Twig/templates for the per-department tables + the yesterday list (status-card pattern).
5. Permission + admin-theme route; menu link under Office → Operations.
6. Verify locally vs synced prod against the Gate 0 numbers (e.g. yesterday/MTD dept totals);
   confirm warranty exclusion + dedupe; check the "Unassigned" bucket shows In House Tasks.
7. (If Option B chosen) coverage-check `field_mow_rate`, then add the mowing-derivation path.

---

## Decisions (approved 2026-06-27)
1. **Mowing revenue — Option A.** Sum `field_wo_total` + show a completions count per dept;
   footnote that mowing is contract-billed. (Option B deferred.)
2. **Week start — Sunday.** WTD = Sunday 00:00 (site tz) → now.
3. **Access — office set:** `administration`, `supervisor`, `site_admin`, `administrator`.

Built on branch `feature/daily-recap-dashboard` (Gate 2). **Deployed to live 2026-06-27**
(`drush en bos_daily_recap`; the install hook grants `view daily recap` to the office roles).
Post-build refinements: department total re-targets the bottom list in place (query-param;
replaced the separate detail page); list groups by service type with subtotals; property cell
shows nickname (customer) + address; URL `/admin/office/daily-recap`; menu link under Calendar
in the Office menu.
