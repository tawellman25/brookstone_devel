# BOS Entities — Core Index (Authoritative)

This folder documents BOS entities as the system’s canonical data model.

Folder: `__BOS_AI/Entities/`

Each entity has its own file. This index defines:
- What “Core” means in BOS
- Which entities are Core vs Supporting
- Cross-cutting rules that entity files must follow

If an entity or relationship is not documented here (or in an entity file),
it does not exist.

---

## Folder Structure

__BOS_AI/Entities/
  ├── 00_core_entities.md              (this file)
  ├── 01_entities_policy.md            (cross-cutting rules)
  ├── properties.md                    (Core)
  ├── work_orders.md                   (Core)
  ├── contracts.md                     (Core)
  ├── users.md                         (Supporting - core user entity)
  ├── people_org_reference_entities.md (Supporting - contacts, address, phone)
  ├── estimate.md                      (Supporting)
  ├── estimate_request.md              (Supporting)
  ├── equipment.md                     (Supporting)
  ├── material.md                      (Supporting)
  └── sop_content_knowledge_entities.md (Governance)

Rules:
- One entity per file.
- Entity files must not contradict `00_core_entities.md` or `01_entities_policy.md`.
- Keep files factual and structural (relationships + invariants), not UI instructions.

---

## Definition: Core Entity

A BOS Core Entity must meet all of these criteria:

1) BOS cannot operate day-to-day without it.
2) Other operational records are anchored to it.
3) It represents long-lived business truth that must persist over time.
4) Losing it breaks workflows, reporting, and/or operational history.

Core entities form the operational spine of BOS.

---

## BOS Core Entities (Operational Spine)

### 1) Properties
File: `properties.md`

Role:
- Physical locations where work happens.

Why Core:
- Work Orders require a location anchor.
- Contracts are typically scoped to a location.
- Properties persist across ownership changes and years of work history.

Required conceptual links:
- Property ← Work Orders (required)
- Property ← Contracts (expected)
- Property ← Contacts (expected)
- Property ← Estimates (expected)

---

### 2) Work Orders
File: `work_orders.md`

Role:
- Execution record of operational work performed.

Why Core:
- Tracks what happened, when, by whom, and what was used.
- Supports operational reporting and billing exports.
- Provides audit trail for quality and client disputes.

Required conceptual links:
- Work Order → Property (required)
- Work Order → Contract (optional/expected depending on workflow)
- Work Order → Notes/Attachments (expected)

---

### 3) Contracts
File: `contracts.md`

Role:
- Commercial agreement defining scope, expectations, and billing context.

Why Core:
- Establishes why work exists and under what terms.
- Drives recurring service commitments and scheduling context.
- Connects operations to billing intent.

Required conceptual links:
- Contract → Property (required or strongly expected)
- Contract → Client/Profile (required or strongly expected)
- Contract → Work Orders (one-to-many over time)

---

## Supporting Entities (Non-Core)

These are important, but BOS can still operate if they are refactored or replaced.

### Identity & Relationships
- `users.md` (core Drupal User entity)
- `people_org_reference_entities.md` (contacts, address, phone_number, profiles)

### Operations Support
- `estimate.md` / `estimate_request.md`
- `equipment.md`
- `material.md`

### Governance
- `sop_content_knowledge_entities.md`

---

## Cross-Cutting Rules (Core Focus)

1) Core entity relationships must be explicit (reference fields + clear ownership rules).
2) Deletion is restricted; prefer archival over deletion.
3) Business logic belongs in code (modules/services), not only UI configuration.
4) Core reporting must be possible using Property + Contract + Work Order relationships alone.

---

## File Status

All core entity files listed above have been created.
