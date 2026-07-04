# BOS Design & Implementation Roadmap

Status-of-record for unfinished BOS initiatives. Reconciled against live production on 2026-07-03 (read-only recon: SSH to Hosting.com checkout + drush against live DB). Verified-done items removed; survivors only.

**Owner:** Todd · **Repo path:** `__BOS_AI/ROADMAP.md` · **Last reconciled:** 2026-07-03 · **Last updated:** 2026-07-03 (drift sweep: daily-recap + wo-notes restyle confirmed shipped & archived; wo_clock Phase A added)

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

### Other NOW items
| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| QuickBooks Desktop IIF export | Billing | T1 | M | Fully scoped. Residential = 1 invoice/WO; Commercial = batched line-items/period; two-bucket on `field_client_type`. |
| Warranty full-dollar-capture + QB zeroing | `wo_sign_off` / Billing | T2 | M | Sign-off path live (1283); **dollar-capture fields do not exist**. Coupled deploy unit — capture + zeroing ship together or risk billing customers for warranty work. |

---

## NEXT — sequence after NOW clears

| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| Natural-language WO intake REST endpoint (Gate 1) | `WorkOrderIntakeService` + REST | T2 | M | Architecture locked (Option B, X-API-KEY, `system_integration` role, dedupe). Gate 1 spec is next. |
| TimeTrax live SQL-read integration | `bos_teammate_operations` | T2 | L | Foundation + swappable `CompensableHoursService` on 8.5hr assumption built. Swap in real SQL Server read (Punch/Employee/EmployeeCards, PunchKey idempotency). Labor-cost accuracy. |
| Estimate board pipeline swimlane rework | `estimate_board` | T2 | M | Build prompt produced; replace single "Active Pipeline" with per-status swimlanes + color. Pending Code execution. |
| wo_clock — foreman crew-status view + end-of-day notifications (Phase B/C) | `wo_clock` | T2 | M | Phase A shipped (clock-in/out redesign + silent GPS + attribution). Next: foreman "who's still clocked in" view + end-of-day open-clock-in alerting. Flag-path retirement is the winter cleanup (see LATER). |
| Status-service refactor | `wo_status_updates` | T2 | M | Fixes presave-saves-the-WO coupling. Call sites: `update_spraying_info_from_invoiced_work_order`, `update_work_order_invoiced_action`. **⚠ name: reconcile "WorkOrderStatusService" (June invoicing chat) vs "WorkOrderInvoicingService" (roadmap v1 seed) — decide canonical name before building.** |
| Fuel surcharge — full build from zero | `wo_sign_off` + 36 bundles | T2 | L | **Live has nothing** (no field, no toggle, no per-zip rates). Locate the branch that claimed "Phase 1 complete" or treat as greenfield. |
| 2 stranded invoiced WOs (In-Progress + `field_invoiced=1`) | Data hygiene | T2 | S | Down from 3. The genuine "invoiced before complete" debt from the June incident. Per-WO decision then correct. |
| 45-day auto-cancel threshold pressure-test | spray-route-guard | T2 | S | Monthly freq = 35d, only 10d margin. Validate no legit pending sprays exceed 45d before unattended cron. |

---

## LATER — off-season, season-gated, or hygiene

### Season-gated (build early fall 2026)
| Item | Area | Tier | Effort | Notes |
|---|---|---|---|---|
| Snow removal architecture + reconciliation bundle | snow_removal | T2 | L | Winter service — build before season. Reconciles clocked labor vs recorded work (same class as WO#49698). |
| `special_mowing` reconciliation bundle | special_mowing | T2 | M | Same reconciliation pattern. Early fall. |

### Supplier pricing pipeline (ongoing sub-project, T2/T3)
| Item | Status | Notes |
|---|---|---|
| Rain Bird MEDIUM-tier backfill (71 rows) | In Progress | Backfill mfr item numbers to raise Tier 1 rate. |
| Hunter backfill (~67) | In Progress | Smaller-scope backfill. |
| Matcher: supplier-alias table + Tier 3 fuzzy tuning | Idea (T2) | Tier 3 catching 0; cross-supplier SKU alias map + threshold tuning. |
| SiteOne standalone scraper (Lever 3) | Idea | Python/Playwright + manual cookie. Build after family map (Lever 2). |
| Tier 1.5 confidence bump 85→90 | Idea | Validate zero false positives over 2–3 batches first. |
| Bulk-rerun batches 205/276; Bulk Confirm/Send-to-Discovery VBO; Spears CSV relocation | Idea | Held until manual pain is felt. |
| Eight Phase 3.12 supplier SOPs | Scoped | Authoring pending. |

### Fleet / WEX (T3)
| Item | Notes |
|---|---|
| WEX cron wrapper + failure alerting (gate 3b) | IMAP fetch works; needs unattended wrapper + silent-failure alert. |
| SFTP delivery channel | Superior long-term; awaiting WEX rep. IMAP primary until then. |
| Fleet Fuel Dashboard | Per-vehicle MPG, monthly rollups, anomaly flags. |
| Bogus-high odometer guardrail | High reads write through and cap the vehicle permanently. |

### Notifications (T3)
| Item | Notes |
|---|---|
| `wo_sms_notifications` (Twilio) | Module not enabled. A2P 10DLC registration = long-lead. Mirror `estimate_notifications`; add "Notify crew" checkbox. |
| Unified BOS notification framework | One framework vs five one-off modules. |
| Contact log module | Tabled. |

### Backflow (T3)
| Item | Notes |
|---|---|
| First signed backflow test — PDF+S3 smoke test | Parked pending office generating first real signed test. |
| Property devices EVA card restyle | Match My Schedule card component. |

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
| Branch strategy review | `drupal-update-20251206` stale; `main` is live reference (rsync deploy, live `.git` stale at 9c239ff). |
| Retire `wo_clock` flag-based timer path | `wo_clock` coexists with the legacy flag timer during migration. Once the button path is trusted in the field, retire the flag path (`wo_timer_flag_update`) and remove the coexistence code. Off-season. |
| Retire `CreateAndScheduleSprinklerCheckUpWorkOrdersAction` VBO | Off-season. |
| Refactor `field_estimate_type` | Off-season. |
| `irrigation_crew` default truck count (1 → NULL?) | Off-season. |

### Scheduling (T2–T3)
| Item | Notes |
|---|---|
| Gantt scheduling mode (Mode 3) | Multi-week phased timeline for Irrigation Install + Landscape crews. FullCalendar can't; needs Frappe Gantt + a phase data model. Useful for scaling design-build. |
| Mow route-list + Spray compliance-list modes | Mode 1 route views. Spray partly covered; mow route list not built. |
| FullCalendar event link `target="_blank"` | Cosmetic; native `<a>` cleaner. |

---

## Decisions pending — your call, not a build

- **Fuel surcharge:** where is the branch that claimed Phase 1? Locate or declare greenfield.
- **Status-service naming:** `WorkOrderStatusService` vs `WorkOrderInvoicingService` — pick canonical.
- **`scheduling_log` entity:** build it? Three sub-decisions — log route-order changes? `field_change_reason` required or optional? historical backfill vs fresh start?
- **Snow / `special_mowing` architecture:** design the reconciliation model (fall).
- **Pruning taxonomy split:** taxonomy-only vs separate WO bundles for Winter Tree / Fruit Tree / Summer under a "Pruning" parent.
- **"183 anomaly" metric:** define it so Code can match (read-only proxy = 3 open clock-ins).
- **329 canceled-but-invoiced WOs (status 1098):** legitimate (invoiced then canceled) or drift? Decide before any cleanup.

---

## ✅ Shipped — verified DONE 2026-07-03 (archive next cycle)

All modules enabled (estimate_board, estimate_notifications, calendars, scheduling, teammate-ops, WEX, price-sync/ingest, backflow, sign-off/notes/schedule/sprinkler-checkup, SMTP/Symfony Mailer stack) · commits 5e76da8a, 7c8c2334, 8cc2b0f4, 8a72d4ae, 0a943fcf · warranty sign-off path (175ea571, status 1283) · billing status floor IN(1097,1281) on 5 crew views · select-all disabled on all 6 billing views · weed-pulling "Bucket or Less" / "2-3 Buckets" · David Garcia teammate_profile + WEX prompt (1225) · WEX odometer/driver cleanup · WO#49698 reconciliation · migrate_devel removed from core.extension · no recent errors for checkup / sprinkler-checkup-date / material-price-sync.

**Check-up queue runaway — RESOLVED 2026-07-03** (`30bcc260` main→live; docs `6e51ca1a`). Root cause: dispatch enqueued one item per contract section (95,279) with no eligibility filter (~31 eligible) + a UTC-day-boundary bug in the once-a-day guard → ~3,000:1 fan-out. Fix: eligibility filter at dispatch (`field_check_up_frequency` + `field_service` + `field_contract`, → 47/dispatch), timezone-safe daily guard + anti-pileup guard, drained 5.1M stale no-ops via `queue:delete`. Live depth: 0. **Watch:** confirm depth stays ~0 under normal daily cron over the next 1–2 days.

**wo_clock Phase A — SHIPPED 2026-07-03** (new `wo_clock` module, ENABLED on live). Clock-in/out button UX on the WO replacing the flag-timer interaction, **silent GPS capture** (5s timeout, geofield + Haversine distance-from-property in ft), and **structured origin attribution** (`field_source` on `wo_time_clock` + `[Start/End: …]` note stamps). `createOpenEntry()` helper guarantees open entries (clears the `field_end_time`="now" + `field_notes`="Manually Entered" instance defaults). Sign-off Phase B guard blocks sign-off while open clock-ins exist. Legacy flag path **coexists** for now → retirement is a LATER item; foreman crew-status view + EOD notifications are the NEXT (Phase B/C) item. Docs: `wo_clock.md`, `wo_sign_off.md`, `Entities/time_clock_entry.md`.

**Daily recap dashboard — SHIPPED 2026-06-27** (`bos_daily_recap`, ENABLED on live; `/admin/office/daily-recap`). Per-department value + job-count cards (Yesterday / WTD / MTD), click-through to service-grouped WO list. _(Was still listed as "NEXT — pending build"; confirmed live and archived 2026-07-03.)_

**WO notes restyle — SHIPPED 2026-06-24** (`e684c53c`; live has all 3 structured fields — `field_change_summary` / `field_note_kind` / `field_is_system_note`). Notes render as clickable My-Schedule cards; `wo_schedule` auto-notes restructured into labeled lines; 1,573 legacy notes migrated. _(Was still listed as "NEXT — pending execution"; confirmed live and archived 2026-07-03.)_

_Note: `wo_material_price_sync` is enabled and error-free on live — the "broken on live" concern did not reproduce. Confirm the form-display/view-filter behavior in the app before fully closing._

---

## Maintenance protocol

1. This file is the status-of-record. If it conflicts with memory, Todoist, or a chat, **this wins** — reconcile the others.
2. **Anchor every status to evidence** — commit hash, module name, config key, or a named live check. No unverifiable rows.
3. **Re-run the read-only recon at each seasonal boundary** (or quarterly). It mechanically resolves most rows and catches drift before it compounds — the way the check-up queue did.
4. Status changes require a next-step or blocker note. Never leave a change unexplained.
5. Shipped items live in the archive one cycle, then drop.
6. New ideas enter with at minimum Area + one-line scope + a tier guess. No untitled rows.
7. End each BOS work session by reconciling the affected rows here.
