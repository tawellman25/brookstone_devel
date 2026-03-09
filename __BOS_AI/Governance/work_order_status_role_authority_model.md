# BOS — Work Order Status Authority Model

## Purpose

This document defines **who is allowed to change Work Order status** in BOS and **why**. It is intended to prevent workflow drift, status misuse, and future automation conflicts.

This is a **governance document**, not a permissions table. Permissions should enforce what is documented here.

---

## Design Principles

1. **Execution truth belongs to the field**
2. **Intent, scheduling, and money belong to the office**
3. **Admins correct mistakes; they do not bypass reality**
4. **A role may only change a status it can personally verify**

If a role cannot directly observe the condition, it should not set the status.

---

## Role-Based Authority (Finalized)

### Teammates (Crew)

**Authority:** Execution truth and on-site blockers

**Allowed status changes (manual):**
- In Progress *(may be auto-triggered on first clock-in)*
- Needs Parts
- Parts Ordered
- Needs Access
- Complete *(via WO sign-off)*
- Canceled *(requires sign-off + note)*

**Notes:**
- Teammates may order parts directly; office staff does not.
- Status changes must be accompanied by a status note when applicable.

---

### Supervisor

**Authority:** Capacity, feasibility, and commitment

**Allowed status changes:**
- All Teammate-allowed statuses
- Needs Confirmed
- Accepted *(shared with Office)*
- Scheduled *(commitment authority)*

**Notes:**
- Supervisors convert proposed dates into committed schedules.

---

### Office / Administration

**Authority:** Intent, customer coordination, and financial lifecycle

**Allowed status changes:**
- Accepted *(shared with Supervisor)*
- Scheduled *(propose only)*
- Invoiced
- Paid
- Canceled *(administrative reasons)*

**Not allowed:**
- In Progress / Needs Parts / Needs Access / Complete

---

### Site Admin / Administrator

**Authority:** Correction and recovery only

**Allowed:** All statuses, backward transitions, post-completion corrections (with note)

---

## Special Case — Canceled


Cancellation represents two distinct realities, even if stored as one status:

### Execution Cancel (Field)
- Unsafe conditions
- Access denied
- Weather or site constraints

**Who may cancel:**
- Supervisor
- Site Admin / Administrator

---

### Administrative Cancel
- Client declined
- Billing issue
- Duplicate or invalid Work Order

**Who may cancel:**
- Office / Administration
- Site Admin / Administrator

---

## Summary Table

| Role | Execution Status | Scheduling Status | Financial Status |
|---|---|---|---|
| Teammate | Yes | No | No |
| Supervisor | Yes | Limited | No |
| Office / Administration | No | Yes | Yes |
| Site Admin / Administrator | Override | Override | Override |

---

## Automation Rules (Finalized)

**Automatic transitions:**
- **Assigned → In Progress** on first teammate clock-in
- **Open → In Progress** on first teammate clock-in (for walk-by / pass-through WO types)
- **In Progress → Complete** on creation of `wo_complete_info` (sign-off entity)

**Manual transitions (no automation):**
- Open → Needs Confirmed *(Supervisor)*
- Needs Confirmed → Accepted *(Supervisor or Office)*
- Accepted → Scheduled *(Office proposes; Supervisor commits)*
- Needs Parts ↔ Parts Ordered *(Teammate-driven)*
- Any → Canceled *(all roles allowed; teammates require sign-off + note)*

**Financial lifecycle:**
- **Complete → Invoiced**
  - **Current:** Manual/VBO by Office during invoice creation
  - **Target:** Automatic on successful QuickBooks export (1 WO → 1 invoice line item)
- **Invoiced → Paid**
  - **Current:** Manual by Office
  - **Target:** Automatic from QuickBooks payment sync (match QB invoice paid → WO Paid)

## Immutability & Locks

- After **Complete**:
  - Execution data (time, materials, chemicals) is read-only
  - Admin-only explicit corrections allowed
- After **Invoiced**:
  - Totals must not change
  - No retroactive repricing
- **Canceled** is terminal (record retained; removed from calendars)

## Enforcement Notes

- UI visibility is not enforcement; validate transitions in code
- All automated transitions must be idempotent
- All overrides require a note

---

## Status

Final — approved for implementation guidance

