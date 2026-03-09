# Contract Section Audit Log (contract_sections_audit)

Purpose: Append-only, system-generated audit trail for **Contract Sections** changes. Tracks **who** changed a section, **when**, and **what** changed (field-level list), plus section bundle context.

---

## Entity Type

- **ECK Entity Type (machine name):** `contract_sections_audit`
- **Label (admin):** Contract - Sections - Audit Log
- **Enabled:** `status: false`
- **Base fields enabled (ECK entity type flags):**
  - `uid: true` (Author = who made the change)
  - `created: true` (Created = when the change happened)
  - `changed: true` (when the log row itself was last touched; should not change in normal use)
  - `title: true`

> **Truth sources**
> - **Who:** base field `uid` (Author/Owner)
> - **When:** base field `created`
> - Log rows are **not edited**; they should be treated as **immutable**.

---

## Bundle

- **Bundle (machine name):** `log`
- **Bundle label:** Log
- **Bundle description:** System-generated, append-only audit entries for Contract Section changes. Not created or edited manually.

---

## Fields (Bundle: log)

> Keep these fields **single-value**.

1) **Contract Section Reference**
- **Machine name:** `field_contract_section`
- **Type:** Entity reference
- **Target entity type:** `contract_sections`
- **Required:** Yes
- **Meaning:** The Contract Section entity that was created/updated/deleted.

2) **Action**
- **Machine name:** `field_action`
- **Type:** List (text)
- **Allowed values (recommended):**
  - `insert`
  - `update`
  - `delete`
- **Required:** Yes
- **Meaning:** What happened to the referenced Contract Section.

3) **Section Bundle Snapshot**
- **Machine name:** `field_section_bundle`
- **Type:** Text (plain)
- **Required:** Yes (recommended)
- **Meaning:** Snapshot of `$section->bundle()` at the moment the log entry was created.

4) **Changed Fields**
- **Machine name:** `field_changed_fields`
- **Type:** Long text (plain)
- **Required:** No (recommended Yes for update events)
- **Storage format:** JSON array of field machine names, e.g.
  - `["field_rate","field_notes","field_service_date"]`
- **Meaning:** Which fields changed during an update. For insert/delete, can be `[]` or omitted.

---

## Invariants / Rules

- Log entries are **append-only** (no manual create/edit/delete).
- Log entries are created **only** by code on Contract Section lifecycle events.
- Logs must never be edited to “explain” changes after the fact (no note field).
- Log entity should not use title/label fields; labels are derived from base id for admin readability.

---

## Permissions / Access

Recommended permissions for entity type `contract_sections_audit`:

- **Create:** none (no roles)
- **Edit:** none (no roles)
- **Delete:** none (no roles)
- **View:** Admin / Office roles only (as needed)

System code can still create logs regardless of UI permissions.

---

## Module Behavior (contract_sections_audit)

Module writes a log row when any `contract_sections` entity is:
- inserted → action `insert`
- updated → action `update` + changed field list (best-effort)
- deleted → action `delete`

### Log creation data mapping

For each log row:
- `type` = `log`
- `uid` (owner/author) = current user id
- `created` = time of save/delete event
- `field_contract_section` = Contract Section id
- `field_action` = insert|update|delete
- `field_section_bundle` = section bundle machine name at time of event
- `field_changed_fields` = JSON list of changed field machine names (update only)

---

## Implementation Notes

### Why logs do NOT have title
ECK log entity types should have `title: false`. Title/label automation (e.g., auto_entitylabel) must be disabled/excluded for this entity type/bundle.

### Contract Sections labels
Contract Sections should have labels/titles enabled to avoid Drupal UI issues (entity reference widgets, selection lists, Views). This is separate from the audit log entity type.

---

## Operational Checks

### Confirm entity type config
- `eck.eck_entity_type.contract_sections_audit` should show:
  - `status: false`
  - `uid: true`
  - `created: true`
  - `changed: true`
  - `title: true`

### Smoke test (manual)
- Edit any Contract Section and save.
- Confirm a new Log row exists:
  - Author = editor
  - Created = now
  - Action = update
  - Section Bundle set
  - Changed Fields JSON set (if using diff logic)

---

## Reporting / Views

Recommended View: “Contract Section Change Log”
- Sort: Created DESC
- Filters:
  - Action (optional)
  - Section Bundle (optional)
  - Author (optional)
- Display fields:
  - Created
  - Author
  - Action
  - Section Bundle
  - Contract Section (linked)
  - Changed Fields

---

## Machine Name Reference

- Audit entity type: `contract_sections_audit`
- Audit bundle: `log`
- Fields:
  - `field_contract_section`
  - `field_action`
  - `field_section_bundle`
  - `field_changed_fields`
