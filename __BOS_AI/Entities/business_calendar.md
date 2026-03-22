# BOS Entity — Business Calendar

Entity Type ID: business_calendar
Storage: ECK
Bundle: event
Module: business_calendar

## Purpose
Company calendar events displayed as background shading on the
scheduling calendar. Display only — no operational or billing logic.

## Bundle: event

Fields:
- title (base) — event name
- field_date (smartdate) — always all-day (duration 1439)
- field_event_type (list_string) — holiday, closure, payday, company_event
- field_notes (string) — optional notes
- field_is_auto_generated (boolean) — TRUE for system-generated records

## Event Types and Calendar Background Colors
- holiday       → #ffd5d5 (light red)
- closure       → #d5e8ff (light blue)
- payday        → #d5ffd5 (light green)
- company_event → #fff3d5 (light amber)

## Payday Generator
Route: GET /admin/business-calendar/generate-paydays
Access: administrator, site_admin, administration

Anchor date: 2026-03-16 (Monday)
Interval: every 14 days
Horizon: 180 days forward from today
Idempotent: skips dates that already exist
Conflict detection: flags paydays landing on holidays with
"⚠ Check Date" in title and a note for office review.

## US Federal Holidays 2026 (pre-loaded)
New Year's Day, MLK Day, Presidents Day, Memorial Day, Juneteenth,
Independence Day (observed + actual), Labor Day, Columbus Day,
Veterans Day, Thanksgiving, Day After Thanksgiving,
Christmas Eve, Christmas Day, New Year's Eve

## Calendar Integration
Endpoint: GET /teammates/calendar/business-events
Returns FullCalendar background event JSON.
Rendered in month view only (hidden in timeGrid week/day views).

## Invariants
- Display-only records — no operational or billing logic.
- Paydays are editable by office staff (e.g. to move around holidays).
- field_is_auto_generated = TRUE means system-created but still editable.
- Background events do not block scheduling on those dates.
