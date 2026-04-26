# BOS Entities — Policy (Cross-Cutting Rules)

This file defines rules that apply to all BOS entities.
Entity files must follow these rules.

---

## Documentation Rules

- Every entity file must include:
  - Entity Type ID (machine name)
  - Bundle(s)
  - Purpose (1-3 bullets)
  - Required Relationships (reference fields that must exist)
  - Key Fields (global fields that drive behavior)
  - Invariants (must/never rules)
  - Deletion/Archival stance
  - Integration fields (external IDs if applicable)

- Do not list every field. Only fields that define:
  - Relationships
  - Workflow behavior
  - Access/security behavior
  - Integration behavior

- Keep each entity file short and authoritative.

---

## Data Model Rules

### Relationships
- All core relationships must be explicit entity reference fields.
- Avoid storing duplicated copies of related data (derive or reference instead).
- Relationships must have a documented “source of truth”.

### Ownership
- Ownership rules must be explicit:
  - Who “owns” the record
  - Who can edit it
  - Who can view it
- Do not infer ownership from names or paths.

### States / Status
- If an entity has a lifecycle, document:
  - allowed states
  - allowed transitions
  - what becomes immutable and when

### Auditing
- Operational history must be preservable:
  - prefer append-only notes/logs over overwriting history
  - record “who/when” for key events where possible

---

## Deletion / Archival Rules

- Default stance: do not delete operational history.
- Prefer:
  - “Archived” status
  - “Inactive” flags
  - retention rules
- If deletion is allowed:
  - it must be role-restricted
  - it must be documented
  - it must handle dependencies explicitly (no surprise cascades)

---

## Integration Rules

- Accounting (e.g., QuickBooks) is downstream from BOS operational truth.
- External IDs may be stored for mapping.
- External systems must not become the logic authority for BOS workflows.

---

## Naming Rules (Entity Docs)

- Use the entity type ID as the file’s primary identifier.
- Use BOS language:
  - Refer to the platform as “BOS”
  - Avoid “ERP” outside technical/admin docs

---

## ECK Config File Conventions (Authoritative)

When creating a new ECK entity type or bundle, **always** follow the file-naming pattern below. The ECK module supports two patterns; BOS uses the older one across the board.

### Use this pattern (BOS standard)

| Config kind | File path |
|---|---|
| Entity type | `config/sync/eck.eck_entity_type.{entity_type}.yml` |
| Bundle | `config/sync/eck.eck_type.{entity_type}.{bundle}.yml` |
| Field storage | `config/sync/field.storage.{entity_type}.{field_name}.yml` |
| Field instance | `config/sync/field.field.{entity_type}.{bundle}.{field_name}.yml` |
| Form display | `config/sync/core.entity_form_display.{entity_type}.{bundle}.{mode}.yml` |
| View display | `config/sync/core.entity_view_display.{entity_type}.{bundle}.{mode}.yml` |

The bundle config name is `eck.eck_type.{entity_type}.{bundle}` — note the `eck_type` (not `eck_entity_bundle`). Field instance dependency blocks must reference the bundle as `eck.eck_type.{entity_type}.{bundle}` for the import to resolve.

### Do NOT use the alternative pattern

There is a newer ECK 5.x pattern using `eck.eck_entity_bundle.{name}.yml` for bundles. **It has a recurring bug:** `drush cex` exports the dependency block with `eck.eck_entity_type.` (empty string) instead of the proper entity type ID, requiring manual repair on every export. Do not introduce this pattern. The 7 existing bundles using it (`business_calendar`, `equipment_defect`, `equipment_inspection`, `equipment_maintenance_event`, `estimate`, `estimate_notes`, `sop_log`) predate this rule — leave them alone but do not extend the pattern.

### When introducing a brand-new ECK entity type

1. Create the entity type config with `id`, `label`, and base field flags (`uid`, `created`, `changed`, `title`, `status`).
2. Create one bundle config per bundle, with `name: <Display Label>`, `type: <bundle_machine_name>`, and `dependencies.config: [eck.eck_entity_type.<entity_type>]`.
3. Create field storage configs with `entity_type: <entity_type>` and the right module dependency (`eck` baseline; add `user`, `datetime`, or `options` per field type).
4. Create field instance configs with `bundle: <bundle_machine_name>` and dependencies `[eck.eck_type.<entity_type>.<bundle>, field.storage.<entity_type>.<field_name>]`.
5. Create form/view displays last (they depend on every field instance).
6. Add ECK permissions to relevant `user.role.*.yml` files alphabetically:
   - `create <entity_type> entities` and `create <entity_type> entities of bundle <bundle>`
   - `edit any/own <entity_type> entities` and `... of bundle <bundle>`
   - `view any/own <entity_type> entities` and `... of bundle <bundle>` (where applicable)
   - **Do NOT add `delete` permissions** unless the entity is explicitly designed for deletion. BOS default is no delete on operational/audit entities.
7. Add the bundle config dependency `eck.eck_type.<entity_type>.<bundle>` to each role's `dependencies.config` block (alphabetical order).
8. Run `drush cim -y` and `drush cr` and verify with `drush ev "echo \Drupal::entityTypeManager()->getDefinition('<entity_type>')->id();"`.

