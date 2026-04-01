# BOS Module — estimate_board

Module: estimate_board
Path: /admin/office/estimates
Package: Custom

## Purpose
Estimate pipeline dashboard providing follow-up tracking, estimator workload,
active pipeline overview, and recent activity feed for office staff.

---

## Routes

- `/admin/office/estimates` — Estimate Board (dashboard)
- `/admin/office/estimates/status-update` — POST: quick status change from board

## Tabs (Drupal local tasks via estimate_board.links.task.yml)

All tabs share base_route `estimate_board.board`:
- Estimate Board (weight -10)
- New Requests → view.estimate_requests.page_1 (weight 0)
- My Requests → view.estimate_requests.page_2 (weight 1)
- All Requests → view.estimate_requests.page_3 (weight 2)
- My Estimates → view.estimate_all_estimates.my_estimates (weight 3)
- All Estimates → view.estimate_all_estimates.all_estimates (weight 4)

## Dashboard Sections

### Section 1: Needs Follow-Up
- Estimate requests with 5+ days since last activity
- Activity = most recent estimate_action_log entry, or entity changed date
- Columns: Client, Status, Property, Services, Coordinator, Days, Last Action, Actions
- Row highlighting: 10+ days = red, 5-9 days = orange
- Quick status change dropdown per row (POST with CSRF protection)
- Links open in new tab

### Section 2: Active Pipeline
- Estimate requests grouped by field_status
- Collapsible details elements per status group
- Count badges per group
- Excludes Declined/Canceled (1657) and Converted (1658)

### Section 3: Estimator Workload
- Active estimates grouped by estimate.field_assigned_to
- Counts only current revisions (field_is_current_revision = 1)
- Excludes Accepted (1418) and Declined (1419) stages

### Section 4: Recent Activity
- Last 48 hours of estimate_action_log entries
- Shows: date, who, action, context, link to request
- Limited to 15 entries

## Daily Email Digest (hook_cron)
- Runs once per day (state key: estimate_board.last_email_sent)
- Sends to office@brookstoneoutdoors.com
- Subject: "Estimate Follow-Up Required — [count] requests need attention"
- Lists requests crossing 5-day threshold with client, service, days, last action

## Access
Roles: administrator, site_admin, administration, supervisor, site_assistant

## Dependencies
- estimate module
- estimate_action_log entity

---

Created: March 2026
Updated: April 2026
