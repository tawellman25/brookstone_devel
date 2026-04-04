# BOS Entity — sop_log

Entity Type ID: `sop_log`
Storage: ECK

## Purpose
Append-only log for SOP lifecycle events. Tracks review notes, clarifications,
improvement suggestions, audit records, exception requests, and follow-up items.

The SOP entity (`sop`) is the controlled document.
The SOP log (`sop_log`) is the history around it.

These must remain separate — do not duplicate log content back into the SOP body.

## Bundle
`standard` — single bundle for all log entry types.
Log type differentiation is via `field_log_type` (list_string), not separate bundles.

## Auto-Label Pattern
`[SOP Code] - [Log Type] - [Date Short]`
Example: `GOV-SOP-001 - Review Note - 04/04/2026 - 8:15 AM`

---

## Fields

### Required — Phase 1

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_sop` | entity_reference → sop | SOP | Required. Links to the SOP this log belongs to. |
| `field_log_type` | list_string | Log Type | Required. See allowed values below. |
| `field_log_entry` | text_long | Log Entry | Required. One event per entry — not a running thread. |
| `field_log_status` | list_string | Status | Required. Default: `open`. See allowed values below. |

Actor and timestamp use ECK base fields:
- `uid` (base) — who created the log entry (Authored by)
- `created` (base) — when the log entry was created (Authored on)

### Strongly Recommended

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_requires_follow_up` | boolean | Requires Follow-Up | For quick filtering/reporting. Default: FALSE. |
| `field_resolved_by` | entity_reference → user | Resolved By | Set when item is resolved or closed. |
| `field_resolved_at` | datetime | Resolved At | Set with resolution workflow. |

---

## field_log_type Allowed Values

| Value | Label |
|---|---|
| `review_note` | Review Note |
| `clarification` | Clarification |
| `improvement_suggestion` | Improvement Suggestion |
| `audit_note` | Audit Note |
| `temporary_deviation` | Temporary Deviation |
| `exception_request` | Exception Request |
| `exception_approved` | Exception Approved |
| `exception_denied` | Exception Denied |
| `incident_linked` | Incident Linked |
| `follow_up_required` | Follow-Up Required |
| `general_note` | General Note |

## field_log_status Allowed Values

| Value | Label |
|---|---|
| `open` | Open |
| `noted` | Noted |
| `under_review` | Under Review |
| `resolved` | Resolved |
| `closed` | Closed |
| `rejected` | Rejected |

---

## Behavioral Rules

### Append-only
Once created, log entries must not be casually edited.

Allowed edits:
- Status updates (`field_log_status`)
- Resolution fields (`field_resolved_by`, `field_resolved_at`)
- Admin correction of obvious mistakes

NOT allowed:
- Rewriting historical log narrative after the fact

### One event per entry
Each `sop_log` record represents one discrete event.

### SOP remains clean
Do not duplicate log content back into the SOP body.
The SOP is the controlled document. The log is the history around it.

---

## Planned Views

| View | Purpose |
|---|---|
| SOP Log by SOP | All log entries for a given SOP, sorted newest first |
| Open SOP Log Items | `field_log_status` NOT IN (resolved, closed) |
| Follow-Up Required | `field_requires_follow_up` = TRUE |
| Audit/Review Feed | `field_log_type` IN (audit_note, review_note, clarification) |

---

## Invariants
- Append-only. Do not delete log entries.
- One event per entry — not a running thread.
- Log content must not bleed into the SOP document.
- ECK base `uid` and `created` fields are authoritative for actor and timestamp.

Created: April 2026
