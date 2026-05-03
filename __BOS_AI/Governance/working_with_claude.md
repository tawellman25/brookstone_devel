# Working with Claude on BOS

Process discipline for collaborative engineering with Claude on the BOS project. These patterns emerged from Phase 0.5 / Phase 1 / Pre-Phase-2 / Phase 2 work (March–May 2026) and surfaced material issues that would have caused regressions or wasted commits if skipped.

This document is for both human contributors directing Claude and for Claude itself reading the project state at the start of a new session.

---

## Pause-and-verify before code

For any non-trivial work, the verification-then-build pattern catches issues before they cascade.

**The pattern:**

1. State the design assumptions explicitly at the top of the work
2. Run diagnostic queries / view existing code / test in isolation to verify the assumptions
3. Surface discrepancies before any code changes
4. Implement only after verification clears

**Why this matters in BOS specifically:**

- The codebase has accumulated latent bugs that activate when new code touches old paths (the wo_sprinkler_check_up `DrupalDateTime` import, the field_notes `appendItem` corruption, the wo_lawn_mowing unflag-without-user pattern). Verifying assumptions catches these before a commit becomes a debugging marathon.
- Field configs and bundle structures aren't always what the docs say (or what older code assumes). A 30-second diagnostic query confirms reality.
- Drupal config sync drift accumulates silently. Knowing the actual active state vs sync-dir state before importing prevents surprises.

**Real examples from recent work:**

| Verification step | Issue surfaced |
|---|---|
| Pre-Phase-2 Step 1 candidate-count diagnostic | 111 backfill candidates (vs hand-wave "many") — revealed scope was bounded |
| Phase 1 STEP 6 admin-permission audit | Discovered `'administer eck entities'` was the right gate, not `'administer site configuration'` |
| Phase 2c STEP 3 cascade-ordering investigation | Surfaced `wo_timer_flag_update_flagging_delete` would clobber reconciliation values; led to commit `92c9484f` |
| Phase 2c diagnostic on `field_signed_off_by` | Caught that the field doesn't exist on `wo_tasks_list` (only on `wo_complete_info`); spec adjusted |
| Pre-Phase-2 cim diff inspection | 8 pre-existing pending changes from prior wo_material_price_sync work surfaced; user authorized them deliberately rather than swept-forward |

**Six material issues** were caught by verification steps across this body of work that would have caused regressions or wasted commits if skipped. The cost of pausing to verify is low; the cost of NOT verifying compounds.

---

## Targeted commits over bundled scope

Each commit should have a single coherent purpose. The git log is part of the project documentation; future readers should be able to understand a change from its commit message alone.

**The pattern:**

- When unrelated work surfaces during implementation, stop, file as a separate commit, then resume the original work.
- Exception: when two fixes are foundationally related and touching the same file, bundling can be acceptable IF the commit message explicitly covers both.

**The `fc2bbf3f → e23a1153` precedent:**

During Pre-Phase-2's 111-entry backfill, the cascade hit a latent bug in `wo_sprinkler_check_up` (missing `use Drupal\Core\Datetime\DrupalDateTime;`). Two paths were possible:

- Bundle the `use` fix into the backfill commit (scope creep)
- Land the `use` fix as a small dedicated commit FIRST, then resume the backfill (clean separation)

The project chose the second path. The commit log now reads:

```
e23a1153  Pre-Phase-2 — wo_time_clock open/closed semantics fix + 111-entry backfill
fc2bbf3f  Fix — wo_sprinkler_check_up missing DrupalDateTime use statement
```

Each commit message accurately describes its scope. A future contributor debugging an irrigation-crew sign-off can find `fc2bbf3f` directly without wading through unrelated wo_time_clock backfill noise.

**The `92c9484f` precedent:**

Phase 2c needed a defensive change in `wo_timer_flag_update` to preserve foreman reconciliation end_times across the cascade unflag. Two changes to the same file: the defensive skip + a correction to the field_notes append. Both touched the same `if ($wo_time_clock)` block; bundling was acceptable. The commit message covered both fixes in dedicated paragraphs.

**Anti-pattern:** "Phase 2c — reconciliation + various fixes" with three unrelated changes mixed in. Always splittable; almost always worth splitting.

**Tactical question to ask at commit time:** "If a future contributor only sees this commit's message and diff, will they understand what changed and why?"

---

## End-to-end verification before declaring done

"The code looks right" is not the same as "the feature works." For any feature work, walk through the actual user workflow the feature enables before declaring complete.

**The pattern:**

- Identify the actual user flow the feature is meant to support
- Simulate or actually walk through it (browser if UI, drush eval if backend, real submission if form)
- Verify the data state matches the intent
- Cross-check downstream consumers (reports, views, billing math) reflect the change

**Why this matters in BOS specifically:**

Three feature shipments in early 2026 went to production non-functional because end-to-end verification was skipped:

1. **`wo_material_price_sync` form display** — supplier fields removed from `hidden:` section but not added to `content:` section. Drupal auto-re-hid them. Discovered during Phase 0.5 cleanup; fixed in commit `fb8a3e3b`.

2. **`wo_material_price_sync` view filter** — `no_invoice` filter configured as string equality with empty value (`LIKE ''`), which matches no rows. The view always returned zero results. Discovered during Phase 0.5 cleanup; fixed in commit `fb8a3e3b`.

3. **`wo_sprinkler_check_up` `DrupalDateTime` import** — referenced `DrupalDateTime` without `use` statement; latent until any irrigation-crew WO save reached the affected line. Discovered during Pre-Phase-2 backfill; fixed in commit `fc2bbf3f`.

All three would have been caught by a single end-to-end test on a real entity in a real browser before merging the original feature.

**For UI changes:** load the page in a browser, exercise the feature, observe the result. Don't just verify the code looks right.

**For backend changes:** run a drush eval or programmatic test that exercises the actual call sites. Don't just verify the function signatures look right.

**For data flow changes:** verify that downstream consumers (views, reports, dashboards, billing) reflect the change correctly. The wo_material_price_sync view bug was invisible in the form-display fix verification — only surfaced when checking the actual user journey "fill out form → submit → see entry in review queue."

---

## Recovery-point pushes

Push to origin at natural pause points between major work units. Multiple commits living only on a single working machine is a single-point-of-failure.

**The pattern:**

- Push to origin (NOT to main, NOT to live) at the end of a coherent work block
- Confirm the push landed before moving on
- Treat the push as backup-only — it doesn't imply the work is ready for deployment
- Live deploy is a separate, deliberate decision

**Recovery points used during Phase 2 work:**

```
Pre-Phase-2 + Phase 1 (4 commits)         → push to origin
Pre-Phase-2 + bug fix (2 commits)         → push to origin
Phase 2 (6 commits)                       → push to origin
```

Each push happened after the work block completed verification and committed locally. The branch advanced on origin without ever touching main or live.

**When NOT to push:**

- Mid-work, in an incomplete state (commits should be at clean stopping points)
- When the local branch contains experimental commits you might want to rebase away
- When merging to main or deploying to live is what's actually intended (those are separate operations with their own discipline)

---

## Live deploy discipline

Deploying BOS to live is a separate, deliberate operation distinct from pushing to origin. The deploy script is `dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh`. It defaults to dry-run; live deploy requires explicit `--live`.

### Always dry-run first

Run the script with no flags to see exactly what will change. The output is verbose — most lines are timestamp-only updates (`<f..T......` rsync flag) that don't affect functionality. Filter to substantive changes:

```bash
./dev_scripts/brookstone-sync-to-remote-DANGEROUS.sh 2>&1 | grep -vE "^<f\.\.T\.\.\.\.\.\."
```

What to look for:
- `<f+++++++++` — new file
- `<f.sT......` — content size differs (real change)
- `*deleting` — file will be removed (verify it's expected)
- `cd+++++++++` — new directory (typically a new module)

Pay particular attention to `config/sync/` entries and `web/modules/custom/` additions. Theme/twig-only timestamp changes are noise.

### `--cim` is opt-in and frequently required

The script does NOT run `drush cim` by default. Pass `--cim` when:

- Any `config/sync/field.field.*` or `field.storage.*` files are new or changed
- Any `core.entity_form_display.*` or `core.entity_view_display.*` files are changed
- `core.extension.yml` is changed (new module being enabled)
- `views.view.*` is created or modified

Without `--cim`, code on live tries to read fields that don't exist in the active config → broken forms, broken queries, errors. If you skip `--cim` and the code expects new infrastructure, live breaks silently.

Without `--cim`, new modules in `core.extension.yml` are NOT installed; their routes 404. The rsync delivers their files to disk, but Drupal's module-installer never fires.

### The BOS field-instance silent-skip bug — recurring on cim with new fields

Documented in [drupal_bos_gotchas.md](drupal_bos_gotchas.md). When a `drush cim` cycle simultaneously creates new `field.field.*` instances AND updates `core.entity_*_display.*` configs that reference them, Drupal sometimes lists the field instances as "Create" in the planner but fails at dependency validation:

```
The import failed due to the following reasons:
Configuration core.entity_form_display.X depends on configuration
(field.field.X.field_new) that will not exist after import.
```

The deploy script's failure trap leaves live in maintenance mode when this happens — users are blocked, but live isn't actively serving the broken state.

**Recovery procedure (used on the 2026-05-03 deploy):**

1. Confirm live is in maintenance mode: `ssh brookstone 'cd /home/brookstoneadmin/brookstone && drush sget system.maintenance_mode'` (returns `1`)
2. Write a one-shot PHP script that loops the new field YAML pairs and creates each via `FieldStorageConfig::create()` + `FieldConfig::create()` directly. Use absolute path for `config/sync/` (drush php:script may run from a different cwd):

   ```php
   $sync = '/home/brookstoneadmin/brookstone/config/sync/';
   $storage_data = Yaml::parseFile($sync . 'field.storage.X.yml');
   $instance_data = Yaml::parseFile($sync . 'field.field.X.yml');
   unset($storage_data['_core'], $instance_data['_core']);
   if (!FieldStorageConfig::loadByName(...)) { FieldStorageConfig::create($storage_data)->save(); }
   if (!FieldConfig::loadByName(...))      { FieldConfig::create($instance_data)->save(); }
   ```

3. `scp` the script to remote `/tmp/`, run via `drush php:script`, then delete it
4. Re-run `drush cim -y` — succeeds for everything else now that the dependency targets exist
5. `drush cr -y && drush sset system.maintenance_mode 0 -y && drush cr -y`
6. Smoke-test the new infrastructure with a read-only PHP script (verify fields exist, services resolve, controllers build)

The live UUIDs generated during step 2 will differ from the local sync YAMLs' UUIDs. Per [drupal_bos_gotchas.md → UUID drift](drupal_bos_gotchas.md), this is benign — code references configs by name, not UUID, and cim never modifies an existing config's UUID. Don't patch.

### Pre-deploy mitigation (preferred when feasible)

The recovery dance is avoidable if you pre-create new field configs on live BEFORE running the deploy that includes the dependent display updates. Two-deploy pattern:

1. **Deploy 1**: ship the new field configs only. Dry-run shows just the new `field.field.*` and `field.storage.*` files. Run with `--cim` — Drupal can create new fields with no display dependencies in a single cim cycle.
2. **Deploy 2** (in a follow-up commit): ship the display updates that reference those fields. Cim now succeeds because the field instances already exist on live.

In practice the work usually arrives bundled (a feature commits the field configs and the display updates together), so the recovery procedure is what gets used. Keep both options in mind.

### Failure log: instances of this bug triggering on BOS

| Date | Context | Recovery |
|---|---|---|
| 2026-03-XX | Phase 0.5 | Local — six business_setting threshold fields |
| 2026-04-XX | Phase 2a | Local — four wo_time_clock audit fields |
| 2026-05-03 | This deploy | Live — same ten fields hit again on first deploy to production; recovery script + re-cim took ~10 min |

The pattern is reliable enough that any deploy with new `field.field.*` configs should anticipate the failure and have the recovery script ready.

### Cleanup checklist after deploy

- Delete temporary recovery scripts from both local `/tmp/` and remote `/tmp/`
- Confirm `system.maintenance_mode = 0` on live
- Run a smoke test as a known admin user (controller invocations, field reads, service resolution)
- Verify any new module's routes resolve via `drush php:eval` against `\Drupal::service('router.route_provider')->getRouteByName('module.route')`

---

## Memory and session continuity

When a Claude session resumes after a context break (compaction, new session, browser refresh), the persistent file-based memory at `~/.claude/projects/-home-todd-code-brookstone/memory/` carries forward project-specific knowledge between sessions. Key entries to be aware of:

- **`feedback_no_drush_cex.md`** — never run blind `drush cex`; it overwrites manually-synced live config
- **`feedback_no_multipro.md`** — admin theme is `brookstone_admin`, never reference `multipro`
- **`feedback_views_pattern.md`** — Views YAML conventions for full field defs, truncation, etc.
- **`reference_live_server.md`** — SSH host `brookstone`, Drupal root `/home/brookstoneadmin/brookstone`

Project memory should be consulted at the start of any non-trivial session. Updates happen when new persistent rules emerge from the work.

---

## When to surface vs when to push through

There's a tension between "stop and ask the user" and "use judgment and proceed." The line:

**Stop and ask when:**

- The work crosses an architectural boundary not covered in existing docs (e.g., choosing between Behavior A/B/C for AJAX rebuild — see [architectural_patterns.md](architectural_patterns.md))
- Verification surfaces a discrepancy from the original spec
- The cost of being wrong is high (data migration, schema change, role/permission update)
- The user gave an explicit "STOP and report" instruction in the prompt

**Proceed with judgment when:**

- The decision is local to the implementation (variable naming, where in a function to put a guard)
- The pattern is documented in BOS governance and applies cleanly
- Verification confirms the spec's assumptions

**The reporting format that works:**

- Lead with what was found (concrete, with file:line citations)
- State the implication clearly
- Propose 2-3 options with explicit tradeoffs
- Make a recommendation with reasoning
- End with "Awaiting your direction" or equivalent

User feedback during Phase 0.5 explicitly endorsed this pattern as "the right one" after a partial-cim recovery. Use it whenever a substantive decision is exposed.

---

## Status

- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- 2026-05-03: added "Live deploy discipline" section after the Phase 2 simplification was deployed — first live cim hit the silent-skip bug; recovery procedure documented while still fresh.
- Living document — append new process discipline patterns as they emerge.
