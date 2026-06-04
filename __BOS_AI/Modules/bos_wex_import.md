# BOS Module — bos_wex_import

Module: `bos_wex_import`
Package: BOS Custom

## Purpose

Import WEX fleet card transactions (CSV / XLSX exports from the WEX online portal) into BOS as `equipment_fuel_transaction` entities. Handles file parsing, driver resolution (via teammate prompt IDs), vehicle resolution (via vehicle numbers), match-status flagging, vehicle-mileage auto-update, and idempotent re-imports.

The module is the operational entry point for fleet fuel data; the entity it maintains is documented in [`__BOS_AI/Entities/equipment_fuel_transaction.md`](../Entities/equipment_fuel_transaction.md).

## Module Dependencies

```yaml
dependencies:
  - drupal:datetime
  - drupal:eck
  - drupal:file
  - drupal:options
  - drupal:user
  - profile:profile
```

PhpSpreadsheet is provided via `phpoffice/phpspreadsheet` (already in the project's composer requirements; not declared as an info.yml dependency since it's a vendor library, not a Drupal module).

## Permissions

| Machine name | Title | Granted to |
|---|---|---|
| `import wex fuel transactions` | Import WEX fuel transactions | `administrator`, `site_admin`, `administration` |

`restrict access: true` — surfaces in the standard "Restricted permissions" warning when granted.

## Routes

| Route | Path | Access |
|---|---|---|
| `bos_wex_import.upload_form` | `/admin/operations/equipment/fuel-transactions/import` | `_permission: import wex fuel transactions` |

`_admin_route: TRUE` — uses the admin theme.

## Local Task Tabs

Two tabs registered on `view.equipment_fuel_transactions_admin.page_1` (the master list):

| Tab | Route | Weight |
|---|---|---|
| Master List | `view.equipment_fuel_transactions_admin.page_1` (default tab pointing back at the base route) | 0 |
| Import | `bos_wex_import.upload_form` | 10 |

The "Master List" entry exists to satisfy Drupal's requirement that a base_route have at least 2 local tasks before the tab bar renders (see `__BOS_AI/Governance/drupal_bos_gotchas.md` → "Drupal local tasks need at least 2 entries").

The Review Queue is its own page at `/admin/operations/equipment/fuel-transactions/unmatched` (registered by the `equipment_fuel_transactions_unmatched` view), not a tab on this hierarchy.

## Service Definitions

```yaml
bos_wex_import.import_service:
  class: Drupal\bos_wex_import\Service\WexFuelImportService
  arguments:
    - '@entity_type.manager'
    - '@logger.factory'
    - '@datetime.time'
    - '@file_system'
```

Single service. The form, batch processor, and both Drush commands (`wex:import`, `wex:fetch-email`) call into the same `importFromFile()` core. A future REST endpoint would do the same — channel-agnostic by design.

## Drush Commands

Two commands, both backed by `WexFuelImportService::importFromFile()` so parse/import/match/mileage logic is never duplicated. Defined in `src/Commands/`; registered via `drush.services.yml`.

| Command | Alias | Source | Purpose |
|---|---|---|---|
| `bos_wex_import:import <filepath>` | `wex:import <filepath>` | local file | Imports a CSV/XLSX from a path on disk. Same parse + match + mileage logic as the upload form, no UI. Useful for ad-hoc re-imports or initial backfills. |
| `bos_wex_import:fetch-email` | `wex:fetch-email` | IMAP mailbox | Reads UNSEEN messages from the configured WEX mailbox, extracts the WEX report URL from each body, downloads the CSV with Guzzle, hands the file to `importFromFile()`. Marks message Seen only on a clean run (URL found + file-level import success); otherwise leaves Unseen for the next run to pick up. |

Both commands print a one-line summary identical in format to the form-driven batch finished message, plus a `formatSummary()` watchdog entry. Exit code is `EXIT_SUCCESS` for any "normal operational outcome" (including empty mailbox, no-URL messages, row-level errors) and `EXIT_FAILURE` only for hard errors (missing config, connection failure).

### `wex:fetch-email` IMAP configuration

Read from `$settings['wex_imap']` in `web/sites/default/settings.php` via `Drupal\Core\Site\Settings::get()`. Required keys: `host`, `username`, `password`. Optional: `port` (default `993`), `encryption` (`ssl`), `validate_cert` (`TRUE`), `sender_match` (default `wexinc.com`).

**Password discipline.** The password value MUST come from `getenv('WEX_IMAP_PASS')` — never a literal in `settings.php` or any other tracked file. The actual secret lives in an off-git env file on the live host (see "Scheduled Daily Fetch" below) and gets injected at command invocation time.

The configured live block:

```php
$settings['wex_imap'] = [
  'host'          => 'mail.brookstoneoutdoors.com',
  'port'          => 993,
  'username'      => 'wex@brookstoneoutdoors.com',
  'password'      => getenv('WEX_IMAP_PASS') ?: NULL,
  'encryption'    => 'ssl',
  'validate_cert' => TRUE,
  'sender_match'  => 'wexinc.com',
];
```

`sender_match` is a case-insensitive substring search against the message `From` header. The WEX mailer sends from `OnlineServices@wexinc.com`, NOT from `wexonline.com` (which is the *download* domain in the body URL). Setting this to `wexonline.com` was the original default and produced silent "no UNSEEN matches" results on the first run.

### Body extraction quirk

WEX wraps the text/plain part of each email inside a `multipart/related` envelope. `webklex/php-imap` exposes that inner part as an "attachment" (mime `text/plain`, no filename) rather than via `getTextBody()`. The command's URL extractor therefore tries body sources in order: `getTextBody()` → `getHTMLBody()` → concatenated `text/*` attachment contents → `getRawBody()`. The download-URL regex is anchored to `https?://go\.wexonline\.com/web/gotoDownloadReport\.do\?…` so picking the URL out of raw multipart text is unambiguous.

### Library dependency

`webklex/php-imap` ^6.2 — pure-PHP IMAP4 client. Chosen over the C `ext-imap` extension because ext-imap is deprecated and slated for removal. Added to `composer.json` in commit `80dfcafb`.

## `WexFuelImportService` — public method reference

`@bos_wex_import.import_service` — `src/Service/WexFuelImportService.php`

| Method | Purpose | Returns |
|---|---|---|
| `parseFile(string $filepath): array` | Parse CSV (.csv) or XLSX (.xls/.xlsx) into a list of associative arrays keyed by column header. Throws `\InvalidArgumentException` on unsupported extension or unreadable file. | `array<int, array<string, mixed>>` |
| `validateHeaders(array $headers): array` | Check the parsed headers against the required column list. | `string[]` of missing required headers (empty array = all present) |
| `resolveDriver(string $promptIdPadded): ?int` | Look up `teammate_profile` by `field_wex_driver_prompt_id` (zero-padded 4-char). | Owning user UID, or NULL when no match |
| `resolveVehicle(int $assetId): ?int` | Look up `equipment` (vehicles bundle) by `field_vehicle_number`. | Equipment ID, or NULL when no match |
| `determineMatchStatus(?int $driverUid, ?int $equipmentId): string` | Compute the match status flag from the two resolution results. | `'matched'` \| `'unmatched_driver'` \| `'unmatched_vehicle'` \| `'unmatched_both'` |
| `isDuplicate(string $wexTransactionId): bool` | Pre-save check against existing `field_wex_transaction_id` values. | TRUE when a transaction with this ID already exists |
| `importRow(array $row): array` | Process a single parsed row: resolve, dedupe-check, build entity field values, save, side-effect mileage. | `['status' => 'imported'\|'skipped_duplicate'\|'error', 'transaction_id' => string, 'match_status' => string\|null, 'message' => string\|null, 'entity_id' => int\|null]` |
| `updateVehicleMileage(int $equipmentId, int $odometer, string $transactionDateUtc, string $txId): void` | Side effect — updates the vehicle's mileage if `$odometer` is greater than the current value, and stamps `field_current_mileage_updated_on`. Logs a warning instead of writing on lower-than-current reads. | (void) |

### Required column headers

`parseFile()` doesn't enforce headers; `validateHeaders()` does. The required set:

- `Transaction ID`
- `Transaction Date`
- `Custom Vehicle/Asset ID`
- `Driver Prompt ID`
- `Units`
- `Net Cost`

Other WEX columns are optional — when missing, the corresponding entity field stays empty. The required set is the operational minimum: enough to identify the transaction, anchor it in time, and resolve its vehicle and driver.

## `WexFuelImportBatch` — batch handler reference

`src/Batch/WexFuelImportBatch.php` — Drupal Batch API operations. Static methods only (Batch operation specs serialize cleanly that way; instance methods would require a serializable object graph).

| Method | Purpose |
|---|---|
| `processRow(array $row, int $index, int $total, array &$context): void` | Single-row operation. Calls `$service->importRow($row)`, increments per-status counters in `$context['results']`, and updates `$context['message']` for the progress display. |
| `finished(bool $success, array $results, array $operations): RedirectResponse` | Batch completion callback. Emits a summary status message via Messenger ("Import complete: X imported, Y duplicates skipped, Z errors. Match status: …"), warns about unmatched count with a link to the Review Queue if > 0, and redirects to the master list view. |

## `WexFuelImportForm` — form reference

`src/Form/WexFuelImportForm.php` — extends `FormBase`.

| Field | Widget | Validators |
|---|---|---|
| `import_file` | `managed_file` | `FileExtension: csv xls xlsx`, `FileSizeLimit: 10485760` (10 MB), `#required: TRUE` |

Submit flow:

1. Load the uploaded `file` entity from the `managed_file` value
2. Resolve real path via `\Drupal::service('file_system')->realpath()`
3. Parse via `WexFuelImportService::parseFile()`
4. Validate headers; abort with form error if any required header is missing
5. Build a Batch with one operation per row
6. Hand off to Batch API (`batch_set()`); the finished callback emits the summary and redirects

Services are resolved via `\Drupal::service()` calls inside form methods rather than constructor injection — `FormBase` children can't safely use constructor-promoted readonly properties because Drupal serializes form objects across request stages and `DependencySerializationTrait` doesn't restore promoted properties on `__wakeup`. See `__BOS_AI/Governance/drupal_bos_gotchas.md` → "FormBase + constructor-promoted readonly properties don't survive form serialization".

## Hooks Implemented

`bos_wex_import_entity_presave(EntityInterface $entity)` — fires for every entity save. Acts only on `equipment_fuel_transaction` entities. Two enforcements:

1. **`field_wex_transaction_id` is required.** Empty value throws `EntityStorageException` with message `"WEX Transaction ID is required."`
2. **`field_wex_transaction_id` is unique.** A query for any other entity with the same value throws `EntityStorageException` with message including the conflicting entity's ID.

The `WexFuelImportService::importRow()` calls `isDuplicate()` BEFORE attempting save, so duplicates are skipped silently in the normal import path. The presave hook is a safety net for any entity-creation path that bypasses the import service (form save, REST API write, programmatic save by other code).

## Field Mapping (WEX column → entity field)

The `WexFuelImportService` translates WEX export columns to `equipment_fuel_transaction` fields per the table below. Columns not in this table are ignored.

| WEX column | Entity field | Type / handling |
|---|---|---|
| `Transaction ID` | `field_wex_transaction_id` | string, required |
| `Transaction Date` + `Transaction Time` | `field_transaction_date` | datetime — combined and stored UTC |
| `Posted Date` | `field_posted_date` | date |
| `Card Number` | `field_card_number_masked` | string, stored as-is |
| `Custom Vehicle/Asset ID` | `field_vehicle_asset_id_raw` | string (audit copy) |
| (resolved equipment ID) | `field_equipment` | entity_reference → equipment.vehicles |
| `VIN` | `field_vin_raw` | string (audit copy) |
| `Driver Prompt ID` (zero-padded 4 chars) | `field_driver_prompt_id_raw` | string (audit copy) |
| (resolved user UID) | `field_driver` | entity_reference → user |
| `Driver First Name` | `field_driver_first_name_raw` | string |
| `Driver Last Name` | `field_driver_last_name_raw` | string |
| `Driver Department` | `field_driver_department_snapshot` | string |
| `Merchant Name` | `field_merchant_name` | string |
| `Merchant Brand` | `field_merchant_brand` | string |
| `Merchant City` | `field_merchant_city` | string |
| `Merchant State / Province` | `field_merchant_state` | string |
| `Merchant Postal Code` | `field_merchant_postal_code` | string |
| `Product` | `field_product_code` | string |
| `Product Class` | `field_product_class` | string |
| `Product Description` | `field_product_description` | string |
| `Units` | `field_units` | decimal (12,4), required column |
| `Unit Cost` | `field_unit_cost` | decimal (12,4) |
| `Total Fuel Cost` | `field_total_fuel_cost` | decimal (12,4) |
| `Net Cost` | `field_net_cost` | decimal (12,4), required column |
| `Current Odometer` | `field_current_odometer` | integer |
| `Adjusted Odometer` | `field_adjusted_odometer` | integer |
| `Previous Odometer` | `field_previous_odometer` | integer |
| `Distance Driven` | `field_distance_driven` | integer |
| `Fuel Economy` | `field_fuel_economy` | decimal (8,2) |
| (computed) | `field_match_status` | list_string, required, default `matched` |

Numeric fields tolerate string input from CSV (cast appropriately, ignore `$,` separators). Empty / null in WEX becomes NULL in BOS, not empty string.

## Match Status Decision Logic

| Driver resolved? | Vehicle resolved? | Status |
|---|---|---|
| ✓ | ✓ | `matched` |
| ✗ | ✓ | `unmatched_driver` |
| ✓ | ✗ | `unmatched_vehicle` |
| ✗ | ✗ | `unmatched_both` |

Office staff resolving an unmatched record manually then sets `field_match_status` to `manually_resolved` — that value is never set by the import.

## Vehicle Mileage Auto-Update Logic

Triggered whenever `field_equipment` resolves successfully (i.e., on `matched` rows AND on `unmatched_driver` rows where the vehicle did resolve). The driver match status is irrelevant — it's the vehicle's mileage being tracked.

Odometer source priority:
1. `field_adjusted_odometer` if populated (WEX has corrected the driver's entry)
2. else `field_current_odometer` (driver's pump entry)

Update rule:

- If the chosen odometer value is **greater than** the vehicle's current `field_current_mileage`, OR `field_current_mileage` is empty:
  - Set `field_current_mileage` = new value
  - Set `field_current_mileage_updated_on` = the transaction's `field_transaction_date` (datetime, UTC ISO)
  - Save vehicle entity
  - Log `info`: `Vehicle {id}: mileage {old} → {new} from WEX transaction {tx_id}.`
- If the chosen value is **less than or equal to** current:
  - Log `warning`: `Vehicle {id}: skipped lower odometer read ({new} < current {current}) from WEX transaction {tx_id}. Possible bad pump entry.`
  - Do NOT update the vehicle.

The timestamp captures the *transaction date*, not the BOS save time — so the mileage anchor reflects when the odometer was actually read at the pump, not when the import happened.

## Idempotency Design

Two-layer protection against duplicate imports:

1. **Primary: `WexFuelImportService::isDuplicate()` check before save.** The import service queries by `field_wex_transaction_id` and returns `skipped_duplicate` without touching the entity. Re-importing the same WEX export is a no-op at the entity level.
2. **Safety net: `bos_wex_import_entity_presave()` hook.** If something other than the import service creates a duplicate (e.g., a programmatic save from a future bug, a REST write, etc.), the hook throws `EntityStorageException` and the save aborts with a clear message.

Mileage updates do NOT run on `skipped_duplicate` rows — they only fire when an entity is actually saved. So re-importing yesterday's file doesn't double-touch any vehicle's mileage timestamp.

## Scheduled Daily Fetch (live)

Set up 2026-06-04 on the live host (`brookstone` SSH alias). Lives entirely off git — the cron entry, env file, and log file are all on the live filesystem under `~brookstoneadmin/`. Nothing about the schedule is deployed via the deploy script; it's pure server-side configuration.

### Files on live (off git)

| Path | Mode | Purpose |
|---|---|---|
| `~brookstoneadmin/.wex_imap_env` | `0600` | Exports `WEX_IMAP_PASS`. Sourced by the cron entry before drush runs. The only file that holds the live password. Rotate the password by editing this file only. |
| `~brookstoneadmin/wex_fetch.log` | `0640` | Rolling log of every cron run. Date-stamped header per run. Truncate or rotate manually if it grows large; idempotency of the import means no harm in losing log history. |

### Crontab entry

Installed in `brookstoneadmin`'s crontab (no system-level scheduling):

```
# WEX fuel-card daily import — added 2026-06-04
# Reads UNSEEN emails from wex@brookstoneoutdoors.com, downloads each
# referenced WEX CSV, imports into equipment_fuel_transaction entities.
# Password sourced from ~/.wex_imap_env (0600). Log: ~/wex_fetch.log.
# Idempotent (dedupes on Transaction ID).
# LANG=C suppresses the cPanel perl locale warnings that fill the log.
# bash -c (NOT -lc) skips the login-profile chain that triggers them.
0 7 * * * LANG=C bash -c 'echo; echo "=== WEX fetch $(date) ==="; . $HOME/.wex_imap_env && cd /home/brookstoneadmin/brookstone && /usr/local/bin/drush wex:fetch-email' >> $HOME/wex_fetch.log 2>&1
```

Fires at **7:00 AM America/Phoenix** every day (the live host is in MST with no DST, so cron's local time == site time year-round). WEX delivers its overnight report between roughly midnight and 6am, so 7am gives a comfortable margin while still picking up the data before the office starts working.

### Why this exact shape

- **`LANG=C`** — without it the cPanel server invokes a perl hook on every shell startup which complains about missing UTF-8 locales and floods stderr (and the log) with `perl: warning: Setting locale failed.` blocks.
- **`bash -c`, not `bash -lc`** — login shells (`-l`) trigger `/etc/profile`, which on this cPanel host runs more perl hooks. Plain `bash -c` skips that chain entirely. Since drush has a deterministic absolute path (`/usr/local/bin/drush`), there's no PATH lookup that would have needed a login shell.
- **Date-stamped header** — `echo "=== WEX fetch $(date) ==="` at the top of every run makes the log scannable when investigating "did it run yesterday?". Without it consecutive empty-mailbox days are indistinguishable.
- **Single-line cron entry** — multi-line cron entries are unreliable across cron implementations. Keeping the whole pipeline on one logical line via `&&` chains is the portable choice.

### Operating the schedule

- **Disable temporarily**: `ssh brookstone "crontab -e"`, comment out the `0 7 * * *` line.
- **Run manually** (same command line as cron): `ssh brookstone "LANG=C bash -c '. \$HOME/.wex_imap_env && cd /home/brookstoneadmin/brookstone && drush wex:fetch-email'"`.
- **Watch live during 7am**: `ssh brookstone "tail -f ~/wex_fetch.log"`.
- **Rotate password**: edit `~/.wex_imap_env` on live. The block in `settings.php` reads `getenv('WEX_IMAP_PASS')` so no Drupal config or code change needed.
- **Investigate a failed run**: `grep -A 5 "=== WEX fetch.*<date>" ~/wex_fetch.log` finds the run's whole output; the full per-message details (UID, subject, URL, import counts) are all there.

### Idempotency under the schedule

The import service dedupes on `field_wex_transaction_id` BEFORE save, so a duplicated email (or a re-fetched UNSEEN run after a partial failure) imports zero rows. A re-fetched WEX email that already processed all its rows shows `imported=0, duplicates_skipped=N` in the summary. Mileage updates only fire when an entity is actually saved, so even a re-run can't double-touch any vehicle's mileage timestamp.

## Operational Notes

- **Supported file formats**: CSV (`.csv`), XLSX (`.xlsx`), XLS (`.xls`). Detection is by extension; XLSX uses PhpSpreadsheet's `IOFactory`, CSV uses native `fgetcsv()`.
- **Maximum file size**: 10 MB. Configured in the form's `FileSizeLimit` upload validator. Adjustable in `WexFuelImportForm::buildForm()` if WEX exports grow beyond that.
- **Batch operation count**: one per data row. A typical monthly export of ~50 transactions runs in under 5 seconds; a year's worth of ~600 transactions in ~30 seconds.
- **Permission required**: `import wex fuel transactions` (granted to administrator/site_admin/administration roles).
- **Temporary files**: uploads land in `temporary://wex_import/`. Files are not persisted (`managed_file` keeps them in `temporary://` indefinitely until Drupal's temp-file cleanup garbage-collects them, typically 6 hours).

## Known Limitations / Future Enhancements

- **No pre-import preview.** The form goes straight from upload to batch processing. Adding a "Preview first 10 rows" step before commit would let operators sanity-check the file before the batch runs.
- **No error log surfacing.** When errors happen on individual rows during batch processing, the count appears in the summary message but the per-row messages are only in `watchdog`. A "View error log" link from the summary would help.
- ~~**No on-demand cron import.**~~ **Resolved 2026-06-04** — `drush wex:fetch-email` + daily 7am crontab entry on live (see "Drush Commands" + "Scheduled Daily Fetch (live)" above).
- **No bulk-resolve in the Review Queue.** Each unmatched transaction must be edited individually. A VBO bulk action ("Set match status to Manually Resolved + assign driver X") would speed up resolving systematic gaps (e.g., one unmapped contractor showing up across 15 rows).

## Related Entities and Views

- Entity: [`equipment_fuel_transaction`](../Entities/equipment_fuel_transaction.md) — the records this module creates and maintains
- Companion field: [`equipment.vehicles.field_current_mileage_updated_on`](../Entities/equipment.md) — the mileage timestamp set by the import service
- Driver-resolution anchor: `teammate_profile.field_wex_driver_prompt_id` — see the teammate_profile section of `__BOS_AI/Entities/users.md` for documentation
- Master list view: `views.view.equipment_fuel_transactions_admin` (`/admin/operations/equipment/fuel-transactions`)
- Review queue view: `views.view.equipment_fuel_transactions_unmatched` (`/admin/operations/equipment/fuel-transactions/unmatched`)
- Per-vehicle EVA: `views.view.equipment_fuel_transactions_eva` (rendered on each equipment view page)

## Related Operator Workflow

The day-to-day import workflow for office staff is documented separately at [`wex_fuel_import_workflow.md`](wex_fuel_import_workflow.md).

## Status

- Created: 2026-05-04 (commits `bfa697fc` build, `114afa70` form-fix)
- Stub uniqueness hook landed earlier with the entity in commit `885eb452`
- Deployed to live: 2026-05-05
- First production run: ~209 transactions imported covering 2025-12-30 → 2026-04-30 (96% match rate)
- IMAP fetch wrapper + channel-agnostic refactor: 2026-05-31, commit `80dfcafb` (`feature/wex-email-fetch`)
- Merged to main + deployed: 2026-06-04 (commits `7330db9e` merge, `ff7a1f59` sender_match/body-extract fix)
- First production IMAP run: 2026-06-04 — 3 messages, 12 transactions imported (11 matched, 1 unmatched_vehicle — known: Gerald's personal truck, equipment record to be created)
- Daily 7am cron installed on live: 2026-06-04
