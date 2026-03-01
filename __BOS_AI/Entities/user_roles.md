# BOS User Roles (Authoritative)

This document defines **user roles**, their **intent**, and **allowed behavior**
within BOS (Brookstone Operating System).

Roles control **what a user may do**.
Identity and business data live on Users and Profiles, not roles.

---

## Role Inventory

machine_name | Label
---|---
anonymous | Anonymous User
authenticated | Authenticated User
administrator | Administrator
site_admin | Site Admin
site_assistant | Site Assistant
administration | Office Admin
supervisor | Supervisor
teammates | Teammates
client | Client
user | Website User

---

## Core Role Definitions

### anonymous — Anonymous User

Purpose:
- Public, unauthenticated visitors.

Allowed:
- View public website pages only.

Must NOT:
- Access BOS
- View or modify any internal data

---

### authenticated — Authenticated User

Purpose:
- Base role for all logged-in users.

Notes:
- This role alone grants **no operational permissions**.
- All BOS access depends on additional roles.

---

### user — Website User

Purpose:
- Authenticated public users without BOS access.
- Often used for marketing, gated content, or future expansion.

Allowed:
- Log in
- View limited non-operational pages

Must NOT:
- Access Contracts, Work Orders, Properties, or BOS tools

---

## BOS Operational Roles

### client — Client

Purpose:
- Represents a customer account holder.

Typically paired with:
- customer_profile

Allowed:
- View own Contracts (read-only)
- View own Properties (read-only)
- View contract status and summaries

Must NOT:
- Create or edit Work Orders
- Edit Contract Sections
- View internal costing, materials, chemicals, or equipment
- Override scheduling, billing, or status rules

---

### teammates — Teammates

Purpose:
- General crew members.

Typically paired with:
- teammate_profile

Allowed:
- View assigned Work Orders
- Add execution data:
  - time entries
  - materials used
  - chemicals used
  - notes
- Update task/checklist items

Must NOT:
- Create Contracts
- Modify Contract Sections
- Change Contract Status
- View billing totals or internal costing
- Edit completed Work Orders

---

### supervisor — Supervisor

Purpose:
- Crew leaders and field supervisors.

Allowed:
- Everything Teammates can do
- Assign or reassign Work Orders
- Update Work Order status (pre-completion)
- Review execution data

Must NOT:
- Override completed Work Orders
- Change Contract intent
- Bypass credit hold / do-not-schedule rules

---

### administration — Office Admin

Purpose:
- Office staff managing contracts, scheduling, and billing prep.

Allowed:
- Create and edit Contracts
- Manage Contract Sections
- Change Contract Status
- Create and schedule Work Orders
- Assign crews and supervisors
- View billing totals and estimates

Must NOT:
- Override completed Work Orders without admin authority
- Change system configuration

---

### site_assistant — Site Assistant

Purpose:
- Limited administrative helper role.

Allowed:
- Assist Office Admin tasks
- Edit drafts and in-progress records
- View internal data as assigned

Restrictions:
- Permissions should be explicitly scoped.
- Must not have broad override powers.

---

## System Administration Roles

### site_admin — Site Admin

Purpose:
- Senior system operator for BOS.

Allowed:
- Full BOS access
- Override completed records when necessary
- Manage roles and permissions
- Manage Services, Statuses, and configuration entities

Use sparingly.

---

### administrator — Administrator

Purpose:
- Drupal superuser role.

Allowed:
- Full Drupal system access.

Notes:
- This role bypasses normal permission checks.
- Should be limited to trusted technical administrators.
- Not intended for day-to-day BOS operations.

---

## Role Governance Rules (Non-Negotiable)

- Roles control **permissions**, not identity.
- Profiles do not grant permissions.
- Clients must never gain execution or billing permissions.
- Crew roles must never see billing or pricing internals.
- Completed Work Orders are immutable except by Admin roles.
- Credit hold and do-not-schedule flags override role permissions.

---

## Enforcement Expectations

- Permissions must be enforced via:
  - Drupal permissions
  - Validation logic
  - Workflow checks

- UI visibility alone is not sufficient enforcement.

---

## Invariants

- A user may have multiple roles.
- Roles must align with BOS workflow intent.
- Permission creep must be avoided.
- Any new role must be documented here before use.
