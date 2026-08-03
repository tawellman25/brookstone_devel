# BOS Server Migration Playbook — shared host → managed VPS (Hosting.com)

**Status: DRAFT / living doc.** Started 2026-08-02. This is the plan for moving
BOS off the current shared cPanel/CloudLinux box onto a dedicated managed VPS
(staying with Hosting.com, to consolidate with the planned reseller program).
Refine the "Open questions for Hosting.com" section first — several later steps
depend on those answers.

---

## 1. Why we're moving (and what "success" means)

**Motivation (owner):** control over disk size + reduce recurring host friction.
Cost is not a constraint; **owner's time is** the constraint. Owner is fine with
2am incidents *provided they can be fixed quickly*.

**Success = all of these:**
- Disk is ours to size and grow at will (no shared array, no neighbor pressure,
  no inode caps disabling backups).
- The chronic environment friction is gone (see §2).
- Day-to-day ops cost *less* of the owner's time, not more (provider handles OS
  patching, hardware, network, monitoring; owner keeps root for app fixes).
- A 2am failure has a **fast** path to resolution: monitoring that says *what*
  broke + a one-command restore + a runbook (§9).

**Chosen shape:** *managed VPS with root* — not a self-managed bare VPS (too much
owner time), not a Drupal PaaS like Pantheon/Acquia (fights BOS's intentional
config drift — see §3). Rationale is in the chat of 2026-08-02 and summarized in
§3.

---

## 2. The friction we are trying to kill (root causes)

Almost every recurring host pain in BOS traces to the **CloudLinux + cPanel +
LiteSpeed/CGI** shared stack, *not* to BOS itself:

| Symptom (documented) | Root cause | Goes away on a clean VPS? |
|---|---|---|
| `drush` dies silently under cron ("preflight… command line", CGI SAPI, `$argv` undefined) | Global drush PHAR re-exec'd through the CloudLinux `/usr/bin/env php` → CGI wrapper | **Yes** — real CLI PHP; normal `drush` |
| Must invoke `/opt/alt/php83/usr/bin/php … vendor/drush/drush/drush.php` everywhere | Alt-PHP workaround for the above | **Yes** — standard `drush` works |
| `Authorization: Bearer` header stripped (forced the WO-intake `X-API-KEY` route auth) | LiteSpeed/CGI SAPI | **Yes** on nginx+php-fpm (Bearer works) — but keep the X-API-KEY design; it's fine |
| Host disables account backups at high inode counts | Shared cPanel account policy | **Yes** — our own backup regime |
| Disk at 97% on a shared 5.2 TB array | Neighbors + shared array | **Yes** — dedicated, resizable volume |
| Backups share the DB's disk (no off-site copy) | No control over storage layout | **Yes** — off-site to S3 (§9) |

> ⚠️ **The migration only delivers §1 if the new box is NOT another
> cPanel/CloudLinux/LiteSpeed managed account.** A Hosting.com "managed VPS" that
> is still cPanel/CloudLinux would give us disk control but **keep most of the
> friction above.** This is the #1 thing to confirm — see §10.

**Note — what does NOT change and shouldn't:** BOS's files already live on **S3**
via `s3fs`, so the server's own disk footprint is small (code + DB + caches; DB
dumps ~161 MB). "Control over disk size" is mostly about escaping the shared
array and inode caps, not about BOS needing terabytes. This makes sizing easy
(§4) and keeps the migration light (files don't move — we just re-point s3fs).

---

## 3. Why managed-VPS-with-root, and why not the alternatives

- **Self-managed bare VPS** — maximum control, but worst fit for "my time is the
  constraint." We'd own OS security patching, firewall, kernel/network/hardware
  incidents, and 24/7 monitoring. A solo admin hitting an *infra* problem at 2am
  can be stuck for hours — the opposite of "fix it quickly." Rejected.
- **Drupal PaaS (Pantheon / Acquia / Platform.sh)** — least owner time, one-click
  rollbacks, but they assume **config lives fully in git and `drush cim` runs
  clean**. BOS deliberately runs **~340 intentionally-drifted configs** with a
  **partial-cim / entity-API-script** deploy model (see CLAUDE.md "Configuration
  Management" and `deferred_work.md` #22). A PaaS would force us to reconcile all
  that drift first or fight the platform continuously, and would throw away the
  working rsync + partial-cim workflow. Rejected **until/unless** the drift
  project is done.
- **Managed VPS with root** — provider does OS/security/hardware/network +
  offers a 2am support line; we keep root for instant app fixes and full control
  of disk; **our existing rsync + partial-cim + drush workflow transfers
  unchanged.** Chosen *in principle*.

> **⚠️ 2026-08-02 finding — Hosting.com doesn't sell "managed WITH root."**
> Research (see §10a) confirms Hosting.com's **managed** line (VPS / VDS /
> dedicated) is **CloudLinux + cPanel/WHM + LiteSpeed with NO root by design** —
> i.e. our *exact current friction stack*. Root is only on their **unmanaged**
> dedicated/cloud. So on Hosting.com the shape that fits our goals is **an
> *unmanaged* root box on a plain OS, with the ops burden automated by us**
> (§7, §9) to keep the "owner's time" cost low — plus, optionally, a Hosting.com
> monitoring/management *add-on* if sales offers one. The reseller endeavors can
> live on a **separate managed cPanel/WHM plan** under the same account, so
> vendor/billing consolidation is preserved without dragging BOS onto cPanel.

---

## 4. Target environment spec

Match the current stack so BOS behaves identically (and the deploy scripts keep
working). Confirm exact availability with Hosting.com (§10).

- **OS:** a clean Linux we control — **AlmaLinux 9** or **Ubuntu 22.04/24.04
  LTS**. *Avoid cPanel/CloudLinux* if the goal is friction reduction (§2 warning).
- **Web:** **nginx + php-fpm** (CLAUDE.md lists nginx-fpm as the intended stack).
  If Hosting.com strongly prefers LiteSpeed, that's acceptable **only if** PHP CLI
  + cron run normally (the friction is the *CGI* SAPI, not LiteSpeed per se) —
  verify with a cron `drush` smoke test before committing.
- **PHP:** **8.3** (current), with CLI + FPM from the same build so `drush` and
  the web SAPI agree.
- **DB:** **MariaDB 10.11** (current).
- **Composer** 2.x, **Drush** (project-vendored — `vendor/drush/drush`).
- **Sizing (starting point; resizable):** 4–8 vCPU / 16–32 GB RAM /
  **160–320 GB SSD**. Files are on S3, so disk is code + DB + caches + a rolling
  set of local DB dumps. Start mid, grow with the slider — that ability *is* the
  point.
- **TLS:** Let's Encrypt (certbot) with auto-renew, OR the provider's managed
  certs.
- **Extensions:** confirm the PHP extension set BOS needs is present — at minimum
  `pdo_mysql, gd` (image styles), `mbstring, xml, curl, zip, opcache, intl,
  imap` (WEX `wex:fetch-email` uses `webklex/php-imap`), plus whatever
  `composer install` requires. `entity_print`/`dompdf` (backflow PDFs) and
  `endroid/qr-code` are pure PHP.

---

## 5. What transfers unchanged (keep these)

- **Deploy model:** `dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh`
  (rsync code → remote `composer install --no-dev` → `drush cr`; **DB never
  touched**; `.vscode/`, `dev_scripts/`, `__BOS_AI/`, `.claude/` protected).
  Only the SSH host target changes.
- **Config discipline:** **partial-cim only, never full `cim`/blind `cex`**
  (~340 drifted configs). ECK/media/field configs via idempotent entity-API
  setup scripts. This does not change on a new server.
- **Files:** stay on **S3** — do not migrate files. Re-point `s3fs` at the same
  bucket (`brookstone-images` / origin `s3fs-public`). Dev keeps
  `stage_file_proxy`.
- **Secrets:** env-only, never in git. `settings.php` / `services.yml` /
  `*.sql*` remain gitignored. Carry over the off-git secrets:
  `BOS_WO_INTAKE_API_KEY`, `$settings['wex_imap']` + `WEX_IMAP_PASS`
  (`~/.wex_imap_env`), S3 creds, DB creds.
- **Local dev:** DDEV is unaffected (it *is* our staging clone — §8).
- **Sync scripts:** `brookstone-sync-db-from-live.sh`,
  `brookstone-sync-code-from-live.sh` — update the SSH host alias, otherwise
  unchanged.

---

## 6. Pre-flight inventory (capture from the CURRENT server first)

Before provisioning, snapshot everything the shared host is quietly providing so
nothing is missed:

- [ ] PHP version + **full `php -m` extension list** (CLI *and* FPM).
- [ ] MariaDB version + `SHOW VARIABLES` for sizing (buffer pool, max_connections).
- [ ] Full `crontab -l` (DB backup 2:30, WEX fetch 7:00, WEX watcher 7:15) +
      contents of `~/.wex_imap_env`, `web/scripts/bos_db_backup.sh`,
      `web/scripts/wex_alert_check.sh`.
- [ ] `settings.php` — the *whole* file (DB creds, `$settings` hash salt,
      trusted_host_patterns, s3fs config, `wex_imap`, `putenv()` lines).
- [ ] Installed composer packages + **applied patches** (`composer-patches`:
      core/smart_date/page_manager/views_aggregator — auto-applied on install).
- [ ] Cron mail path (server `mail` → cPanel MTA today) — how alerts will send
      on the new box.
- [ ] DNS: current A/AAAA records, TTLs, registrar, mail records (MX/SPF/DKIM if
      mail is on the same host).
- [ ] Any host-level rewrite/redirect rules, IP allowlists, or firewall config.
- [ ] Current disk usage breakdown (what's actually eating the 97%).

---

## 7. Provisioning the new server (repeatable)

Goal: a scripted, repeatable build so the box can be rebuilt or cloned. I (Claude)
can write these as shell scripts checked into `dev_scripts/` (or a private infra
repo). High-level order:

1. Base OS hardening: non-root sudo user, SSH key-only (disable password auth),
   `ufw`/`firewalld` (22/80/443 only), automatic security updates, `fail2ban`.
2. Stack: nginx, php-fpm 8.3 (+ extension set from §6), MariaDB 10.11, composer.
3. nginx vhost for Drupal (clean-URLs, `X-Frame-Options`, gzip, static caching,
   deny `.php` in `sites/*/files`) + Let's Encrypt.
4. MariaDB: create DB + app user (least-priv), tune buffer pool to RAM, set
   session timezone sanely (note: the calendar `FROM_UNIXTIME` gotcha was a
   *MariaDB session tz = UTC* issue — see `drupal_bos_gotchas.md`; the code fix
   already handles tz in PHP, but set the server tz to `America/Denver` anyway).
5. `s3fs` — reuse the existing bucket/creds; verify `public://` reads+writes hit
   S3 (this is the load-bearing "files don't move" step).
6. Deploy user + SSH alias so `brookstone-sync-*.sh` target the new host.
7. Cron: DB backup, WEX fetch, WEX watcher — **and confirm `drush` runs cleanly
   under cron** (the whole point; standard `drush` should just work — no Alt-PHP
   dance).
8. Backups + monitoring (§9).

---

## 8. Rehearsal (mandatory before any cutover)

Same discipline as every BOS deploy — **the real cutover must be boring.**

1. Provision the new box (§7).
2. `git clone` BOS → `composer install --no-dev`.
3. Import a **fresh synced live DB** (`brookstone-sync-db-from-live.sh` pointed at
   the *current* live) into the new box.
4. Drop in a migrated `settings.php` (new DB creds; keep hash salt,
   trusted_host_patterns updated for the new hostname; s3fs + wex + WO-intake
   secrets).
5. `drush cr`, then **full smoke test** against the new box on a temp
   hostname/hosts-file entry *before* DNS:
   - [ ] Front page 200; log in as office + as a crew user.
   - [ ] A property page renders (photo gallery images load **from S3** — this
         proves s3fs).
   - [ ] Create/complete a test WO end-to-end (billing recalc fires).
   - [ ] `drush` runs under cron (WEX fetch smoke test; DB backup script runs).
   - [ ] Colorbox lightbox + video player work.
   - [ ] `entity_print` backflow PDF generates to S3.
   - [ ] Email sends (cron alert path).
6. Fix anything, re-run. Only proceed when the temp-host smoke test is clean.

---

## 9. Backups, monitoring, and the "fast 2am fix" kit

This — not the server type — is the real lever on the owner's time. Build all of
it *before* cutover:

- **Off-site backups:** nightly `drush sql:dump --gzip` **pushed to S3** (not just
  local — the current dumps share the DB's disk). Keep the existing 14-dump
  rotation locally + a longer S3 retention. (Improves `bos_db_backup.sh`'s "copies
  still owed" note.)
- **One-command restore:** a `restore-from-s3.sh` that pulls the latest (or a
  named) dump and imports it. Worst-case 2am outcome = "restore last night" in
  minutes. Rehearse it during §8.
- **Monitoring/alerting that says WHAT broke** (so a fix is fast, not a hunt):
  - Uptime/HTTP check (front page + one authed endpoint) → email/SMS.
  - Disk % (alert at 80%, not 97%), DB up, php-fpm up, cron-job success
    heartbeats (reuse the WEX-watcher pattern: alert only on *failure*).
  - Drupal error-rate (dblog watchdog) spike alert.
- **Runbook** (append to this doc): top failure modes + exact fix commands —
  site down, disk full, DB won't start, s3fs mount lost, cert expired, deploy
  half-applied. Each with a copy-paste remedy.

---

## 10. Open questions for Hosting.com (answer these FIRST)

1. **What OS / control panel does the managed VPS use?** Can we get a *clean*
   AlmaLinux/Ubuntu root VPS (nginx + php-fpm), or is it cPanel/CloudLinux? →
   **This determines whether the friction (§2) actually goes away.** If it's
   cPanel/CloudLinux only, ask specifically whether CLI PHP + cron `drush` run
   in a normal CLI SAPI (not CGI) — do a smoke test before committing.
2. What does **"managed"** cover — OS patching, monitoring, backups, 24/7
   incident support? What's the **2am support SLA / response time**? (This is the
   safety net the whole plan leans on.)
3. Can we **resize disk/RAM/vCPU on demand** with minimal downtime? (Core
   motivation.)
4. PHP **8.3** with our full extension set (§6, incl. `imap`), and MariaDB
   **10.11** — supported out of the box?
5. **Root/sudo** access — full, or restricted by the panel?
6. Snapshots/images for fast rebuild? Off-site backup storage or bring-our-own
   (S3)?
7. Where does **mail** send from (for cron alerts) — is outbound SMTP
   allowed/deliverable?
8. **Reseller program:** does consolidating BOS + the reseller endeavors on one
   account/plan change any of the above (isolation, billing, support tier)?
9. IP: static IP included? Any egress limits that would affect S3 traffic?

---

## 10a. Hosting.com product research (2026-08-02)

Findings from Hosting.com's public pages + third-party sources:

- **Managed dedicated servers exist** — 5 tiers (US-based, 2 free IPs, configs
  combinable, "stock limited → contact sales", pricing sales-only):

  | Tier | CPU | RAM | Storage |
  |---|---|---|---|
  | Intel Tier 1 | Intel E-2224 | 16 GB | 1 TB RAID1 NVMe |
  | AMD Tier 1 | Ryzen 7600 | 16 GB | 960 GB RAID1 NVMe |
  | Intel Tier 2 | Xeon Silver 4210R | 64 GB | 1 TB RAID1 NVMe |
  | AMD Tier 2 | EPYC 7232 | 64 GB | 1 TB RAID1 NVMe |
  | AMD Tier 3 | 2× EPYC 7252 | 128 GB | 960 GB RAID1 NVMe |

  Also **VDS** (virtual dedicated, "scale with a reboot") up to "VDS Premium 256"
  + custom builds.
- **Spec is not the constraint.** Files are on S3, so BOS's server footprint is
  code + DB + caches; even the entry 16 GB / 1 TB tier is oversized for us.
- **The blocker:** the **managed** line = **CloudLinux + cPanel/WHM + LiteSpeed,
  NO root by design.** That is our current friction stack. **Root only on
  unmanaged.** → We want **unmanaged** (plain OS + root), ops automated by us.
- **Reseller consolidation is still fine** — run reseller hosting on a separate
  managed cPanel/WHM plan; keep BOS on the unmanaged root box; one Hosting.com
  account.

**Sharpened sales questions (supersede/extend §10):**
- Confirm an **unmanaged dedicated OR cloud** offering with **root** + choice of
  **AlmaLinux 9 / Ubuntu LTS** (no forced cPanel/CloudLinux/LiteSpeed).
- Can we **resize disk / RAM / CPU** on the unmanaged tier with minimal downtime?
- Do you offer an **à-la-carte management/monitoring add-on** on unmanaged boxes
  (best-of-both: our root + your patching/monitoring safety net)?
- Snapshots/images, off-site backup storage, outbound SMTP for cron alerts,
  static IP, S3 egress — all as in §10.

## 11. Cutover (only after §8 is clean)

1. Announce a short maintenance window (BOS is operational — pick a low-traffic
   time; office hours matter more than crew hours).
2. Lower DNS TTL to 300s **a day ahead**.
3. On current live: `drush state:set system.maintenance_mode 1`, take a **final**
   `sql:dump` (this is the source of truth for the cutover).
4. Import that final dump into the new box; `drush cr`; re-run the §8 smoke test
   on the temp host.
5. Flip DNS A/AAAA to the new box. Watch propagation.
6. Verify on the real hostname: front page, login, a gallery page (S3 images),
   a WO complete, cron `drush`, backups, monitoring firing.
7. Take the new box out of maintenance mode.
8. Keep the old box **read-only and intact for ≥2 weeks** as rollback insurance
   before decommissioning.

**Rollback:** if the new box misbehaves post-cutover and can't be fixed fast,
flip DNS back to the old box (still intact, TTL low) and restore its DB from the
final dump if any writes happened on the new box. Because the final dump is the
cutover source of truth and files are shared on S3, rollback is low-risk.

---

## 12. What I (Claude) will produce as we go

- The provisioning scripts (§7) — checked into `dev_scripts/` or a private infra
  repo.
- `restore-from-s3.sh` + the S3-push addition to `bos_db_backup.sh` (§9).
- Updated SSH aliases + a new deploy-host target for the sync scripts (§5).
- The runbook section (§9) filled in with concrete commands.
- A migrated `settings.php` template (secrets left as env placeholders — never
  committed).

**Owner provides / decides:** the Hosting.com plan choice (after §10 answers),
DNS access, and the go/no-go on the cutover window.
