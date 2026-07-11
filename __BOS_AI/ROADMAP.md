# BOS Design & Implementation Roadmap

Status-of-record for unfinished BOS initiatives. Reconciled against live production on 2026-07-03 (read-only recon: SSH to Hosting.com checkout + drush against live DB). Verified-done items removed; survivors only.

**Owner:** Todd · **Repo path:** `__BOS_AI/ROADMAP.md` · **Last reconciled:** 2026-07-03 · **Last updated:** 2026-07-11 (deferred_work.md reconciled + linked; off-server-backup + config-drift promoted)

---

## How this roadmap works

Every item carries three dimensions so priority resolves honestly instead of a flat number that lies:

- **Horizon** — 🔥 Fire (active production problem) · **NOW** · **NEXT** · **LATER**. Derived from tier + effort + season.
- **Tier** — `T1` revenue conversion, live production risk, or compliance · `T2` crew efficiency & margin protection · `T3` hygiene / nice-to-have.
- **Effort** — S / M / L build size.
- **Season** — Western Slope reality: irrigation live now (summer); snow prep early fall; heavy refactor/build in winter downtime (Dec–Feb).

**Rule:** Fire first. Then T1 ahead of T2 ahead of T3, low-effort-high-impact ahead of big builds, all gated by season window.

**Trust discipline:** Every code/config/data status is anchored to a commit hash or a live check so this exact recon can be re-run quarterly. A roadmap you can't re-verify is one you stop trusting. Re-run the recon (see bottom) at each seasonal boundary.

---

## NOW — revenue-blocking or in-season

### ⭐ Estimating epic — the revenue-conversion pipeline (T1, Effort L)
This is the single highest-leverage cluster in the system. The pieces exist but nothing drives the chain end to end — "engine built, cockpit missing." It's how sold work becomes scheduled, billable work. Do this before anything else on the board. Ordered by dependency:

| # | Phase | Module | Notes |
|---|---|---|---|
| 1 | Estimate creation from a Request | `estimate` | Estimator opens request → create estimate entity; bundle inferred from `field_service`. |
| 2 | Estimate request status transitions | `estimate` | New → Assigned → In Progress → Pending Client Review → Accepted/Declined. Nothing drives stages today; sits at New. |
| 3 | Client acceptance flow | `estimate` | Checkbox + e-signature capture + print/PDF fallback for older clients. |
| 4a | Branch: recurring → Contract | `estimate` | Mow/spray/pre-emergent/check-ups: accepted estimate creates a **contract section**, not a WO. |
| 4b | Branch: design-build → deposit → WO | `WorkOrderConverter` | Wire the existing converter to the acceptance trigger; deposit path. |
| 5 | Estimator + client notifications | `estimate_notifications` | Module exists; complete the set (estimator on assign, client on ready). Depends on SMTP being live — verified DONE. |

### ⭐ Voice-to-Work-Order — CORE SHIPPED 2026-07-04 (T1/T2)
Speak into a phone → phone dictation transcribes → BOS creates a Work Order from the text.
**Deterministic** text resolution (no LLM) — parse the utterance, resolve the property by
**nickname** (token-order-insensitive) + service by vocab term, create the WO. **Gates 1–2B are
live.** Remaining work is polish (see LATER). Cowork was one candidate front-end and proved less
useful than this in-house path; the endpoint built for it is the reusable foundation.

| Gate | Status |
|---|---|
| **Gate 1** — authenticated WO-intake endpoint | ✅ **SHIPPED & live** (`166f573b`; `POST /api/wo-intake`, X-API-KEY, `system_integration` + `cowork-connect`). The durable REST foundation. |
| **Gate 2A** — `createFromText()` resolution brain | ✅ **SHIPPED to live 2026-07-04** (`b17c4d27`; deterministic parse + nickname/service resolution + two-tier duplicate guard + complaint note; 11/11 acceptance). Config landed via `hook_update_10001` (81 synonyms). |
| **Gate 2B** — mobile intake page + toolbar icon | ✅ **SHIPPED to live 2026-07-04** (`b17c4d27`; `/wo-intake`, `use work order intake` perm → office/admin roles, AJAX Form, four result states + 37-term picker, candidate-tap resubmit, authored by the logged-in human). Docs: `work_order_api.md` (2A+2B as-built). **Phone test passed 2026-07-06** — parking-lot-to-WO confirmed on-device; refinements tracked under the LATER polish rows. |

**Voice-to-WO — remaining (all LATER polish):**
- **Scheduling / date grammar** — parse a date from the command → wire the scheduling cascade (2A deliberately omitted it).
- **Crew rollout** — tick `teammates` on `use work order intake` (they already hold entity perms). Not a build.
- **Seasonal date-default** — resolve `cleanup` (Fall/Spring) and bare `pruning` (Summer/Winter) by current season instead of candidates.
- **Parent-category word → child candidates** — a phrase matching a category name (`pruning`, `spraying`) offers its WO-service children as candidates instead of an empty `ambiguous(service)`.

**Gate 2A follow-ups (decided/logged 2026-07-04):**
- **Ambiguous service phrases stand as-is** — `design` · `spray`/`weed spray` · `lighting` · `cleanup` return candidates (no auto-map), resolved via 2B tap-to-pick. Not a bug.
- **💡 Seasonal auto-disambiguation (idea, future):** resolve `cleanup` (Fall 413 / Spring 411) — and possibly bare `pruning` (Summer/Winter) — by the **current date/season** instead of returning candidates. A small dated-synonym layer on top of `synonym_map`. Deferred.
- **✅ Data fix DONE (local + live, 2026-07-04):** un-flagged `field_work_order_service` on the **parent-category** terms **366 "Spraying"** (12 children) and **388 "Pruning"** (2 children) — grouping nodes mis-flagged as WO-services. Reference diagnostic (`web/scripts/wo_intake_term_refs.php`) confirmed **0 `field_service` references** → zero-risk; WO-service pool 39→37; leaf children unaffected. Applied as DB data on **both** environments (it's entity data, not config, so a local-only change would revert on the next prod sync). Removes the two `service_bundle_missing` edge cases.
- **💡 Bare category word → child candidates (idea, minor):** post-un-flag, saying just `"pruning"` or `"spraying"` now returns `ambiguous(service)` with **no** candidates (the parent term name no longer resolves; children are "Summer Pruning" / "Weed Control", not "pruning"). Acceptable fallback (2B lets the user pick/retype), but nicer would be: if a phrase matches a **parent category** name, offer that category's WO-service **children** as candidates. Small resolver enhancement; deferred.

### Other NOW items
| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| QuickBooks Desktop IIF export | Billing | T1 | M | Fully scoped. Residential = 1 invoice/WO; Commercial = batched line-items/period; two-bucket on `field_client_type`. |
| Warranty full-dollar-capture + QB zeroing | `wo_sign_off` / Billing | T2 | M | Sign-off path live (1283); **dollar-capture fields do not exist**. Coupled deploy unit — capture + zeroing ship together or risk billing customers for warranty work. |

---

## NEXT — sequence after NOW clears

| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| **⭐ Material price scraping — first real SiteOne end-to-end run** | `supplier_price_ingest` | T2 | M | The ingest pipeline is **built + live** but has **never been run against a real full SiteOne catalog** — that's the missing step. **3.10 (DDEV):** acquire a SiteOne catalog scrape (Claude-in-Chrome → CSV; first pass = irrigation + pvc + brass + galv), create the SiteOne `supplier_ingest_config` column mapping, run parse→match→dry-run→approve→commit, log + fix bugs. **3.11 (prod):** live DB snapshot → deploy fixes → first real ingest → verify (`material_suppliers`, `material_price_history`, `/admin/materials/price-review`) → 48-hr watch. **Then automate:** replace the manual Claude-in-Chrome scrape with the **Lever 3 Python/Playwright scraper** (after the Lever 2 family map). Full detail: `Architecture/supplier_pricing_pipeline_phase3_sequencing.md` §3.10–3.11; family rules in `Extraction/siteone_families.md`. |
| **Off-server DB backup copies** | Infra / DR | T1 | S | Business continuity. Nightly `bos_db_backup.sh` keeps 14 rotating dumps in `~/db_backups` on live but **same-disk as the DB** — protects logical loss, not disk/server failure. Push the newest off-server (S3, or a scheduled pull to a workstation/NAS via `dev_scripts/brookstone-sync-db-from-live.sh`) + a "no backup in 36h" heartbeat alert. Detail: `deferred_work.md` #21. |
| TimeTrax live SQL-read integration | `bos_teammate_operations` | T2 | L | Foundation + swappable `CompensableHoursService` on 8.5hr assumption built. Swap in real SQL Server read (Punch/Employee/EmployeeCards, PunchKey idempotency). Labor-cost accuracy. |
| Estimate board pipeline swimlane rework | `estimate_board` | T2 | M | Build prompt produced; replace single "Active Pipeline" with per-status swimlanes + color. Pending Code execution. |
| wo_clock — foreman crew-status view + end-of-day notifications (Phase B/C) | `wo_clock` | T2 | M | Phase A shipped (clock-in/out redesign + silent GPS + attribution). Next: foreman "who's still clocked in" view + end-of-day open-clock-in alerting. Flag-path retirement is the winter cleanup (see LATER). |
| Status-service refactor → **`WorkOrderStatusService`** | `wo_status_updates` | T2 | M | Fixes presave-saves-the-WO coupling. Call sites: `update_spraying_info_from_invoiced_work_order`, `update_work_order_invoiced_action`. Canonical name settled 2026-07-04 (`WorkOrderStatusService` — broader than invoicing). |
| Fuel surcharge — full build from zero | `wo_sign_off` + 36 bundles | T2 | L | **Greenfield confirmed (2026-07-04).** Exhaustive search found **zero trace** of any "Phase 1": no branch (local/remote), no commit across all refs, no stash/reflog, no design doc, and live has no field/toggle/per-zip rate on zipcodes, business_setting, or any work_order bundle. The 05-04 "Phase 1 complete" was a Chat-side plan that was never coded. Build all of it: per-zip rates + business_setting toggle + 36-bundle fields + sign-off math. |
| 1 stranded invoiced WO (`50078`) | Data hygiene | T2 | S | Was 3 (45301/49668/50078). **Live-verified 2026-07-11:** **45301 fixed** (restored to Invoiced after a clock-in resurrection), **49668** already resolved. **50078** (landscaping, $386.70) remains In-Progress + `field_invoiced=1` — same benign case; restore to Invoiced. Detail: `deferred_work.md` #20. |
| 45-day auto-cancel threshold pressure-test | spray-route-guard | T2 | S | Monthly freq = 35d, only 10d margin. Validate no legit pending sprays exceed 45d before unattended cron. |
| QuickBooks Invoicing SOP family completion | SOPs / `OFF-QBS-INV` | T3 | S | INV-003 authored & installed; **INV-001 (parent) + INV-002 not yet in `__BOS_AI/SOPs/`** — author + run the docx generator. SOP authoring is Chat's domain (Code installs). |

---

## Enablement — built, awaiting adoption

These are shipped, live systems whose only blocker is human adoption, not engineering — tracked separately so trapped value stays visible and self-verifies at each recon.

| System | Backing build | Owner | Done-signal (re-runnable) | Notes |
|---|---|---|---|---|
| Equipment inspection / defect / maintenance | enabled & live (entities + `equipment_inspection_workflow`; 6 checklists, 18 defect-auto rules) | Foremen (Herbert — landscape) | inspection record count > 0 and accruing across trucks — `drush php:eval "print \Drupal::entityQuery('equipment_inspection')->accessCheck(FALSE)->count()->execute();"` | Currently 0 records. Needs crews trained + required to submit before the automation produces value. |
| First signed backflow test (PDF + S3) | Backflow Device Management System, Gates 1–4, live | Office | first signed-test PDF exists — `drush php:eval "print \Drupal::entityQuery('wo_tasks_list')->accessCheck(FALSE)->condition('type','backflow_testing')->exists('field_report_pdf')->count()->execute();"` (≥1 `wo_tasks_list:backflow_testing` with a generated `field_report_pdf`; production files land on S3) | Was previously filed as an engineering "smoke test"; the real blocker is the office running one real signed test. |

---

## LATER — off-season, season-gated, or hygiene

### Season-gated (build early fall 2026)
| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| Snow removal architecture + reconciliation bundle | snow_removal | T2 | L | Winter service — build before season. Reconciles clocked labor vs recorded work (same class as WO#49698). |
| `special_mowing` reconciliation bundle | special_mowing | T2 | M | Same reconciliation pattern. Early fall. |

### Supplier pricing pipeline (ongoing sub-project, T2/T3)
**Core pipeline SHIPPED & live** (`supplier_price_ingest`, enabled on live; Phases 3.1–3.7, commits `05-25`…`05-31`): materials intake = **parse → match → dry-run report → approve → commit**, with a tiered matcher (Tier 1/2 exact + SKU-normalized/prefix, Tier 1.5 title-substring, Tier 3 fuzzy), a Discovery Queue + resolve forms, and office-manager dashboards. `wo_material_price_sync`, `material`, `material_supplier` all on. Docs: `Modules/supplier_pricing_pipeline_phase3_sequencing.md`. The rows below are the **remaining tuning/backfill/authoring** work, not a from-zero build.

| Item | Status | Notes |
|---|---|---|
| Rain Bird MEDIUM-tier backfill (71 rows) | In Progress | Backfill mfr item numbers to raise Tier 1 rate. |
| Hunter backfill (~67) | In Progress | Smaller-scope backfill. |
| **First real SiteOne end-to-end run (Phase 3.10 → 3.11)** | **Promoted to NEXT** | The actionable scraping milestone — run the built pipeline against a real SiteOne scrape (manual Claude-in-Chrome → CSV) in DDEV, then production. See the NEXT row. |
| Matcher: supplier-alias table + Tier 3 fuzzy tuning | Idea (T2) | Tier 3 catching 0; cross-supplier SKU alias map + threshold tuning. |
| SiteOne standalone scraper automation (Lever 3) | Idea | Automate the scrape itself: Python/Playwright + manual cookie, replacing the Claude-in-Chrome step. Build **after** the first manual end-to-end run (NEXT) + the Lever 2 family map. |
| Tier 1.5 confidence bump 85→90 | Idea | Validate zero false positives over 2–3 batches first. |
| Bulk-rerun batches 205/276; Bulk Confirm/Send-to-Discovery VBO; Spears CSV relocation | Idea | Held until manual pain is felt. |
| Eight Phase 3.12 supplier SOPs | Scoped | Authoring pending. |
| **Apprentice onboarding** (pointer) | Idea | Guide + catalog-cleanup checklist + Claude working guidelines for the apprentice — catalog cleanup feeds the SiteOne run, hence the placement here. Detail: `deferred_work.md` #13–#15. |

### Fleet / equipment tracking (T3)
**WEX fuel + mileage core SHIPPED & live** (`bos_wex_import`): automated **daily 7am IMAP fetch** of WEX fuel-card transactions + a drush-independent **failure watcher** (emails on a missed/incomplete fetch), driver resolution (`teammate_profile.field_wex_driver_prompt_id`), vehicle resolution (`field_vehicle_number`), idempotent re-imports, and **vehicle mileage auto-update** from odometer reads. ~388+ transactions across 21 vehicles. Docs: `bos_wex_import.md`, `wex_fuel_import_workflow.md`. _(No live GPS/telematics — "tracking" = fuel/mileage/inspection/maintenance records, not real-time vehicle location.)_

| Item | Status | Notes |
|---|---|---|
| Bogus-high odometer guardrail | Idea (real risk) | A wildly-high odometer read writes through and **permanently caps** the vehicle's mileage. Add a sanity guardrail (reject/flag reads that jump implausibly). |
| Fleet Fuel Dashboard | Idea | Per-vehicle MPG, monthly rollups, anomaly flags. |
| Vehicle 77628 (Webster) odometer human review | Idea (S) | One-time review: stored 81,983 vs the real reading. The 07-03 recon couldn't locate the vehicle record — first confirm it exists, then verify/correct. |
| SFTP delivery channel | Idea | Superior long-term; awaiting WEX rep. IMAP primary until then. |

### Notifications (T3)
| Item | Notes |
|---|---|
| `wo_sms_notifications` (Twilio) | Module not enabled. A2P 10DLC registration = long-lead. Mirror `estimate_notifications`; add "Notify crew" checkbox. |
| Unified BOS notification framework | One framework vs five one-off modules. |
| Contact log module | Tabled. |

### Backflow (T3)
| Item | Notes |
|---|---|
| Property devices EVA card restyle | Match My Schedule card component. |

### Multi-consumer keys + per-consumer attribution (T3, when a 2nd consumer appears)
| Item | Notes |
|---|---|
| Per-consumer keys + audit identity | One shared `X-API-KEY` → one `cowork-connect` identity today. Distinct per-consumer keys + attribution (additional `key` entities + service accounts; provider selects the match) — build only when a 2nd consumer actually appears. |

### Estimating polish (T3, after epic)
| Item | Notes |
|---|---|
| Estimate items per-phase subtotals | Custom Views area handler or views_aggregator. |
| Site-visit WO vs scheduling entry | Lightweight `wo_estimate` visit type vs calendar entry only. |

### Governance / winter cleanup (T2–T3)
| Item | Notes |
|---|---|
| Inert `hook_entity_validate` convention (system-wide) | No invoker anywhere — `*_entity_validate` guards silently inert. Own diagnostic thread. |
| Dedicated code-quality audit pass | 5+ latent issues surfaced; no test tooling. Fall 2026. |
| Time-clock anomaly cleanup (182 historical) | The 5 `AnomalyDetectionService` types: **103** negative-hours + **77** implausible-long (>16h) + **2** stale-open; 0 future/time-travel (live 2026-07-04). Mostly historical bad clock data — correct/annotate. Non-blocking; the 2 stale-open may already be closing under wo_clock. |
| Branch strategy review | `drupal-update-20251206` stale; `main` is live reference (rsync deploy, live `.git` stale at 9c239ff). |
| Reconcile `config/sync` ↔ active drift (make full `cim` safe) | `config/sync` is drifted ~340 configs from live's active (all content-diffs, 0 adds/deletes); a full `cim` would revert live, so discipline is **surgical partial-cim only**. Reconciling (so `cim` is trustworthy + `config/sync` is a real config backup) = a focused **1–2 day** pass **from live's active**, during a **config freeze**, in batches — capture the ~216 low-risk systematic ones first, review the ~115 substantive, and handle the **88 `eck.eck_entity_type`** configs specially (cex exporter-bug injects a stray empty string — see the ECK gotcha). Done = `drush cim --diff` clean. Related: "Branch strategy review". Detail: `deferred_work.md` #22. |
| Retire `wo_clock` flag-based timer path | `wo_clock` coexists with the legacy flag timer during migration. Once the button path is trusted in the field, retire the flag path (`wo_timer_flag_update`) and remove the coexistence code. Off-season. |
| Retire `CreateAndScheduleSprinklerCheckUpWorkOrdersAction` VBO | Off-season. |
| Refactor `field_estimate_type` | Off-season. |
| `irrigation_crew` default truck count (1 → NULL?) | Off-season. |
| **Rework the teammate profile page views** | Reimagine the whole `/user/{uid}` (aliased `/teammates/{name}`) teammate profile — the blocks/views shown, layout, and what a crew member vs. a supervisor sees. Surfaced 2026-07-05: several existing profile blocks (`teammate_profile_wo_by_teammate`, `teammate_properties`, …) carry a **dead `request_path: /teammates`** visibility (no bare `/teammates` page exists — see gotcha) so they likely render nowhere; the new `bos_teammate_hours` "Time on Jobs" card is correctly on `/user/*`. Audit all profile-page blocks, fix/retire the dead ones, and design a coherent profile. Deferred by Todd — "leave it for now." |

### Scheduling (T2–T3)
| Item | Notes |
|---|---|
| Gantt scheduling mode (Mode 3) | Multi-week phased timeline for Irrigation Install + Landscape crews. FullCalendar can't; needs Frappe Gantt + a phase data model. Useful for scaling design-build. |
| Mow route-list + Spray compliance-list modes | Mode 1 route views. Spray partly covered; mow route list not built. |
| FullCalendar event link `target="_blank"` | Cosmetic; native `<a>` cleaner. |

---

## Decisions — resolved in the 2026-07-04 sweep

- **Fuel surcharge** → **greenfield.** No branch/commit/stash/doc/live-field exists anywhere; the 05-04 "Phase 1 complete" was a never-coded Chat plan. Now a from-zero build (see NEXT).
- **Status-service naming** → **`WorkOrderStatusService`** (broader than invoicing; future-proof as more call sites move onto it). NEXT row updated.
- **`scheduling_log` entity** → **not building.** `wo_schedule` already auto-logs every schedule/reschedule as a structured WO note (date/crew/note, old→new), which covers the audit-history need. Revisit only if queryable cross-WO reschedule analytics are ever required.
- **Pruning taxonomy split** → **deferred to off-season** (T3). Existing `winter_pruning`/`summer_pruning` bundles stay as-is; revisit the "Pruning" parent (Winter Tree / Fruit Tree / Summer) in winter.
- **"183 anomaly" metric** → **defined** = the 5 canonical `AnomalyDetectionService` types (negative hours · implausible-long >16h · future start · forgotten clock-out >7d · end-before-start). Live count **182** (103 + 77 + 2; 0 future/time-travel), overwhelmingly historical. Cleanup is its own LATER item.
- **329 canceled-but-invoiced WOs (status 1098)** → **leave as-is** (legitimate migrated/historical: 81% sprinkler, NULL WO#, already excluded from billing by the `IN(1097,1281)` floor). No cleanup.

## Decisions still open

- **Snow / `special_mowing` reconciliation architecture** — design the clocked-labor-vs-recorded-work model (same class as WO#49698). Season-gated: settle in **fall 2026**, before snow season. Builds live in LATER → Season-gated.

---

## ✅ Shipped — verified DONE 2026-07-03 (archive next cycle)

All modules enabled (estimate_board, estimate_notifications, calendars, scheduling, teammate-ops, WEX, price-sync/ingest, backflow, sign-off/notes/schedule/sprinkler-checkup, SMTP/Symfony Mailer stack) · commits 5e76da8a, 7c8c2334, 8cc2b0f4, 8a72d4ae, 0a943fcf · warranty sign-off path (175ea571, status 1283) · billing status floor IN(1097,1281) on 5 crew views · select-all disabled on all 6 billing views · weed-pulling "Bucket or Less" / "2-3 Buckets" · David Garcia teammate_profile + WEX prompt (1225) · WEX odometer/driver cleanup · WO#49698 reconciliation · migrate_devel removed from core.extension · no recent errors for checkup / sprinkler-checkup-date / material-price-sync.

**Check-up queue runaway — RESOLVED 2026-07-03** (`30bcc260` main→live; docs `6e51ca1a`). Root cause: dispatch enqueued one item per contract section (95,279) with no eligibility filter (~31 eligible) + a UTC-day-boundary bug in the once-a-day guard → ~3,000:1 fan-out. Fix: eligibility filter at dispatch (`field_check_up_frequency` + `field_service` + `field_contract`, → 47/dispatch), timezone-safe daily guard + anti-pileup guard, drained 5.1M stale no-ops via `queue:delete`. Live depth: 0. **Watch:** confirm depth stays ~0 under normal daily cron over the next 1–2 days.

**wo_clock Phase A — SHIPPED 2026-07-03** (new `wo_clock` module, ENABLED on live). Clock-in/out button UX on the WO replacing the flag-timer interaction, **silent GPS capture** (5s timeout, geofield + Haversine distance-from-property in ft), and **structured origin attribution** (`field_source` on `wo_time_clock` + `[Start/End: …]` note stamps). `createOpenEntry()` helper guarantees open entries (clears the `field_end_time`="now" + `field_notes`="Manually Entered" instance defaults). Sign-off Phase B guard blocks sign-off while open clock-ins exist. Legacy flag path **coexists** for now → retirement is a LATER item; foreman crew-status view + EOD notifications are the NEXT (Phase B/C) item. Docs: `wo_clock.md`, `wo_sign_off.md`, `Entities/time_clock_entry.md`.

**Daily recap dashboard — SHIPPED 2026-06-27** (`bos_daily_recap`, ENABLED on live; `/admin/office/daily-recap`). Per-department value + job-count cards (Yesterday / WTD / MTD), click-through to service-grouped WO list. _(Was still listed as "NEXT — pending build"; confirmed live and archived 2026-07-03.)_

**WO notes restyle — SHIPPED 2026-06-24** (`e684c53c`; live has all 3 structured fields — `field_change_summary` / `field_note_kind` / `field_is_system_note`). Notes render as clickable My-Schedule cards; `wo_schedule` auto-notes restructured into labeled lines; 1,573 legacy notes migrated. _(Was still listed as "NEXT — pending execution"; confirmed live and archived 2026-07-03.)_

**WEX daily-fetch failure alerting (gate 3b) — SHIPPED** (live `web/scripts/wex_alert_check.sh` + 7:15 AM watcher cron; the 7:00 fetch + 2:30 DB-backup crons also confirmed active 2026-07-04). The drush-independent watcher emails on a missed/incomplete 07:00 fetch — the unattended wrapper + silent-failure alert this item asked for. _(Was still listed under Fleet/WEX as pending.)_

**Crew properties list → cards + map split — SHIPPED 2026-07-05.** `/teammates/properties` reworked to slim property cards (nickname, flags, street+city, contact+phone w/ owner fallback, Mow Day, GPS Directions link — read-only, no action buttons) + a **Search** box and **50/page pager** (was all 2,531 rows unpaged). The all-pins Google map (the slow part) moved to its own page `/teammates/properties/map`, added as a **child menu item under Properties**; map blocks disabled. Batch-prefetch for mow days + contacts. Via `web/scripts/teammate_properties_split.php`. Verified live. Gotcha logged: child-display overrides need `defaults[<opt>]=false`.

**Property admin view → card layout — SHIPPED 2026-07-05.** `/admin/properties` reworked from a plain table to the BOS status-card pattern (`properties` module: row template + preprocess + CSS/JS). Card shows nickname, operational-flag badges, compact street+city, primary contact + phone (owner fallback), Mow Day, current-year contract status, and a Google-Maps GPS link; actions = Edit, Add Contract (when none), and an Add Work Order service-type picker. Dropped: Property ID / Full Address / Aerial view / Map Point / Operations / VBO columns (Property ID kept as an exposed filter). View change via idempotent `web/scripts/properties_view_to_cards.php` (per-env, not cim). Verified live.

**Teammate "Time on Jobs" profile-page hours — SHIPPED 2026-07-05.** New `bos_teammate_hours` module (Block `teammate_time_on_jobs` on the **teammate profile page** = `/user/{uid}`, aliased `/teammates/{name}`; `brookstone_olivero`, visibility `/user/*`, guarded to a `teammates` page-owner). Shows the page-owner's `wo_time_clock` hours for a calendar week (Sun–Sat), grouped by day with per-day + week totals; each entry links its WO + property. Reads the **page-owner** (teammates can't view others' profiles → effectively self-only for crew), **no GPS**, **no dollar figures** (WO clocked hours ≠ billable ≠ compensable). _(Placement fix: first pointed at `/teammates` — which isn't a real page in BOS — then retargeted to `/user/*`; verified live on a real teammate profile.)_ Open entries flagged + excluded from totals; prev/next week nav. Deployed via `drush en` (config/install block); verified live (real crew week, 15.63 hrs). Docs: `bos_teammate_hours.md`. **Supervisor counterpart — SHIPPED same day:** the per-teammate variance detail page (`/admin/office/operations/teammates/variance/{user}`) now shows clock-in/out **GPS distance-from-property** (`In 📍`/`Out 📍`, Maps-linked, ≥500 ft flagged) in its per-day WO-entry sub-table; expanded date range was already there. GPS shows for supervisors only (never the crew self-view). Few punches carry GPS yet — expected, since `wo_clock` only shipped 07-03 and location is captured only on button punches (historical/legacy-flag entries have none); the cells show `—` where absent and accrue naturally.

**Lighting `wo_*` billing modules — SHIPPED 2026-07-05.** `wo_landscape_lighting` / `wo_exterior_lighting` (already-enabled since 05-28) **rewritten** to mirror `wo_sprinkler_repair`: on Complete (1097) → labor (clocked hrs × the new dedicated lighting rate w/ increment + minimum) + materials (w/ markup) + trip + rentals + billing adjustment → `field_wo_total`. New **dedicated** `business_setting` rate fields `field_lighting_technician_rate` + `field_lighting_tech_minimum` (Option B — separate from the maintenance/sprinkler rates), created via `web/scripts/add_lighting_rate_fields.php` and left **EMPTY** for the office to set after competitive-rate analysis (labor is skipped until a rate is entered — no bogus totals). Local billing test: 2 hrs @ temp $65 → $130. **Resolved:** rate set to **$75/hr** and minimum to **0.5 hr** on the Business Settings page — labor now computes with a **$37.50 per-visit floor**. Item fully closed.

_Note: `wo_material_price_sync` is enabled and error-free on live — the "broken on live" concern did not reproduce. Confirm the form-display/view-filter behavior in the app before fully closing._

---

## Maintenance protocol

1. This file is the status-of-record. If it conflicts with memory, Todoist, a chat, or **[`deferred_work.md`](Governance/deferred_work.md)** (its reconciled engineering-detail sibling), **this wins** — reconcile the others. The engineering hygiene / code-quality / small-fix backlog lives in `deferred_work.md`; **reconcile it at each recon** (dedup vs the NEXT/LATER rows, carry `↔ ROADMAP:` cross-refs).
2. **Anchor every status to evidence** — commit hash, module name, config key, or a named live check. No unverifiable rows.
3. **Re-run the read-only recon at each seasonal boundary** (or quarterly). It mechanically resolves most rows and catches drift before it compounds — the way the check-up queue did.
4. Status changes require a next-step or blocker note. Never leave a change unexplained.
5. Shipped items live in the archive one cycle, then drop.
6. New ideas enter with at minimum Area + one-line scope + a tier guess. No untitled rows.
7. End each BOS work session by reconciling the affected rows here.
