# BOS Deferred Work Tracker

Items surfaced during recent BOS work but deliberately deferred. Mirror to Todoist where actionable, but having the list here gives future Claude sessions and contributors context without re-discovering each item from scratch.

Each entry cites the surfacing commit (or session) so readers can dig into git history for the full context.

> **Relationship to the roadmap:** this file is the **engineering-detail tracker** (code-quality, hygiene, small fixes, with commit provenance) and is **subordinate to [`__BOS_AI/ROADMAP.md`](../ROADMAP.md)** — the strategic status-of-record and tie-breaker. Items tracked in both must agree; **ROADMAP wins on conflict.** Items also on the roadmap carry a `↔ ROADMAP:` pointer to the section that owns them.

---

## Code quality follow-ups

### 1. `wo_lawn_mowing` cascade unflag without explicit user

`wo_lawn_mowing_handle_wo_tasks_list_operations()` calls:

```php
$flag_service->unflag($flag, $flagging->getFlaggable());
```

Without an explicit `$account` third argument, `unflag()` defaults to `\Drupal::currentUser()`. In contexts where the current user differs from the original flagger (account_switcher in drush eval, queue processors, REST endpoints), this throws "The entity is not flagged by the user" because the (flag, entity, account) tuple doesn't match.

**Fix:** pass the flagging's owner as the third argument: `$flag_service->unflag($flag, $flagging->getFlaggable(), $flagging->getOwner())`.

Pre-existing latent issue — not Phase 2c. **Surfaced during Phase 2c implementation, commit `ae59a12c`.**

---

### 2. Historical `wo_time_clock` entries with corrupted `field_notes`

Every flag-driven clock-out from project inception until commit `92c9484f` (May 2026) called `appendItem('End Time entered by system')` on the single-value `field_notes` field. Drupal's behavior on `appendItem()` against a single-value field is silently destructive: it either clobbers the existing value, internally appends but loses on save, or throws — depending on Drupal version.

Forward-fix is in (`92c9484f` uses explicit string concat + trim). Historical entries are not corrected.

**Quantification query** (run on local first, then live if curious):

```sql
SELECT COUNT(*) FROM wo_time_clock__field_notes
WHERE field_notes_value LIKE '%End Time entered by system%'
  AND field_notes_value NOT LIKE 'Start time entered through system%';
```

If the count is small and the audit is operationally meaningful, a backfill script could attempt to reconstruct the original "Start time entered through system" prefix on affected entries. If the count is large, accept the historical data loss and document the cutoff date.

**Surfaced during Phase 2c-prep, commit `92c9484f`.**

---

### 3. `wo_total_time` logger notice change-log discrepancy

[Change Log](change_log.md) entry dated 2026-03-12 says:

> "Removed debug logging from `wo_total_time` (Presave Debug, Not updating UID notices)..."

But the actual code in [`wo_total_time.module`](../Modules/wo_total_time.md) still has the `\Drupal::logger('wo_total_time')->notice()` calls in the manual-entry ownership-reassignment block (lines around 51–69 of the current file).

Two possibilities:
- The cleanup was reverted (possibly by a sync from live overwriting a clean local)
- The change-log was aspirational / mis-logged

Investigate. If the cleanup was intentional and reverted by accident, re-apply. If the change-log was wrong, correct it. Either way, the discrepancy itself is worth resolving so the change-log stays trustworthy.

**Surfaced during Phase 0.5 / Phase 1 verification.**

---

### 24. Pre-boundary `wo_time_clock` dual-field drift backfill (deferred)

The dual-field drift pattern (`uid` populated, `field_teammate` empty on the same entry) affected 9,118 wo_time_clock entries at time of discovery. The reverse-sync guard added to `wo_total_time` corrects new writes going forward, and 72 of 74 post-boundary affected entries were backfilled (2 blocked by pre-existing `time_travel` data corruption). The remaining **9,043 pre-boundary entries** were left as-is.

These are predominantly outside the variance dashboard's default boundary (`field_data_quality_boundary_date = 2026-01-01`), so operational reports already filter them out. But:

- Per-teammate historical reports that go pre-boundary will under-attribute time to teammates whose entries are in this state
- Phase 2 reconciliation on a pre-boundary WO sign-off would surface these as MISSING for any teammate whose only entries are dual-field-drifted

If a backfill is wanted: the same drush eval pattern used for the post-boundary set can be expanded by removing the date filter. ~9,000 saves; expect maybe 50-100 failures from existing data corruption (`time_travel`, `negative_hours`, etc.) that Phase 1 guards block.

Deferral rationale: scale of operation deserves its own decision; pre-boundary accuracy isn't the dashboard's primary signal; failures would need batch-level error handling rather than per-entity surfacing.

**Surfaced 2026-05-02 during Phase 2 sign-off live use.** Greg Kouri's wo_complete_info form showed reconciliation-needed for an entry he'd already populated; diagnostic revealed the dual-field drift pattern. Forward fix in `wo_total_time` commit (this work).

---

## Cosmetic / UX cleanups

### 4. `teammate_wo_clocked_in_not_complet` view — candidate for deprecation

This view filters `wo_time_clock` entries to those with `field_end_time IS NULL` (open clock-ins). Originally a diagnostic surface; functionally superseded by the Teammate Operations Hub variance dashboard at `/admin/office/operations/teammates`.

Confirm zero current usage (no blocks placed, no menu links, no controllers), then either:

- Delete the view config entirely
- Absorb into a future Phase 3 cleanup UI

Before delete, snapshot the filter logic — it's a useful reference for any future "active clock-in" view.

**Surfaced during Phase 2 codebase impact scan.**

---

### 5. `wo_time_clock_buttons` view template — empty end_time fragment

[`config/sync/views.view.wo_time_clock_buttons.yml`](../../config/sync/views.view.wo_time_clock_buttons.yml) line 358 has a Twig template `alter_text`:

```twig
<sup>{{ uid }} was last to clock out at: {{ field_end_time }}.</sup>
```

When `field_end_time` is NULL (post-Pre-Phase-2 backfill, all open clock-ins are now NULL), this renders as "username was last to clock out at: ." — awkward but not broken.

**Fix:** use a Twig default filter:

```twig
<sup>{{ uid }} was last to clock out at: {{ field_end_time|default('not yet clocked out') }}.</sup>
```

Low priority — internal-only view, low operational visibility.

**Surfaced during Phase 2 codebase impact scan.**

---

### 6. Phase 2c lawn_mowing form — Behavior C upgrade path

The lawn_mowing reconciliation form currently uses Behavior B (no AJAX rebuild on roster change; validate handler catches at submit time). See [architectural_patterns.md → Form-alter rebuild behavior decisions](architectural_patterns.md#form-alter-rebuild-behavior-decisions).

If field-tablet usage data shows the no-auto-update friction is operationally meaningful, the upgrade is:

- Add `#after_build` callback to the `field_mowing_who_on_site` widget
- Walk each child autocomplete element
- Attach `#ajax` with `event => 'autocompleteclose'` and the reconciliation wrapper as target

Defer until usage warrants. Premature complexity here is more expensive than the click.

**Surfaced during Phase 2c implementation, commit `ae59a12c`.**

---

## Strategic / fall 2026

### 7. `snow_removal` sign-off architecture

`wo_complete_info:snow_removal` exists with `field_those_on_crew` populated, but is explicitly excluded from Phase 2 reconciliation per the original spec ("snow_removal deferred to fall 2026").

Decision points for fall 2026 design pass:

- Bring `snow_removal` into the existing Phase 2b pattern (add to `WoCrewRosterService::COMPLETE_INFO_BUNDLES`, add to audit field `target_bundles`)
- OR design a parallel pattern if snow_removal's operational characteristics differ enough (route-based dispatch, multi-day runs, salting decisions)

Examine actual snow season usage (2026-2027 season data) before deciding.

↔ **ROADMAP:** LATER / Season-gated — "Snow removal architecture + reconciliation bundle".

**Surfaced during Phase 2 design.**

---

### 8. `special_mowing` reconciliation

`wo_tasks_list:special_mowing` has 8 entries in the local DB at time of Phase 2 diagnostic (May 2026). Excluded from Phase 2c per the heuristic ("fewer than ~50 entries OR roster adoption under 50% → defer").

Revisit if:

- Entry count grows substantially (≥50 entries)
- Roster adoption stays high (the 8 entries were 100% populated)
- Operational pattern stabilizes around scheduled per-property mowing rather than ad-hoc requests

Implementation note: `wo_special_mowing` has `hook_entity_presave` only — no cascade hook on `wo_tasks_list:special_mowing` like wo_lawn_mowing has. Reconciliation pattern would need adaptation, possibly intercepting at the wo_complete_info path instead.

↔ **ROADMAP:** LATER / Season-gated — "`special_mowing` reconciliation bundle".

**Surfaced during Phase 2 diagnostic, before commit `ae59a12c`.**

---

### 9. BOS code quality audit

The pattern of latent bugs surfaced during 2026-04 / 2026-05 work suggests a proactive audit is overdue:

- `wo_sprinkler_check_up` missing `use Drupal\Core\Datetime\DrupalDateTime;` (commit `fc2bbf3f`)
- `wo_timer_flag_update` `appendItem()` on single-value field (commit `92c9484f`)
- `wo_lawn_mowing` unflag without explicit user (item 1 above)
- `wo_material_price_sync` form display incomplete un-hide (commit `fb8a3e3b`)
- `wo_material_price_sync` view filter broken-by-default (commit `fb8a3e3b`)

A dedicated audit pass through `web/modules/custom/` looking for:

- Missing `use` statements (PHPStan / Psalm could automate)
- `appendItem()` calls on fields whose cardinality is 1
- `$flag_service->unflag()` calls without explicit `$account`
- Form display configs that remove fields from `hidden:` without adding to `content:`
- View filters with empty values that can't be the intended state
- Anywhere the code assumes a field exists without a `hasField()` guard

Static analysis tools would catch a substantial subset; manual review needed for the rest.

↔ **ROADMAP:** LATER / Governance — "Dedicated code-quality audit pass".

**Surfaced as pattern across multiple commits in spring 2026.**

---

### 10. BOS branch strategy review

`drupal-update-20251206` has accumulated 100+ commits parallel to `main`. The branch was created in late 2025 for a Drupal version update; subsequent feature work landed on top because there was no clean re-merge cadence.

Decide on either:

- Periodic merge cadence (every N weeks, every M commits, every release-candidate tag)
- Branch model that prevents this kind of drift (trunk-based development, short-lived feature branches, or formalized long-lived release branch)

The current state isn't broken — origin tracks the full history, recovery is possible — but the longer the drift, the harder the eventual reconciliation. Worth a strategic decision before adding another 100 commits.

↔ **ROADMAP:** LATER / Governance — "Branch strategy review".

**Surfaced during Phase 2 final report.**

---

## Process / hygiene

### 31. Re-enable CSS/JS aggregation when the theme work settles

`system.performance` has **`js.preprocess: false` and `css.preprocess: false`** on live.
This is **deliberate** — Todd turned aggregation off while perfecting the CSS, and chose
on 2026-10-25 to leave it off through the winter, when he has a batch of changes planned.
That is the right call while iterating: aggregation combines and minifies, which is exactly
what makes a CSS change hard to see.

Logged only because "off for now" is the kind of setting that quietly becomes off forever.
The cost is narrow — unminified, unbundled assets served as separate requests — and it
falls almost entirely on **authenticated admin/crew pages**, since anonymous pages are
served by the LiteSpeed edge cache without entering PHP (`bos_edge_cache`). So there is no
urgency and no public-facing penalty.

Re-enabling is two config values. **Verify afterwards** that the admin theme still renders
(`brookstone_admin` ships hand-written CSS that has never been through the aggregator) and
that nothing depends on a raw file path. Current state is recorded here so a future session
does not "fix" an intentional setting mid-winter.

**Surfaced 2026-10-25** while confirming that the Route Editor popup deploy needed no cache
rebuild — aggregation being off is *why* `_drupal_flush_css_js()` sufficed.

### 11. Post-commit config sync checklist

After any commit touching `config/sync/`:

- Run `ddev drush cim` locally to verify clean state
- If drift exists, decide intentionally: `drush cex` to recapture, manual edit to align, or document as permanent diff
- Resolve before the next dev session begins

Goal: prevent multi-day drift gaps. The Phase 0.5 work surfaced 6 days of pending pre-existing drift from earlier `wo_material_price_sync` work that hadn't been imported. That kind of accumulation makes every subsequent change harder to reason about.

**Surfaced during Phase 0.5 cleanup.**

---

### 12. Phase 2 test scaffolding — clock-in/out interval

When testing the wo_total_time computation downstream of a flag-based clock-out, use clock-in/out intervals of **at least 36 seconds** (preferably 5+ minutes) so `field_total_time` rounds to a non-zero decimal hour.

`field_total_time` formula: `round(($end - $start) / 3600, 2)`. A 2-second interval rounds to 0.00, which can confuse "did the computation run?" assertions.

Document this in any test scaffolding helpers. If exact end_time control is needed, set explicit timestamps rather than using `time()` + `sleep()`.

**Surfaced during Phase 1 + Phase 2c testing.**

---

## Apprentice onboarding (separate project)

These items are part of an in-progress apprentice-readiness initiative. Scope is broader than just deferred technical work — they're new docs/SOPs that need authoring.

### 13. BOS Apprentice Guide

Soup-to-nuts onboarding doc covering the BOS architecture, the dev workflow, the governance structure, and the operational rhythm. Aimed at someone with Drupal experience but no BOS context.

Must include pointers to:
- [`drupal_bos_gotchas.md`](drupal_bos_gotchas.md) (day-one reading)
- [`architectural_patterns.md`](architectural_patterns.md) (read before extending modules)
- [`working_with_claude.md`](working_with_claude.md) (collaboration patterns)
- This file for ongoing context on what's deferred

---

### 14. Catalog cleanup task checklist

The material/supplier/equipment catalogs accumulated drift from years of ad-hoc edits. A recurring cleanup task list would surface stale records, missing supplier links, deprecated equipment that's still referenced by active WOs, etc.

Output: a checklist runnable monthly that identifies drift candidates without mass-deleting (per the BOS principle: "no deletion of operational history; prefer archival status flags").

---

### 15. Claude working guidelines for the apprentice

A shorter version of [working_with_claude.md](working_with_claude.md) tailored for an apprentice who'll be using Claude as a collaborator but isn't deeply familiar with the BOS-specific verification patterns yet. Heavier emphasis on the "stop and ask" boundaries, lighter on the architectural-pattern citations.

---

### 16. Automatic lunch / break deduction on long clock sessions

Surfaced 2026-05-16 during the wo_time_clock single-entry cap work (the per-bundle 4hr / 14hr-long-job cap with override checkbox).

The root behavioral problem: crews on long jobs (landscaping, sprinkler repair/install) don't reliably clock out for lunch and breaks — and there's legitimate pushback on expecting them to remember mid-job. The single-entry cap mitigates the *runaway* (forgot-overnight) case but doesn't address the everyday reality that a real 9-hour landscaping session almost certainly contains an unpaid ~30–60 min lunch that's currently being captured as worked time.

Desired: a rule that deducts a configurable lunch/break period from a single clock session once it exceeds some duration, in a way that's defensible for payroll and billing — e.g., "any single entry over N hours has M minutes auto-deducted, with the deduction visibly noted on the entry." Needs business-rule definition (threshold, deduction amount, whether it's per-bundle, how it interacts with the cap/override, and whether the deduction is shown as a separate adjustment vs. baked into total_time) before any implementation.

Explicitly scoped OUT of the cap work — separate initiative, separate decision.

---

### 18. Weed-spray stale-cancel threshold tuning

The abandoned-WO sweep cancels stale-empty WOs at **>45 days**. As of 2026-06-25, 49903
and 49906 (43 days, zero work) sit just under the line and aren't swept yet. 45 days is
past even a monthly cycle (35d), so it's deliberately conservative; revisit if the office
wants empty WOs cleaned sooner (could be made frequency-relative). Branch
`feature/spray-route-guard`.

↔ **ROADMAP:** NEXT — "45-day auto-cancel threshold pressure-test".

### 19. Legacy 2024 weed_spraying WOs stuck in status 1301 "Active"

Surfaced 2026-06-25. A handful of 2024 weed_spraying WOs (e.g. 35093/35098/35104/35106)
are `invoiced = 1` yet sit in status **1301 "Active"** — a non-done status. They're out
of scope for the spray-route guard (year-scoped + invoiced-guarded, so never touched), but
worth understanding: why did invoiced WOs land in "Active," and should 1301 be folded into
the done-set / corrected? Data hygiene, not urgent.

### 20. Old stranded `field_invoiced` flags (pre-completion status) — ✅ RESOLVED 2026-07-11

Surfaced 2026-06-25 (billing-crash investigation). Three WOs carried `field_invoiced = 1` while in
a pre-completion status (In Progress): **45301 / 49668 / 50078** — same orphan pattern the
2026-06-20 remediation reverted for three other WOs (an accidental clock-in bounces an
already-invoiced WO back to In Progress).

**Live-verified 2026-07-11:**
- **45301** (sprinkler_winterizing, $90) — ✅ **RESOLVED 2026-07-11**: restored to **Invoiced** (status 1281) via the documented `$wo->_skip_invoiced_guard` bypass (it had a `wo_complete_info` and `field_invoiced=1`; no billing recompute, $90 preserved).
- **49668** (landscaping, $2,714) — already **Invoiced**; the office fixed it earlier. Not stranded (this is the one that made the roadmap read "down from 3").
- **50078** (landscaping, $386.70) — ✅ **RESOLVED 2026-07-11**: restored to Invoiced the same way (had a `wo_complete_info`, 0 open clock-ins; `_skip_invoiced_guard`, no billing recompute).

**Remaining: 0 — fully resolved; archive next cycle.**

↔ **ROADMAP:** NEXT — "stranded invoiced WO(s) (In-Progress + `field_invoiced=1`)".

---

### 21. Off-server copies of the nightly DB backup

Added 2026-06-27: `web/scripts/bos_db_backup.sh` runs nightly (cron 2:30 AM) and keeps 14
rotating `drush sql:dump` gzips in `~/db_backups` on live (host backups are unreliable). But
those dumps live on the **same disk as the DB**, so they only protect against logical loss,
not a disk/server failure. Follow-up: get copies **off-server** — e.g. a scheduled pull to a
workstation/NAS (the `dev_scripts/brookstone-sync-db-from-live.sh` path), or push the newest
dump to S3 from the backup script. Also worth a heartbeat check that the backup actually ran
(it already emails on failure; a "no backup in 36h" alert would catch a silent cron stall).

**Update 2026-11-08:** the off-server half shipped **2026-08-16** (S3 push from the same script, see
change_log) — the roadmap row still said otherwise and is corrected. **Open = the heartbeat only**:
nothing alerts if cron silently stops running (no dump → no failure mail). Two cheap checks: on-box
`find ~/db_backups -mmin -2160` from a separate cron line, and off-box (independent of the server)
an S3 bucket age check, since the IAM key is PutObject-only a listing needs a second read-only key or
an S3 lifecycle/EventBridge rule.

---

### 22. Reconcile config/sync ↔ active drift (make full `cim` safe again)

Surfaced 2026-06-27. `config/sync` is drifted from live's active config by **~340 configs**
(all content-diffs; 0 adds/deletes). Active is the source of truth — BOS evolves config via the
UI and deploys never import config — so a **full `drush cim` would revert all ~340** and break
things. Current discipline (documented loudly in CLAUDE.md): **never full-`cim`, only surgical
partial-cim**; never blind `cex`. That neutralizes the risk, so this is **hygiene, not urgent**.

Reconciling (so `cim` is trustworthy + sync is a real config backup + fresh clones get correct
config) is a focused **1–2 day** careful pass: do it **from live's active**, during a **config
freeze**, in batches — capture the ~216 low-risk systematic ones first (pathauto.pattern 75,
auto_entitylabel.settings 53, etc.), review the ~115 substantive ones (views.view 52, field.field
41, displays 15, user.role 3, ai/ai_agents), and handle the **88 `eck.eck_entity_type`** configs
specially (the cex exporter bug injects a stray empty-string into the bundles array — each needs a
manual fix; see the ECK gotcha). Done = `drush cim --diff` comes back clean.

---

### 28. Live `config/sync` is stale vs the repo — produce a per-file diff report before any whole-dir sync

**Surfaced 2026-10-04 (Gate 1 Part A).** A partial cim run **on live** imports from
`/home/brookstoneadmin/brookstone/config/sync/`, **not** from the repo. Targeted
rsync — the documented default for shipping a feature — never touches that
directory, so it drifts from git the moment active config is changed with `cset`
and the YAML is committed only to the repo.

Caught live: the repo and live *active* config both carried
`cache.page.max_age: 900` while **live's on-disk sync copy still said `0`**. A
partial cim of that one file would have silently reverted the change.
Fixed for that file by rsyncing it alone; **the general condition stands.**

**What is needed before anyone syncs the whole directory:** a **read-only
three-way diff report**, per file, reported as **two separate comparisons** so the
causes are not conflated:

1. **repo vs live's sync dir** — pure staleness; the repo is authoritative, and
   this is what a targeted rsync fixes one file at a time.
2. **repo vs live ACTIVE** — the genuine intentional drift, the thing #22 exists
   to reconcile.

Only the second is real drift. Reporting them together is what produced the
misleading 1,975 figure above. Syncing the whole directory blind is
the dangerous move, because it silently rewrites what every future partial cim
will import.

**Measured on live the same day — the drift is far larger than this repo
documents:**

| `drush config:status` state | count | what a full `cim` does |
|---|---|---|
| **Only in DB** | **1,236** | in active, absent from sync → **DELETED from active.** The destructive category. |
| Different | 696 | in both → active overwritten with the stale sync version |
| Only in sync dir | 43 | in sync, absent from active → **created**; risks resurrecting deliberately-removed config. Deletes nothing. |
| **total** | **1,975** | |

CLAUDE.md said "~340 configs differ" in both the deploy warning and the
never-full-cim rule. The real figure is **1,975** — corrected on 2026-10-04.

**⚠ What was compared, and why these are not the whole story.** These counts come
from `drush config:status` **on live**, which compares **live ACTIVE against
LIVE's sync directory** — and live's sync directory is itself stale against the
repo (that is the subject of this item). So the figures **conflate genuine
intentional drift with live-sync staleness**, and they are **not** repo-vs-live
drift. The per-file report below must separate the two comparisons.

*(Corrected 2026-10-04: an earlier version of this item had the direction
inverted, calling the 43 "Only in sync dir" the destructive category. It is the
1,236 "Only in DB" that a full import deletes — drush's own usage text documents
`--state='Only in sync dir'` as the items that would be* created *in active
storage on import.)*

Related: **#22** (reconcile the drift so `cim` is safe again) — this item is the
read-only prerequisite for it. Runbook: `working_with_claude.md` → "Live's
`config/sync` is only as current as the last FULL deploy".


### 30. `http://www.` takes two hops, and one SOP step still hard-codes www

Left open by the 2026-10-10 canonical-host fix, which otherwise met every
requirement. Neither is urgent.

**a) Two hops on the http-www entry only.** `http://www.brookstoneoutdoors.com/x`
goes `https://www.` first and then to `https://brookstoneoutdoors.com/x`. The
cause is ordering: **cPanel/LiteSpeed enforces HTTPS at the vhost level, before
`.htaccess` is consulted**, so nothing placed in `.htaccess` can beat it — the
request is already redirected to the www https URL before our rule is read. The
vhost config is not readable without root. Every other entry point is one hop
and the end state is correct in all cases.

Fixing it means turning OFF cPanel's **Force HTTPS Redirect** for the domain and
owning both redirects in `.htaccess`:

    # www, any scheme -> https non-www, one hop
    RewriteCond %{HTTP_HOST} ^www\.brookstoneoutdoors\.com$ [NC]
    RewriteRule ^ https://brookstoneoutdoors.com%{REQUEST_URI} [L,R=301]
    # everything else http -> https, same host
    RewriteCond %{HTTPS} !=on
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

**Recommended: don't, unless something else calls for it.** It trades a working
server-level HTTPS guarantee for one we maintain, to save one hop on a legacy
entry point. Google follows redirect chains of this length without penalty, the
duplicate-canonical problem is already solved, and the sitemap it crawls is
entirely https non-www. **Note there is no HSTS header**, so browsers do make
the http request — adding HSTS would remove the hop for returning visitors and
is the lower-risk way to improve this if it is ever worth improving.

**b) One SOP step hard-codes a www URL.** A single row in
`sop__field_sop_steps`. It still resolves (via the new 301), so it is a tidy-up,
not a break. A full scan found **0 config objects** and **0 sitemap rows** with
www; everything else that matched was transient cache or log rows.


## Resolved — archive next cycle

### 29. Seven sprinkler systems billing could not reach — ✅ RESOLVED 2026-10-10

All seven removed; **0 systems are now unreachable by billing** (1,256 total).

**The $110 question resolved in the company's favour.** 300 Stafford Lane had two
records disagreeing — 18 zones vs 40. **Todd confirmed 18.** The provenance made
it obvious once looked at: record 1169 (the 40) was created **2025-10-11
14:05:05, to the same second** as records literally named "Test System" and
"test 5 System", with "4th test System" four minutes later. It was a practice
entry that happened to get pointed at a real property; the other three in that
batch were left unattached, which is why only this one caused trouble. **So
WO#52767's $150 was correct all along** (base $95 + 13 extra zones × $5 − $10
contract discount), and no money was lost.

The other three were exact duplicates of the records billing already used
(916 / 948 / 1118) and were deleted rather than linked — **linking them would
have overcharged**, since zone charges sum across every system billing can see.

Script: `web/scripts/cleanup_orphan_sprinkler_systems.php` (dry run by default;
discovers every reference field rather than guessing, and refuses to delete
anything referenced). Verified after: all four real properties still show one
system with the right zone count, and WO#52767 still reads $150 on 18 zones.

**⚠ A bug in that script is worth remembering.** Its backup used
`realpath('public://…')`, which returns **empty on live** because `public://` is
S3 via s3fs — so it wrote to the filesystem root, failed with permission denied,
and **printed "backup written" anyway**. Seven records were deleted with no
backup beside them. Nothing was lost (the nightly dump from 02:33 that morning
holds them, and the content was either trivial or duplicated), but the script now
writes through the stream wrapper and **aborts rather than deleting if the backup
cannot be written**. Write through the stream URI, and never let a backup step
report success it did not achieve.


### 17. Finalize weed-spray WO #49698 — ✅ RESOLVED (SHIPPED/verified 2026-07-03)

**RESOLVED** — the roadmap archives this as SHIPPED / verified 2026-07-03. #49698 (19988 Iris Rd) —
a real spray ($183, completed 05-12) that a stray clock-in **resurrected** to In Progress — was set
back to **Complete** by the office (06-25), so it's no longer stuck/trapping; the spray-route guard
now **flags** resurrected WOs rather than auto-fixing them (auto-restore could corrupt spray history).
The original follow-ups (invoice it in the normal billing flow; reconcile its 5 time-clock entries vs
the one recorded spray if any of the extra time is real later work) fold into normal billing / data
hygiene.

Surfaced 2026-06-25 (spray-route-guard investigation).

### 23. Stale `drupal/calendar` composer-patch fails on every `composer install` — ✅ RESOLVED 2026-07-06

**RESOLVED 2026-07-06** — removed the `drupal/calendar` patch from `composer.json` `extra.patches`
(commit on `main`, deployed). The patch targets a line in `Calendar.php` that **beta5 rewrote**
(now a `datetime_type` match handling smart_date **natively**), so it never applied. **Correction
to the note below:** the calendar+smart_date combo is **NOT unused** — the `teammate_properties` /
`work_order_teammate_calendar` views plot `scheduling.field_scheduled_date_and_time` (a smart_date
field) and render **571 events with the patch NOT applied**, confirming beta5 handles it natively.
So removal was a runtime no-op that just ends the remove/reinstall churn + the transient
`calendar.theme.inc`-missing warning. The paired **`smart_date` → `3177760-13` patch was KEPT** (it
applies cleanly). Deploys now quiet on calendar. Original write-up:

Surfaced 2026-07-04 (noticed during the wo_clock deploy). The declared patch
`drupal/calendar` → `3177761-6.calendar.Support-for-Smart-Date.patch` (composer.json
`extra.patches`) **fails to apply** — every `composer install` prints "Removing package
drupal/calendar so that it can be re-installed and re-patched" then "Could not apply patch!
Skipping." Confirmed **environment-agnostic**: local DDEV and live both fail it identically,
and both run calendar **vanilla beta5, unpatched** (0 `smart_date` refs in
`web/modules/contrib/calendar/src` on either). **Zero operational impact** — BOS scheduling
uses `fullcalendar`/`admin_calendar`/`business_calendar`, not the calendar+smart_date combo
the patch enables; no runtime errors, front page 200 post-deploy. `composer-patches` is
skip-on-failure so installs still complete. **Cleanup:** either drop the (and the paired
`smart_date` → `3177760-13`) patch from `composer.json` if the smart_date-in-calendar feature
is genuinely unused, or refresh to a patch revision that applies to the installed calendar
version. Hygiene only — removes noisy remove/reinstall churn from every deploy.

---

### 25. Go all-Claude — remove the unused OpenAI AI provider (hygiene)

Surfaced 2026-08-28 (invoice-photo vision shipped on Claude). BOS now uses **only
Claude** (`ai_provider_anthropic`, claude-sonnet-4-5) for its one AI feature —
invoice-photo → material-list extraction (`chat_with_image_vision`, set to
anthropic in active config). **`ai_provider_openai` is still enabled with an EMPTY
key** — inert (its model call fails in ~11ms; **not** a performance factor), but
unused clutter that still gets patched/maintained.

**Not a one-click uninstall — two references remain:**
1. `ai.settings` `default_providers` — **10 operation types** (chat,
   chat_with_complex_json, chat_with_structured_response, chat_with_tools,
   embeddings, moderation, speech_to_text, text_to_image, text_to_speech) still
   point at `openai`. None are invoked by BOS, but they'd dangle on uninstall.
2. `editor.editor.full_ai_html` — a CKEditor "AI HTML" editor config tied to the
   AI integration; Drupal may block the uninstall on this dependency or leave the
   editor's AI button pointing at a missing provider.

**Clean sequence (when the server is healthy — do NOT run during the current
host-level CPU overload; needs a ~2.5-min `cr`):** repoint all 10 `ai.settings`
defaults → anthropic + a Claude model (entity-API script, not cim — same pattern
as `web/scripts/setup_anthropic_vision.php`); resolve/retarget `full_ai_html`
(repoint to Claude or drop its AI plugin); `drush pmu ai_provider_openai`; `cr`;
verify vision still works + no config-dependency errors. Alternatively just leave
it installed-but-idle (zero runtime cost). See `Modules/wo_material_list_import.md` + `web/scripts/setup_anthropic_vision.php`.

---

### 26. Update `guzzlehttp/guzzle` once a fixed tag lands on the core line (security)

Surfaced 2026-08-30 (first security pass on the new VPS). `composer audit` flags
**guzzle** with a **high** advisory (CVE-2026-69246, "noncanonical host can bypass
host-based checks"; also CVE-2026-69245 / -67354). Fixed in **7.15.2**, and our
`composer.json` allows `^7.12.1` (which permits 7.15) — **but `drupal/core-recommended`
pins guzzle to the 7.12.x line on Drupal 10.6, and the only 7.12.x fix is an
untagged `7.12.x-dev`**, which we won't ship to prod. So it's held at **7.12.3**.

**Revisit when:** a Drupal core release (on our 10.6.x line, or when we move to a
newer minor/11) bundles a fixed guzzle tag — then `composer update drupal/core-recommended --with-dependencies`
picks it up. Re-check with `composer audit`. **Risk is low for BOS:** guzzle is
only used for calls to *known* hosts (Anthropic API, Google geocoder, WEX CSV
download, s3fs/stage_file_proxy) — not untrusted-URL host-allowlist checks.
The other 5 packages in that audit (core, dompdf, phpspreadsheet, entity,
entity_browser) were updated 2026-08-30; see CLAUDE.md changelog.

---

### 27. Contracted winterizing rate — set the service-count threshold before the January rate card

Surfaced 2026-09-07 (read-only analysis; `web/scripts/analyze_contract_service_distribution.php`).
Plan: a **Contracted Customer winterizing rate** (e.g. $90 → $85) gated on a signed
agreement with **N or more services**. Not launch-blocking (contracted rates are a
renewal-season matter, agreements run March–October) — **but the threshold must be
set before the January rate card.**

**Analysis done (live, slot-based counting — the reliable method; the `field_contract`
back-ref undercounts, 14k+ orphan sections):**
- Typical residential contract carries **median 2 / mean 3.3 services**; a 4-service
  contract is in the **top ~20%**.
- Of the **462** 2026 contracts including winterizing: **3+** = 243 (53%), **4+** = 183
  (40%), **5+** = 146 (32%), **6+** = 112 (24%). A $5 discount costs **$915/yr at 4+**
  (trivial either way → this is a positioning call, not a cost one).
- **Tree-treatment stacking is a non-issue** — exactly **1** winterizing contract reaches
  4+ raw but <4 when the six tree/shrub sprays collapse to one, and a pure-tree customer
  can't get the rate anyway (no winterizing). **Do NOT collapse the tree treatments.**

**Recommendation:** **4+** as a loyalty *reward* floor (rewards top 40%, ~280 winterizing
customers just outside → possible "why not me?" calls); **3+** if it's meant as a
*retention* nudge (majority qualify, +$300/yr).

**UPDATE 2026-09-07 — the billing engine SHIPPED** (commits `22a9b6bd`/`82c0245a`,
live). Base is now **$95** with four auto-applied discounts (single largest wins,
no stacking): contract/auto-list −$5, **>4 services −$10** (strictly more than 4 =
5+), new-customer-4+-homes −$15 (office checkbox on the WO), HOA −$35. So the
"contracted rate" concept is built; the **January decision is now just tuning the
amounts / the 4-vs-5 service threshold** in Business Settings (no code needed).
See CLAUDE.md 2026-09-07 + `Modules/wo_bundle_modules.md`.

**Decision owed (Todd):** for the Jan rate card, confirm/adjust the discount amounts
and whether the services discount should trigger at 4+ vs the current >4 (5+).

**Also surfaced (governance, separate):** §2.2 says services don't carry over between
seasons, yet the automatic winterizing list drives WOs regardless of a re-signed contract
— **39 of 500 (8%)** 2026 winterizing-WO properties have no 2026 contract line; **612**
properties winterized in 2+ of the last 3 seasons. Small, but real. Plus data-quality:
**22 properties have >1 2026 residential contract** (violates one-per-property-per-year);
313 zero-service 2026 contracts; `field_estimate` non-zero on only 14% of contracts.

Re-run for current numbers: `drush php:script web/scripts/analyze_contract_service_distribution.php`
(read-only; writes a per-contract CSV to `~/tmp/`). Full write-up in the 2026-09-07 session.


### 32. Plant Problems landing page — follow-ups from the 2026-11-10 build

Shipped 2026-11-10 (`8f04ba94`, change_log). Left open, each small:

- **`/material/plants` intro contradicts its grid** — marketing rewrites to the list-agnostic rule (on the ROADMAP, Plant catalogue table).
- **Subtitle has no slot** on a material_types page — decision needed (ROADMAP row).
- **`field_services_performed` on no plant_problems display** — place with the first treated problem (ROADMAP row).
- **Grid heading "Problems"** on the landing page is Code's one-word label; marketing may replace it in the Views UI (`plant_problem_children` → header).
- **`plant_problem_children` renders an empty wrapper `<div>` on every other material_types page** (argument empty → no rows, header hidden) — zero height, harmless, same as `material_children` on a leaf category. Note it so nobody "discovers" it.
- **`bos_breadcrumbs.info.yml` description still names four vocabularies**; the allowlist is 32. Cosmetic.
- **Live `~/tmp/inspect_plant_problems_live.php`** (read-only inspection helper) can be deleted by Todd at leisure; writes there are classifier-blocked for Code.

### 33. Two live findings surfaced 2026-11-10 while preparing the target-hours deploy

Found in the deploy dry-run and the live `web/error_log`; neither fixed (out of scope for the cleanup-hours work).

- **The deploy script's `--delete` removes live `web/error_log` on every run.** PHP writes its error log into the docroot (`web/error_log`, 126 KB / 967 lines today alone), the file is not in git, so rsync `--delete` wipes it — this morning's 12:48 UTC Plant Problems deploy did (and the 11-08 deploy before it), and today's dry-run listed it again. Copies of the 11-10 file are in the session scratchpad (`live_error_log_20261110.txt`, and an identical 04:47 MT 11-11 copy); **the 11-11 04:47 deploy deleted it again** — the file is gone from live until PHP next logs. Deleted a third time by the 09:51 MT 11-11 item-6 deploy (copy `live_error_log_20261111_0940.txt` in the scratchpad); a fresh one started at 09:54 MT with my own refresh-script error. Fix: point `error_log` outside the docroot in live `.user.ini`/`php.ini`, or add the path to the deploy script's protected list beside `.vscode/`, `dev_scripts/`, `__BOS_AI/`. Tier 3, S.
- **AEL post-insert shutdown TypeError leaves sentinel titles stuck.** `TypeError: SplObjectStorage::offsetSet(): Argument #1 ($object) must be of type object, null given` in `Renderer::setCurrentRenderContext()` ← `renderInIsolation()` ← `token` `TokenTokensHooks::fieldTokens()` ← entity presave ← `_auto_entitylabel_post_insert()` (a shutdown function). **17 occurrences today, 07:39–16:26 MT, all crew hours.** Effect: the entity keeps `%AutoEntityLabel: <uuid>%` as its title — **≥45 `wo_status_updates` on live** (the check stopped at `wo_material_list_item`, which has no title column; later types unchecked). `wo_shared_work_order_insert()` heals work_order only (4 heals 11-09, 1 on 11-10). Reproduced on dev by a drush script that saves then deletes entities after sub-request renders. Suspect the D11 cutover (token's OOP hooks + an empty render-context stack in shutdown). Fix candidates: a generic heal (clear + re-save when the title carries the sentinel, every AEL'd type) in `wo_shared` plus a one-off backfill, and the root cause upstream (token/core issue to find or file). Tier 2, M. **On the ROADMAP** (NEXT) — it is visible in every WO timeline.


### 34. ✅ DONE 2026-11-11 10:35 MT — Crews cannot read most SOPs — SOP view permissions are per bundle and only sprinkler_maintenance is open

Surfaced 2026-11-11 entering the crew sign-off SOP (`WEB-SYS-SGN-001`, bundle `system_procedures`): it returned **403 for every crew lead and for the office**. Measured on live: `teammates` hold `view any sop entities of bundle sprinkler_maintenance` only; `supervisor`/`administration` add `office_administration`; nobody below site_admin can open `system_procedures`, `landscaping`, `spray`, `snow_removal`, `lighting`, `maintenance`, `safety`, `training` or `sop_governance` SOPs. The SOP Directory view (`/admin/operations/training/sop/directory`) is role-open to teammates, so crews see titles they cannot open. Immediate: Todd grants `system_procedures` view to Teammates / Supervisor / Administration (`~/tmp/grant_sop_system_procedures_view.php` staged on live; granted on dev already). Proper fix: a permissions pass over all 11 SOP bundles by audience — crew-facing bundles viewable by `teammates`, office bundles by `administration`+`supervisor`, governance by office — as a committed role-config change with a per-role diff report first (config/sync roles are drifted; never a blind import). Tier 2, S.

**Update 2026-11-11 10:15 MT:** `view any sop entities of bundle system_procedures` granted on live to `teammates`, `supervisor`, `administration` (Todd's request; staged script run via `drush php:script`). Verified read-only: a crew-only account (uid 134) gets 200 on `WEB-SYS-SGN-001`, a supervisor-only account 200 on all three new SOPs. **Closed 2026-11-11 10:35 MT:** `web/scripts/sop_grant_crew_view.php` granted view on `landscaping, lighting, maintenance, safety, snow_removal, spray, training` to teammates/supervisor/administration on dev + live (Todd's call); `office_administration` + `sop_governance` stay office/governance-only. Verified as a crew-only account (change_log).

---

## Status

- **2026-07-11 — reconciled against [`ROADMAP.md`](../ROADMAP.md).** Fixed the duplicate "#16" (dual-field-drift renumbered → #24); moved resolved #17 + #23 to the new "Resolved — archive next cycle" section; added `↔ ROADMAP:` cross-refs on the items also on the roadmap (#7, #8, #9, #10, #18, #20); flagged #20's 3-vs-2 stranded-id discrepancy for live verification. ROADMAP is the tie-breaker.
- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Living document — append new deferred items as they emerge from project work, with the surfacing commit cited.
- Items 1-12 are technical work tracked here for context; mirror to Todoist if you want them in your active queue.
- Items 13-15 are the apprentice-onboarding initiative; tracked separately.

## wo_deletion_manager gates deletion on status alone, ignoring billing evidence

**Surfaced 2026-11-02.** `wo_deletion_manager` decides whether a work order may
be deleted from its **status**. WO 49466 was deletable because its status read
*Open* — while it carried **$4,950.00** in `field_wo_total`. The delete
cascaded cleanly: base row, every `work_order__*` field table and all child
records gone, no orphans.

In that instance it was harmless (the total had been typed in by hand, not
computed or billed, and Todd chose to leave it deleted). The pattern is not
harmless: **this is the same blind spot that, one day earlier, let a cleanup
sweep cancel two paid 2024 invoices** — status trusted as the single signal
while `field_invoiced`, a populated `field_wo_total` and the status history all
said the work had been billed. `cancel_work_orders.php` was hardened to refuse
on any of those; the deletion guard was not.

**Fix when convenient:** refuse deletion when `field_invoiced` is set, when
`field_wo_total` is non-zero, or when the status history contains
Complete/Invoiced/Paid — mirroring the guard in `cancel_work_orders.php`. BOS
rule 4 (no deletion of operational history) is policy; right now only status
enforces it.

## Service cards render a truncated public article instead of the short description

**Surfaced 2026-11-03.** The `service_children` view — the child-card list on a
parent service page such as `/services/landscape-lawn-care/spraying` — builds
each card from `field_iconic_image` + `name` + **`field_service_public_desc`**.
That is a trimmed body used as teaser text, the precise anti-pattern the
three-field content model was introduced to remove ("a card never shows a
truncated body").

`field_short_description` exists on the `services` vocabulary and is the correct
card field. **No services term currently has it populated**, which is why the
view was never wired to it.

**Why this is not a one-line Views edit:** switching the view's card field to
`field_short_description` today would blank the card text on all **11 sibling
terms** under Spraying alone, because theirs are empty and an empty teaser
correctly renders no text. The prerequisite is a content backfill — one card
sentence per services term — and that is an office/marketing task, not a code
one.

**Order of work:** (1) backfill `field_short_description` across services terms;
(2) then swap the card field in the `service_children` view via Views UI. Doing
(2) first visibly breaks the services section.

## The clock buttons should be a real Manage Display component

**Surfaced 2026-11-03.** `wo_clock` injects the clock UI from
`hook_ENTITY_TYPE_view()`, so it is not a Manage Display component and the
office cannot position it. Its place is derived in code: half a step below
`field_work_todo_description`, plus the per-bundle `WO_CLOCK_ABOVE_BUTTONS`
map naming fields the buttons must step below (today: `pre_emergent` →
`field_pre_emergent_season`).

The map works and is cheap, but it means **every future "move field X above
the buttons" is a code change and a deploy** for what should be a drag in the
UI. Measuring is what kept it small: anchoring to the Notes group instead
would have moved the buttons on 24 of 36 bundles
(`audit_wo_clock_anchor.php`).

**Fix when convenient:** declare the clock via
`hook_entity_extra_field_info()` so it appears in Manage Display per bundle,
render it from the configured component, and delete the map. **The migration
is the real cost, not the hook** — the clock currently renders on all 36
bundles with a good position, and an extra field would arrive unplaced, so
the change needs a script that writes the present computed weight into each
of the 36 displays before the code switches over, or the buttons vanish or
jump on bundles nobody checked. Verify with `test_wo_clock_anchor.php`, which
already asserts the position per bundle.

## VBO `findDefinitions()` fatals when a class cannot be resolved

**Surfaced 2026-11-03.** `views_bulk_operations` line 105 of
`ViewsBulkOperationsActionManager::findDefinitions()` passes
`class_implements($definition['class'])` straight into `in_array()`.
`class_implements()` returns **`false`**, not an array, for a class it cannot
resolve, so the page fatals with *"in_array(): Argument #2 ($haystack) must be
of type array, false given"*.

It fired five times on live at 22:20–22:21 on Todd's own work-order pages and
the calendar, then self-healed. The trigger is any entity view display load →
`eva_entity_extra_field_info()` → EVA initialising its views' handlers → the
VBO bulk form on a **discovery cache miss**, with the web SAPI inside its
`opcache.revalidate_freq=60` window after a file change. So it needs a cache
miss and a concurrent deploy or live drush run — which is why it is rare and
why it does not reproduce in CLI (`opcache.enable_cli=Off`).

`?: []` on the `class_implements()` call is the whole fix and it is correct
upstream behaviour (a missing class should be skipped, not fatal).

**⚠ It must be a declared `cweagans/composer-patches` entry in
`composer.json` `extra.patches` — never a manual `sed`.** `contrib/` is
composer-managed and installed per environment, so a hand-edit silently
vanishes on the next `composer install`; both former manual patches are
documented in CLAUDE.md as obsoleted precisely because they were tracked.
Check whether a newer VBO release fixes it before writing a patch.

**Mitigation that costs nothing meanwhile:** avoid live drush runs that
invalidate caches while crew or office are on the site — the standing rule for
`drush cr` applies to any cache-invalidating run.

## Re-saving a Complete WO re-bills it under TODAY's contracts and rates

**Surfaced 2026-11-06** (D11 step 4, in the sandbox — nothing touched on live).
A plain `$work_order->save()` on a **Complete** (1097) snow-removal WO, with no
flag and no time entry involved, recomputes its billing in the `wo_*` presave
using **current** contract state and rates rather than those in force when it
was completed:

| WO | stored (and on the untouched main dev env) | after one re-save |
|---|---|---|
| 40049 | labor 16.25 · task rate 0.00 · **total 16.25** | labor 0.00 · task rate 100.00 · **total 100.00** |
| 32887, 32888, 32889 | billing fields **empty** | **total 16.25** each, plus `wo_profit` cost/profit |

40049's hours were **unchanged** (0.02) — the difference is pure logic/contract
drift. The re-save also logged *"No contracts found for Property ID …"*, which
fits a contract-dependent rate falling back to a default when the property's
contract for that year is gone or different.

**Why it matters:** "re-save recomputes" is known by design
(`wo_billing_recalc` re-saves Complete WOs on purpose; the backflow doc says
completed WOs "keep their frozen totals **unless re-saved**"). The undocumented
part is the *consequence*: **anything that re-saves a historical WO silently
changes what it bills** — a forgotten time entry added later (wo_clock or the
legacy flag path, via `wo_total_time` re-saving the parent), a billing-child
edit (`wo_billing_recalc`), a VBO re-save (`wo_actions`). Rule 7 makes BOS
authoritative over accounting, so a changed historic total is a real
reconciliation hazard if that WO is ever re-exported or reported on.

**Not yet known:** whether other bundles drift the same way (only snow removal
was measured), and how often live actually re-saves a WO completed in a prior
season. Measure before designing anything — e.g. count live WOs whose
`changed` is a season or more after their completion date.

**Candidate shape of a fix (not decided):** freeze billing at completion and
recompute only on an explicit, logged admin action, or recompute only when the
inputs that feed billing actually changed. Either touches every `wo_*` presave
and `wo_billing_recalc` — design it, do not patch one bundle.

**Test hygiene that came out of it:** `web/scripts/test_flag_timer_path.php`
(on `feature/drupal-11-upgrade`) runs inside a DB transaction that is rolled
back, because hand-cleanup of the flagging and time entry still left 40049
re-billed. Any test that touches a time entry on a Complete WO needs the same.

## `phpoffice/phpspreadsheet` 2.4.7 carries three advisories and no fixed 2.x exists

**Surfaced 2026-11-07** (D11 step 5). `composer audit` after the step-5 batch leaves exactly
three advisories, all on `phpoffice/phpspreadsheet` 2.4.7: SSRF via `Drawing::setPath`
(GHSA-2phh-27fw-q4gc), ODS reader CPU/memory exhaustion (GHSA-j2w7-387v-m2rq), XmlScanner
bypass → XXE (GHSA-3x94-m842-937c). Affected `<=2.4.7`; fixed on `3.10.8+` and `5.10.0`.
**There is no fixed release on the 2.x line**, so this cannot be closed by a minor update.

**What pins it to 2.x** (`composer why`): root `^2.2`; `migrate_spreadsheet` 2.2.0 (`^1.29.1 ||
^2.2.1`) — **Disabled**, 0 migrations use the spreadsheet source; `xls_views_data_export`
3.0.x-dev (`^2.3`) — **Disabled**. The *enabled* consumer, `xls_serialization` 2.1.0 (one XLS
export display, `admin_contracts_dethatching`), allows `^2.4.0 || ^3.10.0`. So the path is:
remove the two disabled pinners, raise the root constraint, take **3.10.8+**.

**Before that:** four custom services call the library directly — `supplier_price_ingest`
`IngestParser`, `wo_material_list_management` `MaterialListImportService`, `bos_it_import`
`ItEquipmentImportService`, `bos_wex_import` `WexFuelImportService`. Check their API surface
against the 2 → 3 release notes (3.x removed deprecated reader/writer entry points) and run each
importer's parser against a real file in the sandbox. Exposure today is bounded — BOS only parses
XLSX/CSV uploaded by authenticated office roles — which is why this is logged rather than done.
Not a D11 blocker (the library is not core-constrained).

## 29 Views handlers render as the Broken handler — pre-existing, found by the D11 audit

**Surfaced 2026-11-07.** `web/scripts/audit_views_handlers.php` resolves every handler the way
Views does — through views **data** for `[table][field][type]`, not the `plugin_id` stored in
config, which Views ignores for handlers — and reports 29 that fall back to the Broken handler.
**Byte-identical on the untouched main dev env and on the D11 sandbox after every step-5 update**,
so none of them came from the upgrade.

Two recurring shapes. (1) A **`status` filter on an ECK entity type that has no `status` base
field** — `zipcodes`, `contracts`, `city`, `contacts`, `sprinkler_types`, `crew_types`,
`ownership_record`, `property_fertilizing_info`, `work_order` (`wo_time_clock_button`): a no-op,
the view shows everything regardless. (2) A **date SORT configured as `field: field_x` instead of
`field: field_x_value`** — Views registers sort/filter/argument on the *column* (`_value`), only
the field handler on the bare name — so the sort silently never applies: `backflow_compliance`
(`field_next_due_date`), the three `backflow_test_history_*` EVAs (`field_test_date`), the
`equipment_*` fuel/defect/inspection/maintenance views. Effect varies and was checked on the
built SQL: **`backflow_compliance` has no ORDER BY at all**; `equipment_fuel_transactions_admin`
also has a correctly-configured twin sort, so its SQL still orders. Singletons:
`estimate_current_customer_info` points at a **misspelled table `estmate_field_data`** (argument
+ relationship both broken); `admin_work_load` argument `created_year`;
`teammate_manual_related_sops` argument on `sop__field_teammate_manual_pages`;
`phone_number_changed_calendar` (a leftover `calendar`-module demo view) ×3.

**Fix is per-view config** — rename the sort's `field` to the `_value` column, delete the no-op
status filters, correct the table name. Re-run the audit after; the count is the verdict. The
healthy pattern to copy is already in the repo: `admin_billing` sorts on
`wo_complete_info__field_date_completed.field_date_completed_value`.

## `bos_contract_migrate` has a duplicate class file in the wrong directory

**Surfaced 2026-11-07** by `web/scripts/scan_class_compat.php`. `src/Plugin/migrate/source/process/BosContractSectionsGenerator.php`
declares `namespace Drupal\bos_contract_migrate\Plugin\migrate\process` — the namespace of the
`src/Plugin/migrate/process/` directory, where no such file exists. PSR-4 can never load it by its
declared name, and plugin discovery, deriving the class from the path, includes a file that declares
a different class. The module is **Disabled** on dev and live (a Feeds-era migration helper, last
touched in the 2026-03-01 "WIP snapshot" commit), so nothing runs it. Move the file to the directory
its namespace names, or delete it if the generator was superseded.

*Note on the "29 Views handlers render as Broken" item above:* the count is now **27** — step 6
deleted `estimate_current_customer_info` (unloadable: misspelled base table) and the
`d11_step6_config_fixes.php` script repaired the two entries that were aborting core's post-update.

## Post-D11-cutover follow-ups — surfaced during the live window 2026-11-07

Live has been Drupal 11.4.12 since 2026-11-07 23:51 UTC (record: `d11_live_cutover.md` → "What the
ACTUAL run found"). None of these blocked the cutover; each was seen and deliberately left.

1. ✅ **DONE 2026-11-08 04:53 UTC** — `web/sites/default/files/private` created on live (755, owner `brookstoneadmin`), Drupal wrote its deny-all `.htaccess` via `file.htaccess_writer->ensure()`; both `/sites/default/files/private/` and its `.htaccess` return **403**; the status report has no error-level row left; 0 new `security` watchdog rows after. Nothing uses `private://` yet (0 field storages, 0 files, 0 config mentions, measured first); it stays inside the docroot behind `.htaccess` — move it outside with a `settings.php` change the day sensitive files land there. *Original item:* **`private://` has no directory and no `.htaccess` — pre-existing.** The status report's only
   severity-2 row and a `security` watchdog entry on every status-report view: *"Couldn't write
   .htaccess file. Create a .htaccess file in your `private://` directory."* `web/sites/default/files/private`
   has never existed on the new VPS (first logged 2026-11-06 19:50 UTC, before any D11 work). Either
   create the directory (owner `brookstoneadmin`, outside the docroot is better: point
   `$settings['file_private_path']` at `~/private` and let Drupal write the `.htaccess`) or unset the
   private path if nothing uses `private://` (check field storages with `uri_scheme: private` first).
   Hygiene; 15 minutes.
2. ✅ **EXPLAINED 2026-11-08 — not a permissions bug.** The `county` entities: Delta `status=1`, Montrose
   `status=1`, **Gunnison `status=0` (unpublished)**. Anonymous is refused on `/colorado/gunnison-county`
   because the page is unpublished; office users see it because they may view unpublished content. Delta
   and Montrose are public and 200. Todd is still writing Gunnison — publish it when ready and the 403 (8
   rows / 1 crawler IP in the last 7 days) ends by itself. *Original item:* Anonymous `access denied` on
   `/colorado/gunnison-county`, one crawler IP since at least 2026-11-06.
3. ✅ **DONE 2026-11-08 05:14 UTC** — `web/libraries/` is **in git** (`829746cc`: ignore rule dropped, `colorbox` + `dropzone` + `nouislider` committed, **`dompurify` 3.4.17 added** from the npm registry with the tarball's sha512 verified against the registry's integrity field, `drupal-superfish` + `fullcalendar_io` deleted). Deployed `--skip-composer --no-maintenance`, dry run read first (262 library deletions, 5 new files, nothing else but the two `error_log`s and an empty AutoSSL dir), rsync 5 min, `cr` clean; live now serves all four library files (200), the removed folder 404s, DOMPurify md5 identical dev/live, Colorbox requirements both *Installed*, 0 watchdog rows after. The gitignored exports question: **deploy excludes added `c613ffc8` (2026-11-08 15:25 UTC), stray tracked `test.php` removed**; the 15 copies already on live come off with Todd's `~/tmp/cleanup_live_20261108.sh` (section 1, md5-gated). **Correction:** `drupal-superfish` + `fullcalendar_io` are composer-managed (`type:drupal-library`) and came back on the next `composer install` — now gitignored, see #13. *Original item:* **`web/libraries/` is gitignored but rsynced with `--delete`** — a deploy from any fresh clone
   deletes every manually installed library on live (`colorbox` is enabled; the touch-punch library
   went on 2026-11-07 on purpose). Either commit `web/libraries/` (small, rarely changes) or add it
   to the deploy script's exclude list and document that libraries are installed on live by hand.
   The same hazard applies to the gitignored `docs/backups/*.json`, `docs/*.csv`,
   `properties_export.csv`, `web/scripts/export_properties.php` — decide per file whether they live
   in git, on live only (exclude), or nowhere.
   **Measured 2026-11-08:** dev and live hold the **same five folders, byte-identical** (343 files,
   4.4 MB; md5 of every file equal): `colorbox`, `dropzone`, `nouislider`, `drupal-superfish`,
   `fullcalendar_io`. Not in git (`.gitignore:19`), not composer-managed (`installer-paths` for
   `drupal-library` exists but no such package is installed; no asset-packagist repository). The
   deploy excludes only `web/libraries/aws*`. **Used by enabled modules on live: 3** — `colorbox`
   (→ `colorbox`), `dropzone` (→ `dropzonejs` + `dropzonejs_eb_widget`), `nouislider` (→
   `better_exposed_filters` 7). **Unused: 2** — `drupal-superfish` (module `superfish` not enabled)
   and `fullcalendar_io` (nothing in the codebase references the folder; `fullcalendar_view` is not
   enabled and would want `/libraries/fullcalendar`, `moment`, `rrule`, `JSFrame` instead).
   **Missing: 1** — `colorbox` also declares `/libraries/dompurify/dist/purify.min.js`, which is why
   the status report says *"DOMPurify library: Not installed"*; without it Colorbox skips caption
   sanitisation. **Recommendation:** drop the ignore rule and **commit the three used folders +
   add `dompurify`** (~2.5 MB) so a fresh clone deploys safely and dev = live by construction;
   delete the two unused folders in the same deploy (dry-run first). Composer/asset-packagist
   management is the cleaner long-term shape but needs a package + path mapping per library —
   not worth it for three static folders.
4. **Stale `batch` row 10871** — the `update.php` batch LiteSpeed timed out at request 91. Harmless;
   cron's batch GC removes rows older than its window. Just know it is there if someone grep's the
   table.
5. ✅ **DONE 2026-11-08 14:24 UTC — Trusted Host Settings enabled on live.** `trusted_host_patterns` =
   bare domain + `www` only (live `settings.php` lines 882–884, re-hardened 444 / 555; pre-edit copy
   `~/backups/settings.php.pre_trusted_hosts_20261108_1424`). Measured first: four hostnames reach this
   docroot (bare, `www` → 301 in `.htaccess`, the `mail.` alias, and the `dev.` subdomain — see #10);
   LiteSpeed already 404s raw-IP/forged hosts; no HTTP cron; CLI exempt (`DrupalKernel.php:1235`).
   Applied by Todd via the self-reverting `~/tmp/apply_trusted_hosts.sh` — the classifier blocks SSH
   writes into the live docroot, `scp` to `~/tmp` works. Status report: no error-level rows left.
   `change_log.md` 2026-11-08.
6. ✅ **DONE 2026-11-08 15:25 UTC** (`26df1f3d`, deployed; `installed.json` on live records the local paths). *Original:* **Two of the four composer patches are fetched from remote URLs at install time** (re-measured 2026-11-08: `views_aggregator` MR 27 on git.drupalcode.org, `page_manager` 3438993 on drupal.org; core and the second `page_manager` patch are local) — with
   `composer-exit-on-patch-failure: true` armed, a drupal.org outage is now deploy-stopping.
   Localise them into `patches/` (the core patch already is). T3, 10 minutes.
7. **Guardrail: do not enable `block_visibility_groups_admin` on Drupal 11** — 2.0.4/2.0.5 still
   call `user_role_names()` (removed in 11.0) despite declaring `^11`; the fix exists only on the
   untagged 2.1.x branch. The parent `block_visibility_groups` 2.0.4 is enabled and fine.
8. **Drupal 12 deprecations seen along the way** — the D11 status report's full "Deprecated modules found" list on live (2026-11-08): `ai_ckeditor`, core `ban`, `contact`, `field_layout`, `history`, `layout_builder_expose_all_field_blocks`, `migrate_drupal`, `migrate_drupal_ui`, plus Field Group's accordion formatter. Also "DOMPurify library: Not installed" (CKEditor 5 warning, not an error). Nothing to do for 11; the planning input for 12 — each needs a usage check before uninstall (`history` feeds "new/updated" markers; `field_layout` may back entity displays).
9. ✅ **DONE 2026-11-08 15:31 UTC** — primary dev re-seeded on **MariaDB 11.4.8-MariaDB-ubu2404-log** (`.ddev/config.yaml` `drupal11` / `11.4`; `ddev delete -Oy` → start → import of the 02:30 UTC nightly; no DB updates, 0 pending entity changes, 373 modules, smoke 200). The composer-version pin waits for the `brookstone-d11` deletion (HOLD). *Original:* **Dev environments:** the primary `brookstone` DDEV project runs **MariaDB 10.11** while live and
   the sandbox run **11.4** — the sandbox proved 11.4 is not a variable; moving dev to 11.4 at the next
   re-seed removes the last mismatch (`.ddev/config.yaml` is tracked; it needs a DB delete + import).
   The two DDEV images also carry different composer versions (2.9.2 vs 2.11.1), which rewrites the
   lock's `plugin-api-version` back and forth — harmless churn, worth pinning when `brookstone-d11`
   is deleted.
10. ✅ **DONE 2026-11-08 ≈15:50 UTC — removed by Todd in cPanel → Domains** (it was not visible in the Hosting.com portal/WHM, only in the account's own cPanel). Verified read-only right after: `uapi DomainInfo list_domains` → `mcp.` only; `/var/cpanel/userdata/brookstoneadmin/dev.*` gone; zone has no `dev` A record; live `web/index.php` + `settings.php` untouched (md5 unchanged); site 200. `mcp.` kept at Todd's request. *Original:* **`dev.brookstoneoutdoors.com` is a second hostname for the live site** — surfaced 2026-11-08 in the
   trusted-host recon. `uapi DomainInfo domains_data`: the subdomain's document root is
   `/home/brookstoneadmin/brookstone/web` (the live tree; the `~/dev.brookstoneoutdoors.com` folder does
   not exist). Almost certainly the pre-cutover test hostname from the 2026-08-30 server move. Reflectionbot
   crawled material pages through it that morning (200s), and Drupal's page cache held a `dev.` copy of `/`
   from 08:30 UTC. Since #5 Drupal answers it 400, but the DNS record and AutoSSL cert remain and every
   request still costs a LiteSpeed→PHP round-trip. **Todd:** cPanel → Domains → delete
   `dev.brookstoneoutdoors.com` — removal leaves the document root untouched by design (cPanel support),
   so the live tree is safe. Not in the Google Maps key's Websites list (Todd checked 2026-11-08). Decide `mcp.brookstoneoutdoors.com` too (own docroot with only a
   422 B `.htaccess` from 2026-08-30, `cgi-bin`, 0 hits in both logs). Roadmap row under the D11
   follow-ups. T3. Not in the Google Maps key's Websites list (Todd checked 2026-11-08).
11. ✅ **DONE 2026-11-08 15:25 UTC** (`5b5c496a`, deployed; live probes `/scripts/service_bodies.json`, a CLI-guarded `.php`, `/scripts/.htaccess` → **403**). *Original:* **`web/scripts/` is reachable over HTTP** — surfaced 2026-11-08. 546 files (538 PHP) inside the docroot,
   no `.htaccess`; `https://brookstoneoutdoors.com/scripts/` → 403 (listing denied by `Options -Indexes`), so
   the directory is served and a `.php` requested by name executes. Core's `web/.htaccess` denies specific
   extensions, not `.php`. 33 scripts guard on `PHP_SAPI`; most others fatal without bootstrap (500, no state
   change); 2 bootstrap Drupal themselves via `autoload.php` (`s3_backup_upload.php`, `scan_class_compat.php`).
   Fix: `web/scripts/.htaccess` → `Require all denied` (+ `<IfModule mod_authz_core.c>` fallback for
   `Deny from all`); deploys with rsync; `drush php:script` unaffected. T2, 10 minutes. Roadmap row.
12. ✅ **DONE 2026-11-08 ≈16:05 UTC — Todd ran `cleanup_live_20261108.sh --with-rollback-pair`.** Verified read-only after: the 15 exports + `test.php` gone (`docs/` on live keeps only 3 tracked `.md` files, now orphaned by the deploy exclude — harmless); `~/tmp` holds only the script, an empty `composer-pre-w1` dir and cPanel's own session/stats entries; `~/backups` **1.8 GB → 136 MB** (the four D10-era dumps and the 1.3 GB rollback pair deleted; kept: the D11 logs, `settings.php.pre_trusted_hosts_20261108_1424`, and a **`pre-plum-update-20261108.sql.gz` 142 MB that appeared today and is not from this session — left alone**); 14 nightlies intact; site 200 in 0.28 s. *Original:* **Live backup / `~/tmp` hygiene** — surfaced 2026-11-08; staged as `~/tmp/cleanup_live_20261108.sh` for Todd (classifier blocks remote deletes; md5-gated; copies verified on `/mnt/d/Backups/brookstone-dev/`). Details on the roadmap row: four D10-era one-off
   dumps in `~/backups` (≈600 MB) superseded by nightlies + S3; four `settings.php` copies with secrets in
   `~/tmp` from the Oct 2026 memlog/pathfix work; Sept work CSVs in `~/tmp`; my trusted-host helper files. The
   stray tracked `test.php` at the repo root (`<?php echo 'OK';`, 2026-05-28) is covered by #3's row. T3.
13. **Live IO rule (learned the hard way 2026-11-08 ≈15:05 UTC):** a `md5sum` over ~1 GB of dumps plus a 470 MB
   `rsync` pull during the morning gave Todd a **503** on a public page — the account's IO throttle starved
   the PHP workers (load was 0.6; it is IO, not CPU). Bulk reads on live (`md5sum`, `rsync`, `tar`, `du`
   over big trees) go **at night**, under `ionice -c3 nice -n19`, with `rsync --bwlimit=2000`. Two traps:
   the remote process **outlives a dropped ssh session** (it kept reading after the local side was killed),
   and `pkill -f "<path>"` matches the shell issuing it — use `pgrep -af "^md5sum"`. **Also recorded:**
   `web/libraries/fullcalendar_io` + `drupal-superfish` are composer-managed (`drupal/fullcalendar_io`,
   `lobsterr/drupal-superfish`, `type:drupal-library` via `installer-paths`) — yesterday's #3 called them
   stray; they are composer's and are now gitignored (`c613ffc8`). Removing the packages belongs to the
   `fullcalendar*`/`superfish` module-retirement decision.
