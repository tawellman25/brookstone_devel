# WEX Fuel Import — Operator Workflow

A practical guide for the office staff member running fuel imports. Pair this with [`bos_wex_import.md`](bos_wex_import.md) (the technical spec) when you need to know what the system is doing under the hood.

## Purpose

Bring WEX fleet card transactions into BOS so that:

- Per-vehicle fuel cost and gallons show on the master list and on each vehicle's page
- Driver-attribution is automatic where possible, manual where not
- Vehicle mileage stays current without anyone typing it in
- Repeat imports of the same export are safe (no duplicates)

If everything's working right, this is a 5-minute weekly task.

## Frequency

**Weekly** is the recommended cadence — pulls a clean Mon-through-Sun snapshot, easy to verify, easy to remember. The system also handles ad-hoc imports fine (one-off backfills, mid-week catch-ups). Re-imports of overlapping date ranges are safe — duplicates skip silently.

## What You Need

- WEX portal access (`go.wexonline.com`)
- The "BOS Fuel Import — Standard" report template configured in WEX (already created — don't make a new one)
- A BOS account with one of: `administrator`, `site_admin`, or `administration` role (these have the `import wex fuel transactions` permission)

## Step-by-step: Routine Weekly Import

1. Open the WEX scheduled-report email and save the attachment to your local disk
   *(Or pull on-demand from the portal — see next section.)*
2. Go to BOS → **Office → Equipment → Fuel Transactions** (`/admin/operations/equipment/fuel-transactions`)
3. Click the **Import** tab at the top
4. Click **Choose File**, pick the WEX export (`.csv` or `.xlsx`)
5. Click **Import Transactions**
6. Wait for the batch progress bar — small files finish in seconds, ~600 rows takes ~30 seconds
7. Read the green status message at the top of the redirect page:
   > *Import complete: N imported, M duplicates skipped, X errors. Match status: …*
8. If the message also includes a yellow warning about unmatched transactions, click the **Review Queue** link in that warning
9. Resolve each unmatched transaction one-by-one (see "Resolving Unmatched" below)

## Step-by-step: On-Demand Pull from the WEX Portal

Use this when the scheduled report didn't arrive, you need a date range outside the schedule, or you're backfilling history.

1. Log in to `go.wexonline.com`
2. Navigate to **Reports**
3. Find the saved report template **"BOS Fuel Import — Standard"**
4. Click **Run**
5. Set the date range you want
6. Choose CSV or XLSX as the output format
7. Click **Run Report** — wait for it to complete (status bar in the portal)
8. Download the file when ready
9. Continue with the upload steps above (step 2 onward)

## Automated Daily Email Fetch (cron)

Most days you don't import by hand at all — a cron job on the live server pulls the WEX report email automatically and runs the importer. (The manual/portal paths above are for catch-ups, backfills, or when the automation is down.)

- **Schedule:** `0 7 * * *` — daily at **7:00 AM Mountain Time**, after WEX's overnight delivery.
- **Command:** the `wex:fetch-email` drush command (reads UNSEEN messages from the WEX mailbox, extracts the report URL, downloads the CSV, and imports it). See [`bos_wex_import.md`](bos_wex_import.md) for what the command does internally.
- **Log file:** `$HOME/wex_fetch.log` (i.e. `/home/brookstoneadmin/wex_fetch.log`) — every run appends a timestamped block here. This is the first place to look when transactions stop appearing.
- **Credentials:** the IMAP login is sourced from `$HOME/.wex_imap_env` (must be `0600`, `-rw-------`). The cron line `. $HOME/.wex_imap_env` before running drush; without it the fetch can't authenticate to the mailbox.

**Current live cron line** (`crontab -l` on the `brookstoneadmin` account):

```
0 7 * * * LANG=C bash -c 'echo; echo "=== WEX fetch $(date) ==="; \
  . $HOME/.wex_imap_env && cd /home/brookstoneadmin/brookstone && \
  /opt/alt/php83/usr/bin/php /usr/local/bin/drush wex:fetch-email' \
  >> $HOME/wex_fetch.log 2>&1
```

> **The PHP binary path matters.** drush is invoked as `/opt/alt/php83/usr/bin/php /usr/local/bin/drush`, **not** the bare `/usr/local/bin/drush`. On this cPanel/CloudLinux host the bare path routes through a PHP wrapper that breaks drush under cron (silently — it only writes to the log). This exact line is the fix for a 17-day silent outage; do not "simplify" it back to `/usr/local/bin/drush`. Full explanation: `__BOS_AI/Governance/drupal_bos_gotchas.md` → "cPanel/CloudLinux cron `drush` invocation fails silently".

## Resolving Unmatched Transactions

The Review Queue (`/admin/operations/equipment/fuel-transactions/unmatched`) shows transactions where automatic matching failed. The "Issue" column tells you which side(s) didn't resolve.

### Unmatched Driver

The transaction's Driver Prompt ID didn't match any teammate's WEX prompt ID on file.

- **If the driver IS a current teammate:** open their teammate profile (Office → Teammates → edit), expand the **External System Identifiers** section, set their **WEX Driver Prompt ID** to the value WEX recorded. Save. Then come back to the unmatched transaction, edit it, set the **Driver** field to the now-matched teammate, and change **Match Status** to **Manually Resolved**. Future imports for that driver will match automatically.
- **If the driver is NOT a current teammate (former employee, contractor, etc.):** check whether their WEX card needs to be deactivated on the WEX side first. For the orphan transactions in BOS, edit each one, assign **Driver** to whichever teammate actually drove that day (or leave it empty if you genuinely don't know), and change **Match Status** to **Manually Resolved**.

### Unmatched Vehicle

The transaction's Custom Vehicle/Asset ID didn't match any vehicle's Truck Number in BOS.

- **If the vehicle exists in BOS but with a different number:** decide which side is correct (BOS vs WEX) and fix the wrong side. Then come back to the unmatched transactions and edit each to set **Vehicle**.
- **If the vehicle doesn't exist in BOS yet** (recently added to fleet, missed during onboarding): create the vehicle equipment record first (Office → Equipment → Add Equipment → Vehicles), set its Truck Number to match the WEX asset ID. Then come back and resolve the transactions.

### Both Unmatched

Resolve driver first, then vehicle. Set **Match Status** to **Manually Resolved** when done.

### "SIG ON FILE" or No Prompt ID

Sometimes a pump doesn't capture a PIN — usually a card reader fallback or a manager override. The transaction lands with empty driver fields and `unmatched_driver` status.

- Look at the transaction date, vehicle, and merchant location to figure out who was driving that day
- Manually assign the driver and change status to **Manually Resolved**
- If you can't reasonably figure out who drove, leave it unresolved and note in your records — the cost is still on the books, just unattributed

## What Happens Automatically

- **Vehicle mileage updates** — when a transaction's odometer reading is higher than the vehicle's current mileage on file, BOS updates the mileage automatically and stamps the date
- **Lower-than-current odometers are ignored** — protects mileage history against driver typos at the pump (entering 87000 when the truck is at 187000). Logged as a watchdog warning so you can review if needed
- **Mileage timestamp** records the **transaction date** (when fuel was actually pumped), not the time the import ran
- **Re-imports are safe** — uploading the same file twice just shows duplicates skipped, no double-import
- **Driver match runs against the canonical 4-digit padded prompt ID** — entering `625` in WEX matches BOS's `0625`

## Common Issues and Fixes

| Symptom | Likely cause | Fix |
|---|---|---|
| "File too large" error | WEX export over 10 MB | Split by date range and upload separate files |
| "Required column missing" error in red | The WEX template was modified | In the WEX portal, edit the saved template and re-add the missing field |
| Whole batch shows `unmatched_driver` | No teammates have prompt IDs set in BOS | Set prompt IDs on teammate profiles first; then re-import |
| Whole batch shows `unmatched_vehicle` | Vehicles in BOS don't have Truck Number populated | Set field_vehicle_number on each vehicle to match its WEX asset ID |
| Same person showing up unmatched across many transactions | Their prompt ID isn't set in BOS | Set their `field_wex_driver_prompt_id` once on their teammate profile — future imports for that driver will match automatically |
| Mileage didn't update on a vehicle that should have | The transaction's odometer was lower than the vehicle's existing mileage | Check the transaction's odometer; if WEX has it wrong, edit the transaction's odometer fields manually; if BOS had a stale-high mileage, edit the vehicle's mileage manually first |

## Troubleshooting

Deeper diagnostics for when the automation (the daily cron fetch) is involved, or when something's off that the table above doesn't cover. These are server/CLI steps — run on the live server.

### "Transactions aren't appearing in BOS"

Work through these in order:

1. **Check the live count + latest transaction date:**
   ```
   drush eval "
   \$count = \Drupal::entityQuery('equipment_fuel_transaction')
     ->accessCheck(FALSE)->count()->execute();
   \$ids = \Drupal::entityQuery('equipment_fuel_transaction')
     ->accessCheck(FALSE)->sort('field_transaction_date', 'DESC')
     ->range(0, 1)->execute();
   \$latest = \Drupal::entityTypeManager()
     ->getStorage('equipment_fuel_transaction')->load(reset(\$ids));
   print 'Total: ' . \$count . \"\n\";
   print 'Latest TX: ' . \$latest->get('field_transaction_date')->value . \"\n\";
   "
   ```
   If the latest date is days/weeks stale, the fetch has been failing.

2. **Read the fetch log:** `tail -100 ~/wex_fetch.log`. Failure modes to look for:
   - **PHP errors at drush startup** (`Undefined variable $argv`, `array_shift(): ... null given`, `500 Internal Server Error`) → the cron PHP-binary/wrapper problem. See `drupal_bos_gotchas.md` → "cPanel/CloudLinux cron `drush` invocation fails silently". This is the one that caused the 17-day outage.
   - **IMAP connection failures** → bad/missing credentials, network, or mailbox auth (check `.wex_imap_env`).
   - **`Found 0 UNSEEN messages`** → WEX isn't delivering, or something is marking the emails read before the fetch sees them.
   - **URL-extraction failures** → the WEX email format/layout changed and the report-link extractor no longer finds it.

3. **Verify the mailbox is actually receiving WEX emails:** log into webmail for `wex@brookstoneoutdoors.com` and confirm the WEX emails are (a) arriving, (b) **UNSEEN** (not already read), and (c) from the expected sender / subject the fetcher matches on.

4. **Run the fetch by hand to watch real-time output:**
   ```
   cd /home/brookstoneadmin/brookstone && \
   . $HOME/.wex_imap_env && \
   /opt/alt/php83/usr/bin/php /usr/local/bin/drush wex:fetch-email
   ```

### "Vehicle mileage isn't updating"

The fetch log shows repeated lines like:

```
[warning] Vehicle XXXXX: skipped lower odometer read (NNNNN < current MMMMM)
```

A one-off skip is normal (it's the pump-typo guard — see "What Happens Automatically"). But when the **same vehicle** gets rejected across multiple runs with **reasonable-looking** odometer readings, the stored `field_current_mileage` on that vehicle is likely **corrupted (too high)** — every legitimate read now looks "lower" and gets ignored.

Fix:
1. Physically read the vehicle's actual odometer.
2. Correct the stored baseline:
   ```
   drush eval "
   \$vehicle = \Drupal::entityTypeManager()
     ->getStorage('equipment')->load(VEHICLE_ID);
   \$vehicle->set('field_current_mileage', ACTUAL_ODOMETER);
   \$vehicle->set('field_current_mileage_updated_on',
     date('Y-m-d\TH:i:s'));
   \$vehicle->save();
   "
   ```
3. Future imports will accept correct reads going forward.

#### Known suspected-corrupt mileage baselines (as of 2026-06-21)

Listed for awareness — resolution requires reading the physical odometer and correcting via the steps above:

| Vehicle ID | Stored `field_current_mileage` | Why suspected | 
|---|---|---|
| 77628 (Webster) | 81,983 | Multiple rejected reads in the ~18–19K and ~76–77K ranges — stored value looks corrupted (too high). |
| 35103 (highest-mileage unit) | 300,649 | Plausible for the oldest truck, but worth a physical check before trusting it as the baseline. |
| 35110 | 124,147 | Two rejected reads close to the baseline (101,497 and 123,726) suggest it may be slightly off. |

### "Cron job stopped running entirely"

1. **Confirm the cron is installed (with the correct PHP path):**
   ```
   crontab -l | grep wex
   ```
   It should show the full line including `/opt/alt/php83/usr/bin/php` before `/usr/local/bin/drush`.
2. **Confirm the env file is present + locked down:**
   ```
   ls -la ~/.wex_imap_env
   ```
   Expect `-rw-------` (0600). If missing, the IMAP credentials need restoring.
3. **Confirm the cron daemon is running:**
   ```
   ps aux | grep crond | grep -v grep
   ```

## Where to Find Things

| Page | URL |
|---|---|
| Master list of all transactions | `/admin/operations/equipment/fuel-transactions` |
| Review queue (unmatched only) | `/admin/operations/equipment/fuel-transactions/unmatched` |
| Import form | `/admin/operations/equipment/fuel-transactions/import` |
| Per-vehicle fuel history | Each vehicle's view page — scroll to the "Fuel Transactions" section (EVA) |

## Related

- [`bos_wex_import.md`](bos_wex_import.md) — technical spec of the import module
- [`__BOS_AI/Entities/equipment_fuel_transaction.md`](../Entities/equipment_fuel_transaction.md) — the entity these records become
- [`__BOS_AI/Entities/users.md`](../Entities/users.md) → "External System Identifiers" — the prompt-ID field on teammate profiles
- [`__BOS_AI/Entities/equipment.md`](../Entities/equipment.md) → "Fleet Management Fields" — the mileage fields the import auto-updates
