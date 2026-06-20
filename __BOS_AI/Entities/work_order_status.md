# BOS Vocabulary — Work Order Status

Vocabulary ID:
- wo_status

Used By:
- work_order (ECK)
  - field_status (entity_reference → taxonomy_term: wo_status)

Related execution entities (authoritative triggers / locks):
- wo_time_clock (ECK) — clock-in / time tracking
- wo_complete_info (ECK) — sign-off / completion authority

---

## Purpose

The **Work Order Status** vocabulary defines the **execution lifecycle state** of a Work Order.

This vocabulary is a **workflow controller**, not cosmetic labels.

Work Order Status determines:
- whether a Work Order is ready to execute
- whether it should appear on calendars / teammate lists
- whether execution data is editable vs locked
- whether billing/export actions are allowed
- whether the record is operationally terminal (Paid / Canceled)

---

## Canonical Term Order (Authoritative)

| Weight | TID  | Label |
|---:|---:|---|
| 0  | 1089 | Open |
| 1  | 1099 | Needs Confirmed |
| 2  | 1095 | Waiting for Customer Response |
| 3  | 1503 | Accepted |
| 4  | 1091 | Scheduled |
| 5  | 1090 | Assigned |
| 6  | 1092 | In Progress |
| 7  | 1093 | Needs Parts |
| 8  | 1094 | Parts Ordered |
| 9  | 1096 | Needs Access |
| 10 | 1097 | Complete |
| 11 | 1283 | Warrantied |
| 12 | 1281 | Invoiced |
| 13 | 1504 | Paid |
| 14 | 1098 | Canceled |

---

## Status Definitions (Authoritative)

### 1089 — Open
Meaning:
- Applied immediately on Work Order creation.
- Work exists in BOS but has not been confirmed/assigned/scheduled.

Operational logic:
- Some WO types may remain “Open” until a teammate begins work as part of route flow.
- Open may transition directly to In Progress via clock-in automation.

---

### 1099 — Needs Confirmed
Meaning:
- Work Order requires verification/approval before execution should proceed.

Operational logic:
- Confirmation is a supervisor/office checkpoint.
- Used when scope, feasibility, access, or client agreement must be clarified.

---

### 1095 — Waiting for Customer Response
Meaning:
- Teammate has documented contact attempt and is awaiting client response.

Operational logic:
- Work is blocked by communication.
- Follow-up cadence is operationally owned by Supervisor/Office.

---

### 1503 — Accepted
Meaning:
- Customer has approved the estimate and authorized Brookstone Outdoors to perform the work.
- No schedule/crew commitment has been made yet.

Operational logic:
- “Approved job, pending scheduling and crew assignment.”

---

### 1091 — Scheduled
Meaning:
- The Work Order has a date/time or window placed on the calendar.

Operational logic (critical):
- Office may **propose** schedule.
- Supervisor **commits** schedule (capacity authority).
- Scheduled does not imply feasibility unless committed by Supervisor.

---

### 1090 — Assigned
Meaning:
- The Work Order is allocated to a specific crew/teammate for execution.

Operational logic:
- Assignment establishes responsibility.
- Assigned → In Progress may be auto-triggered by first clock-in.

---

### 1092 — In Progress
Meaning:
- Execution has begun and time tracking is active.

Operational logic:
- Entry to In Progress is an execution fact.
- Triggered automatically on first teammate clock-in.
- May be reached from Open or Assigned depending on WO type/workflow.

---

### 1093 — Needs Parts
Meaning:
- Set when a teammate enters a status update selecting “Needs Parts.”

Operational logic:
- Execution is blocked by parts/material availability.
- Teammates are responsible for ordering parts in current workflow.

---

### 1094 — Parts Ordered
Meaning:
- Set when a teammate enters a status update selecting “Parts Ordered.”

Operational logic:
- Work is paused pending delivery/availability.
- Return trip may be Scheduled/Assigned when parts are available.

---

### 1096 — Needs Access
Meaning:
- Set when a teammate cannot access the property.

Operational logic:
- Must trigger notification/escalation to Supervisor + Office.
- Work is blocked until access is resolved.

---

### 1097 — Complete
Meaning:
- Work is complete and has been signed off.

Authoritative trigger:
- In Progress → Complete occurs when a `wo_complete_info` entity is created and references the Work Order via `field_work_order`.

Operational logic:
- Complete represents execution truth.
- Complete may be set manually only for explicit historical backfill or admin correction.

---

### 1283 — Warrantied
Meaning:
- The work completed under this order was performed under Brookstone warranty obligations.

Operational logic:
- Warranty classification state applied post-completion.
- Used for tracking warranty work history.

---

### 1281 — Invoiced
Meaning:
- Work has been invoiced; invoice issued to the client.

Operational logic:
- Current: Office sets via manual/VBO during invoice creation.
- Target: set automatically upon successful QuickBooks export (1 WO → 1 invoice line item).

---

### 1504 — Paid
Meaning:
- Work completed, invoiced, and full payment received.

Operational logic:
- Terminal financial closeout state.
- Target: set automatically based on QuickBooks payment sync.

---

### 1098 — Canceled
Meaning:
- Work Order is canceled; it is not deleted.
- Removed from calendars and teammate lists.
- Remains visible on the Property record for history.

Operational logic:
- Terminal non-execution state.
- Teammate cancellation requires sign-off + note (gate).

---

## Authority Model (Who Can Change Status)

### Office vs Supervisor: proposal vs commitment
- Office may **propose** schedule.
- Supervisor **commits** schedule.

### Allowed status changes by role (authoritative intent)

Teammates (Crew):
- May set: In Progress, Needs Parts, Parts Ordered, Needs Access, Complete, Canceled (with sign-off + note gate)

Supervisor:
- May set: all teammate statuses + Needs Confirmed + Accepted + Scheduled (commit) + Assigned

Office / Administration:
- May set: Accepted + Scheduled (propose) + Invoiced + Paid + Canceled (administrative)

Site Admin / Administrator:
- May set: all statuses, including backward transitions, for explicit correction (with note)

---

## Automation Rules (Authoritative)

Automatic transitions:
- Assigned → In Progress on first teammate clock-in
- Open → In Progress on first teammate clock-in (walk-by / pass-through WO types)
- Clock-in auto-promotion to In Progress (1092) is suppressed when the WO's
  current status is terminal/closed — Complete (1097), Warrantied (1283),
  Invoiced (1281), Paid (1504), or Canceled (1098). Re-clocking-in on a closed
  WO records the time entry but does not revert status. (wo_timer_flag_update,
  commit 5e76da8a, 2026-06-19.)
- In Progress → Complete on creation of `wo_complete_info` referencing the WO

Manual transitions:
- Open → Needs Confirmed (Supervisor)
- Needs Confirmed → Accepted (Supervisor or Office)
- Accepted → Scheduled (Office proposes; Supervisor commits)
- Needs Parts ↔ Parts Ordered (Teammate-driven)
- Any → Canceled (all roles allowed; teammate gate requires sign-off + note)

Financial lifecycle:
- Complete → Invoiced
  - Current: manual/VBO by Office during invoice creation
  - Target: automatic on successful QuickBooks export
- Invoiced → Paid
  - Current: manual by Office
  - Target: automatic from QuickBooks payment sync

---

## Allowed Transitions (Governance)

Common forward flow:
Open
→ Needs Confirmed
→ Waiting for Customer Response
→ Accepted
→ Scheduled
→ Assigned
→ In Progress
→ Complete
→ Invoiced
→ Paid

Execution blockers (valid from In Progress):
- In Progress → Needs Parts → Parts Ordered → Scheduled/Assigned → In Progress
- In Progress → Needs Access → Waiting for Customer Response → Scheduled/Assigned → In Progress

Terminal states:
- Paid (terminal)
- Canceled (terminal)

Backward transitions:
- Discouraged; admin-only correction with note.

---

## Visibility Rules (Calendars / Teammate Lists)

Must appear on calendars / teammate execution views:
- Scheduled
- Assigned
- In Progress
- Needs Parts
- Parts Ordered
- Needs Access

Must be removed from calendars / teammate lists:
- Canceled

Office closeout / billing views (not crew execution lists):
- Complete
- Warrantied
- Invoiced
- Paid

---

## Immutability & Locks (Non-Negotiable)

After **Complete**:
- Execution data becomes read-only:
  - time entries
  - materials/chemicals
  - equipment usage
- Admin-only explicit corrections allowed (must be traceable)

After **Invoiced**:
- Totals must not change
- No retroactive repricing or recalculation
- Adjustments must be explicit and role-restricted

Canceled:
- Record retained
- Removed from calendars and teammate lists
- Treated as terminal

---

## Notes / Cleanup Targets (Optional)

- Several term descriptions are verbose; BOS prefers short, enforceable definitions:
  - meaning
  - trigger
  - allowed transitions
  - enforcement notes (notifications/locks)
- If term descriptions are later rewritten, they must not contradict this file.
