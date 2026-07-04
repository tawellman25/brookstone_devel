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

---

# Build Baseline (Option B — custom key-auth REST over a transport-agnostic service)

> **Gate 0 evidence, 2026-06-29.** READ-ONLY inspection of THIS install. Closes the truncated
> Task-4 baseline + two open role questions. Transport decided in `transport_decision_mcp_vs_rest.md`
> (Option B now; service is transport-swappable later). No build performed.

## B1. REST baseline (what's here, what we must write)

- **Core 10.6.12.** `rest`, `serialization`, `jsonapi` (core; jsonapi is `read_only: true`) and
  `key` are **enabled**. No version drift — they ship with core 10.6.12.
- **No key-auth provider exists.** `key` is **storage-only** (it holds secrets; it does *not*
  provide an authentication provider). There is **no `key_auth`, `rest_api_authentication`, or
  `simple_oauth`** module installed, and **no `authentication_provider`-tagged service** in
  contrib/custom. → **Option B must ship a small custom authentication provider.**
  - **Extension point:** a service class implementing
    `\Drupal\Core\Authentication\AuthenticationProviderInterface` (`applies()` + `authenticate()`),
    registered in `*.services.yml` with `tags: [{ name: authentication_provider, provider_id: bos_system_key, priority: 100 }]`. Its `authenticate()` reads the API-key header, validates it
    against a `key` entity, and returns the **System service account user** (so the request runs
    as that account). The custom REST resource then lists `bos_system_key` in its `authentication`.
  - Estimated build cost: ~1 small provider class + 1 RestResource plugin + 1 `rest.resource.*`
    config + the service account/key. No new contrib.
- **Gotchas (true build cost, made explicit):**
  1. **Route `_access` ≠ entity access — two distinct gates.** A custom `RestResource` POST route
     carries its own permission (auto `restful post <plugin_id>`, or a custom `permissions()`),
     which gates *who may hit the endpoint*. That is a **transport-layer** grant, **separate** from
     the four entity perms, and the System role **will need it** (see GO/NO-GO). Entity create is a
     *second* gate the handler must enforce itself (B2).
  2. **CSRF.** Drupal's `_csrf_request_header_token` requirement applies **only to cookie/session
     authenticated** unsafe requests. A **stateless custom-key provider is exempt** (core skips
     CSRF for non-cookie auth) — so **no `X-CSRF-Token` is needed** for Cowork, *provided* our
     provider is a real non-cookie `authentication_provider` (not a session bolt-on).
  3. **Formats.** Declare `json`; client sends `Content-Type: application/json` and
     `?_format=json` (or `Accept`). Emit JSON error bodies with proper 4xx/5xx codes.
  4. **⚠ Production SAPI / header stripping (evidence-grounded).** Live runs on CloudLinux/cPanel
     with CGI/LiteSpeed-style PHP (the same SAPI nuance that silently broke the WEX cron — see
     `drupal_bos_gotchas.md`). The **`Authorization` header is frequently stripped** before it
     reaches PHP on such stacks. → **Use a custom header (e.g. `X-API-KEY`), not
     `Authorization: Bearer`**, and verify it survives to PHP in production. Bonus: `X-API-KEY` is
     exactly the header shape Copilot Cowork's OpenAPI API-key auth expects (B4).

## B2. Access-enforcement intent — do the 4 perms actually bite?

- **BOS programmatic creators bypass entity access.** Every existing path uses raw
  `Entity::create()->save()` / `$entity->save()`, which does **not** run entity-access checks:
  e.g. `wo_schedule.module` creates `wo_notes` via `storage->create([...])->save()` (lines
  276-285), creates+saves a status update (401-409), and does `$work_order->set('field_scheduled',1)->save()`
  (338/347); `wo_shared.module` re-saves at 121-122. **A bare `->save()` ignores the role.**
- **Therefore the role is a real least-privilege boundary ONLY if `WorkOrderIntakeService`
  performs explicit access checks before each write.** Cleanest pattern (standard core API):
  ```php
  $ach = $etm->getAccessControlHandler('work_order');
  if (!$ach->createAccess($bundle, $system, [], TRUE)->isAllowed()) { throw 403; }
  // then $entity->save();   // raw save is fine AFTER the explicit gate
  ```
  Repeat per write: `work_order` (the chosen bundle), `wo_notes` bundle `note`, `scheduling`
  bundle `work_order`. The ECK access handler honors `create <type> entities` / per-bundle on
  `createAccess` (proven in `system_integration_role_inspection.md` §0), so these checks enforce
  exactly the four perms.
- **Verdict:** the 4 perms are **guards iff the service checks explicitly; decoration otherwise.**
  The build spec MUST gate each write with `createAccess(...)`. (Core's generic `EntityResource`
  POST checks access for free — but our **custom** resource does not inherit that; it must check.)

## B3. AEL double-save vs access (does create force edit/view work_order?)

- `wo_shared_work_order_insert()` heals the stuck `%AutoEntityLabel:` sentinel with
  `$entity->set('title', ''); $entity->save();` (lines 121-122) **inside `hook_entity_insert`** —
  a **programmatic save, not an access-checked update**. The cascade in `wo_schedule` (status
  flip to 1091) is likewise programmatic.
- **Consequence:** create-time intake triggers these internally with **no** edit/view check. The
  System account needs **NO `edit`/`view` `work_order` permission**. **Role stays create-only.**
  (Had it been access-checked, it would have forced `edit any work_order entities` — preferred
  over `own` per the ownership-fragility note in the role inspection — but it is **not** required.)

## B4. Cowork → REST directly? (shim needed?)

- **Product identity not user-confirmed** (candidates: Microsoft **Copilot Cowork** vs **Coworker
  AI**) — flagged, not guessed. Under **both** readings, direct authenticated REST is supported:
  - **Copilot Cowork** supports **API plugins defined by an OpenAPI document with API-key auth in
    a custom header** (`securitySchemes`, `in: header`, `name: X-API-KEY`) — i.e. it can call our
    key-authenticated HTTPS endpoint **directly**; it also supports OAuth and MCP connectors (with
    Dynamic Client Registration). [MS Learn: Cowork plugin dev / API-plugin authentication.]
  - **Coworker AI** advertises **custom integrations via REST API** (plus its own MCP server),
    OAuth-scoped.
- **Conclusion: the MCP→REST shim is NOT needed.** Cowork reaches our REST endpoint directly via a
  connector/API-plugin manifest (an OpenAPI spec with `X-API-KEY` header auth), not a separately
  hosted proxy. *Caveat:* confirm against the specific Cowork product's connector docs before
  build; if that exact product turns out to consume **only** MCP tools, a thin MCP→REST wrapper
  (one tool that forwards to our endpoint) would be the minimal bridge — but available sources
  indicate that is unlikely.

## GO / NO-GO

- **`create work_order entities`** — ✅ **GO, stays.** Covers all 36 bundles (handler OR-logic).
- **`create wo_notes entities of bundle note`** — ✅ **GO, stays.**
- **`create scheduling entities of bundle work_order`** — ✅ **GO, stays.** (Drives WO→1091 via
  programmatic cascade; needs a date.)
- **`view any properties entities of bundle property`** — ✅ **GO, stays.** Needed to resolve
  `field_property`; bites only when the service explicitly checks property view (same pattern as
  B2) — retain for least-privilege intent + reference-field validation.
- **Edit/view `work_order`** — ✅ **NO-GO (not added).** B3 proves the AEL double-save + scheduling
  cascade are programmatic; create-time intake needs neither. **Role stays at the 4 entity perms.**
- **ADD — one transport-layer grant (not an entity perm):** the custom REST resource's route
  permission (`restful post <plugin_id>` or a custom `permission()`). Required so the System
  account may invoke the endpoint; distinct from the 4 entity-access perms (B1 gotcha #1).
- **Shim:** **NOT needed** — Cowork can call the authenticated REST endpoint directly via a custom
  `X-API-KEY` header (confirm against the specific product's connector docs).

**Net:** role = **4 entity perms (unchanged) + 1 REST-resource route permission**; build = **1 custom
auth provider + 1 RestResource + the `WorkOrderIntakeService` (explicit `createAccess` gating, raw
saves after) + service account/key**. No shim, no new contrib.

*Sources (B4):* [MS Learn — Build plugins for Copilot Cowork](https://learn.microsoft.com/en-us/microsoft-365/copilot/cowork/cowork-plugin-development) · [MS Learn — Configure Authentication for plugins in Agents in M365 Copilot](https://learn.microsoft.com/en-us/microsoft-365/copilot/extensibility/api-plugin-authentication) · [Coworker AI — Connectors](https://coworker.ai/connectors).

**STOP — Gate 0 baseline complete. Build spec next, after review.**

---

# Gate 1 — AS BUILT (shipped to live 2026-07-04)

Module **`bos_wo_intake`** ("Cowork Connect"). Gate 1 = authenticated transport + bare-WO
skeleton. Natural-language resolution, two-tier duplicate logic, and child entities
(notes/scheduling) are **Gate 2** (separate spec).

**Endpoint:** `POST /api/wo-intake?_format=json`
**Auth:** custom provider `cowork_key` (route-scoped, NOT global — wired via
`rest.resource.wo_intake` `authentication: [cowork_key]`). Header **`X-API-KEY`** only
(`Authorization` is ignored — it is stripped by the live LiteSpeed/CGI SAPI). Constant-time
`hash_equals`. Implements `AuthenticationProviderChallengeInterface` (401 on missing key).
**Secret:** `key` entity `bos_wo_intake` → `env` provider → env var
**`BOS_WO_INTAKE_API_KEY`**, exported via `putenv()` in each environment's `settings.php`
(off-git; born server-side via `openssl rand -hex 32`; never in git/config/chat).
**Role:** `system_integration` = `create work_order entities` · `create wo_notes entities of
bundle note` · `create scheduling entities of bundle work_order` · `view any properties
entities of bundle property` · `restful post wo_intake`. **Service account:** `cowork-connect`
(active; all writes run as it; subject of the explicit `createAccess()` gate).
**Config:** ships in the module's `config/install/` → imported by `drush en` (NO `drush cim`).

**Request:** `{ "property_id": <int>, "service_term_id": <int> }`
**201:** `{ "success": true, "work_order": { "id", "work_order_id", "bundle", "status", "status_tid" } }`
**Error:** `{ "success": false, "error": { "code", "message" } }`

**Status semantics (verified live):** valid → **201**; missing key → **401**;
`Authorization`-only → **401** (ignored); wrong key → **403** (core-standard — the 401 challenge
only fires on *absent* credentials, same as `basic_auth`; not overridden); business rejects →
**422** (`service_not_work_order`, `service_bundle_missing`, `estimate_bundle_forbidden`,
`property_not_found`); malformed body / bad ids → **400**.

**SAPI proof (the Gate 1 milestone), live 2026-07-04:** valid `X-API-KEY` + a deliberately
invalid `service_term_id` → **422, not 401** → the header survived the SAPI and the account
authenticated, with `work_order` count unchanged (zero prod data). Controls: no key → 401;
`Authorization: Bearer` → 401.

**`WorkOrderIntakeService::createBareWorkOrder(propertyId, serviceTermId)`** — transport-agnostic
(reused by Gate 2 + a future MCP tool): validate WO-service → resolve bundle → block `estimate`
→ validate property → explicit `createAccess()` → create+save (normal path heals the AEL title).

**Known finding (out of scope):** `field_work_order_id` is **not** assigned to newly-created WOs
by any path (UI, cron, or this API) — it was a one-time legacy backfill (= entity id) on ~30.5k
older WOs; ~16k recent WOs have none. The API returns the **entity `id`** as the durable handle
(`work_order_id` is null, consistent with the whole system). Whether new WOs should get a stable
WO# is a separate, system-wide question.

**Gate 2 scope:** natural-language / name resolution of property + service; two-tier duplicate
detection; child-entity creation (`wo_notes`, `scheduling`); read-back endpoints if needed.
