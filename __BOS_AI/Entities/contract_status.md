# BOS Vocabulary — Contract Status

Vocabulary ID:

* contract_status

Used By:

* contracts (ECK entity)

  * field_contract_status

---

## Purpose

The **Contract Status** vocabulary defines the **authoritative lifecycle state** of a Contract from internal creation through seasonal completion or permanent cancellation.

This vocabulary is a **workflow and governance controller**, not a cosmetic label set.

Contract Status determines:

* what actions are permitted
* when Work Orders may be generated
* when Contract data is editable or locked
* where the Contract appears in office and operational workflows

Status changes must always reflect **real business state**.

---

## Lifecycle Model (Authoritative)

Contracts move through **preparation**, **delivery**, **authorization**, **execution**, and **closure** phases.

Not all transitions are mandatory, but **all listed transitions are valid**. Transitions not listed are invalid.

---

## Status Definitions & Rules

### 1117 — Created – Updating

**Purpose**
Internal draft and preparation state. Used for system-generated renewals and manual drafting.

**Rules**

* Contract is fully editable
* Contract Sections may be added, removed, or modified
* Client has not been sent the contract
* Work Orders must NOT be created

**Allowed Transitions**

* → Ready to Send
* → Canceled / Void

---

### 1118 — Ready to Send

**Purpose**
Final internal quality gate before customer exposure.

**Rules**

* Contract content is considered customer-ready
* Only minor internal corrections allowed
* Contract has not yet been delivered
* Work Orders must NOT be created

**Allowed Transitions**

* → Sent / Posted
* → Created – Updating
* → Canceled / Void

---

### 1119 — Sent / Posted

**Purpose**
Confirms the contract has been delivered to the customer.

**Rules**

* Contract has left internal control
* Structural edits are not allowed
* Correction-level edits only
* Work Orders must NOT be created

**Allowed Transitions**

* → Client Viewed
* → Received Back
* → Canceled / Void

---

### 1120 — Client Viewed *(System Only)*

**Purpose**
System-detected indicator that the client accessed the contract page.

**Rules**

* Informational only
* No edits allowed
* Does not imply agreement or approval
* Work Orders must NOT be created

**Allowed Transitions**

* → Received Back
* → Canceled / Void

---

### 1121 — Received Back

**Purpose**
Confirms the client has returned the contract or formally responded.

**Rules**

* Signed contract or client response is in possession
* Verification and review required
* No execution allowed yet

**Allowed Transitions**

* → Changes Entered
* → Approved *(only if no changes are required)*
* → Canceled / Void

---

### 1122 — Changes Entered

**Purpose**
Confirms all client-requested changes from a returned contract have been fully entered into BOS.

**Rules**

* Digital contract must match client intent exactly
* Manual verification required
* Work Orders must NOT be created

**Allowed Transitions**

* → Approved
* → Ready to Send
* → Canceled / Void

---

### 1123 — Approved

**Purpose**
Internal authorization confirming the contract is accurate and safe for execution.

**Rules**

* Contract data must be complete and verified
* Contract intent is locked
* No Work Orders are created automatically

**System Behavior**

* Contract becomes eligible for Work Order generation
* Validation may occur, but execution does not

**Allowed Transitions**

* → Generate Work Orders *(action)*
* → Work Orders Created *(system result)*
* → On Hold
* → Canceled / Void

---

### 1124 — Work Orders Created

**Purpose**
Confirms that one or more Work Orders have already been generated.

Indicates that all contract-eligible, auto-generated Work Orders have been created and linked for this Contract at this point in time.

Route-based and condition-based services (e.g., mowing, weed control, snow removal) are intentionally excluded and are created separately as needed.

**Rules**

* Contract intent is locked
* Contract Sections must not change
* Contract is no longer the operational driver

**Allowed Transitions**

* → Assigned
* → On Hold

---

### 1125 — Assigned

**Purpose**
Ownership of execution has been assigned to crews or supervisors.

**Rules**

* Work Orders exist and are owned
* Contract is execution-active
* Contract is read-only

**Allowed Transitions**

* → Completed for the Year
* → On Hold

---

### 1126 — On Hold

**Purpose**
Temporary, intentional pause of contract execution.

**Rules**

* No new Work Orders may be generated
* Existing Work Orders must not proceed
* Non-destructive and reversible

**Allowed Transitions**

* → Assigned
* → Approved
* → Created – Updating
* → Completed for the Year
* → Canceled / Void

---

### 1127 — Completed for the Year

**Purpose**
Seasonal closeout indicating all services for the contract year are complete.

**Rules**

* All Work Orders must be closed
* Contract is read-only
* Used for reporting and renewal generation

**Allowed Transitions**

* (Terminal for the season)
* New year requires a new Contract

---

### 1128 — Canceled / Void

**Purpose**
Permanent termination of the contract.

**Rules**

* Contract is read-only
* No Work Orders may be created or executed
* Historical reference only

**Allowed Transitions**

* None (terminal)

---

## Enforcement Rules (Non‑Negotiable)

* Work Orders may only be generated when Contract Status is:

  * Approved
  * Work Orders Created
  * Assigned

* Contract Sections must not be modified once status is:

  * Approved or later

* Canceled / Void contracts must be excluded from:

  * scheduling
  * billing
  * execution workflows

---

## Invariants (Authoritative)

* Contract Status controls lifecycle, not UI convenience
* Status transitions must reflect real business state
* Execution must never occur on non‑approved contracts
* Completed and Canceled contracts are immutable
* System‑only statuses must never be manually set

---

## Audit & Reporting Expectations

Contracts must be reportable by:

* current status
* lifecycle phase
* year / season
* client
* property

Full status history must be preserved for audit, renewal logic, and operational analysis.
