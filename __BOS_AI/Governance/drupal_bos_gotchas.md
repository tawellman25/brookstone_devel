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

### `drush en` followed by `drush cim` re-disables the module

`drush en bos_wex_import -y` enables a module in active config but does not modify the sync directory. The next `drush cim` reads `config/sync/core.extension.yml` as the source of truth, finds the module absent from the enabled list, and disables it.

Symptom: module installs cleanly via `drush en`, works during the same session, then mysteriously becomes disabled after the next cim cycle (e.g., from a separate config change).

Fix: after `drush en`, immediately update `config/sync/core.extension.yml` to include the new module — then commit both the module files AND the core.extension.yml change in the same commit. Subsequent cim cycles preserve the enabled state.

```yaml
# core.extension.yml fragment after enabling bos_wex_import:
module:
  ...
  bos_user_time_clock_mapping: 0
  bos_wex_import: 0      # ← added
  breakpoint: 0
```

**Surfaced during bos_wex_import enablement, May 2026.** Module disappeared between Phase 12 verification and the smoke test that followed, traced to an intervening cim that didn't include the module in sync.

### ECK + pathauto: enabled_entity_types registration is mandatory

ECK entities don't get a `path` base field automatically. Pathauto's `EntityAliasTypeDeriver` requires entity types to have a `path` base field, AND that field is added by `pathauto_entity_base_field_info()` only when the entity type is listed in `pathauto.settings.yml → enabled_entity_types`.

Symptoms when this is missed:
- `pathauto.pattern.{entity}.yml` is imported and looks valid
- `\Drupal::entityTypeManager()->getStorage('pathauto_pattern')->load('{entity}')` returns the pattern with status TRUE
- Token replacement on the pattern returns the correct path string when called manually
- BUT no alias gets generated when entities save — `pathauto.generator->updateEntityAlias()` returns NULL silently
- AND `\Drupal::service('plugin.manager.alias_type')->getDefinitions()` does NOT contain `canonical_entities:{entity}`

Fix: add the new entity type ID to `pathauto.settings.yml`:

```yaml
enabled_entity_types:
  - profile
  - user
  - equipment
  - equipment_check_in_out
  - equipment_fuel_transaction   # ← new entry
  ...
```

Then cim. The path base field gets attached, the alias type plugin appears in the deriver, and pattern matching starts working.

Existing BOS reference: only 3 of the 5+ ECK child entities of `equipment` are pathauto-registered (`equipment`, `equipment_check_in_out`, `equipment_status_update`). `equipment_inspection`, `equipment_defect`, `equipment_maintenance_event` are NOT registered and therefore generate canonical (`/equipment_inspection/{id}`) URLs only — that's an existing accepted state, not a bug.

**Surfaced during equipment_fuel_transaction build, May 2026.** Cost about 30 minutes of debugging because all the diagnostic surfaces lied: pattern was loaded, route was registered, token replacement worked. The plugin manager's silent omission was the giveaway.

### list_string field storage must use `module: options`, not `module: core`

When generating field storage YAMLs programmatically, easy to default `module: core` for all field types. For `list_string` fields specifically, this breaks Views integration silently:

- `module: core` → `getTypeProvider()` returns `core` → Drupal looks for `core_field_views_data()` (doesn't exist) → falls back to default views_data → filter handler is `string`
- `module: options` → `getTypeProvider()` returns `options` → `options_field_views_data()` fires → filter handler is upgraded to `list_field`

Symptom: a Views filter configured with `plugin_id: list_field` and a non-empty `value` array silently doesn't filter at all. The filter handler that ends up wired is `string` (or `views_autocomplete_filters`-extended string in BOS), which doesn't accept array values for `or`/`and` operators in non-exposed mode.

Fix: ensure list_string field storage YAML has:

```yaml
module: options
```

…not `module: core`. Same applies to `list_integer` and `list_float`.

Diagnostic: query `\Drupal::service('views.views_data')->get('TABLE_NAME')` for the field's `_value` column. If `filter.id` is `string` instead of `list_field`, the field storage's `module` key is wrong.

**Surfaced during equipment_fuel_transaction Views build, commit `5120b90f`.** Diagnosis took ~6 cycles before tracing back to the field storage YAML — the symptom was "filter not in WHERE clause" but the root cause was 4 layers deep.

### Drupal local tasks need at least 2 entries on a base_route

A single local task definition pointing at a `base_route` does NOT render a tab bar. Drupal core's local task block silently suppresses the UI when only one task exists for the base route — the assumption being "if there's just one tab, why show tabs?"

Fix: when adding a tab to an existing page (typically a Views page), define BOTH the new tab AND a "default" task pointing back at the base route itself:

```yaml
mymodule.master_list:
  title: 'Master List'
  route_name: view.my_view.page_1
  base_route: view.my_view.page_1
  weight: 0

mymodule.import:
  title: 'Import'
  route_name: mymodule.import_form
  base_route: view.my_view.page_1
  weight: 10
```

Both must be present; without the master_list "default tab", the tab bar doesn't appear at all.

**Surfaced during bos_wex_import build, May 2026.** Verified via `\Drupal::service('plugin.manager.menu.local_task')->getLocalTasksForRoute(...)` — single-tab returned 0 tasks, two-tab returned both.

### FormBase + constructor-promoted readonly properties don't survive form serialization

Drupal serializes form objects in `form_state` across request stages — most commonly between `managed_file` upload and form submit. When deserialized, services injected via PHP 8 constructor-promoted readonly properties remain uninitialized:

```
TypeError: Typed property must not be accessed before initialization
```

The cause: `FormBase` uses `DependencySerializationTrait`, which tracks service properties via a `$_serviceIds` array populated during `__sleep()`. Constructor-promoted promoted properties are NOT visible to that tracking mechanism, so `__wakeup()` doesn't restore them — and the constructor isn't re-invoked when unserializing.

Fix options for `FormBase` children that need services:

1. **Lazy `\Drupal::service()` calls** inside form methods (simplest):

   ```php
   public function submitForm(array &$form, FormStateInterface $form_state): void {
     $importService = \Drupal::service('bos_wex_import.import_service');
     // ...
   }
   ```

2. **Traditional protected properties + `create()` factory** (DependencySerializationTrait handles them automatically):

   ```php
   protected ImportService $importService;
   public static function create(ContainerInterface $container): self {
     $form = new self();
     $form->importService = $container->get('bos_wex_import.import_service');
     return $form;
   }
   ```

DO NOT use constructor-promoted properties for service dependencies on `FormBase` children. Works for `ControllerBase` (controllers aren't serialized) but breaks across the buildForm → submitForm boundary on forms.

**Surfaced during bos_wex_import field test, commit `114afa70`.** First import attempt crashed mid-submit; the constructor had run on initial form build but the form was reconstructed via `__wakeup` during the file-upload AJAX cycle.

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

## `wo_time_clock` dual-field attribution: `uid` vs `field_teammate`

Two different user-reference fields on `wo_time_clock:entry` track attribution, and **different consumers query different fields**:

| Field | Purpose | Consumed by |
|---|---|---|
| `uid` (base field) | Entity owner — who entered the data | `views.view.wo_time_clock_entries` (display grouping/header), Drupal entity access checks, `EntityOwnerInterface` consumers |
| `field_teammate` (custom field) | Operational attribution — who the entry is *for* | Phase 2 sign-off reconciliation, `AnomalyDetectionService::*ForUser` queries, variance dashboard per-teammate breakdowns, `wo_sign_off`'s billing math (counts crew members via this field) |

These two fields agree by convention but can drift in practice. The drift was operationally invisible until Phase 2 sign-off reconciliation surfaced it — entries with `uid` populated and `field_teammate` empty appeared in display views (using `uid`) but invisible to per-teammate queries (using `field_teammate`).

`wo_total_time` has two presave auto-syncs to keep these consistent:

- **Forward sync** (POST-only): when `field_teammate` is set and differs from `uid`, copy `field_teammate` → `uid`. Use case: office staff manually enters on behalf of a teammate; the entity should be owned by the teammate, not the data entrant.
- **Reverse sync** (all save paths): when `field_teammate` is empty and `uid` is non-anonymous, copy `uid` → `field_teammate`. Use case: a teammate manually enters their own time and doesn't notice the `field_teammate` field; the entity stays consistent without requiring the user to know about both fields.

Both syncs are mutually exclusive — only one fires per save (forward gates on `!isEmpty`; reverse gates on `isEmpty`).

**When extending wo_time_clock with new fields or save paths:** maintain this attribution invariant. Any new writer that sets `uid` should also set `field_teammate` (or rely on the reverse sync to fill it).

**When querying wo_time_clock for "what entries does this user have":** prefer `field_teammate` for operational attribution; use `uid` only when you specifically mean "who entered the data."

**Surfaced 2026-05-02 during Phase 2 live use.** Reverse sync added in the same commit. See [wo_total_time.md](../Modules/wo_total_time.md) for implementation detail and [deferred_work.md item 16](deferred_work.md#16-pre-boundary-wo_time_clock-dual-field-drift-backfill-deferred) for the historical-entries scope question.

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

## `$entity->original` is not populated on update in this Drupal version

During `hook_entity_update` / `hook_ENTITY_TYPE_update` on this build, `$entity->original` is **NOT set** and there is **no `$entity->getOriginal()` method** (verified 2026-05-16 on the `scheduling` entity: `property_exists($entity, 'original')` → `false`, `method_exists($entity, 'getOriginal')` → `false`, `$entity->original` → `NULL`).

Implication: any change-detection code that reads `$entity->original` to compare old vs new field values **silently never fires its old-vs-new branch**. `wo_schedule`'s status-transition logic (`_wo_schedule_handle_status_update`, the `$changedDate` / `$origAssigned` branches) uses this pattern and is therefore latently broken — its reschedule/reassign status branches don't run. Not yet fixed (needs its own diagnostic); flagged so it isn't mistaken for a regression.

**Correct pattern when you need pre-save values:** capture them in **presave** via `\Drupal::entityTypeManager()->getStorage($type)->loadUnchanged($entity->id())` (presave still sees the pre-write DB row), compare to the in-memory `$entity`, stash what you need on the entity for the post-save hook. Used by `wo_schedule`'s schedule-change WO-note logging.

**Surfaced 2026-05-16 building the schedule-change WO note (commit `59c16c2c`).**

## Form rebuild: `getValues()` is empty at build-time

When a validate/submit handler calls `$form_state->setRebuild(TRUE)`, the form is reconstructed (form_alter, `#process`, element builders all run again) **before** the rebuilt form is re-processed. At that build-time point `$form_state->getValues()` is **not yet repopulated**. Code that builds form structure conditionally on submitted values sees nothing on the rebuild — even though the same values were fully available to the validate handler that triggered the rebuild.

This bit the `wo_sign_off` reconciliation fieldset repeatedly: per-row time fields were built in form_alter from `getValues()`, so on the Add wo_complete_info form they never rendered after Save (three fix attempts chased the wo_id/roster *source* before the *timing* was identified as the real cause).

**Pattern that works:** resolve the data in the **validate handler** (runs post-process, `getValues()` fully populated), `$form_state->set('_some_ctx', [...])` it, and have the build-time code read that stash first, falling back to value/entity derivation only when there's no stash (initial GET). Clear the stash when inputs become invalid so a rebuild can't resurrect stale structure. Implemented in `_wo_sign_off_reconciliation_validate` / `_wo_sign_off_build_reconciliation_fieldset`. `getUserInput()` is the other build-time source but its raw nested-by-`#parents` structure is fragile — prefer the validate-handler stash.

**Surfaced 2026-05-16 fixing the Add-form sign-off reconciliation (commits `3e3ba64b`, `235707d9`).**

## Byte-based `substr` on user-entered strings breaks `json_encode` for the whole response

`strlen()` and `substr()` count bytes, not characters. When PHP code truncates user-entered text (property nicknames, names, notes) at a byte offset that falls **inside** a multi-byte UTF-8 character (`–` en-dash, `—` em-dash, `ñ`, `é`, `…`), the result contains an orphaned partial sequence. `json_encode` then **rejects the entire array** as malformed UTF-8 (`json_last_error_msg()` → "Malformed UTF-8 characters, possibly incorrectly encoded") and Symfony's `JsonResponse` throws on construction. One bad row blanks the whole feed.

Hit 2026-05-20 in `AdminCalendarEventsController`: property-nickname truncation `substr($name, 0, 21) . '…'` cut into the middle of "Ambulance District – Eckert"'s en-dash (U+2013, 3 bytes spanning offsets 19–21), producing orphan `e2 80` followed by `…` → `e2 80 e2 80 a6`. That single row took down `/teammates/calendar/events` for the entire dispatch team — 149 valid events, all invisible.

**Correct pattern:** use `mb_strlen()` / `mb_substr()` whenever truncating any string that could contain non-ASCII characters (any free-text user-entered field). Belt-and-braces for endpoints emitting JSON over user-entered content: also set `JSON_INVALID_UTF8_SUBSTITUTE` on the `JsonResponse` so any future stray invalid byte is silently replaced with `U+FFFD` in that one field rather than killing the entire response.

**Surfaced 2026-05-20 fixing the empty dispatch calendar (commit `366c9014`).**

## `auto_entitylabel` + `[entity:id]` token + single `->save()` on insert = stuck placeholder

If an AEL pattern uses `[work_order:id]` (or any `[entity:id]` token), the token evaluates to **empty during `hook_entity_presave` on insert** — the ID isn't assigned until the entity is committed. AEL detects the empty resolution and writes its sentinel placeholder `%AutoEntityLabel: <entity-uuid>%` into the title, expecting a follow-up save to heal it.

For form-created entities AEL's own machinery triggers a re-save and the placeholder gets replaced. For **programmatic** creation with a single `$entity->save()`, no follow-up save fires and the placeholder sticks **permanently**. Pathauto then generates a URL alias from that placeholder string (`autoentitylabel-<uuid>` in the slug), so the affected entities have both broken titles and broken URLs.

Compounding factor: AEL's `status: 2` (OPTIONAL) only sets the title when it is **empty**. A plain `->save()` on a stuck entity does **not** heal the title because AEL sees a non-empty value and skips. Heal mechanic: `$entity->set('title', '')` + `->save()`.

Hit 2026-05-23 across 30 `sprinkler_check_up` WOs created by the `contract_residential` check-up generator. Both the queue worker (`ContractResidentialCheckupGeneratorQueueWorker::createWorkOrder`) and the action (`CreateAndScheduleSprinklerCheckUpWorkOrdersAction::createAndScheduleWorkOrder`) called `$wo->save()` exactly once per WO.

**Correct pattern for any programmatic creator whose entity has an `[entity:id]`-bearing AEL pattern:** save twice, clearing the title between saves —

```php
$wo = $storage->create([...]);
$wo->save();
$wo->set('title', '');
$wo->save();
```

The cleared title is the cue AEL needs to re-evaluate its pattern with the now-known ID. Backfill mechanic for already-broken rows is the same: load, clear title, save.

**Surfaced 2026-05-23 fixing 30 broken check-up WOs (commit `cabb8a6e`; backfill at `web/scripts/backfill_broken_checkup_titles.php`).**

**Generalized 2026-06-19 (commit `8a72d4ae`):** `wo_shared_work_order_insert()` now auto-heals the sentinel on *any* work_order insert (clear title + re-save when the placeholder is present), so the manual double-save in programmatic creators is belt-and-suspenders rather than the only defense. The interactive add-form path — which the double-save never covered — is now protected too. Child entities (`wo_status_updates`, `wo_notes`, `wo_time_clock`, `scheduling`, `wo_material_list*`) inherit the parent's broken alias segment, so healing old rows means re-saving the WO **and** regenerating child aliases: iterate `path_alias` rows matching `%autoentitylabel%`, parse `/type/id`, load each entity, `\Drupal::service('pathauto.generator')->updateEntityAlias($e, 'update')`.

## Entity query `range()` without `sort()` is non-deterministic

Drupal entity queries with `->range(N, M)` but no `->sort(...)` may return different `N..N+M` slices across calls when the underlying result set exceeds the cap. MariaDB makes no ordering guarantee on `LIMIT/OFFSET` queries without an explicit `ORDER BY`, so the storage engine returns whichever rows are convenient at query time. The behavior is silent — no warning, no exception, just intermittent results.

Failure modes seen / anticipated:

- **Audit trail corruption.** A matcher / scoring routine picks a different "best candidate" each run for the same input. Test fixtures intermittently appear and disappear from the pool, masking the underlying non-determinism.
- **Pagination drift.** A batch migration paged via `range(offset, batch_size)` returns overlapping windows across ticks — the same row appears in multiple batches, OR rows fall between batches and are silently skipped.
- **Test flakiness.** Verification scripts that depend on "the first match" pass on one run and fail on the next; bisecting is fruitless because the code itself didn't change.

The same caveat applies to `\Drupal\Core\Database\Query\Select::range()` — use `->orderBy(...)` there.

**Rule:** ANY entity query that calls `->range(...)` MUST also call `->sort(...)`. ANY database-API select that calls `->range(...)` MUST also call `->orderBy(...)`.

- For non-presentational queries (matching, dedupe, "find existing"), sort by `id()` ASC (canonical "first") or DESC (most recent), depending on the consumer's intent. `id` is free — the PK index is already there.
- For admin-list / user-facing queries, sort by the natural display field (`created` DESC for activity logs, label ASC for browse lists, etc.).
- For `range(0, 1)` queries used as boolean existence checks (`if (!empty($ids))`), the order is technically irrelevant but a defensive `->sort('id', 'ASC')` is recommended anyway — it keeps the rule memorable and protects future code that may re-use the result for more than just the bool.

Discovered: Phase 3.4 fuzzy matcher candidate pool, 2026-05-25. The matcher's pool query for `material.pvc` (~666 rows in the live DB) called `range(0, 201)` with no sort and intermittently dropped freshly-created test fixtures from the result. The same pattern across the BOS custom-module surface was audited in `__BOS_AI/Reports/range_audit_2026-05-25.md` (gitignored snapshot); 1 HIGH and 16 MEDIUM sites surfaced for follow-up remediation.

**Correct pattern:**

```php
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition(...)
  ->sort('id', 'DESC')   // ← required when range() is used
  ->range(0, $cap)
  ->execute();
```

**Surfaced 2026-05-25 fixing the Phase 3.4 fuzzy-pool flake (commit `c5782cfb`); audit + rule documented same day.**

## text_long vs string_long for structured-text fields

`text_long` field storage is a **formatted-text** column — values pass through a text format (often CKEditor by default) on input and a filter chain on output. Use it for human-written prose where you want WYSIWYG editing and HTML filtering. Use it ONLY for that.

`string_long` is a **raw-text** column with no format dependency. Values store and emit exactly as submitted. No CKEditor, no `<p>` autowrapping, no smart-quote replacement, no `&amp;` mangling.

**Rule:** any field that stores structured data (JSON, CSV, config, code snippets, markup intended to be rendered verbatim, anything machine-readable) MUST be `string_long`, not `text_long`. The cost of getting this wrong is silent data corruption.

Failure modes seen / anticipated when JSON is in `text_long`:

- **CKEditor auto-formats input.** Paste `{"key": "value"}` into a CKEditor-enabled textarea; CKEditor wraps it in `<p>...</p>`, optionally converts `"` to `&quot;` or smart quotes, optionally drops the JSON's whitespace. `json_decode()` then fails or — worse — succeeds on a structurally-warped input.
- **Form alters can't override at the widget level reliably.** `text_format` is a compound element whose `#process` callback runs AFTER `hook_form_alter`. Setting `#attributes` on `widget[0]['value']` from a form alter gets overwritten silently. Workarounds (`#after_build` callbacks, removing the format selector) all add complexity that disappears the moment storage is `string_long`.
- **Future tool re-introduces CKEditor by default.** A Views in-place edit, an entity-edit-by-API tool, a custom admin form rendering the same field — none of them know to disable CKEditor unless the storage type itself denies the formatter. With `string_long`, no caller can accidentally route the field through a formatter.
- **`drush cim` refuses field-type changes.** Once a field is created as `text_long` and you decide it should be `string_long`, Drupal core refuses the change via config-import. You have to delete the field storage (which deletes the data) and re-import. So getting the type right at creation matters disproportionately — fixing later is a delete-and-recreate operation.

**Discovered:** Phase 3.7 dashboard verification, 2026-05-25. The Phase 3.1 spec authored `supplier_ingest_config.field_column_mapping` and `field_bundle_policy` as `text_long`. Subsequent form-alters tried to override the widget to behave like a plain textarea — none of which landed visibly on the rendered form. Root cause was the storage type, not the form alter; converting both fields to `string_long` made every workaround redundant.

**Correct pattern:**

```yaml
# field.storage.{entity}.{field_name}.yml
type: string_long
settings:
  case_sensitive: false
module: core
```

```yaml
# field.field.{entity}.{bundle}.{field_name}.yml
field_type: string_long
settings: {  }
```

```yaml
# core.entity_form_display.{entity}.{bundle}.default.yml
type: string_textarea
settings:
  rows: 18
```

```yaml
# core.entity_view_display.{entity}.{bundle}.default.yml
type: basic_string
```

**Migration path when correcting an existing field with empty data:** delete the field storage via `\Drupal::entityTypeManager()->getStorage('field_storage_config')->load($id)->delete()`, then re-import config — cim re-creates the field as the new type. If the field has data, the migration is more involved (export → delete → re-import → restore) and should be planned per-field.

**Surfaced 2026-05-25 fixing the supplier_ingest_config form alter that wouldn't land (paired with the storage-type conversion).**

## `private readonly` promoted properties on Drupal forms break serialize/wakeup

Drupal forms that get serialized between request and submit (anything with `managed_file`, AJAX, multi-step, or Batch API flows) hit a silent failure mode when constructor-promoted properties are declared `private readonly`:

```php
class MyForm extends FormBase {
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,  // BROKEN
  ) {}
}
```

Symptom: **`Error: Typed property ::$entityTypeManager must not be accessed before initialization`** in the submit handler, on a form that worked when serialization wasn't involved.

Mechanism — three things interact:

1. **Drupal's `DependencySerializationTrait::__sleep()`** uses `get_object_vars($this)` to enumerate properties. The function is defined inside the trait, which has its own visibility scope. Inside that scope, **private properties of the class using the trait are invisible**. The trait sees only its own properties (`_serviceIds`, `_entityStorages`) plus public/protected properties of the class.
2. With `private`, the trait captures *nothing*. `_serviceIds` stays empty. Serialization proceeds with the property's value stripped from `$vars`.
3. On unserialize, `__wakeup()` has nothing in `_serviceIds` to restore. The typed property ends up uninitialized. First access throws PHP's "must not be accessed before initialization" error.

`readonly` makes this even more brittle: even if visibility were fixed, `__wakeup`'s reassignment (`$this->$key = $container->get($service_id)`) would throw "Cannot modify readonly property" in PHP 8.2+.

**Fix:** use `protected` (or `public`) promoted properties without `readonly` on any class that uses `DependencySerializationTrait` (FormBase and subclasses; some other Drupal base classes):

```php
class MyForm extends FormBase {
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,  // works
  ) {}
}
```

`protected` is visible to the trait, and non-readonly lets `__wakeup()` reassign.

**Doesn't apply to:**
- **Services** registered in `*.services.yml` — they're fetched fresh per request from the container; they aren't serialized between request and submit. `private readonly` is fine on services.
- **Controllers** — same as services: fresh per request, never serialized into form state.
- Anything that doesn't use `DependencySerializationTrait`.

**Discovered 2026-05-25:** `BatchUploadForm` (uses `managed_file`, which forces form-state caching between the AJAX upload and the final submit) threw the property-uninitialized error on submit. All 10 Phase 3.5+ forms had the same latent bug — they hadn't hit it because their submit paths didn't trigger form caching. Fixed by converting `private readonly` → `protected` across all 10. Verifier addition: `verify_supplier_price_ingest_parser.php` Step 11 round-trips `BatchUploadForm` through `serialize()`/`unserialize()` and calls `submitForm()` directly on the deserialized instance — catches this class of regression at the form-DI layer rather than the service layer.

**Verification helper for any FormBase subclass with constructor-promoted properties:**

```php
$form = \Drupal::service('class_resolver')->getInstanceFromDefinition($formClass);
$round = unserialize(serialize($form));
$reflect = new ReflectionClass($round);
foreach ($reflect->getProperties() as $p) {
  if ($p->isStatic() || str_starts_with($p->getName(), '_')) continue;
  if ($p->hasType() && !$p->getType()->allowsNull()
      && !$p->isInitialized($round) && !$p->hasDefaultValue()) {
    fail("Property {$p->getName()} uninitialized post-wakeup");
  }
}
```

---

## Word-boundary regex prevents partial-numeric over-matches

**Discovered:** 2026-05-31 — Tier 1.5 title-substring matcher design (Phase 3.7.6 of `supplier_price_ingest`).

When implementing a substring-match search across user-entered text, **always anchor the lookup with word-boundary metacharacters** — otherwise short numeric SKUs swallow longer ones that just happen to start with the same digits.

Concrete example from the Tier 1.5 matcher:

| Naive substring | Word-boundary regex |
|---|---|
| `'1806' in '...Rain Bird 18006 Heavy-Duty Body...'` → MATCH (wrong!) | `/(?:^|\W)1806(?:$|\W)/i` → no match (correct) |
| `'1806' in '...Rain Bird 1806 Spray Body...'` → MATCH | `/(?:^|\W)1806(?:$|\W)/i` → MATCH (correct) |

The `(?:^|\W)` and `(?:$|\W)` flanks require the SKU to be bounded by non-word characters OR the start/end of the haystack. `\W` covers spaces, punctuation, parentheses — anything that isn't `[A-Za-z0-9_]`.

**Why this matters:** Tier 1.5 verifier Scenario 14 specifically tests this — a row with `field_supplier_sku = "1806"` must NOT match a material whose name contains "18006". Without word boundaries, every "1806" SKU would spuriously match every "18006" material, polluting fuzzy_med review with false candidates.

**Generalization:** This applies anywhere a short identifier is searched inside longer user-entered text — SKUs, model numbers, lot codes, ZIP+4 prefixes, etc. If the haystack might contain longer strings starting with the needle, word-boundary anchoring is mandatory.

**Pair with `preg_quote()`** when the needle comes from user input — the metacharacters in `preg_quote($needle, '/')` get escaped, defending against `.`, `*`, or `(` in supplier-provided SKUs. Tier 1.5 normalizes the SKU to alphanumerics before this, so the escape is defensive overhead rather than essential, but the pattern (`preg_quote` always when interpolating user data into regex) is the safe default.

---

## An uncaught exception in a VBO action aborts the whole batch

A Views Bulk Operations action's `execute()` runs once per selected row. If it throws an **uncaught** exception on one row, the **entire batch aborts** — rows already processed keep their writes, rows after the failure never run. Worse when the action does multiple writes per row: an early write commits, a later write throws, and the row is left half-applied.

Hit 2026-06-19: "Mark WO Invoiced" (`MarkWorkOrderInvoicedAction`) set `field_invoiced = 1`, then created a status-update whose presave tripped the `wo_shared` Invoiced guard (`EntityStorageException`) because the WO wasn't Complete — aborting the batch and stranding `field_invoiced = 1` flags. Select-all-across-pages had swept pre-completion (Scheduled 1091) WOs into a billing batch.

**Correct pattern for VBO actions:**
1. **Eligibility-gate at the top** and `return` early on ineligible rows — never attempt the operation (so a downstream guard is never reached).
2. **Order writes so the validated/throwing step runs first**, before any other persistent write — a throw then can't leave a half-applied row.
3. **Wrap the body in try/catch** and collect per-row failures so the batch continues.
4. **Defend at the data source too:** action/billing views should carry a **non-exposed** status floor so ineligible rows never appear for selection in the first place. (A `taxonomy_index_tid` filter with `operator: or` + fixed `value` + `exposed: false` gives a clean "is one of" floor.)

Reference: `MarkWorkOrderInvoicedAction` (gate + inverted write order + per-row catch) and the non-exposed `IN(1097,1281)` floor on the five `admin_*_billing` views (commits `54f7ab23`, `df6592bc`).

---

## cPanel/CloudLinux cron `drush` invocation fails silently

A `drush` command that works perfectly from an interactive shell fails when the **same** command runs from cron, with PHP errors like:

```
PHP Warning:  Undefined variable $argv in phar:///usr/local/bin/drush
PHP Fatal error:  Uncaught TypeError: array_shift(): Argument #1 ($array) must be of type array, null given
Status: 500 Internal Server Error
X-Powered-By: PHP/8.3.31
```

Cron writes the error to the job's log file but nothing else alerts, so the failure can go unnoticed for **weeks**.

**Cause.** On cPanel/CloudLinux/Hosting.com servers, `/usr/local/bin/php` is **not** a PHP binary — it's a small (~933-byte) Perl wrapper that uses CloudLinux's PHP selector (`/etc/cl.selector/ea-php-cli`) to route to whichever Alt-PHP the account has selected. The wrapper resolves correctly in an interactive shell but routes through **CGI PHP** when run from cron, which leaves `$argv` undefined and breaks drush's startup. (Drush's shebang is `#!/usr/bin/env php`, so it accepts whatever PHP the environment serves it — under cron that's CGI PHP.) The crash is at drush startup, **before any BOS module or drush command code loads**.

**Fix.** In cron, invoke the **direct CloudLinux Alt-PHP binary on the project's own `drush.php` entry point** — bypassing both the `/usr/local/bin/php` wrapper *and* the global `/usr/local/bin/drush` phar. For PHP 8.3:

```
# BROKEN — relies on the /usr/local/bin/php wrapper (routes to CGI under cron):
0 7 * * * ... && /usr/local/bin/drush wex:fetch-email ...

# ALSO BROKEN — Alt-PHP binary, but still hands off to the global drush PHAR:
0 7 * * * ... && /opt/alt/php83/usr/bin/php /usr/local/bin/drush wex:fetch-email ...

# FIXED — Alt-PHP CLI binary runs vendor drush.php directly, in ONE process:
0 7 * * * ... && cd /home/brookstoneadmin/brookstone \
  && /opt/alt/php83/usr/bin/php vendor/drush/drush/drush.php wex:fetch-email ...
```

**Why the middle form isn't enough (the 2026-06-24 recurrence).** `/usr/local/bin/drush` on this host is an ancient (2021) **global drush PHAR** with a `#!/usr/bin/env php` shebang. Even when you launch it with the Alt-PHP CLI binary, the PHAR's own bootstrap **re-execs `php`** to run the project drush — and that re-exec goes back through the `/usr/bin/env php` → `/usr/local/bin/php` wrapper → **CGI PHP**, re-introducing the exact "`$argv` undefined / Content-type: text/html / [preflight] Drush is designed to run via the command line" failure. The only robust fix is to invoke the project's `vendor/drush/drush/drush.php` **directly** as a script argument to the Alt-PHP CLI binary, so there is no phar and no re-exec — one process, real CLI context, `$argv` populated.

Confirm the binary is genuinely CLI **under a cron-like (empty) environment**, not just interactively:

```
env -i HOME=/home/brookstoneadmin /opt/alt/php83/usr/bin/php -r 'echo PHP_SAPI;'   # must print: cli
# Prove the whole cron line end-to-end (idempotent — re-running finds 0 UNSEEN):
env -i HOME=/home/brookstoneadmin LANG=C bash -c '. $HOME/.wex_imap_env && \
  cd /home/brookstoneadmin/brookstone && \
  /opt/alt/php83/usr/bin/php vendor/drush/drush/drush.php wex:fetch-email'
```

**This failure mode is silent by default** — cron surfaces nothing unless watched. The fetch log (`~/wex_fetch.log`) is the only signal; pair the fix with a failure-alert (mail on non-zero exit, or a staleness check on the latest `equipment_fuel_transaction` date) so the next environment shift doesn't go undetected.

**Discovered** 2026-06-21, after **17 days** of silent WEX `wex:fetch-email` failures (worked 06-04 → 06-07, broke when Hosting.com updated cPanel/EasyApache ~06-07/08; first "fix" was `/opt/alt/php83/usr/bin/php /usr/local/bin/drush`). **Recurred 2026-06-24** — a deploy's `composer install` (or another env shift) put the global PHAR back in the path so the Alt-PHP binary was handing off to it and re-exec'ing through the wrapper again; latest transaction had been stuck at 06-18 for ~6 days. Final fix: invoke `vendor/drush/drush/drush.php` directly (no phar, no re-exec). Caught up 9 transactions on the manual run. Resolution both times was diagnostic log inspection, not a code change. See `__BOS_AI/Modules/wex_fuel_import_workflow.md` → Troubleshooting.

---

## Find-or-create that treats "any open record" as a blocker traps on stale/abandoned records

A find-or-create flow that redirects to an existing "open" record — anything **not** in a
hardcoded done-set — will silently **trap** the user on a stale or wrongly-reopened record
instead of making a new one. BOS hit this in weed spraying: the create route, the crew
add-form alter, and the WO presave guard all used `field_status NOT IN [1097,1098,1281,1283]`
to mean "already open → redirect to it." A WO that was **completed then resurrected** to
In Progress (a stray clock-in) — or **created then abandoned** (never worked) — still
matched "open," so every "create new spray" for that property bounced back to the dead WO.
Techs reported "it keeps routing me back to the same work order."

**Lessons.**
- "Open" (`NOT IN done`) is not the same as "actively in use." Distinguish *genuinely
  active* from *stale / resurrected / abandoned* before treating a record as a blocker.
- Centralize the decision in **one** helper used by every path (create route, form alter,
  presave guard) — divergent copies of the same `NOT IN done` query guarantee they drift.
- Auto-cleanup the safe class (old + zero activity → cancel) but **flag, don't auto-fix**,
  the billing/history-sensitive class (e.g. restoring a resurrected WO re-fires its
  completion write-back and can overwrite a newer record's data).
- Watch the done-set for omissions: BOS's list omits **Paid (1504)** (and a legacy
  **1301 "Active"** exists), so those would read as "open" too.

**Surfaced** 2026-06-25; fix on branch `feature/spray-route-guard` (commit `7c8c2334`).
See `__BOS_AI/Modules/wo_weed_spraying_updates.md`.

---

## Config-ahead-of-code half-deploy (and why the BOS surgical-cim split invites it)

BOS deploys code (rsync) and config (`drush cim --partial`) **separately**, and `cim`
does not run by default. That's deliberate, but it means a fix split across **code + config**
can go live in halves. The 2026-06-24 billing VBO batch crash was exactly this: the
billing-views status-floor **config** had been imported (2026-06-20) while the rewritten
`MarkWorkOrderInvoicedAction` **code** had not yet been rsync'd. So old, ungated code ran
against floored views — and on a view without the floor (`admin_work_order_administration`),
select-all swept an ineligible WO in, the old code threw an uncaught `EntityStorageException`,
and the whole batch aborted (`404 "No batch with ID … exists"`).

**Lessons.**
- When a fix is code **and** config, deploy and verify them **together**, or you get a
  window where one half is live and the other isn't — often worse than neither.
- After a partial deploy, verify the *deployed code on disk* (live has no git history) **and**
  `config:status`, not just one. A clean `config:status` does **not** mean the code shipped.
- Diagnose "what's actually live" from the server, not from local `main`.

**Surfaced** 2026-06-25 (read-only investigation). The fix is now fully live; no lasting
data damage. Related: "uncaught exception in a VBO action aborts the whole batch" (above).

---

## Status

- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Maintained as living document — append new gotchas as they're discovered, with the surfacing commit cited.
