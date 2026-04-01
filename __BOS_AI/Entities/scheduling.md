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

Updated: April 2026

