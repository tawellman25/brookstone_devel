# BOS Entity — Scheduling

Entity Type ID:
- scheduling

Storage:
- ECK entity type

Bundles:
- work_order | Schedule for Work Order

---

## Purpose

The Scheduling entity represents **planned assignment and timing** of Work Orders.

Scheduling is a **planning and coordination layer**, not execution.

It answers:
- when work is planned
- who it is assigned to
- whether the schedule is firm or tentative
- how the work recurs (if applicable)

Scheduling does **not** record:
- work performed
- time worked
- completion status
- execution results

Execution is recorded on:
- Work Orders
- wo_time_clock
- other Work Order child entities

---

## Relationship to Work Orders

Authoritative relationship:
- scheduling.field_work_order → work_order

Rules:
- A Scheduling record must reference exactly one Work Order.
- A Work Order may have:
  - zero scheduling records (unscheduled)
  - one scheduling record (typical)
  - multiple scheduling records (recurring or rescheduled work)

Scheduling does not replace Work Order status.
It supplements it with planning data.

---

## Bundle: work_order — Schedule for Work Order

This bundle represents a single scheduled instance for a Work Order.

---

## Global Fields

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Scheduling Type
- title | string | Scheduled Title
- uid | entity_reference | Assigned by
- created | created | Created
- changed | changed | Changed
- default_langcode | boolean | Default translation
- path | path | URL alias

---

## Scheduling Core Fields

Assignment:
- field_assigned_to | entity_reference → user
  - Teammate assigned to perform the work.

Timing:
- field_date | smartdate | Date
  - Primary scheduled date (supports Smart Date behavior).

- field_scheduled_date_and_time | daterange
  - Scheduled start/end window.

Status flags:
- field_scheduled | boolean
  - Indicates the Work Order is scheduled.

- field_scheduled_firm | boolean
  - TRUE = firm commitment
  - FALSE = tentative / flexible

Order / priority:
- field_scheduled_oder | integer
  - Ordering within a daily or route list.
  - Lower numbers should be treated as higher priority.

Communication:
- field_notify_assigned_teammate | boolean
  - If TRUE, assigned teammate should be notified.

Notes:
- field_scheduling_note | string
  - Internal scheduling notes.

Recurrence:
- field_rrule | string_long
  - Stores recurrence rules (RFC-style RRULE).
  - Used for recurring Work Orders.

---

## Entity References (Confirmed)

- type → scheduling_type
- uid → user (Assigned by)
- field_assigned_to → user (Assigned to)
- field_work_order → work_order

---

## Scheduling Semantics (Important)

- Scheduling represents **planned intent**, not execution.
- A scheduled date does not imply work was performed.
- Work completion must be inferred from:
  - Work Order status
  - execution data (time entries, sign-off, etc.)

---

## Recurring Work Orders

When field_rrule is populated:
- Scheduling may represent:
  - repeated planned occurrences
  - seasonal or periodic work
- Each recurrence must still:
  - reference the same Work Order
  - be auditable as a scheduling decision

Execution for each occurrence is still captured on Work Orders
and their child entities.

---

## Invariants (Non-Negotiable)

- Scheduling must always reference a Work Order.
- Scheduling must never store execution data.
- Scheduling must not be treated as proof of work completion.
- Work Order execution truth lives outside Scheduling.
- Historical Scheduling records must not be retroactively altered
  to imply execution.

---

## Deletion / Archival

Default:
- Scheduling records may be deleted or updated
  while work has not yet been executed.

Rules:
- Do not delete Scheduling records that are required
  for audit or historical planning review.
- Prefer marking records as not scheduled (field_scheduled = FALSE)
  over deletion when rescheduling.

---

## Reporting Expectations

Scheduling must support reporting by:
- date
- assigned teammate
- Work Order
- firm vs tentative
- recurrence

Scheduling reports must not be used as execution reports.

---

## Summary

Scheduling is a **planning layer** in BOS.

- Contracts define intent
- Work Orders define execution
- Scheduling defines when and by whom work is planned

These responsibilities must remain separate.

---

## Admin Calendar (admin_calendar module)

Path: /teammates/calendar
Access: administrator, administration, supervisor, site_admin, site_assistant, teammates
Theme: olivero_sewards (front-end theme)

FullCalendar 6 JS calendar (CDN, no contrib dependency).
Authoritative date field: field_date (smartdate, Unix timestamps).
Legacy field: field_scheduled_date_and_time (daterange) kept in sync.

Features:
- Department color coding via department.field_color
- SOP code abbreviations (field_sop_code on services taxonomy)
- Teammate initials + order code format: [ToW-01]
- Status filter (active statuses default, expandable to historical)
- Completed WOs overlay via wo_complete_info.field_date_completed
- Drag-and-drop rescheduling (supervisor/office roles only)
- Business calendar background events (holidays, paydays, closures)
- Sticky filter bar with mobile collapse toggle
- Tabs: Dispatch | Calendar | My Schedule (Drupal local tasks)

Timezone: America/Denver
UTC conversion: CONVERT_TZ(FROM_UNIXTIME(ts), @@session.time_zone, '+00:00')
All-day detection: smartdate duration >= 1365 && <= 1440 minutes

Active status TIDs shown on calendar:
1089 Open, 1099 Needs Confirmed, 1095 Waiting, 1503 Accepted,
1091 Scheduled, 1090 Assigned, 1092 In Progress, 1093 Needs Parts,
1094 Parts Ordered, 1096 Needs Access

Excluded from calendar (office/billing only):
1097 Complete, 1283 Warrantied, 1281 Invoiced, 1504 Paid, 1098 Canceled

Endpoints:
- GET /teammates/calendar — calendar page
- GET /teammates/calendar/events — JSON scheduled events
- GET /teammates/calendar/completed — JSON completed WO overlay
- GET /teammates/calendar/business-events — JSON business calendar events
- POST /teammates/calendar/event/{id}/reschedule — drag-drop save

---

## BOS Scheduling (bos_scheduling module)

### Crew Daily Schedule
Path: /teammates/calendar/my-schedule
Access: all roles including teammates
Query param: date (Y-m-d, defaults to today)
Navigation: skips empty days (prev/next day with WOs)

Shows per-day WOs assigned to current user:
- Property nickname, full address, gate code
- CALL AHEAD flag (red badge)
- AER - Flag Heads flag (green badge) from field_aeration_flag_heads
- Work todo description (html_entity_decode + strip_tags)
- Scheduling note, route order, status, service code
- Link to full WO page

### Supervisor Dispatch Board
Path: /teammates/calendar/dispatch
Access: administrator, administration, supervisor, site_admin, site_assistant
Auto-refreshes every 5 minutes with countdown timer.

Shows all WOs for a given day grouped by teammate:
- Rows by teammate with department color dot
- Job cards in route order, horizontal scroll per row
- Color coded by WO status
- Stats bar: total, in progress, complete, unassigned
- Department filter buttons
- Unassigned WOs section at bottom
- AER - Flag Heads shown in tooltip and green left border accent

### Sprinkler Bulk Scheduling
Path: /admin/office/work-orders/scheduling/sprinkler
Save endpoint: POST /admin/office/work-orders/scheduling/sprinkler/save
Access: administrator, administration, supervisor, site_admin, site_assistant

Bulk scheduling tool for all sprinkler WO types:
sprinkler_start_up, sprinkler_winterizing, sprinkler_check_up,
sprinkler_repair, backflow_testing, sprinkler_design, sprinkler_installation

Features:
- Filter by type, city/zip, status, street
- Grouped by city — zip, sorted by street then aeration flag first
- AER - Flag Heads badge on flagged properties
- Property name links to WO page (opens _blank)
- Bulk assign: date + technician + start route order #
- Creates scheduling entity for each selected WO
- Sets field_scheduled = TRUE on WO after scheduling
- Skips WOs that already have a scheduling record (idempotent)
- Progress bar showing overall season completion

### Scheduling Hub
Path: /admin/office/work-orders/scheduling
Access: administrator, administration, supervisor, site_admin, site_assistant
Menu: Under Office → Work Orders → Scheduling

Landing page for all bulk scheduling tools. Shows:
- Stats: total unscheduled, scheduled this week, tools available/planned
- Active tool cards with unscheduled WO counts and season progress bars
- Planned tool cards (coming soon, grayed out)

Active tools:
- Sprinkler Systems → /admin/office/work-orders/scheduling/sprinkler

Planned tools (not yet built):
- Spraying → /admin/office/work-orders/scheduling/spraying
- Clean-ups → /admin/office/work-orders/scheduling/clean-ups
- Landscaping → /admin/office/work-orders/scheduling/landscaping

### Timezone Handling
All scheduling controllers use `date_default_timezone_get()` instead of
hardcoded 'America/Denver'. This reads Drupal's system.date config.
All-day event dates are converted in PHP from Unix timestamps using the
site timezone, bypassing FROM_UNIXTIME server-timezone dependency.

## 2026-05-16 changes (wo_schedule module)

**All Day is the default for new schedules.** The `field_date` Smart
Date widget's `default_duration` is set to **1439** (all-day) with a
`1439|All day` increment option. But that alone does NOT tick the
widget's "All day" checkbox — Smart Date's JS (`setAllDay()`) only
checks the box when the rendered start/end time inputs are exactly
`00:00:00` / `23:59:00`. So `wo_schedule_entity_prepare_form()` seeds
a NEW `scheduling:work_order` form to today, 00:00–23:59
America/Denver, duration 1439, which makes the widget render the
midnight/23:59 inputs and the JS check the box. Begin/end times are
fully preserved — unchecking All Day reveals the normal time pickers.
The field's own default (`default_date_type: now`, dur 15) is
auto-applied at create, so `field_date` is never "empty" — the hook
deliberately overrides it. Guards: only the entity *form*, only new
entities (programmatic creators — bos_scheduling, admin_calendar,
contract enrichers — never hit a form, so they're untouched).

**Schedule changes auto-log a WO note.** `wo_schedule` records a
`wo_notes:note` on the parent WO whenever a scheduling entity is
created or any of `field_scheduled_date_and_time`, `field_assigned_to`,
or `field_scheduling_note` changes. Insert → a snapshot; update →
only the changed fields as old → new; no-op resaves produce nothing.
`field_scheduling_note` is tracked deliberately: crews misuse it for
job-notes, and mirroring it onto the WO note (text_long, no length
limit) puts that content on the WO's permanent record. Old values
are captured in **presave via `loadUnchanged()`** — `$entity->original`
is not populated on update in this Drupal version (see
`drupal_bos_gotchas.md`), which also means this module's existing
`_wo_schedule_handle_status_update` original-based reschedule/reassign
status branches are **latently non-firing** (not yet fixed). The
note's Date renders date-only (UTC→Denver) so an all-day schedule
shows one clean date, not the misleading "6:00 AM – 5:59 AM" span.

Commits: `119a5993`, `642595ef`, `59c16c2c`, `4f438b5a`.

Updated: 2026-05-16
Prior: April 2026

## 2026-05-20 fix (admin_calendar)

**UTF-8 truncation in the events feed blanked the calendar.** `/teammates/calendar/events` was returning HTTP 500 / empty because `AdminCalendarEventsController` used byte-based `substr` to shorten property nicknames to 22 chars. When the cut fell inside a multi-byte character (en-dash in "Ambulance District – Eckert"), the orphan bytes failed `json_encode`, and `JsonResponse` threw — taking down the entire 149-event response over a single bad row. Fix: `mb_strlen` / `mb_substr` for the truncation, plus `JSON_INVALID_UTF8_SUBSTITUTE` on the response as a defensive guard. Diagnostic kept at `web/scripts/diag_calendar_events.php`. See `Governance/drupal_bos_gotchas.md` for the cross-cutting pattern.

Commit: `366c9014`.

Updated: 2026-05-20
