# BOS Module — estimate_board

Module: estimate_board
Path: /admin/office/estimates
Package: Custom

## Purpose

Estimate pipeline dashboard for office staff. Provides:

- Per-status swimlane board for active estimate requests
- One-click status advancement via AJAX (no full page reload)
- Per-stage swimlane board for the current user's estimates
- Follow-up tracking with daily email digest
- Inline "+ New Request" form
- Read-only Accepted / Declined tabs

---

## Routes

| Route | Path | Method | Purpose |
|---|---|---|---|
| estimate_board.board | /admin/office/estimates | GET | Main swimlane dashboard |
| estimate_board.new_request | /admin/office/estimates/new-request | GET | Inline estimate_request add form |
| estimate_board.status_update | /admin/office/estimates/status-update | POST | AJAX status change for estimate_request (with CSRF) |
| estimate_board.my_estimates | /admin/office/estimates/my-estimates | GET | Per-stage swimlane board for current user's estimates |
| estimate_board.estimate_stage_update | /admin/office/estimates/estimate-stage-update | POST | AJAX stage change for an estimate (with CSRF) |
| estimate_board.accepted | /admin/office/estimates/accepted | GET | Accepted estimate requests (terminal status 1658) |
| estimate_board.declined | /admin/office/estimates/declined | GET | Declined estimate requests (terminal status 1657) |

Access: roles `administrator`, `site_admin`, `administration`, `supervisor`, `site_assistant`.

---

## Tabs (estimate_board.links.task.yml)

All tabs use `base_route: estimate_board.board`. Weights group them:

**Group 1 — Board (0–9):**
- Estimate Board (weight 0)
- + New Request (weight 5)

**Group 2 — Requests (10–29):**
- My Requests → `view.estimate_requests.page_2` (weight 10)
- All Requests → `view.estimate_requests.page_3` (weight 15)
- Accepted → `estimate_board.accepted` (weight 20)
- Declined → `estimate_board.declined` (weight 25)

**Group 3 — Estimates (30+):**
- My Estimates → `estimate_board.my_estimates` (weight 30)
- All Estimates → `view.estimate_all_estimates.all_estimates` (weight 35)

---

## Main Board (Estimate Request Pipeline)

### Pipeline Swimlanes (active statuses, in order)

Defined in `EstimateBoardController::PIPELINE_ORDER`:

| TID | Label | Slug |
|---|---|---|
| 1652 | New – Gathering Info | `new-gathering-info` |
| 1654 | Ready to Estimate | `ready-to-estimate` |
| 1655 | Estimating | `estimating` |
| 1810 | Send Estimate | `send-estimate` |
| 1656 | Waiting on Customer | `waiting-on-customer` |

Off-board (terminal): `1657 Declined`, `1658 Converted` — shown only on Accepted / Declined tabs. On Hold (`field_on_hold = TRUE`) is collected separately and shown in a dedicated On Hold section at the bottom.

### Per-row Action Buttons

- ← Back — moves to previous status
- Next → — advances to next status (label is dynamic based on next-status text)
- ✕ — marks as Declined (TID 1657), with confirm prompt
- ⏸ — puts on hold; prompts for hold-until date (MM-DD-YYYY format) or blank for indefinite

All buttons fire AJAX to `/admin/office/estimates/status-update` with CSRF token validated against key `estimate_board_status_update`. Token passed via `drupalSettings.estimateBoard.csrfToken`.

### Per-row Display

Columns: Client | Property | Services | Coordinator | Age | Estimates | Actions

- **Client** — owner display name, fallback to requestor_name, links to estimate_request canonical
- **Property** — `properties.title` (full address + city), pulled via DB join in controller (NOT `field_nickname`)
- **Services** — comma-separated service term names
- **Coordinator** — `field_assigned_to` user display name
- **Age** — days since `created`; warning class when > 7 days
- **Estimates** — list of linked estimate entities with bundle label + total

### On Hold Section

- Renders below all swimlanes when at least one request has `field_on_hold = TRUE`
- Auto-lifts holds when `field_hold_until` date has passed (checked on each board render via `checkAndLiftHold()`)
- Shows pipeline stage badge per row + hold-until date (MM-DD-YYYY)
- "▶ Resume" button calls AJAX to lift hold

### Needs Follow-Up Section (top of board)

Estimate requests with 5+ days since last activity. Activity = most recent `estimate_action_log` entry, or fallback to entity `changed`. Severity: `critical` (10+ days) renders red, `warning` (5–9) renders orange.

### Estimator Workload Section

Active estimates grouped by `estimate.field_assigned_to`. Counts only current revisions (`field_is_current_revision = 1`), excludes Accepted (1418) and Declined (1419) stages. Highlights current user's row.

### Recent Activity Section

Last 48 hours of `estimate_action_log` entries (max 15). Shows date, user, action key, context, link to request or estimate.

### Help Modal ("?" button)

Top-right floating "?" button opens an accessible modal explaining the 7-step pipeline, who owns each step, and what triggers movement. Includes On Hold footer note.

### Filter Bar

Client-side text filter that searches all swimlane rows + the On Hold section simultaneously. Auto-expands details panes containing matches and shows match count.

### Row Limit

Each swimlane shows max 15 rows by default. "Show all N ↓" button expands. Filter override applies regardless of limit.

### Swimlane Collapse Persistence

`<details>` open/closed state per swimlane is persisted to localStorage under key `estimate_board_swimlane_state:<slug>`.

---

## My Estimates Board (per-stage swimlanes)

Path: `/admin/office/estimates/my-estimates`. Shows estimates assigned to the current user, grouped by `field_stage`.

### Stage Pipeline (in order)

Defined in `EstimateBoardController::ESTIMATE_PIPELINE`:

| TID | Label | Slug |
|---|---|---|
| 1412 | New | `new` |
| 1413 | Contacted | `contacted` |
| 1414 | Appointment Set | `appointment-set` |
| 1415 | In Preparation | `in-preparation` |
| 1420 | Under Review | `under-review` |
| 1416 | Estimate Sent | `estimate-sent` |
| 1421 | Client Feedback | `client-feedback` |
| 1417 | Pending | `pending` |

Off-board: `1418 Accepted`, `1419 Declined`.

### Per-row Action Buttons

- ← Back — moves to previous stage
- Next Stage → — advances to next stage
- ✕ — marks as Declined (TID 1419), with confirm prompt

All buttons fire AJAX to `/admin/office/estimates/estimate-stage-update`. Returns 422 with `scope_required: TRUE` if the estimate has placeholder scope summary text and the new stage requires real content (see Scope Summary Validation below).

### Per-row Display

Columns: Estimate | Client | Property | Request | Total | Age | Actions

### Help Modal

Same "?" pattern as the main board. 10 stages explained with role ownership and scope-summary requirement footer.

---

## Scope Summary Validation

The AJAX endpoint `updateEstimateStage()` blocks advancement past "In Preparation" (TID 1415) if `field_scope_summary` is empty or still contains placeholder text. Placeholder strings checked:

- `"CLIENT REQUEST (update after site visit)"`
- `"Update this scope summary after the site visit."`
- `"Client is requesting a Landscaping project. Please review and update"`

The same `_estimate_scope_is_placeholder()` helper is shared with `estimate.module`'s form validation and `EstimateStageChangeForm::validateForm()`. Stages that require real scope: 1420, 1416, 1421, 1417, 1418.

JS receives the 422 response, shows an alert, and re-enables the button.

---

## Accepted / Declined Tabs

Controller-driven tabs (not Views). Show estimate requests in terminal status (1658 Converted / 1657 Declined) with a date range selector. Default: 90 days. Columns: Client, Property, Services, Coordinator, Date, Estimates.

---

## Daily Email Digest (hook_cron)

- Runs once per day; state key `estimate_board.last_email_sent`
- Sends to `office@brookstoneoutdoors.com`
- Subject: `"Estimate Follow-Up Required — [count] requests need attention"`
- Body lists each follow-up: client, services, days since activity, last action description, link

If no follow-ups, no email is sent (state still updated to skip until tomorrow).

---

## AJAX Architecture

Both endpoints (`status_update`, `estimate_stage_update`) follow the same pattern:

1. Validate CSRF token from `X-CSRF-Token` header against the relevant key
2. Load entity, check access
3. Apply transition (status change, hold, lift_hold, stage change)
4. Return JSON with `success`, plus full row data for client-side reinsertion: `client_name`, `property`, `services`, `coordinator`, `age_days`, `estimates`, `prev_status_tid/label`, `next_status_tid/label`, `current_status_label/slug`, `url`

JS:
- Fades source row out
- Decrements source swimlane badge
- If destination is on-board: builds new row HTML via `buildRowHtml()`, fades into destination swimlane, increments destination badge, opens destination `<details>` if closed
- If destination is off-board (Declined/Converted/Accepted): just removes from source

---

## Theme Hooks (estimate_board.module)

| Hook | Template | Variables |
|---|---|---|
| `estimate_board` | estimate-board.html.twig | followups, pipeline, on_hold_requests, workload, activity, decline_tid, csrf_token |
| `estimate_board_my_estimates` | estimate-board-my-estimates.html.twig | groups |
| `estimate_board_accepted` | estimate-board-accepted.html.twig | rows, days |
| `estimate_board_declined` | estimate-board-declined.html.twig | rows, days |

---

## JS Behaviors (estimate_board.js)

- `estimateBoardHelp` — main pipeline help modal toggle
- `estimateMyEstimatesHelp` — My Estimates stage help modal toggle
- `estimateBoardSwimlaneState` — localStorage open/closed persistence
- `estimateBoardRowLimit` — 15-row limit + "Show all" toggle
- `estimateBoardFilter` — live text filter across all swimlanes + On Hold
- `estimateBoardStatusButtons` — handles status / hold / lift_hold actions
- `estimateBoardEstimateStage` — handles My Estimates stage buttons (separate endpoint)

---

## CSS Classes / Visual Language

Per-status border + badge + button colors defined in `estimate_board.css`:

| Status / Stage | Color |
|---|---|
| New / New – Gathering Info | `#0d6efd` blue |
| Ready to Estimate / Appointment Set | `#0dcaf0` cyan |
| Estimating / Under Review | `#6f42c1` purple |
| Send Estimate / Estimate Sent | `#ffc107` yellow |
| Waiting on Customer / Pending | `#6c757d` gray (also default) |
| Contacted | `#0d6efd` blue |
| In Preparation | `#fd7e14` orange |
| Client Feedback | `#20c997` teal |
| On Hold | `#6c757d` gray |
| Decline / ✕ button | `#dc3545` red border, transparent fill |
| Hold / ⏸ button | `#6c757d` gray border, transparent fill |
| Lift Hold / ▶ Resume | `#198754` green |

---

## Dependencies

- `estimate` module (for entity types and `_estimate_scope_is_placeholder()` helper)
- `estimate_action_log` ECK entity type
- Drupal core: csrfToken, messenger, ajax, dialog.ajax

---

## Related Files

- `src/Controller/EstimateBoardController.php` — all controller methods
- `templates/estimate-board.html.twig` — main board layout
- `templates/estimate-board-my-estimates.html.twig` — My Estimates board layout
- `templates/estimate-board-accepted.html.twig` / `estimate-board-declined.html.twig` — terminal-status tabs
- `js/estimate_board.js` — all behaviors
- `css/estimate_board.css` — all styling

---

Created: March 2026
Last major rewrite: April 2026 — full swimlane rebuild, My Estimates board, help modals, scope summary validation, On Hold system, property title display.
