# BOS Governance — Equipment Status Lifecycle (LOCKED)

Vocabulary: `equipment_status`
Used by: `equipment.field_status` (all 8 equipment bundles)

---

## Purpose

Defines the lifecycle states for all BOS equipment entities. Equipment status
drives operational visibility, maintenance scheduling, dispatch eligibility,
and asset management decisions. Status must accurately reflect the current
physical and operational state of the equipment at all times.

---

## Work Order Eligibility (Enforced)

Only the following statuses are allowed for Work Order **assignment**
(dispatching equipment to perform work):

* Active
* Deployed

All other statuses must be blocked from Work Order assignment.

**Clarification:** This rule governs active operational assignment — dispatching
a truck, mower, or sprayer to a job. Equipment in other statuses may still
appear in historical Work Order records, cost/usage logs, or maintenance
references. The restriction applies to new assignment only.

The following statuses are NOT valid for Work Orders:

* Idle
* In Storage
* On Loan/Loaned Out
* Being Serviced
* Needing Repairs
* Unrepairable
* Salvaged for Parts
* Lost/Stolen
* Sold

---

## Status Definitions

### Active (TID 1301)

Equipment is part of the active fleet and operational.

* Default operational state
* Equipment is integrated into daily operations
* May or may not be immediately assigned
* No known blocking issues

Use this for: equipment in serviceable condition and part of the working fleet.

---

### Deployed (TID 1309)

Equipment is actively in use at a specific location.

* Assigned to a job site, project, or crew
* May be off-site or remotely located
* Operational and contributing to work

Use this for: equipment currently in use on a job or assigned long-term.

---

### Idle (TID 1311)

Equipment is not currently in use but staged for availability.

* Fully operational
* Explicitly staged as available for immediate dispatch
* Preferred status for assignment preparation

Use this for: backup units, seasonal readiness, or staged equipment.

---

### In Storage (TID 1308)

Equipment is stored and not actively staged for use.

* Stored in designated location
* Maintained but not ready for immediate deployment
* May require prep before use

Rules:

* Equipment must be transitioned to "Idle" before use
* Must not be directly assigned to Work Orders

Use this for: winterized equipment, reserve inventory, long-term storage.

---

### On Loan/Loaned Out (TID 1307)

Equipment is temporarily assigned outside normal control.

* Assigned to another crew, department, or external party
* Ownership remains with Brookstone
* Must track location, user, and return expectation

Rules:

* Not eligible for Work Orders

Use this for: temporary reassignment or external usage.

---

### Needing Repairs (TID 1302)

Equipment requires repair before returning to service.

* Not functioning as intended
* Awaiting diagnosis, parts, or scheduling
* Not safe or reliable for operation

Rules:

* Must not be used
* Must transition to "Being Serviced" or "Unrepairable"

Use this for: identified issues not yet in active repair.

---

### Being Serviced (TID 1303)

Equipment is actively undergoing maintenance or repair.

* In shop or with technician
* Not available for use

Rules:

* Must not be used
* Must transition to "Active" or "Idle" upon completion

Use this for: equipment currently being repaired or maintained.

---

### Unrepairable (TID 1304)

Equipment cannot be economically or safely repaired.

* Repair not feasible or exceeds replacement value
* May present safety risk

Rules:

* Must transition to "Salvaged for Parts" or "Sold"
* Must not return to operational states

Use this for: end-of-life equipment pending disposition.

---

### Salvaged for Parts (TID 1305)

Equipment has been decommissioned and stripped for usable parts.

* No longer exists as a functional unit

Rules:

* Terminal state
* Irreversible

Use this for: equipment dismantled for component reuse.

---

### Lost/Stolen (TID 1310)

Equipment is no longer in possession.

* Missing or confirmed stolen
* Requires incident reporting

Rules:

* Not eligible for Work Orders
* May transition back to "Active" if recovered

Use this for: missing or stolen equipment.

---

### Sold (TID 1306)

Equipment has been sold and ownership transferred.

* Removed from asset inventory

Rules:

* Terminal state
* Irreversible

Use this for: equipment sold to external party.

---

## Lifecycle Transitions (Enforced)

```
Active → Deployed → Active
Active → Idle
Idle → Deployed

Idle → Needing Repairs → Being Serviced → Active
Being Serviced → Idle

Any → Lost/Stolen
Lost/Stolen → Active (if recovered)

Any → Unrepairable → Salvaged for Parts
Any → Sold
```

---

## Status Categories (Operational)

Dispatchable:

* Active
* Deployed

Available (Preferred):

* Idle

Non-dispatchable:

* In Storage
* On Loan/Loaned Out

Maintenance:

* Needing Repairs
* Being Serviced

Terminal:

* Sold
* Salvaged for Parts

Exception:

* Lost/Stolen

---

## Rules

* Equipment status must reflect current physical reality, not planned state
* Status changes must be timely — stale statuses are not allowed
* "Needing Repairs" must not be used as a parking state
* "Lost/Stolen" requires incident documentation
* "Sold" and "Salvaged for Parts" are terminal and irreversible
* Equipment must follow defined transition paths; invalid transitions are not allowed

---

## Related Entities

* `equipment_status_update` (bundle: `update`) — tracks status change events
* `equipment_check_in_out` (bundle: `check_in`) — tracks physical custody changes
* `equipment_status_updates` module — propagates status updates to equipment entity

---

## Status

* Governance: LOCKED
* Enforcement: REQUIRED
* Scope: All equipment bundles

---

Created: April 2026
Updated: Locked governance version
