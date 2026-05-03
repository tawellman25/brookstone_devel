# BOS Drupal Gotchas

Cross-cutting reference for Drupal and BOS-specific constraints that bite when not anticipated. Apprentice day-one reading. Anyone modifying BOS custom modules, field configurations, or running Drush commands should know what's here.

This document captures hard-learned lessons. Most entries cite a specific commit or session where the gotcha was discovered, so future readers can dig into git history for the full context.

---

## Drupal field and bundle naming limits

### 32-character maximum on field machine names

Drupal core enforces a hard 32-character limit on field machine names. Validation fires at `field_storage_config` save time; cim aborts mid-import with `Attempt to create a field storage with an name longer than 32 characters` and leaves partial state behind.

Spec field names with this limit in mind. Examples:

- `field_closed_signoff_complete` (29 chars) ✓
- `field_closed_by_signoff_complete` (32 chars — at the limit, marginal) ✓
- `field_created_by_signoff_complete` (33 chars — REJECTED) ✗

**Surfaced during Phase 2a, commit `d58b9a7f`.** The original spec used `field_*_by_signoff_*` names; cim hit the 32-char ceiling on `field_created_by_signoff_complete` and forced a rename to drop "by_" across all four fields. Recovery required deleting partial-imported orphan field configs from the active DB before re-running cim.

### 32-character maximum on bundle machine names

Same constraint applies to bundle names (verify before creating long bundle names like `seasonal_lawn_treatment_application`).

### Help text and labels are not constrained

Natural-language wording stays natural. Only machine names lose words. The audit fields above kept their human labels: "Closed by sign-off (complete info)" — full English in the label even though the machine name had to drop "by".

---

## ECK quirks

### Bundle export produces an empty-string config

Every new ECK bundle export produces an `eck.eck_entity_type.{type}.yml` with a stray empty string in the bundles array that must be manually fixed in the YAML before deploying. CLAUDE.md "ECK Config File Conventions" documents the right pattern; the bug is in ECK's exporter.

### Field instance configs sometimes silently skip on cim

`drush cim` occasionally appears to succeed but silently fails to create or update specific field instance configs. The result: form/view display configs that depend on the missing instances either silently break (display configs reference non-existent fields) or generate dependency-validation errors on the next cim cycle.

Documented BOS workaround:

1. Create the field instance directly via `field_config` entity storage in PHP (`drush php-eval`)
2. After save, the active config has a UUID — patch that UUID into the sync-dir YAML
3. Future cim cycles see no drift; the field is properly tracked

If you skip step 2, the sync dir lacks a UUID while active has one — perpetual drift. See [UUID drift between environments](../../CLAUDE.md#uuid-drift-between-environments).

**Surfaced during Phase 0.5 + Phase 2a.** Both required the workaround for different field configs.

### Stale `entity.definitions.installed` KV-store entries

When a partial cim leaves field instance configs in active DB without their corresponding storage configs, Drupal's `entity.definitions.installed` key/value store retains "phantom" storage definition entries that block subsequent operations (e.g., entity deletes throw `Base table or view not found` because Drupal tries to drop from non-existent storage tables).

Resolution: edit the KV store directly to remove the phantom entries:

```php
$kv = \Drupal::keyValue('entity.definitions.installed');
$installed = $kv->get('ENTITY_TYPE.field_storage_definitions', []);
unset($installed['field_phantom_name']);
$kv->set('ENTITY_TYPE.field_storage_definitions', $installed);
```

Then `drush cr`.

**Surfaced during Phase 2a recovery, commit `d58b9a7f`.**

---

## UUID drift between environments

Config-entity UUIDs are environment-local in BOS. When a field instance, view, or other config entity is created in one environment (local DDEV vs live), it gets a UUID generated locally. That UUID does not propagate to other environments — each environment generates its own when the config is created there independently.

Implications:

- **Sync-dir YAMLs SHOULD include the local UUID** for consistency across `drush cim` cycles. Missing UUID in sync triggers unstable diffs (sync vs active perpetually look "different" because active has a UUID and sync doesn't).
- **The same field on local vs live will have different UUIDs.** This is fine — UUIDs don't affect functionality. Code references config entities by name (`config_pages.business_setting`), never by UUID.
- **When a field is created via the cim silent-skip workaround**, Drupal generates a UUID at save time. Patch that UUID back into the sync YAML so future cim cycles produce clean diffs.
- **Apprentices cloning the repo** will adopt the committed UUIDs when cim runs against their fresh DDEV — that's good for dev consistency. Live retains its own pre-existing UUIDs because cim doesn't modify existing entities' UUIDs.

The UUID-stripping bug in BOS field-instance configs (above) is the recurring cause of UUID drift. Always verify sync YAMLs have a `uuid:` line before committing field configs.

---

## Contrib module patches

`contrib/` is excluded from the rsync deploy. Any patches applied to contrib modules (e.g., `form_mode_control`, `views_bulk_operations` — both documented in [CLAUDE.md → Patched Contrib Modules](../../CLAUDE.md#patched-contrib-modules)) **must be re-applied manually on live after any `composer update` or `composer install`**.

Failing to re-apply patches results in latent bugs that may not surface immediately but cause incidents weeks later when the affected code path executes. Consider adding patch re-application to a deploy checklist or post-deploy script.

---

## Live production constraints

### `drush sqlq` and `drush sqlc` are broken on live

cPanel/MariaDB driver issue. Use direct `drush eval` with PHP database queries instead:

```bash
ddev drush eval '\Drupal::database()->query("SELECT ...")->fetchAll();'
```

### Live MariaDB session timezone is MST (no DST)

PHP timezone is `America/Denver` (which DOES observe DST). The mismatch can produce off-by-one-hour bugs when comparing timestamps or formatting dates in raw SQL.

**Always use `date_default_timezone_get()`** rather than hardcoded timezone strings. Use Drupal's `\Drupal\Core\Datetime\DrupalDateTime` for any time math; it respects the configured site timezone correctly.

### Drush command prefix differs

- Local: `ddev drush ...`
- Live: `drush ...` (no `ddev` prefix; running from the Drupal root via SSH)

When writing scripts or notes that reference Drush commands, be explicit about which environment they target.

---

## WSL / DDEV development environment

### Heredoc syntax fails in WSL → DDEV `php:eval`

Multi-line PHP via heredoc piped through `ddev drush php:eval` breaks in WSL due to shell escaping interactions. Workaround:

1. Write the PHP script to a file inside the container: `/var/www/html/scratch.php` or similar
2. Run with `ddev drush php:script /var/www/html/scratch.php`

For one-off short scripts, single-line PHP via `drush eval` works reliably:

```bash
ddev drush eval 'foreach ([1,2,3] as $i) print $i . PHP_EOL;'
```

### Arrow functions can fail in inline `php:eval`

`fn($x) => $x + 1` style sometimes parses incorrectly in the eval context. Use standard anonymous functions (`function($x) { return $x + 1; }`) for reliability in inline scripts.

### Common drush eval pitfall: superuser bypass

`\Drupal::currentUser()` defaults to anonymous in `drush eval`. If you need to test as a specific user, switch via `\Drupal::service('account_switcher')->switchTo($user)`.

Do NOT use `uid=1` for "teammate" testing — uid=1 has `is_admin: true` (Drupal's superuser flag) and bypasses ALL permission checks regardless of role. To test as a real teammate, find a non-uid-1 user with the `teammates` role:

```bash
ddev drush eval '
$ids = \Drupal::entityTypeManager()->getStorage("user")->getQuery()
  ->accessCheck(FALSE)
  ->condition("status", 1)
  ->condition("roles", "teammates")
  ->condition("uid", 1, "<>")
  ->range(0, 1)->execute();
echo reset($ids);
'
```

**Surfaced during Phase 1 testing.** First test pass used uid=1 (Todd Wellman) which has teammate role assigned; tests passed but didn't actually exercise the permission-denial path.

---

## Config sync hygiene

### Permanent diff: YAML normalizer key reordering

Drupal's YAML normalizer reorders keys differently than how some configs are saved to disk. Certain files show "permanent diff" on `drush cim` — re-running cim is a no-op (data is identical) but the file appears as "Different" indefinitely.

Examples seen in BOS:

- `field.storage.material_price_history.field_supplier_item_number` — `case_sensitive` and `is_ascii` reorder
- `user.role.administration` and `user.role.site_admin` — single permission line reorders within the permissions list
- `views.view.material_price_review_queue` — pager option key reordering

**Don't try to clear these via blanket `drush cex`.** That would also overwrite manually-synced live config (per [`feedback_no_drush_cex.md`](../memory/feedback_no_drush_cex.md) project memory). If the diff bothers you, do a one-time targeted `drush cex` for the specific file, but only after confirming no other local-only changes are pending.

### Field defaults fire on CREATE only, not UPDATE

Field-level `default_date: 'now'` configs (and similar default value callbacks) fire when an entity is first saved but NOT on subsequent updates. Verified in Pre-Phase-2 work: setting `field_end_time = NULL` on an existing wo_time_clock entry and saving produces a NULL value (the default doesn't re-fire); creating a new entity without specifying field_end_time triggers the default.

Implication for code creating entities: if you want NULL to mean something semantic (e.g., "open clock-in"), explicitly pass `field_end_time => NULL` in the `create()` array to override the default.

**Surfaced during Pre-Phase-2, commit `e23a1153`.** Fixed `wo_timer_flag_update_flagging_insert` to explicitly pass NULL.

---

## `wo_time_clock` open-clock-in semantic (post-Pre-Phase-2)

`field_end_time IS NULL` reliably means "open clock-in" as of commit `e23a1153` (May 2026).

Prior to that commit, the flag-based clock-in path produced `start == end + total = 0` entries that masked active clock-ins. Operational consumers like `AnomalyDetectionService::open_stale` and the `teammate_wo_clocked_in_not_complet` view query couldn't see the actual orphan count.

Future code reading `wo_time_clock` for orphan detection should use:

```php
$storage->getQuery()->accessCheck(FALSE)->notExists('field_end_time')->execute();
```

The `work_order_timer` flag entity is also a valid source of truth for "currently clocked in" — but the flag is deleted on clock-out, so it's good for "right now" but not historical reconstruction. `field_end_time IS NULL` is the durable signal.

See [wo_timer_flag_update.md](../Modules/wo_timer_flag_update.md) for the full lifecycle.

---

## `wo_time_clock` lifecycle: where end_time gets written

Multiple code paths can write `field_end_time` on a `wo_time_clock` entry. Future contributors adding new write paths should be aware of all existing ones to avoid clobbering or coordination issues:

1. **Clock-out via flag delete** ([`wo_timer_flag_update_flagging_delete`](../Modules/wo_timer_flag_update.md)) — the standard path. Defensive: skips if `field_end_time` is already populated (commit `92c9484f`).
2. **Phase 2 sign-off reconciliation** ([`_wo_sign_off_reconciliation_submit`](../Modules/wo_sign_off.md)) — closes orphans during sign-off. Sets `_signoff_reconciliation = TRUE` to bypass Phase 1 guard 4.
3. **Manual office cleanup** — admin users editing entries via the form. Phase 1 guards apply; site_admin and administrator bypass via `'administer eck entities'`.
4. **Phase 1 form-layer validation** ([`wo_total_time.module`](../Modules/wo_total_time.md)) — rejects bad end_time values BEFORE save, doesn't write itself.

Any new write path must respect the same invariants: don't clobber populated values without intent, set `_signoff_reconciliation` if the operation is part of a coordinated reconciliation flow, and be aware that downstream consumers (variance dashboards, billing computations, audit fields) read whatever's there.

---

## Phase 1 guards on `wo_time_clock` saves

Phase 1 (commit `d3eb4771`) added five hard presave guards that throw `EntityStorageException` to prevent the anomaly categories detected by `AnomalyDetectionService`:

1. End time before start time
2. Start time in future (5-min grace)
3. End time in future (5-min grace)
4. Parent WO is Invoiced (1281) or Paid (1504), update only — bypass via `'administer eck entities'` permission OR `_signoff_reconciliation` context flag
5. Parent WO is Canceled (1098), insert + update — bypass via `'administer eck entities'` only (NOT `_signoff_reconciliation`)

Programmatic code creating or modifying `wo_time_clock` entries needs to know:

- Operations on closed/canceled WOs require admin context OR the reconciliation flag
- Future-dated time entries are rejected unconditionally (no admin bypass — clock skew alone justifies the guards)
- Form-layer validation on the wo_time_clock add/edit form mirrors guards 1-3 with friendly inline error messages plus a soft >16-hour confirmation prompt

See [wo_total_time.md](../Modules/wo_total_time.md) for the full guard table and bypass semantics.

---

## Anti-patterns to avoid

### Don't use `appendItem()` on single-value fields

Calling `$entity->get('field_X')->appendItem('value')` on a single-value field (cardinality 1) does NOT append. Behavior varies by Drupal version: silent clobber, internal append that drops on save, or throw. Always:

- For multi-value fields: `appendItem` works as documented
- For single-value fields: read existing value, concatenate, set explicitly:

```php
$existing = $entity->get('field_notes')->value ?? '';
$appended = trim($existing . "\n" . 'New note');
$entity->set('field_notes', $appended);
```

**Surfaced during Phase 2c-prep, commit `92c9484f`.** Every flag-driven clock-out from project inception until that commit had been silently corrupting `field_notes` audit trails.

### Don't use `drush cex` blindly

The project memory file `feedback_no_drush_cex.md` is explicit: blanket `drush cex` overwrites manually-synced live config. If you genuinely need to capture some active-config state to the sync dir, run a TARGETED export for the specific config item, after confirming no other local changes are pending.

### Don't use `--no-verify` on git commits

CLAUDE.md project rules: never skip hooks, never bypass signing. If a hook fails, investigate and fix the underlying issue rather than working around it.

### Don't reference deleted-but-still-cached fields

When you delete a field via `drush cim`, Drupal's entity field manager may continue to cache the field definition until the next cache clear. After any field config delete, run `drush cr` before any operation that reads the entity's field definitions.

---

## Status

- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Maintained as living document — append new gotchas as they're discovered, with the surfacing commit cited.
