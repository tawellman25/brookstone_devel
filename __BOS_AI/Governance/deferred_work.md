# BOS Deferred Work Tracker

Items surfaced during recent BOS work but deliberately deferred. Mirror to Todoist where actionable, but having the list here gives future Claude sessions and contributors context without re-discovering each item from scratch.

Each entry cites the surfacing commit (or session) so readers can dig into git history for the full context.

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

### 16. Pre-boundary `wo_time_clock` dual-field drift backfill (deferred)

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

**Surfaced during Phase 2 design.**

---

### 8. `special_mowing` reconciliation

`wo_tasks_list:special_mowing` has 8 entries in the local DB at time of Phase 2 diagnostic (May 2026). Excluded from Phase 2c per the heuristic ("fewer than ~50 entries OR roster adoption under 50% → defer").

Revisit if:

- Entry count grows substantially (≥50 entries)
- Roster adoption stays high (the 8 entries were 100% populated)
- Operational pattern stabilizes around scheduled per-property mowing rather than ad-hoc requests

Implementation note: `wo_special_mowing` has `hook_entity_presave` only — no cascade hook on `wo_tasks_list:special_mowing` like wo_lawn_mowing has. Reconciliation pattern would need adaptation, possibly intercepting at the wo_complete_info path instead.

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

**Surfaced as pattern across multiple commits in spring 2026.**

---

### 10. BOS branch strategy review

`drupal-update-20251206` has accumulated 100+ commits parallel to `main`. The branch was created in late 2025 for a Drupal version update; subsequent feature work landed on top because there was no clean re-merge cadence.

Decide on either:

- Periodic merge cadence (every N weeks, every M commits, every release-candidate tag)
- Branch model that prevents this kind of drift (trunk-based development, short-lived feature branches, or formalized long-lived release branch)

The current state isn't broken — origin tracks the full history, recovery is possible — but the longer the drift, the harder the eventual reconciliation. Worth a strategic decision before adding another 100 commits.

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

### 17. Finalize weed-spray WO #49698 — mostly resolved

Surfaced 2026-06-25 (spray-route-guard investigation). #49698 (19988 Iris Rd) is a real
spray ($183, completed 05-12) that was **resurrected** to In Progress by a stray clock-in.
The new housekeeping **flags** resurrected WOs rather than auto-fixing them (auto-restore
could corrupt spray history on older ones). **Update (06-25):** the office set #49698 back
to **Complete** by hand, so it's no longer stuck/trapping. Remaining: invoice it in the
normal billing flow, and note it carries 5 time-clock entries vs one recorded spray —
reconcile the extra time if any of it is real later work.

### 18. Weed-spray stale-cancel threshold tuning

The abandoned-WO sweep cancels stale-empty WOs at **>45 days**. As of 2026-06-25, 49903
and 49906 (43 days, zero work) sit just under the line and aren't swept yet. 45 days is
past even a monthly cycle (35d), so it's deliberately conservative; revisit if the office
wants empty WOs cleaned sooner (could be made frequency-relative). Branch
`feature/spray-route-guard`.

### 19. Legacy 2024 weed_spraying WOs stuck in status 1301 "Active"

Surfaced 2026-06-25. A handful of 2024 weed_spraying WOs (e.g. 35093/35098/35104/35106)
are `invoiced = 1` yet sit in status **1301 "Active"** — a non-done status. They're out
of scope for the spray-route guard (year-scoped + invoiced-guarded, so never touched), but
worth understanding: why did invoiced WOs land in "Active," and should 1301 be folded into
the done-set / corrected? Data hygiene, not urgent.

### 20. Old stranded `field_invoiced` flags (pre-completion status)

Surfaced 2026-06-25 (billing-crash investigation). Three WOs carry `field_invoiced = 1`
while in a pre-completion status (In Progress): ids **45301 / 49668 / 50078** (changed
2026-04-20 / 05-26 / 06-05). They predate the 06-24 batch crash (unrelated) and are the
same orphan pattern the 2026-06-20 remediation reverted for three other WOs. Optional
data cleanup: revert the flag or finalize the WOs.

---

## Status

- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Living document — append new deferred items as they emerge from project work, with the surfacing commit cited.
- Items 1-12 are technical work tracked here for context; mirror to Todoist if you want them in your active queue.
- Items 13-15 are the apprentice-onboarding initiative; tracked separately.
