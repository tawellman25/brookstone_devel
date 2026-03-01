# BOS — Contract Action Log

## Purpose

The **Contract Action Log** records **intentional contract workflow actions** (status transitions) in an **explicit, append-only, reportable** way. It exists to answer:

* Who changed a Contract’s status
* When it happened
* From which status to which status
* Whether an administrator override was used

This is **not** audit logging, **not** inferred from timestamps, and **not** UI-driven logging.

---

## Scope (Authoritative)

### Included

* Staff-driven Contract **status transitions** executed via VBO Actions
* Explicit logging at the moment the action succeeds

### Excluded

* Contract Section audit history (separate system)
* Generic entity update timestamps
* Frontend JS / UI events
* Retroactive or inferred history

---

## Entity Definition

### Entity Type

* **Machine name:** `contract_action_log`
* **Bundle:** `log`
* **Storage:** ECK
* **Write pattern:** append-only (no edits, no deletes)

### Base Fields

* `id`
* `uuid`
* `uid` — who executed the action
* `created` — when the action occurred (**Authored On**)

### Bundle Fields

| Field                  | Type                                 | Required | Notes                                       |
| ---------------------- | ------------------------------------ | -------- | ------------------------------------------- |
| `field_contract`       | Entity reference → `contracts`       | Yes      | Contract acted upon                         |
| `field_action`         | String                               | Yes      | Machine-readable action key (VBO plugin id) |
| `field_from_status`    | Entity reference → `contract_status` | No       | Prior status (if available)                 |
| `field_to_status`      | Entity reference → `contract_status` | Yes      | New status                                  |
| `field_admin_override` | Boolean                              | Yes      | TRUE only for administrator bypass          |
| `field_actor`          | List (string)                        | Yes      | `staff` | `client` | `system`               |
| `field_context`        | Long text                            | No       | Optional metadata / reason                  |

---

## Override Governance

**Authoritative rule:**

* Only users with the Drupal role **`administrator`** may bypass status guardrails.
* When bypass occurs:

  * `field_admin_override = 1`
* All other roles must follow defined status sequences.

No other roles are considered override-capable.

---

## Logged Actions (Current)

The following VBO Action plugins write log rows:

| Action Plugin ID                            | From Status            | To Status |
| ------------------------------------------- | ---------------------- | --------- |
| `contract_residential_mark_ready_to_send`   | 1117, 1126             | 1118      |
| `contract_residential_mark_sent_posted`     | 1117, 1118, 1126       | 1119      |
| `contract_residential_mark_received_back`   | 1118, 1119, 1120, 1126 | 1121      |
| `contract_residential_mark_changes_entered` | 1121, 1120, 1126       | 1122      |
| `contract_residential_mark_approved`        | 1122, 1121, 1126       | 1123      |

*(Status term 1120 — Client Viewed — is reserved for future client-driven transitions.)*

---

## Write Location (Critical Invariant)

Log rows are written **only**:

* After a Contract status action **successfully saves**
* Inside the **Action execution path**

Log rows are **not** written from:

* Views
* Form alters
* Entity hooks
* Frontend JS

Each successful action → **exactly one log row**.

---

## Logging Implementation

### Shared Writer

All actions call a shared helper:

`ContractActionLogWriter::write()`

Responsibilities:

* Validate entity type
* Populate required fields
* Write one append-only log row
* Never block the primary action if logging fails

### Logged Values

* `field_action` = VBO plugin id
* `field_actor` = `staff`
* `uid` = current user
* `created` = now
* `field_from_status` = prior TID
* `field_to_status` = target TID
* `field_admin_override` = 0/1

---

## Reporting Usage

The Contract Action Log is designed to support:

* Per-Contract status timelines
* “Who approved what” reports
* Override frequency analysis
* Status aging (time between steps)
* Dispute and client inquiry support

### Canonical Timeline View

* Base: `contract_action_log`
* Filter: `bundle = log`
* Contextual filter: `field_contract` (Contract ID)
* Sort: `created ASC`
* Fields:

  * Authored On
  * User
  * Action
  * From Status
  * To Status
  * Admin Override
  * Actor

---

## Explicit Non-Goals

* No workflow automation
* No notifications
* No UI rendering logic
* No retroactive backfill
* No schema changes to Contracts

---

## Status

**Implemented, validated, and in use.**

The Contract Action Log is now the authoritative source for intentional Contract status transitions in BOS.
