# 2nd Brain (Claude Code) → BOS Live access

Drop this into your 2nd Brain's memory / `CLAUDE.md`. It gives a Claude Code
agent what it needs to **read** BOS Live data and, carefully, **update** it —
using SSH + drush (the same path the BOS project itself uses). This is direct
production access: **default to read-only; treat every write as production.**

**Prerequisite:** the machine running the 2nd Brain must have the `brookstone`
SSH host alias in `~/.ssh/config` (same alias the BOS dev scripts use). If SSH to
`brookstone` doesn't work, stop and tell Todd — don't improvise another path.

---

## What BOS is
BOS (Brookstone Operating System) = the LIVE Drupal 10 operations platform for
Brookstone Outdoors. **All operational data is ECK entities, not nodes.** Spine:
**Properties → Work Orders → child records (time, materials, chemicals, notes)**;
Contracts → Contract Sections → Work Orders via the Services taxonomy. Full model
lives in the BOS repo's `CLAUDE.md` and `__BOS_AI/` docs (authoritative — read
them if the repo is available locally).

## Connecting (READ + UPDATE)
- **SSH host:** `brookstone` · **Drupal root:** `/home/brookstoneadmin/brookstone`
- **Always invoke drush via the Alt-PHP CLI + the project's drush.php** — NEVER
  the global `drush` PHAR (on this host it re-execs through a CGI wrapper and dies
  silently):
  ```
  ssh brookstone "cd /home/brookstoneadmin/brookstone && \
    /opt/alt/php83/usr/bin/php -d memory_limit=512M vendor/drush/drush/drush.php <command>"
  ```
- **Prefer `php:script <file>` over `php:eval`** for anything with quotes/multiple
  lines — `php:eval` over SSH mangles escaped quotes. `scp` a small PHP script up,
  run it with `php:script`, then remove it.

## Reading data (safe, do freely)
- Entity queries / lookups: `drush php:eval '...'` or `php:script`, e.g.
  `\Drupal::entityQuery('work_order')->accessCheck(FALSE)->condition(...)`.
- Raw SQL reads: `drush sql:query "SELECT ..."`.
- Handy: status term IDs — Open 1089, Scheduled 1091, In Progress 1092,
  Complete 1097, Canceled 1098, Invoiced 1281, Warrantied 1283, Paid 1504.
- Entity types you'll use most: `properties`, `work_order` (36 bundles),
  `contracts`, `equipment`, `material`, `chemical`, `estimate`. Field/bundle
  inventory is in the BOS `CLAUDE.md`.

## Updating data (allowed, but with the full ritual)
Before ANY write to live:
1. **Take a DB dump first:** `drush sql:dump --gzip --result-file=~/pre-change-<label>.sql.gz`
2. **Rehearse non-trivial changes on the DDEV dev copy** before touching live.
3. **Confirm with Todd** what you're about to change (which entities, how many).
4. Make the change via the **entity API in an idempotent `php:script`** (load →
   set → save), not raw SQL UPDATEs (raw SQL bypasses hooks/validation).
5. **Verify after** (re-query and report actual counts/values).
6. Run **live/irreversible steps in the foreground** so they're interruptible —
   never background them.

## HARD rules — do not violate
- **NEVER `drush cim` (full) or a blind `drush cex` on live.** `config/sync` is
  intentionally drifted (~340 configs); a full import reverts live. Config
  changes are **surgical partial-cim only**, or entity-API scripts for ECK/field
  configs.
- **The database is never touched by deploys** — deploys are code-only. Data
  changes are deliberate, dumped-first, verified.
- **Never hand-poke S3.** Files go through the Drupal file API (`public://` →
  s3fs). Don't write to the bucket directly.
- **Secrets are env-only.** Never read, echo, copy, or commit `settings.php` /
  `services.yml` credentials, API keys, or hashes.
- **No deletion of operational history.** Prefer status/archival flags. Audit
  trails are append-only; completed-WO pricing snapshots are immutable.
- **Read before you delete or overwrite** — if what you find contradicts the
  request, surface it instead of proceeding.
- When unsure whether an action is safe on production, **ask Todd first.**

## Where to go deeper
If the 2nd Brain has the BOS repo locally: read `CLAUDE.md` (full architecture,
module map, config-management rules, gotchas) and the `__BOS_AI/` tree
(`Governance/drupal_bos_gotchas.md`, `Entities/`, `Modules/`). Those are the
source of truth; this file is just the connection + safety primer.
