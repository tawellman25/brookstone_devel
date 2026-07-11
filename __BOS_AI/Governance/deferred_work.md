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

[`CLAUDE.md` Change Log](../../CLAUDE.md#change-log) entry dated 2026-03-12 says:

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

### 20. Old stranded `field_invoiced` flags (pre-completion status)

Surfaced 2026-06-25 (billing-crash investigation). Three WOs carried `field_invoiced = 1` while in
a pre-completion status (In Progress): **45301 / 49668 / 50078** — same orphan pattern the
2026-06-20 remediation reverted for three other WOs (an accidental clock-in bounces an
already-invoiced WO back to In Progress).

**Live-verified 2026-07-11:**
- **45301** (sprinkler_winterizing, $90) — ✅ **RESOLVED 2026-07-11**: restored to **Invoiced** (status 1281) via the documented `$wo->_skip_invoiced_guard` bypass (it had a `wo_complete_info` and `field_invoiced=1`; no billing recompute, $90 preserved).
- **49668** (landscaping, $2,714) — already **Invoiced**; the office fixed it earlier. Not stranded (this is the one that made the roadmap read "down from 3").
- **50078** (landscaping, $386.70) — **still stranded** (In Progress + `field_invoiced=1`, 0 open clock-ins). Same benign case; fix = restore to Invoiced the same way.

**Remaining: 1 (50078).**

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

## Resolved — archive next cycle

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

## Status

- **2026-07-11 — reconciled against [`ROADMAP.md`](../ROADMAP.md).** Fixed the duplicate "#16" (dual-field-drift renumbered → #24); moved resolved #17 + #23 to the new "Resolved — archive next cycle" section; added `↔ ROADMAP:` cross-refs on the items also on the roadmap (#7, #8, #9, #10, #18, #20); flagged #20's 3-vs-2 stranded-id discrepancy for live verification. ROADMAP is the tie-breaker.
- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Living document — append new deferred items as they emerge from project work, with the surfacing commit cited.
- Items 1-12 are technical work tracked here for context; mirror to Todoist if you want them in your active queue.
- Items 13-15 are the apprentice-onboarding initiative; tracked separately.
