# Drupal 11 — live cutover checklist

**Window:** Sunday 2026-11-08, crew off. **Operator:** Todd + Claude Code, in the foreground, one
step at a time, each gated on the previous step's exit code. **Rehearsed:** Saturday 2026-11-07 on
a fresh live dump in `brookstone-d11` (runbook `drupal_11_upgrade.md` §4g carries the why; this
file is the how). Status-of-record stays `ROADMAP.md`.

**Rehearsal numbers (Saturday):** `drush updb` on a fresh live dump — **74/74 updates, 0 errors, wall
22:00, peak RSS 1,313,976 KB ≈ 1.25 GB** (GNU `time -v`; the largest single child process). Live's
binding limit is **1 GB PMEM** (CloudLinux LVE — `/proc/lve` is present; the box has 15.4 GB RAM,
~10 GB free), so that process would be killed. **Saturday evening, measured on live:** a 1.5 GB allocation in one
PHP process is **killed (exit 137)** — the ceiling is real — and `/proc/lve` shows full LVE
(`resellers`, `per-lve`, `list`), i.e. LVE Manager exists on this box. **Todd's WHM login does not
show it**, which means the login is the reseller `toddwell`, not `root`. **So:**
- **Root WHM available by morning → Path A** (step 0a, then `updb` as rehearsed, 22 min).
- **Otherwise → Path B** (`update.php`; measured 673 MB peak per request, under the 1 GB ceiling;
  ≈20–30 min) **with step 0b below**. No root needed. Both paths are fully written.

**DECIDED Saturday 2026-11-07 evening: Path B.** Todd confirmed the WHM login is `toddwell`
(reseller — no LVE Manager, no root). Step 0a is **skipped**; step 0b is **done and verified
read-only** (`max_execution_time=600` in `~/.cl.selector/alt_php83.cfg`). Sunday starts at the
⚠ SSH check, then step 0. `date.timezone=UTC` in the PHP ini was questioned and **left alone**:
Drupal's `system` `TimeZoneResolver` sets the timezone per request from `system.date`
(live: `America/Denver`); the ini value never reaches a page.

**Re-opened later Saturday night — Path A is back on the table, decided by a MEASUREMENT Sunday
morning, not by a login.** Hosting.com's managed VPS (A2's KB) ships cPanel + *reseller* WHM by
default — which is exactly the `toddwell` login — and **root is a per-plan option whose WHM/SSH root
credentials live in the Customer Portal under the VPS product**, not in WHM. Two ways to get Path A
without a single WHM click by Todd: (1) root credentials found in the portal → root WHM at
`https://65.181.121.20:2087` → CloudLinux Manager → `brookstoneadmin` → PMEM 3072; (2) no root →
support ticket Saturday night asking for `brookstoneadmin` PMEM → **3072 MB** (they do it in
CloudLinux Manager; smallest ask, nothing for us to touch). **Sunday, right after the SSH check, run
the step-0a proof** (`-d memory_limit=3G` 1.5 GB `str_repeat`): **OK → Path A**, **Killed → Path B**,
which stays fully prepared (0b is done). `/proc/lve/list` is not readable as the account user, so
the proof is the only read we have; cPanel → *Resource Usage* shows the current limits to Todd.

## Fixed facts used below

| | |
|---|---|
| Live root | `/home/brookstoneadmin/brookstone` (ssh host `brookstone`) |
| Drush on live | `/opt/alt/php83/usr/bin/php -d memory_limit=5120M vendor/drush/drush/drush.php` — **the deploy script's own invocation; never bare `drush`, never `vendor/bin/drush` (a bash wrapper — `php vendor/bin/drush` prints its source and exits 0, as the rehearsal found), never a lower limit** (lowering it caused an OOM kill, per the script's comments) |
| PHP on live | 8.3.36 (Alt-PHP). Bare `php` on PATH is a different interpreter — never use it |
| Composer on live | 2.9.4, via `bash -lc` (login shell); the deploy script handles it. (The sandbox container runs 2.11.1 — the lock's `plugin-api-version` line will churn; harmless.) |
| Deploy script | `dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh --live` run **from the `brookstone-d11` checkout on `feature/drupal-11-upgrade`, clean tree**. It rsyncs the repo (excluding `vendor/`, `web/core/`, contrib, `files/`, `settings.php`, `*.sql.gz`), then `composer install --no-dev …`, then `drush cr`. Config import never runs. |
| Dumps in the sandbox root | excluded from rsync (`*.sql.gz`) — verified |
| Maintenance | **managed by hand for the whole window** (`--no-maintenance` on the deploy) so `updb` runs inside it too |
| Cron on live | 02:30 DB backup · 07:00 WEX fetch · 07:15 WEX alert — a daytime window avoids all three |

## T-1 — Saturday, after the rehearsal

- [ ] Rehearsal green end-to-end **using only the committed scripts** — any hand fix became a script change, committed, pushed.
- [ ] Branch pushed; `brookstone-d11` checkout clean on `feature/drupal-11-upgrade`.
- [ ] Numbers above filled in and under the ceiling.
- [ ] Todd has clicked through the sandbox on live data: maps, a contract edit **saved**, a WO sign-off, dispatch, Route Editor, QB export list. **Still open Saturday night — do it Sunday morning before step 0 if not done; it is the only check nobody has run.**

## T-0 — Sunday

Every `drush` below means the live invocation in the table. Run from the live shell unless marked LOCAL.

### 0a. WHM — raise the memory ceiling for the window (Path A), and PROVE it took
WHM → **CloudLinux LVE Manager** (a.k.a. CloudLinux Manager) → **Users** → `brookstoneadmin` → edit
limits → **PMEM: 3072 MB** (3 GB — the measured need is one 1.25 GB child plus the ~150 MB drush
parent; 3 GB is 2× headroom and the box has 10 GB free). Leave CPU/EP/IO alone. **Set it back to
1024 MB after step 9.**

Then prove it from the account shell — `lveinfo` is not visible to this user, so allocate past the
old ceiling and see the process survive:
```bash
/opt/alt/php83/usr/bin/php -d memory_limit=3G -r '$a = str_repeat("x", 1500 * 1024 * 1024); echo "1.5 GB allocated: OK\n";'
```
"OK" = the new limit is in effect. "Killed" = it is not; do not go past step 5 until it is.

### 0b. cPanel — Path B's one prerequisite (no root needed)
Live's web PHP (Alt-PHP 8.3 via CloudLinux PHP Selector) runs with a per-user **`memory_limit=2G`**
already (`~/.cl.selector/alt_php83.cfg`) but the global **`max_execution_time=60`** — and the
heaviest `update.php` steps ran up to **179 s** in the rehearsal. In cPanel (the account, not
WHM): **Select PHP Version → Options → `max_execution_time` = 600** for the window; back to 60
after step 9. Verify from the shell, read-only: `grep max_execution_time ~/.cl.selector/alt_php83.cfg`
should show 600. (Note `max_execution_time` counts CPU time on Linux, not wall time; 600 is
margin, not a prediction.)

### ⚠ Before anything: confirm SSH works, and touch nothing else in WHM
On Saturday evening, something opened or saved in WHM (most likely "Modify an Account" with the
`toddwell_BOS` package defaults) **switched the account's shell access off** — `ssh brookstone`
answered *"Shell access is not enabled on your account"* until **Manage Shell Access → Normal
Shell** restored it. The deploy, the backups and `updb` all need SSH. First command of the day:
```bash
ssh brookstone 'echo shell OK'
```
and make no WHM change on Sunday beyond step 0a (if root) — each one risks a side effect at the
worst moment.

### 0. Backups — nothing else starts until both exist and their sizes make sense
```bash
cd /home/brookstoneadmin/brookstone && mkdir -p ~/backups
STAMP=$(date +%Y%m%d_%H%M)
drush sql:dump --gzip --result-file=~/backups/pre_d11_db_$STAMP.sql.gz      # expect ~150 MB; same method as the nightly ~/db_backups/bos-db-*.sql.gz
tar -czf ~/backups/pre_d11_code_$STAMP.tgz --exclude='web/sites/default/files' -C /home/brookstoneadmin brookstone   # code only; files are on S3
ls -la ~/backups/pre_d11_*                                                   # both present, both plausible
```

### 1. Maintenance ON (and stays on until step 9)
```bash
drush sset system.maintenance_mode 1 -y && drush cr -y
```

### 2. LOCAL — ship the scripts ahead of the code (they must run on D10 code, before the deploy)
```bash
scp /home/todd/code/brookstone-d11/web/scripts/{d11_step0_cleanup,d11_step5_retire_modules,d11_step6_config_fixes,scan_class_compat,audit_views_handlers}.php brookstone:/home/brookstoneadmin/brookstone/web/scripts/
```

### 3. Pre-deploy scripts, on D10 code — **dry-run first, read it, then apply; stop on any non-zero**
```bash
drush scr web/scripts/d11_step0_cleanup.php            && drush scr web/scripts/d11_step0_cleanup.php -- --apply
drush pmu -y jquery_ui_datepicker jquery_ui_slider jquery_ui_touch_punch
drush scr web/scripts/d11_step5_retire_modules.php     && drush scr web/scripts/d11_step5_retire_modules.php -- --apply
drush scr web/scripts/d11_step6_config_fixes.php -- --skip-verify && drush scr web/scripts/d11_step6_config_fixes.php -- --apply --skip-verify
drush cr -y
```
Expected on live (rehearsed on a live dump, Saturday): step 0 installs the `ai_agent_override` definition, removes the `migrate_devel` orphan, uninstalls `action`, `forum`, `tour` (deleting the stock "General discussion" term first) and deletes the five forum block placements — **10 items**; step 5 retires `editablefields` (rewriting the QB export list column first — dependents must hit 0), `migrate_file`, `imagefield_tokens`, `metatag_views` — **5 items**; the config fixes repair `equipment_fuel_transactions_eva` (formatter), `teammate_estimates` (empty vid → `estimate_stage`), remove one dead Layout Builder component, and **delete** `estimate_current_customer_info` (YAML printed first — keep the scrollback) — **4 items**. **A refusal is a stop, not a warning.**

> Why the config fixes run **here**, on D10 code, with `--skip-verify`: on D11 code against the D10
> schema a config save can trigger a router rebuild that fails on the `alias` column
> `system_update_11201` has not yet added. Their *verification* (section 4) runs after the deploy
> (step 5b), once `page_manager` is patched — before that it truthfully reports the three
> PageVariants throwing, which is the wrong moment.

### 4. LOCAL — deploy the branch
```bash
cd /home/todd/code/brookstone-d11 && git status --short | wc -l     # must be 0
./dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh                 # dry-run first; read the file list
./dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh --live --no-maintenance --skip-cr   # type LIVE
```
Composer on live moves core to 11.4.12, applies the four declared patches (a failure **aborts** — that is the tripwire) and regenerates both `autoload_runtime.php` files.

> ⚠ **`--skip-cr` is required, not optional.** On D11 code against the D10 schema, `drush cr`
> **fails deterministically** at the router rebuild — `SQLSTATE[42S22] Unknown column 'alias'` —
> because `system_update_11201` adds that column and has not run yet. The deploy script's
> post-composer `cr` would die and abort the deploy with the code already moved. Other drush
> commands bootstrap fine (the container rebuilds on the way in); only the router rebuild dies.
> The first `cr` happens in step 7, after `updb`. Reproduced on Saturday's rehearsal.

Then, still on live, **one `cr` that is expected to fail** — and must be run anyway:
```bash
drush cr -y ; echo "exit $? — expected 1: 'Unknown column alias' at the router rebuild"
```
It clears every cache bin and rebuilds the container *before* it reaches the router step and dies
there. Without it, the first drush command on the new code hits the D10 cached container and
fails with *"The …EntityFormDisplay class does not correspond to an entity type"* (rehearsed twice).
Any other error from this `cr` is a stop.

Then verify on live (shell checks only — **no web request for 60 s after the deploy**, see Path B):
```bash
grep -oE "const VERSION = '[^']+'" web/core/lib/Drupal.php                    # 11.4.12
ls -la vendor/autoload_runtime.php web/autoload_runtime.php                   # both exist
grep -c "?? ''" web/core/lib/Drupal/Core/Layout/LayoutPluginManager.php       # 2
grep -c CacheLookup web/.htaccess                                             # >0 (bos_edge_cache block intact)
drush status --fields=drupal-version,bootstrap,db-status                      # 11.4.12 / Successful / Connected
```

### 5b. Config fixes — after the deploy (patched `page_manager`), **before** `updb`
```bash
drush scr web/scripts/d11_step6_config_fixes.php -- --verify-only        # section 4 only, no saves
```
Must print **"none throw"** and exit 0 — it replicates core's post-update over the ~350 config entities that depend on `help`, in memory, against the now-patched `page_manager`. If anything throws, stop.

### 5. Class-load scanner — **before** `updb`
```bash
/opt/alt/php83/usr/bin/php web/scripts/scan_class_compat.php
```
Expected FATAL lines and nothing else: `ai_search` (disabled), `bos_contract_migrate` (disabled), `s3fs` (dead legacy class — the route uses `NewS3fsImageStyleDownloadController`), `metatag_views` (uninstalled in step 3). **Any other FATAL on an enabled module = stop and read it.**

### 6. The one-way door — pick the path first

**Path A — only if root WHM turned up and step 0a was done: run `updb` as rehearsed (22 min, 74/74). NOT the chosen path as of Saturday night.**
```bash
drush updatedb:status                                                         # expect 74 on LIVE: 45 core + 29 contrib (BEF, flag, address, ai, metatag, captcha…) that dev had already absorbed in steps 2–5
# GNU time is NOT installed on live: sample peak RSS of the php processes in the background instead.
( while sleep 2; do ps -o rss= -u brookstoneadmin -C php 2>/dev/null | sort -n | tail -1; done > ~/backups/d11_updb_rss_$STAMP.log ) & SAMPLER=$!
date; /opt/alt/php83/usr/bin/php -d memory_limit=5120M vendor/drush/drush/drush.php updb -y 2>&1 | tee ~/backups/d11_updb_$STAMP.log; date
kill $SAMPLER; echo "peak RSS KB: $(sort -n ~/backups/d11_updb_rss_$STAMP.log | tail -1)"
```
`updb` is **resumable** — if it dies, read the last lines, fix nothing by hand unless it is one of the known shapes, re-run. It leaves maintenance mode on if it dies (we want that). Afterwards:
```bash
drush updatedb:status                                                         # "No database updates required"
```
Record the two `date` stamps and the peak RSS in the runbook.

**Path B — THE CHOSEN PATH (decided Saturday night, see top). `update.php`, one web request per
batch step, each a bounded process — measured Saturday on the kept dump:** all 74 updates applied; **peak PHP-worker RSS 673 MB, p95
529 MB, median 186 MB** (3,207 one-second samples) — under the 1 GB ceiling with margin; the heavy
steps are the views/BEF post-updates at **23–179 s per request**, so budget **≈20–30 min** of work
(my loop then spun for 50 min on the finished batch — hence the pending-count stop rule below).
The site is in maintenance mode; `update.php` is reachable to uid 1. **Wait 60 s after the deploy
before the first web request of any kind** — live's opcache revalidates every 60 s, and a request
inside that window hit a stale-container error on the login page in the rehearsal. From the LOCAL
shell, with a uid-1 cookie:
```bash
BASE=https://brookstoneoutdoors.com; JAR=$(mktemp)
U="$(ssh brookstone 'cd /home/brookstoneadmin/brookstone && /opt/alt/php83/usr/bin/php -d memory_limit=5120M vendor/drush/drush/drush.php uli --no-browser' | tr -d '\r' | grep -o 'http[^ ]*' | head -1)"
curl -s -c $JAR -b $JAR -L -o /dev/null "$U"
curl -s -b $JAR -c $JAR -o /tmp/sel.html "$BASE/update.php/selection"; grep -oE '[0-9]+ pending updates?' /tmp/sel.html   # read: 74
# In this core the "Apply pending updates" control is a plain link, not a form: GET /update.php/run creates the batch.
LOC=$(curl -s -b $JAR -c $JAR -o /dev/null -w '%{redirect_url}' "$BASE/update.php/run")   # → …/update.php/start?id=N&op=start
STEP="$(printf '%s' "$LOC" | sed 's/op=start/op=do_nojs/')"; echo "step URL: $STEP"   # op=do_nojs ALONE. PHP keeps the last `op`, so the Drupal-7-era `op=do_nojs&op=do` drives the JSON (JavaScript) endpoint instead — it still processes updates, but reports JSON and never shows the finish (rehearsed). Built with sed: bash 5.2 expands & in ${x/y/z}.
# No text-based error detection: the healthy batch page carries "An unrecoverable error has occurred" in hidden markup (it stopped two rehearsal runs). The verdict is the pending count, checked every 25 requests.
PEND() { ssh brookstone 'cd /home/brookstoneadmin/brookstone && /opt/alt/php83/usr/bin/php -d memory_limit=5120M vendor/drush/drush/drush.php updatedb:status --format=json 2>/dev/null' | python3 -c 'import sys,json; d=sys.stdin.read(); print(len(json.loads(d)) if d.strip() else 0)'; }
N=0; while :; do EFF=$(curl -s -b $JAR -c $JAR -L -o /tmp/b.html -w '%{url_effective}' --max-time 900 "$STEP"); N=$((N+1)); case "$EFF" in *"/update.php/results"*) echo "results page at request $N"; break;; esac; grep -qi 'http-equiv="Refresh"' /tmp/b.html || { echo "no meta-refresh at request $N"; break; }; if [ $((N % 25)) -eq 0 ]; then P=$(PEND); echo "request $N: $P pending"; [ "$P" = 0 ] && break; fi; sleep 1; done
echo "$N requests → $EFF"; grep -oiE 'updates? (was|were) performed[^<]{0,80}|failed[^<]{0,80}' /tmp/b.html
```
Then on live: `drush updatedb:status` → "No database updates required", then `drush cr`. (`sleep 1`
between steps: live is shared.) If the loop ends with updates still pending, the last `/tmp/b.html`
is the error page — read it; `drush updb` can resume the rest under Path A rules only.

### 7. Rebuild and warm — sequentially, sleep 3 between (live is shared)
```bash
drush cr -y
drush php:eval 'printf("entity defs pending %d, schema orphans %d, modules %d\n", count(\Drupal::entityDefinitionUpdateManager()->getChangeList()), count(array_diff_key(\Drupal::keyValue("system.schema")->getAll(), \Drupal::service("extension.list.module")->getAllAvailableInfo())), count(array_filter(\Drupal::service("extension.list.module")->getList(), fn($m)=>$m->status)));'
```

### 8. Smoke — **read bodies, not status codes** (a PHP fatal before Drupal's handler is a 200)
LOCAL, against `https://brookstoneoutdoors.com`, one request at a time, `sleep 3` between:
```bash
for p in / /winterize /colorado /services/sprinkler-system; do curl -s -o /tmp/b.html -w "$p %{http_code} %{size_download}B " "https://brookstoneoutdoors.com$p"; echo "drupal=$(grep -c 'data-drupal\|drupal-settings-json' /tmp/b.html) fatal=$(grep -ci 'fatal error' /tmp/b.html)"; sleep 3; done
curl -s -o /dev/null -w "/jsonapi %{http_code} (want 401)\n" https://brookstoneoutdoors.com/jsonapi
```
(The maintenance page is a 503 — these run after step 9 for the public pages; `/jsonapi` and the drush checks run before.) On live via drush: `drush core:requirements --severity=2` → errors should match the pre-cutover set (no new "incompatible"/"plugin" errors).

### 9. Maintenance OFF, then Todd's eyes
```bash
drush sset system.maintenance_mode 0 -y && drush cr -y
```
Wait 60 s (web opcache revalidation), then the step-8 public loop, then Todd: `/teammates/calendar`, a WO page, dispatch, Route Editor, a property page map, the QB export list, a contract edit **saved**, `/admin/reports/status`.

## Go / no-go rule — decided now, not during
Anything red in steps 4–9 that is **not understood within 15 minutes → roll back.** No debugging on live.

## Rollback (≈10 minutes)
```bash
drush sset system.maintenance_mode 1 -y
cd /home/brookstoneadmin && mv brookstone brookstone.failed_$STAMP && tar -xzf ~/backups/pre_d11_code_$STAMP.tgz -C /home/brookstoneadmin
mv brookstone.failed_$STAMP/web/sites/default/files brookstone/web/sites/default/files     # files were excluded from the tar
cd brookstone && zcat ~/backups/pre_d11_db_$STAMP.sql.gz | $(drush sql:connect)
drush cr -y && drush sset system.maintenance_mode 0 -y && drush cr -y
```
Then the step-8 loop. The failed tree stays on disk for the post-mortem in the sandbox.

## After success
- [ ] LOCAL: `cd /home/todd/code/brookstone && git merge --ff-only feature/drupal-11-upgrade && git push` — `main` is the live reference.
- [ ] Re-seed Todd's main dev env from a **post-cutover** live dump (`composer install` there first — main is D11 now).
- [ ] ROADMAP: move the D11 item to Shipped with the hashes and the measured numbers; change_log entry.
- [ ] WHM: PMEM for `brookstoneadmin` back to **1024 MB** (Path A) · cPanel: `max_execution_time` back to **60** (Path B).
- [ ] Remove `https://brookstone-d11.ddev.site/*` from the Google Maps key's referrer list; delete the sandbox project when the dev env is re-seeded.
- [ ] Parked decisions resume: `calendar` retirement, `ai_ckeditor`, `phpspreadsheet`, `ban`/`contact` (Drupal 12).
