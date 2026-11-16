# bos_router_guard — router integrity guard

**Package:** BOS Infrastructure · **Shipped:** dev 2026-11-15, live 2026-11-15 · **Menu-link pass** added 2026-11-16, live 09:25 MT (`62fb2b1a`; see below) · **Rollback:** `drush pmu bos_router_guard` (nothing else references it).

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
| `RoutingEvents::FINISHED` | Record the row count (`state bos_router_guard.expected` + `expected_at`). If an incident was open: close it, log **Router REPAIRED**, and if an alert went out for it, email the all-clear. Then the **menu-link pass** (below) — core's menu rebuild ran at priority 100, just before. |
| `kernel.exception` (priority 250, ahead of core's fast-404) | On a `NotFoundHttpException` or a `RouteNotFoundException` thrown while rendering: **one** table check per request and at most **one a minute** per site (`cache.default` item), so a scanner's 404 storm costs one `COUNT(*)`. Never throws. |
| short table | Open/update the incident in State, log at **error**, email the alert address (≤ 1 per `alert_interval`, 1 h), request a rebuild (≤ 1 per `rebuild_interval`, 10 min) — at **kernel.terminate** for a web request (the visitor gets the 404 page first), **in-process** for cron. |
| `kernel.terminate` (priority 90, after automated_cron) | Run the pending rebuild under core's `router_rebuild` lock. Re-inspects first: if another process already repaired it, nothing happens. |
| `hook_cron` | Check + repair in-process. The path that does not wait for a visitor, and the one that catches a table left short by a dump that died in a **drush** process. Then the **menu-link pass** — from a fresh process, which is what catches a cause local to the one that rebuilt the menu. |
| `hook_runtime_requirements` | One row on `/admin/reports/status` — **Router integrity (BOS)**: OK "5,663 routes, matching the last complete rebuild (11/15/2026 7:46 AM MST)", Warning before the first recorded rebuild, **Error** with the short count and the incident time. The read-only probe. Since 2026-11-16 the description also carries the menu-link picture: stale links (stored unrouted, path resolves today) → **Warning** + the manual command; dead links (path has no route) listed for the office; the last pass's time, trigger and counts. |

**Inside the rebuild** the guard lifts PHP's memory limit (web only) and sets a 300 s time limit.
Reason: a PHP fatal is a *shutdown*, and a shutdown **commits** the half-written table — the whole
bug — whereas the LVE OOM kill on live is a **SIGKILL**, which rolls back. Prefer the kernel's
limit to PHP's. Measured on dev 2026-11-15: a router-only rebuild is **6.6 s, peak 272 MB** with
22k routes; **4.2–4.6 s** with the 5.7k that remain after the JSON:API removal.

## Menu links stored without a route (added 2026-11-16)

**The failure.** `menu_link_content` links store their *routedness*, not just their path: every
`internal:` link (core marks them all `rediscover = 1` on save) is resolved through the path
validator whenever the menu tree is rebuilt — `MenuLinkContent::getPluginDefinition()` — and the
row in `menu_tree` gets either `route_name` or, when the path could not be matched **at that
moment**, `url = base:admin/operations`. Nothing revisits the row until the next menu rebuild. A
baked link skips its route's access check, loses the active trail, and one directly under
`system.admin` makes core's `MenuVisualView.renderVertical()` throw on an invalid selector, which
is how live lost every toolbar expand arrow on 2026-11-16 (22 of 55 internal links baked; fixed by
hand with `plugin.manager.menu.link->rebuild()`; gotcha "A menu-link rebuild while the router is
broken bakes `base:` into `menu_tree`…"). The process that baked them was not pinned down; a
half-written router or a cached negative `route_match` lookup both produce it.

**What the guard does.**

- `RouterGuard::probeMenuLinks()` — **read-only.** One SQL join (`menu_tree` ⨝ `menu_link_content`
  ⨝ `menu_link_content_data`, `url LIKE 'base:%'` and `link__uri LIKE 'internal:%'`), then each row
  is re-derived in memory exactly as a rebuild would and classified: **repairable** (the path
  resolves to an ordinary route — the stored row is stale), **role_gated** (the path is a Page
  Manager page with selection conditions or several variants — see below; never repaired),
  **unrouted** (no route serves the path: a dead link or a redirect), **failed** (could not derive;
  `error` says why). Rows stored *routed* to a variant of a role-gated page are reported as
  **pinned**. **Why Page Manager is special:** route *filters* may consult the current user —
  page_manager's `VariantRouteFilter` keeps only the variant whose selection conditions the
  current user meets (`$variant->access('view')`), and the context repository caches that user for
  the rest of the process (`LazyContextRepository`), so the same link derives differently in a
  drush process (no user → no variant → `base:`) and in an administrator's web request (→ the
  **admin** variant's route). `/teammates` is such a page (admin roles / teammates / supervisor
  variants). Stored routed to one variant, the menu link carries that variant's access check and
  disappears for every other role; stored as a plain URL, every role follows it to its own variant.
  An account switch does not make this user-independent (the cached context wins), so the rule is
  **structural**: a path that is a `page` entity with conditioned or multiple variants is role-gated,
  full stop — not repaired when stored `base:`, and flagged **pinned** (Status row Warning, one
  warning log line when it first appears) when stored routed; a menu rebuild run from **drush**
  restores the plain URL. Core's own rebuild has no such guard; BOS rebuilds run in drush, so this
  has not bitten yet, and now it would be seen.
- `RouterGuard::repairMenuLinks($trigger)` — probe, then for each repairable row
  `MenuLinkManager::updateDefinition($plugin_id, $definition, FALSE)` — the path
  `MenuLinkContent::postSave()` takes — which rewrites the `menu_tree` row and invalidates
  `config:system.menu.<menu>`, so the toolbar hash changes and browsers re-fetch their subtrees.
  Nothing is written when nothing is repairable. The pass is summarised in State
  `bos_router_guard.menu_links` (`at`, `trigger`, `checked`, `repaired[]`, `unrouted{plugin_id:
  label}`, `failed[]`); re-routes are logged at **notice**, failures at **error**.
- Runs at **`RoutingEvents::FINISHED`** (the existing listener, priority 0 — after core's
  `MenuRouterRebuildSubscriber` at 100 has rebuilt the tree; matching reads the `router` table
  directly and the `route_match` tag was cleared at 200, so no lower priority was needed) and on
  **cron**. Honest caveat: core's own rebuild at FINISHED already re-derives every `internal:`
  link, so a baked row survives a rebuild only when *that process* could not match the path — the
  guard's retry in the same process will usually find the same thing and **record** it (Status row
  → Warning), and the **cron pass from a fresh process** is the one that actually re-routes it. Live
  cron is `automated_cron` every 3 h (`interval: 10800`), so within 3 h at the latest. Manual: `drush php:eval '\Drupal::service("bos_router_guard.guard")->repairMenuLinks("manual");'`.
- **Residue on dev and live (2026-11-16): two stored-unrouted links — neither dead.** `Office →
  internal:/teammates` (menu `teammate-navigation`) is the role-gated Page Manager page above
  (correctly a plain URL; the Status row says "nothing to fix"); `Handbook →
  internal:/teammates/handbook` (menu `admin`) is a `redirect`-module 301 to
  `/teammates/training/handbook` (works; pointing the link at the target directly would make it
  routed, with an access check — the Status row lists it as "no route — a dead link or a redirect").

**Deploying it was crew-hours safe by construction:** only method bodies of the existing service,
listener and hooks changed; the two new constructor dependencies are **optional** (`\Drupal::service()`
fallback) so the container compiled before them keeps constructing the service with nine arguments,
and `services.yml` injects them properly at the next container rebuild, whenever that is. Live's web
PHP revalidates opcache every 60 s (`opcache.validate_timestamps=1`, `revalidate_freq=60`), so a
code-only rsync is live within a minute with no `cr`.

## Emails

Plain text, from `hook_mail` (keys `short`, `repaired`), URLs alone on their line (the legacy mail
path collapses spaces and wraps at ~77 columns).

- `BOS ALERT: router table short on <host> — self-repair requested|running|already requested in the last 10 min`
  — the numbers, the missing core routes, the trigger and time, what the guard did, the manual
  repair (`drush cr`; the live incantation), the Status report link.
- `BOS: router table repaired on <host> — 5,663 routes` — only when an alert went out for that
  incident.

**Recipient:** `bos_router_guard.settings:alert_email`, empty = the site mail. **Live is set to
`todd@brookstoneoutdoors.com`** (2026-11-15; dev falls back to the site mail → Mailpit). Change it with
`drush cset bos_router_guard.settings alert_email you@example.com`.

## Settings (`config/install/bos_router_guard.settings.yml`, schema in `config/schema/`)

`alert_email: ''` · `rebuild_interval: 600` · `alert_interval: 3600`. Not in `config/sync`
(precedent: the other `bos_*` settings objects); `core.extension.yml` in sync lists the module.

## Scripts

- `web/scripts/router_guard_verify.php` — **read-only, safe on live**: modules (guard on, jsonapi +
  bos_jsonapi_guard off, no `jsonapi.*` rows), wiring (service, three listeners, three hooks,
  recipient), table (count/expected/sentinels/incident, biggest prefixes), the Status report row,
  three anonymous GETs through the kernel (`/jsonapi` → 404, `/user/login` → 200, bogus → 404 not
  500, and the 60 s cache item proving the 404 ran the check), the menu-link picture (no stale
  stored-unrouted link, none failed to derive; dead links and the last pass printed), last five log
  lines; no pinned Page Manager link; role-gated and no-route links printed. **27 checks** (23 before 2026-11-16).
- `web/scripts/test_router_guard.php` — **dev only** (deletes router rows, then rebuilds): fakes a
  dump that died (322 `view.*` rows + `user.login`), drives 404 → check → alert email →
  terminate-rebuild → repaired email, both throttles, the cron path, the whole-table no-ops, the
  adopt-upwards case; bakes a routed `internal:` menu link as `base:` the way a rebuild does when it
  cannot match the path and checks probe / Status-row Warning / `repairMenuLinks()` / cron / idle
  pass / notice log, plus the FINISHED pass after the real rebuild; pins the Office link to the admin
  variant and checks it is reported (Status Warning, warning log, State) and never "repaired"; and
  reproduces the real hazard — after a control step resolves the current user as uid 1, core's own
  rebuilds in that process pin the Office link, which the guard's FINISHED pass reports; restores the
  table, both links and state and deletes its Mailpit messages. **65 checks** (42 before 2026-11-16). Drush exits 1 because
  the guard logs at error level — read the "N passed, 0 failed" line.

## Diagnosis / manual repair (if it ever cannot self-heal)

1. `/admin/reports/status` → Router integrity row, or `drush php:script web/scripts/router_guard_verify.php`.
2. `SELECT COUNT(*) FROM router` vs `drush state:get bos_router_guard.expected`;
   `SELECT SUBSTRING_INDEX(name,'.',1) p, COUNT(*) FROM router GROUP BY p` shows the cut.
3. `drush cr` from the CLI (the full flush, not `router:rebuild` — menus/toolbars rendered with
   half the links sit in render caches). Then warm `/winterize`, `/`, `/colorado`,
   `/services/sprinkler-system` sequentially.
4. Find what terminated the rebuild: the access log for the minute `information_schema.TABLES.UPDATE_TIME`
   gives for `router` (gotcha recipe).
5. Toolbar expand arrows gone, or a menu link that opens for a role it should not: the Status row's
   menu-link note, or `probeMenuLinks()` from the verify script; repair with `repairMenuLinks('manual')`
   (3 s, no `cr`).

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
