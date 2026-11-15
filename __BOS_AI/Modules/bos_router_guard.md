# bos_router_guard — router integrity guard

**Package:** BOS Infrastructure · **Shipped:** dev 2026-11-15, live 2026-11-15 · **Rollback:** `drush pmu bos_router_guard` (nothing else references it).

## Why it exists

Drupal rebuilds the router by `DELETE` + chunked `INSERT`s inside one transaction. A thrown
exception rolls that back. A request the web server **terminates** never throws: lsphp's clean
shutdown runs destructors and `Transaction::__destruct()` **commits whatever was written**. On
2026-11-14 a scanner's `GET /core/install.php` triggered a full rebuild inside a web request, the
client gave up after 15 s, and live ran 03:35–06:40 MT with **14,300 of 22,056 routes** — every
work order, property, View and the login page 404, the 404 page itself 500 — until Todd hit it and
ran `drush cr`. Nothing logged the death and nothing detected the state (gotcha
"`/core/install.php` on an installed site rebuilds the whole router…", change log 2026-11-14).

The trigger is blocked in `web/.htaccess`; this module closes the **structural** gap — any
terminated rebuild, whatever starts it.

## The signal

The dumper is the only writer of `router` and every complete dump ends with
`RoutingEvents::FINISHED`. So:

- a row count **below** the one recorded at the last FINISHED, or
- one of four **sentinel** routes every installed site has (`user.login`, `system.404`,
  `system.admin`, `entity.user.canonical`) being absent

is the signature of a dump that died, and of nothing else. Extra rows cannot appear without a
dump; a count **above** the record only means a process died between the commit and FINISHED, and
the record is adopted silently (also what happens after a dev DB re-seed from live).

## What it does

| Point | Behaviour |
|---|---|
| `RoutingEvents::FINISHED` | Record the row count (`state bos_router_guard.expected` + `expected_at`). If an incident was open: close it, log **Router REPAIRED**, and if an alert went out for it, email the all-clear. |
| `kernel.exception` (priority 250, ahead of core's fast-404) | On a `NotFoundHttpException` or a `RouteNotFoundException` thrown while rendering: **one** table check per request and at most **one a minute** per site (`cache.default` item), so a scanner's 404 storm costs one `COUNT(*)`. Never throws. |
| short table | Open/update the incident in State, log at **error**, email the alert address (≤ 1 per `alert_interval`, 1 h), request a rebuild (≤ 1 per `rebuild_interval`, 10 min) — at **kernel.terminate** for a web request (the visitor gets the 404 page first), **in-process** for cron. |
| `kernel.terminate` (priority 90, after automated_cron) | Run the pending rebuild under core's `router_rebuild` lock. Re-inspects first: if another process already repaired it, nothing happens. |
| `hook_cron` | Check + repair in-process. The path that does not wait for a visitor, and the one that catches a table left short by a dump that died in a **drush** process. |
| `hook_runtime_requirements` | One row on `/admin/reports/status` — **Router integrity (BOS)**: OK "5,663 routes, matching the last complete rebuild (11/15/2026 7:46 AM MST)", Warning before the first recorded rebuild, **Error** with the short count and the incident time. The read-only probe. |

**Inside the rebuild** the guard lifts PHP's memory limit (web only) and sets a 300 s time limit.
Reason: a PHP fatal is a *shutdown*, and a shutdown **commits** the half-written table — the whole
bug — whereas the LVE OOM kill on live is a **SIGKILL**, which rolls back. Prefer the kernel's
limit to PHP's. Measured on dev 2026-11-15: a router-only rebuild is **6.6 s, peak 272 MB** with
22k routes; **4.2–4.6 s** with the 5.7k that remain after the JSON:API removal.

## Emails

Plain text, from `hook_mail` (keys `short`, `repaired`), URLs alone on their line (the legacy mail
path collapses spaces and wraps at ~77 columns).

- `BOS ALERT: router table short on <host> — self-repair requested|running|already requested in the last 10 min`
  — the numbers, the missing core routes, the trigger and time, what the guard did, the manual
  repair (`drush cr`; the live incantation), the Status report link.
- `BOS: router table repaired on <host> — 5,663 routes` — only when an alert went out for that
  incident.

**Recipient:** `bos_router_guard.settings:alert_email`, empty = the site mail
(`office@brookstoneoutdoors.com` on live). Set it with
`drush cset bos_router_guard.settings alert_email you@example.com`.

## Settings (`config/install/bos_router_guard.settings.yml`, schema in `config/schema/`)

`alert_email: ''` · `rebuild_interval: 600` · `alert_interval: 3600`. Not in `config/sync`
(precedent: the other `bos_*` settings objects); `core.extension.yml` in sync lists the module.

## Scripts

- `web/scripts/router_guard_verify.php` — **read-only, safe on live**: modules (guard on, jsonapi +
  bos_jsonapi_guard off, no `jsonapi.*` rows), wiring (service, three listeners, three hooks,
  recipient), table (count/expected/sentinels/incident, biggest prefixes), the Status report row,
  three anonymous GETs through the kernel (`/jsonapi` → 404, `/user/login` → 200, bogus → 404 not
  500, and the 60 s cache item proving the 404 ran the check), last five log lines. 23 checks.
- `web/scripts/test_router_guard.php` — **dev only** (deletes router rows, then rebuilds): fakes a
  dump that died (322 `view.*` rows + `user.login`), drives 404 → check → alert email →
  terminate-rebuild → repaired email, both throttles, the cron path, the whole-table no-ops, the
  adopt-upwards case, restores the table and state and deletes its Mailpit messages. 42 checks.
  Drush exits 1 because the guard logs at error level — read the "N passed, 0 failed" line.

## Diagnosis / manual repair (if it ever cannot self-heal)

1. `/admin/reports/status` → Router integrity row, or `drush php:script web/scripts/router_guard_verify.php`.
2. `SELECT COUNT(*) FROM router` vs `drush state:get bos_router_guard.expected`;
   `SELECT SUBSTRING_INDEX(name,'.',1) p, COUNT(*) FROM router GROUP BY p` shows the cut.
3. `drush cr` from the CLI (the full flush, not `router:rebuild` — menus/toolbars rendered with
   half the links sit in render caches). Then warm `/winterize`, `/`, `/colorado`,
   `/services/sprinkler-system` sequentially.
4. Find what terminated the rebuild: the access log for the minute `information_schema.TABLES.UPDATE_TIME`
   gives for `router` (gotcha recipe).

## JSON:API — uninstalled with this (2026-11-15, Todd)

16,396 of 22,064 routes were `jsonapi.*` (74 % of every rebuild's work and the exposure surface
`bos_jsonapi_guard` existed for) and **nothing consumed them** — the only API client is the
work-order intake endpoint, built on core REST (`bos_wo_intake`). JSON:API was enabled in a bulk
config commit on 2025-11-29 with no consumer; the 2026-10-04 roadmap kept it as the foundation of
a *future* crew mobile app. Decision 2026-11-15: **uninstall `jsonapi` + `bos_jsonapi_guard` until
the app starts** — `drush en jsonapi bos_jsonapi_guard` brings both back, and the field-access work
the app needs (ROADMAP "Teammate mobile app") has to be done either way. The module code stays in
the repo. `/jsonapi` is now a 404 (was 401).

## Not done (ROADMAP → LATER)

Make the dump **atomic** — decorate `router.dumper` to write `router_new` and `RENAME TABLE`
(atomic in MariaDB) so a dying rebuild can never leave a partial table — and file core's
commit-on-destruct behaviour upstream. This module makes the outage a self-healed blip with a
trace; that would make it impossible.
