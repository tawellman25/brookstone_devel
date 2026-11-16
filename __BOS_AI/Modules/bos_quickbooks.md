# bos_quickbooks — QuickBooks Desktop integration (Web Connector)

**Package:** BOS Billing · **Status:** Phase 0 built and tested on dev 2026-11-15; **not on live yet** (P1 = the shop visit) · **Rollback:** `drush pmu bos_quickbooks` (the `qb_list` mirror entities stay — BOS never deletes history) · **Entity:** [`Entities/qb_list.md`](../Entities/qb_list.md) · **ROADMAP:** Billing row "QuickBooks Desktop integration — Web Connector sync + invoice push".

## Why it exists

Invoices must reach QuickBooks Desktop keyed on a customer identity QuickBooks keeps stable. The
IIF route (verified at the shop 2026-11-15) keys on NAME, auto-creates an unknown name as a phantom
customer, and needed a Customer List upload before every run — a step Todd ruled out ("she won't do
it right"). The **QuickBooks Web Connector** — Intuit's own Windows client, part of the Desktop SDK —
polls a URL on this site on a timer and carries qbXML requests in and answers out. All code lives
in BOS; nothing custom runs on the office PC. **Design rule (Todd, 2026-11-15): every step that
depends on the office manager either disappears or fails loudly.**

Phase 0 (this): the connector keeps a **mirror of customers, items and classes** current in BOS, each
QuickBooks customer is **linked to one BOS client by ListID**, and a stale mirror is **visible and
alerts**. Phase 1: install + authorize at the shop. Phase 2: push invoices with `InvoiceAdd` through
the same connector (the IIF writer becomes the disaster fallback).

## How a sync runs

```
Web Connector (shop PC, every N min)              BOS  /qbwc/soap  (SoapServer, WSDL document/literal)
  serverVersion / clientVersion   ──────────────▶  version strings; connector version → State
  authenticate(user, password)    ──────────────▶  QbwcSession: hash check (State), flood 10/h/IP,
                                  ◀──────────────  [ticket, ''] · ['', 'none'] if a session is live · ['', 'nvu']
  sendRequestXML(ticket, HCP, file, …, 16, 0) ──▶  first call: company name/file/product recorded;
                                  ◀──────────────  QbSyncRunner → ClassQueryRq  (then CustomerQueryRq, ItemQueryRq)
  receiveResponseXML(ticket, qbXML) ────────────▶  QbXml::parse → QbListMirror::upsert per record
                                  ◀──────────────  percent: 0–99 = send me the next request, 100 = done, −1 = error
  … iterator="Continue" pages of 500 for customers and items …
  closeConnection(ticket)         ──────────────▶  run summary → State; a clean run = last_full_sync
```

- **Jobs:** `class` → `customer` → `item`, every list queried with `ActiveStatus All` (inactive
  included — the mirror must know what QuickBooks hid). Customers and items page with QuickBooks
  iterators (`MaxReturned` = `max_returned`, 500); classes do not iterate.
- **Upsert writes only on change.** A record whose EditSequence / TimeModified / name / flags are
  unchanged costs a read, not a save; `field_last_seen` is re-stamped at most once a day. Measured
  with the real 3,544-customer list on dev: **first sync 19 s, unchanged hourly sync 12.7 s with 0
  saves**.
- **Missing, never deleted.** When a list's query completes, every mirror row of that bundle the sync
  did not return gets `field_missing = 1` (deleted or merged in QuickBooks) and is kept.
- **One list failing does not block the others.** A refused query (statusCode ≠ 0/1) fails that job
  and the run moves on; the run is then **PARTIAL** and does not count as a full sync. A connector
  `hresult` ends the session with −1 (the next scheduled poll starts over).
- **qbXML version** = the lower of the connector's offer and `qbxml_version` (16.0 = Desktop 2024).

## Customer ↔ BOS client (the key invoicing will use)

- `qb_list:customer.field_bos_client` → `user` (role `client`) is the **source of truth**. Its presave
  writes the ListID onto `profile.customer_profile.field_qb_list_id` (and clears the previous
  client's); that profile field is **disabled on the profile form** — never typed.
- **Matching pass** (`CustomerMatcher`, Drush `bos:qb:match`, confirm form at `/admin/office/quickbooks/match`):
  QuickBooks name with the office's status suffixes stripped (`EMAIL`, `NO SERVICES`, `COLLECTIONS`,
  `DECEASED`, `PREPAY`, …) + QuickBooks First/Last/Company, against BOS user name, profile
  first/last/spouse/company; keys normalised (lower, `and` → `&`, letters/digits/&/space). Exactly one
  client → linked, `auto`; two or more → `ambiguous` (note lists them); none → `unmatched`; a client
  another present customer already links → `duplicate`. `manual` and `ignored` rows are never touched.
  **Real list, dry run on dev: 1,734 active → 1,569 auto, 54 ambiguous, 9 duplicate, 102 unmatched**
  (0.5 s).
- **Review list** `/admin/office/quickbooks/review` = active, present customers in
  unmatched / ambiguous / duplicate. The office opens the row, picks the client (autocomplete over
  clients), saves → `manual`. Clearing the client → `unmatched`. Picking a client another customer
  already has shows a warning (a merge is due in QuickBooks).

## Staleness — what billing will be gated on

`QbMonitor::isStale()` = no clean run within `stale_hours` (24). Cron emails `alert_email` (empty =
site mail) once per `alert_interval` (24 h) while stale, and the all-clear when a clean run lands;
**nothing is emailed before the first-ever complete sync** (the Status report row says "Never
synced" instead). Status report row **QuickBooks connector (BOS)**: Warning = no password / never
synced, Error = stale, OK = current with counts. Phase 2's invoice run refuses to start while stale.

## Admin UI (`/admin/office/quickbooks`, Office ▸ QuickBooks)

Tabs: **Status** (connector facts, mirror counts, match counts, the shop-PC setup steps with the
`.qwc` download) · **Review** · **Customers** · **Items** · **Classes** (Views `qb_customers` with
displays page_1/page_2, `qb_items`, `qb_classes` — Views-UI editable) · **Settings** (credentials,
interval, company file, staleness, alert address, page size, qbXML version). Permissions:
`view quickbooks lists` + ECK view/edit-customer for `administration`, `site_assistant`,
`site_admin`; `administer quickbooks integration` for `site_admin` (+ `administrator`).

### The `.qwc` file (`/admin/office/quickbooks/bos-quickbooks.qwc`)

`AppURL` = `https://<host>/qbwc/soap`, `AppSupport` = `/qbwc/support`, `UserName` from settings,
`OwnerID` / `FileID` = braces GUIDs minted once at install (State — changing them means re-adding the
app), `QBType QBFS`, `RunEveryNMinutes` from settings, `IsReadOnly false` (so the one authorization
at the shop already covers Phase 2's writes), `PersonalDataPref pdpNotNeeded`,
`UnattendedModePref umpRequired` (QuickBooks offers "even when QuickBooks is not running").

## Security

- `/qbwc/soap` is anonymous at the route (`_access: TRUE`, `no_cache`, `_maintenance_access`); the
  authentication is SOAP `authenticate()`: user name + password hashed with Drupal's password
  service into **State** (never config/sync), **flood control 10 failures per hour per IP**,
  constant-time user compare. Responses are `private, no-store` so neither Drupal's page cache nor
  the LiteSpeed edge ever stores one. GET returns a one-liner (or the WSDL with `?wsdl`).
- One session at a time; a ticket is 128 random bits; an abandoned session is replaced after 10 min.
- Everything from the connector is parsed as untrusted XML (DOM, `LIBXML_NONET`, no entity expansion).
- The office PC needs HTTPS to the site (the connector refuses plain HTTP off localhost).

## Protocol contract — and where it came from

Intuit's developer pages are script-rendered and unreadable to a plain fetch; the PDF guide returned
403. The method shapes come from the QBWC WSDL (`wsdl/QBWebConnectorSvc.wsdl`, namespace
`http://developer.intuit.com/`, document/literal) and the return-value semantics from the widely
deployed PHP implementation (ConsoliBYTE quickbooks-php): `authenticate` → `[ticket, status]` with
`''` go / company-file path / `'none'` / `'nvu'`; `sendRequestXML` → qbXML or `''`;
`receiveResponseXML` → 0–99 / 100 / negative; `getLastError` → text or `NoOp`; `connectionError` →
`'done'`. **P1 at the shop therefore starts with a protocol smoke test against the real connector**
(serverVersion → clientVersion → authenticate → one ClassQuery) before the full sync is trusted.
PHP's `SoapServer` in WSDL mode hands each method ONE object (the request element's children as
properties) and takes back an array keyed `<op>Result` — proven by the dev test over real HTTPS.

## Files

| Path | Role |
|---|---|
| `src/QbwcSession.php` | password, flood, ticket, one-at-a-time, GUIDs, last contact / company |
| `src/QbXml.php` | request envelope + list queries; response parser (Customer / Item* / Class) |
| `src/QbListMirror.php` | upsert / markMissing / counts; `syncClientProfile()` (ListID → profile) |
| `src/QbSyncRunner.php` | the three-job queue, progress, run summaries, full-sync record |
| `src/QbMonitor.php` | staleness, Status row facts, stale / recovered emails |
| `src/CustomerMatcher.php` | name → ListID pass |
| `src/Soap/QbwcSoapHandler.php` | the eight SOAP operations |
| `src/Controller/QbwcController.php` | `/qbwc/soap` (SoapServer), `/qbwc/support` |
| `src/Controller/AdminController.php` | status page, `.qwc` download |
| `src/Form/SettingsForm.php`, `src/Form/RunMatcherForm.php` | settings (password → State), run the pass |
| `src/Commands/QuickBooksCommands.php` | `bos:qb:status`, `bos:qb:match [--dry-run] [--rematch]`, `bos:qb:set-password` |
| `wsdl/QBWebConnectorSvc.wsdl` | the contract; `soap:address` rewritten when served |
| `config/install/bos_quickbooks.settings.yml` | defaults (not in config/sync — precedent `bos_*` settings) |

Scripts: `web/scripts/bos_quickbooks_config_gen.php` (writes the 81 `config/sync` YAML, idempotent on
uuid) · `bos_quickbooks_setup.php` (creates them through the entity API **keeping the uuid**, enables
the module, grants permissions; re-runnable; `--dry-run`) · `bos_quickbooks_verify.php` (read-only,
live-safe, 49 checks) · `test_bos_quickbooks.php` (**dev only**: a fake connector drives the real
HTTPS endpoint — protocol, mirror, second sync with a disappearance, failure paths, matcher, office
resolution, staleness emails via Mailpit, `.qwc`, admin pages; 74 checks; cleans up; drush exits 1
because the stale alert logs at error — read the "74 passed, 0 failed" line).

## Deploying P0 to live (before the shop visit)

1. Targeted rsync, no `--delete`: `web/modules/custom/bos_quickbooks/`, the five `web/scripts/bos_quickbooks_*` +
   `test_bos_quickbooks.php`, the 81 `config/sync` files in `bos_quickbooks_config_files.txt`,
   `config/sync/core.extension.yml` is **not** imported (never) — the setup script enables the module.
2. `scp web/scripts/bos_quickbooks_setup.php` to `~/tmp`, run `drush php:script ~/tmp/bos_quickbooks_setup.php -- --dry-run`,
   then for real; **re-run until the verify section prints** (1 GB PMEM can kill it; every step is
   idempotent). Then the targeted refresh / `cr` off-hours for the menu link.
3. `drush php:script web/scripts/bos_quickbooks_verify.php` → 49 ok. Run
   `web/scripts/config_uuid_audit.php -- <the 81 files>` → 0 mismatches (the setup keeps uuids).
4. Set the connector password on `/admin/office/quickbooks/settings`; set `alert_email`.
5. Shop (P1): the steps on the status page — restored copy first, then the real file.

## Not done (Phase 2 — ROADMAP)

`InvoiceAdd` push with the grouping function, services → `qb_list:item` / `qb_list:class` mapping
fields on the services vocabulary (picked from the mirror, never typed), the export-run record,
`field_invoiced` set on QuickBooks' confirmation, `TxnID` / `RefNumber` read-back, the stale gate on
the run, the IIF writer as fallback.
