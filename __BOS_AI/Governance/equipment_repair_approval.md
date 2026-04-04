# BOS Governance — Equipment Repair Approval Model (LOCKED)

---

## PURPOSE

Defines authority, approval thresholds, and workflow for all equipment repairs within BOS.

This governance ensures:

* repair costs are controlled
* authority is clearly defined
* downtime is visible and managed
* no unauthorized repair work occurs

---

## SCOPE

Applies to:

* all equipment entities
* all repair events
* all staff interacting with equipment

---

## CORE PRINCIPLE

No repair work may begin without defined authority, except under approved emergency conditions.

---

## ROLES

### Level 1 — Field Authority (Foreman / Lead)

Responsibilities:

* identify issues
* remove equipment from service
* initiate repair request

---

### Level 2 — Operations Manager

Responsibilities:

* approve standard repairs
* manage scheduling and vendors

Fallback Rule:

* If no Operations Manager role is assigned, all Level 2 decisions default to Owner

---

### Level 3 — Owner

Responsibilities:

* approve major repairs
* approve replacement decisions
* resolve escalations

---

## AUTHORITY MATRIX

### Step 1 — Issue Identification

Any Field Authority may:

* set status → Needing Repairs

Requirements:

* issue description must be recorded
* severity must be identified (minor / major / critical)

---

### Step 2 — Repair Approval Thresholds

$0 – $250

* Field Authority may approve

$251 – $2,000

* Operations Manager approval required
* If no Operations Manager exists, Owner approval required

$2,000+

* Owner approval required

---

### Step 3 — Transition to Being Serviced

Needing Repairs → Being Serviced

Requires:

* approved repair decision
* assigned technician or vendor
* estimated cost recorded

---

### Step 4 — Repair Completion

Being Serviced → Active / Idle

Requires:

* repair completed
* equipment verified operational
* final cost recorded

---

## EMERGENCY RULE

### Definition

Emergency conditions include:

* risk of job failure
* safety risk
* significant revenue impact

---

### Emergency Authority

Field Authority may approve repairs up to $500

Requirements:

* must notify Operations Manager or Owner immediately
* must document reason for emergency decision

---

## REPLACEMENT THRESHOLD RULE

If repair cost exceeds 50% of replacement value:

* must escalate to Owner
* repair may not proceed without Owner decision

---

## STATUS ENFORCEMENT

* equipment must be set to Needing Repairs before repair begins
* equipment must not be set to Being Serviced without approval
* equipment must not return to Active or Idle without verification

---

## HARD RULES

* no unlogged repairs are allowed
* no verbal-only approvals are allowed
* all repair decisions must be recorded
* all costs must be captured

---

## REQUIRED DATA (MINIMUM)

Each repair event must include:

* equipment reference
* issue description
* severity level
* status changes
* estimated cost
* final cost
* approval authority
* technician or vendor
* date of work

---

## STANDARD FLOW

Active / Idle
→ Needing Repairs
→ (approval)
→ Being Serviced
→ Active / Idle

---

## END-OF-LIFE FLOW

Needing Repairs
→ Unrepairable
→ Salvaged for Parts or Sold

---

## COMPLIANCE

This governance is enforced when:

* all repairs follow approval thresholds
* all status transitions are correct
* all repair data is recorded

Non-compliance results in:

* SOP violation
* required corrective action

---

## STATUS

* Governance: LOCKED
* Enforcement: REQUIRED
* Scope: All equipment repairs

---

Created: April 2026
