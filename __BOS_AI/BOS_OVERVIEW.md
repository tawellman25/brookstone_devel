# BOS — Brookstone Operating System

> **Purpose of this file:** drop this into a fresh Claude / ChatGPT conversation to give it the working context it needs to be useful on BOS without having to ramp up from scratch. Pair with the rest of `__BOS_AI/` for entity- or module-level depth.

## What BOS Is

BOS is the internal operations platform for **Brookstone Outdoors LLC**, a residential and commercial outdoor-services company. The business performs lawn care, fertilizing and spray services, sprinkler installation/maintenance, snow removal, holiday lighting, landscaping, hardscaping, and related services.

BOS centralizes:
- Properties (where work happens)
- Clients (who's paying)
- Teammates (who does the work)
- Contracts (intent to perform work)
- Work Orders (execution records)
- Equipment, Materials, Chemicals (inputs)
- Time tracking, pricing, billing (outputs)

BOS is **not** a public-facing ERP. It is an operations-discipline tool: it enforces business rules through structure rather than relying on memory or after-the-fact paperwork.

QuickBooks is still the accounting system of record. BOS exports to QB; it does not replace it.

## Platform / Tech Stack

- Drupal 10 (D11-compatible)
- PHP 8.3, MariaDB 10.11, nginx-fpm
- Local dev: DDEV (`https://brookstone.ddev.site`)
- Production: hosted VPS, user files on AWS S3 (proxied locally via `stage_file_proxy`)
- ~80 custom modules in `web/modules/custom/`
- Almost all BOS data lives in **ECK** (Entity Construction Kit) — *not* nodes

## The Operational Spine

Three entity types anchor everything:

```
Properties (ECK: properties)            ← where work happens
  ├── Property Detail Sub-entities      ← service-specific facts about a property
  ├── Work Orders (ECK: work_order)     ← execution records
  │     ├── wo_time_clock                time punches
  │     ├── wo_material_list/_item       materials used (snapshot pricing)
  │     ├── wo_chemicals_used            chemicals applied
  │     ├── wo_rental_equipment          equipment used
  │     ├── wo_complete_info             sign-off when crew finishes
  │     ├── wo_status_updates            append-only event timeline
  │     ├── wo_notes, wo_tasks_list, wo_spraying_conditions, ...
  └── Contracts (ECK: contracts)        ← intent / agreement
        └── Contract Sections (ECK: contract_sections)
              └── field_service → Services taxonomy → maps to a WO bundle
```

**Critical invariant:** A Work Order's bundle (e.g. `aerating`, `lawn_mowing`, `snow_removal`) must equal the bundle name on its Service taxonomy term's `field_service_bundle`. The Services taxonomy is the single source of truth for "what kind of service is this WO."

## Lifecycle: Intent → Execution → Billing

1. **Estimate Request** — customer asks for a quote (`estimate_request` ECK)
2. **Estimate** — one estimate per requested service; priced via line-items (`estimate` + `estimate_items` ECK)
3. **Contract** — customer accepts; Contract Sections become the structured plan (`contracts` + `contract_sections`)
4. **Work Orders** — generated from Contract Sections (manually or via conversion service)
5. **Schedule** — WO assigned to a crew, slotted on the calendar
6. **Execution** — crew uses the My Schedule / daily WO view, time-clocks in, records materials/chemicals used
7. **Sign-Off** — `wo_complete_info` entity created → drives WO status to Complete (term ID **1097**), billing totals calculated, "last completed" history written back to `property_*` detail entities
8. **Billing** — office reviews completed WOs, marks Invoiced/Printed, exports to QuickBooks

## Two Architectural Patterns You'll See Everywhere

### `wo_*` Per-Bundle Modules
Each Work Order bundle has its own custom module (`wo_lawn_mowing`, `wo_snow_removal`, `wo_aerating`, …). Each does two things:
- **Read at presave:** when the WO is being completed, read `property_*` detail entities to calculate billing totals
- **Write at insert/update:** on completion, write "last completed" data back to the corresponding `property_*` detail entity

This is a **formal** pattern — do not consolidate the modules. Bundle-specific business logic lives bundle-by-bundle.

Cross-cutting WO logic (sign-off, total time, status updates, chemical subtotals, dump fees, etc.) lives in separate cross-cutting modules (e.g. `wo_sign_off`, `wo_total_time`, `wo_status_updates`).

### `property_*` Detail Sub-Entities
Each property has 15+ service-specific detail entities — `property_snow_removal_info`, `property_landscape_details`, `property_spraying_info` (9 bundles), and more.

These are NOT static data. They participate in a bidirectional flow with `wo_*` modules:
- WO modules **read** from them at presave (e.g. turf sq footage feeds aerating pricing)
- WO modules **write back to them** on completion (e.g. last plowed date, last salt amount)

## Roles (Increasing Privilege)

`anonymous → authenticated → user → client → teammates → supervisor → administration → site_assistant → site_admin → administrator`

Office and admin roles use the `brookstone_admin` admin theme (Claro sub-theme). Clients and crews see the `brookstone_olivero` portal theme (Olivero sub-theme).

## Key Domain Rules (Don't Violate Lightly)

- **Intent vs Execution.** Contracts/Sections = intent. Work Orders = execution. Never store execution data in Contracts.
- **Pricing snapshots are immutable.** Once a WO is completed, the `wo_material_list_item` and `wo_chemicals_used` cost snapshots must not change.
- **No deletion of operational history.** Archive via status flags. Hard deletes are role-restricted.
- **Audit trails are append-only** and use entity lifecycle hooks, not form/route handlers.
- **Business logic belongs in code** — services, event subscribers, hooks — not only in Views/Rules UI config.
- **One residential contract per property per year**, enforced at entity validation.
- **SOP codes are immutable** once approved.
- **WO bundle = Service taxonomy `field_service_bundle`** (see above).

## Naming Conventions and Permanent Typos

These look like bugs but are NOT. Do not "correct" them:
- `equipment.attachements` (bundle name typo, permanent)
- `sop_system_prosedures` (module directory typo, permanent)
- `pinion_pine_ips_beetle` (work_order bundle) vs `pinyon_pine_ips_beetle` (estimate bundle) — different on purpose; rename is a coordinated future task
- `estmate.landscape` (entity type typo, permanent on an old entity)

Status term IDs are **hardcoded** in code, not config: Complete=1097, In Progress=1092, Cancelled=1098.

`field_work_order_id` mirrors the entity ID and is the BOS-visible "WO#" everyone refers to.

## Where to Find Things

| Path | Contents |
|---|---|
| `__BOS_AI/Entities/` | every entity type's spec (fields, bundles, invariants) |
| `__BOS_AI/Modules/` | per-module architecture (purpose, hooks, services) |
| `__BOS_AI/Business/` | domain rules (pricing, services, payment, etc.) |
| `__BOS_AI/Governance/` | engineering norms, working-with-Claude discipline, Drupal/BOS gotchas |
| `__BOS_AI/SOPs/` | written Standard Operating Procedures |
| `CLAUDE.md` (repo root) | Claude-Code-specific instructions for working in this repo |
| `config/sync/` | all Drupal config (cim/cex managed; 4000+ files) |
| `web/modules/custom/` | the ~80 custom modules |
| `dev_scripts/` | sync/deploy/backup scripts (SSH aliases: `brookstone`, `sewardsdevel`) |

Authoritative reading order for a fresh deep-dive:
1. `__BOS_AI/Entities/00_core_entities.md` — the operational spine
2. `__BOS_AI/Entities/01_entities_policy.md` — rules for entity decisions
3. `__BOS_AI/Modules/wo_bundle_modules.md` — the `wo_*` pattern in detail
4. `__BOS_AI/Entities/property_detail_entities.md` — the `property_*` pattern in detail
5. `__BOS_AI/Governance/drupal_bos_gotchas.md` — Drupal-specific traps that have bitten BOS before
6. `__BOS_AI/Governance/working_with_claude.md` — process discipline norms

## How Office Work Gets Done (Typical Day)

- **Property page** is the universal entry: from there, office staff create WOs, view contracts, view history, see all child records
- **Contract page** holds the full plan; sections are edited via modal dialogs (Admin Table mode preferred over the legacy EVA blocks)
- **Scheduling views** filter WOs by service/status/area; supervisors drag WOs onto crew calendars
- **Crew My Schedule** shows the day's assigned WOs; crew opens each WO → time clock → tasks → materials/chemicals → mark complete
- **Billing review** runs across completed-but-not-invoiced WOs; office staff verify totals and flip invoice flags

## When the AI Should Push Back

If a user request would:
- Bypass an architectural rule above
- Add data to Contracts that belongs on Work Orders (or vice versa)
- Mutate a completed WO's price snapshots
- Hard-delete operational history
- Invent an entity type, bundle, or field that isn't documented in `__BOS_AI/`

…surface the conflict and propose an alternative that respects the system's invariants. The `__BOS_AI/` docs are the authoritative source of truth; if the docs are wrong, fix the docs first, then the code.
