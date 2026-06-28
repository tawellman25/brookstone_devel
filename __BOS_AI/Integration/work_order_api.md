# BOS → Work Order API: Integration Briefing

> **Status: GREENFIELD.** As of 2026-06-28 there is **no Work Order API and no external
> write path**. JSON:API and REST are enabled but JSON:API is **`read_only: true`**, and
> there is **no OAuth / basic_auth** configured. The `key` module is installed but unused.
> Treat everything below as design input for building a *new* API, not documentation of an
> existing one. Author: Claude Code, for the "build a WO-filling API" discussion.

This document tells an integrator (Cowork / Chat) what they need to know about BOS to design
an API for **creating and filling out Work Orders**.

---

## 1. What BOS is

Drupal 10 operations platform for Brookstone Outdoors. **All operational data is ECK entities,
not nodes.** The spine is **Properties → Work Orders → child records**. There is no existing
external API surface — this integration is net-new.

---

## 2. Transport & auth — solve this first (not ready today)

- **JSON:API + REST are enabled, but JSON:API is `read_only: true`** → no `POST`/`PATCH`/
  `DELETE`. **No OAuth, no basic_auth.** `key` module installed (API-key storage) but unused.
- Before any WO can be filled via API, BOS needs a **write path + authentication**. Two paths:
  - **Option A — flip JSON:API to writable + add OAuth (`simple_oauth`).** Fast, but exposes
    *every* entity for write and pushes the WO business-rule sequencing onto the client.
    Security-sensitive (wide surface).
  - **Option B (recommended, BOS-idiomatic) — a custom authenticated REST resource** scoped to
    WO create/fill, key-authenticated via the existing `key` module. It can encapsulate the
    correct write sequence (create WO → children → sign-off), validate input, and reject bad
    requests. Narrow surface, traceable, matches BOS's "custom over contrib" rule.
- Either way, **writes run through normal Drupal entity hooks**, so all the automation in §5
  fires automatically. That is a feature — but the client must work *with* it, not around it.

---

## 3. The Work Order entity model

- `work_order` ECK entity, **36 bundles — one per service** (`lawn_mowing`, `aerating`,
  `weed_spraying`, `backflow_testing`, …).
- **Required fields:** `field_property`, `field_service`, `field_status`.
- **THE invariant:** `work_order.bundle` **must equal** the `field_service` term's
  `field_service_bundle`. The **`services` taxonomy is the source of truth** — each term has
  `field_work_order_service` (bool) + `field_service_bundle` (the WO bundle machine name). Flow:
  pick the service term → read its `field_service_bundle` → create the WO on **that** bundle
  with `field_service` set to that term. You cannot choose a bundle independently of the service.
- **Status (`field_status`, taxonomy term IDs):** Open `1089`, Scheduled `1091`,
  In Progress `1092`, Complete `1097`, Canceled `1098`, Invoiced `1281`, Warrantied `1283`,
  Paid `1504`.
- **Computed — do NOT set (BOS calculates on completion):** `field_wo_total`,
  `field_labor_total`, `field_material_chemical_total`, `field_dump_fee_total`,
  `field_rental_total`, `field_trip_fee`, `field_work_order_id` (auto, stable, never reused),
  and the **title** (auto-generated via Auto Entity Label; a known placeholder quirk is
  self-healed by BOS).
- **Settable inputs:** `field_estimated_price`, `field_billing_adjustment`. Invoice flags
  (`field_invoiced`, `field_printed`) belong to the billing workflow, not the fill API.

---

## 4. "Filling out" a WO = creating child entities

The WO is a container; the real data lives in child entities, each referencing the parent WO:

| Child entity | Purpose |
|---|---|
| `wo_time_clock` | Time punches (labor) |
| `wo_material_list` → `wo_material_list_item` | Materials; **unit cost snapshots at time of use** |
| `wo_chemicals_used` | Chemicals applied (spray services) |
| `wo_rental_equipment` | Equipment / rentals used |
| `wo_material_dumping` | Dump loads |
| `wo_spraying_conditions` | Weather / compliance (spray services) |
| `wo_tasks_list` | Crew checklist |
| `wo_notes` | Structured notes |
| **`wo_complete_info`** | **Crew sign-off — this is how a WO is completed (see §5)** |
| `wo_status_updates` | Append-only event timeline |

---

## 5. The write-path automation (the part that trips integrators up)

- **Completion is NOT a status PATCH.** Do **not** set `field_status = 1097`. Instead **create a
  `wo_complete_info`** (sign-off) entity. BOS's `wo_sign_off` then drives the WO to Complete and
  computes trip fee + total time. Deleting the sign-off reverts the WO to In Progress.
- **On completion, BOS computes all the money** — the per-bundle `wo_{service}` module reads the
  time / materials / chemicals / rentals and the property's history, then writes the billing
  totals back onto the WO. The API submits *inputs*, never dollar totals.
- **Clock-in promotes** Open/Scheduled → In Progress (guarded so it won't resurrect a terminal
  WO that was already Complete/Invoiced/Canceled).
- **On completion, BOS writes "last completed" data back** to the property's `property_*` detail
  entities (persistent service history).
- **Pricing snapshots are immutable** once the WO is Complete (admin correction only).

---

## 6. Rules the API must respect (BOS Architectural Rules)

- **Intent vs Execution** — WO = execution; never write execution data to contracts.
- **Pricing snapshots immutable** once complete.
- **No deletion of operational history** — completed WOs are delete-guarded (`wo_deletion_manager`).
- **Automation must stay traceable**; audit logs are append-only.
- **Do not touch the legacy `estimate` WO bundle** (being phased out).

---

## 7. Lookups & idempotency

- Resolve **property** (`field_property` → `properties`, by nickname/address) and the **service
  term** (→ bundle) before creating a WO.
- **Idempotency matters.** Some services enforce one-open-WO rules — e.g. weed spray is
  **one-spray-per-WO** with find-or-create guards (a duplicate-create would be routed/blocked).
  The API needs a dedupe strategy so it does not spawn duplicate WOs.

---

## 8. Open decisions for the integrator

- Auth mechanism — Option A vs B (§2).
- Which **user/role identity** the API acts as (drives entity permissions + sign-off attribution).
- Read+write vs write-only scope.
- How validation errors (the entity-validation invariants) surface back to the caller.

---

## 9. Source docs (in the BOS knowledge bundle)

- `wo_bundle_modules.md` — the `wo_*` per-bundle module pattern + completion calculations
- `wo_sign_off.md` — the completion/sign-off mechanism
- `work_order_status.md` — status lifecycle + role authority
- `property_detail_entities.md` — the read/write-back property history pattern
- CLAUDE.md — Work Order section (bundles, fields, status TIDs, child entities)
