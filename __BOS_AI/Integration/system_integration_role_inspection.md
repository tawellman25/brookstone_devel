# Gate 0 Inspection — `system_integration` role definition (READ-ONLY findings)

- **Date:** 2026-06-28 · **Scope:** data gathering only — no role created, no config changed.
- **Goal:** define a minimal headless role for a "System" service account whose entire job is
  six operations (create/edit Work Orders, create WO notes, schedule, read Properties, read
  Services). Backs a future custom authenticated REST resource (Option B) — see
  `work_order_api.md`. GREENFIELD: no API/auth exists yet.
- **Method:** `drush role:list` + `drush php:eval` against the local DDEV install (roles/perms
  are config; local == live for this). ECK access **handler source** read directly. No SQL.

---

## 0. The decisive fact first: the ECK access model

`work_order`, `wo_notes`, `properties`, and `scheduling` are all **ECK** entity types and share
one access handler: `web/modules/contrib/eck/src/EckEntityAccessControlHandler.php`.
A broad grep found **no supplementary access hooks** (no `hook_entity_access`,
`hook_work_order_access`, `hook_entity_field_access`) in custom code, `eck`, `bos_scheduling`,
or `admin_calendar` — so this handler is authoritative.

- **CREATE** (`checkCreateAccess`, lines 93-101) — allowed if the account has **either**:
  - `create <type> entities` (global), **OR**
  - `create <type> entities of bundle <bundle>` (per-bundle).
  → **A single global `create work_order entities` grants create on ALL 36 bundles.**

- **EDIT / VIEW / DELETE** (`checkAccess`, lines 74-88) — only ever checks **global**
  `<op> any <type> entities`, plus `<op> own <type> entities` *if the account is the entity
  owner*. **The per-bundle `edit any/view any/delete any … of bundle X` permissions are NOT
  consulted by this handler.** They exist (36×3 of them) but are effectively **dead** for
  edit/view/delete. ⚠ You cannot bundle-scope editing via ECK perms; edit is all-bundles
  (global) or nothing. Bundle-scoped editing would require a custom access hook.

This shapes every recommendation below.

---

## 1. Role inventory + Site Assistant dump

`drush role:list` → 10 roles: `anonymous`, `authenticated`, `administrator`, `site_admin`,
**`site_assistant`** (machine name confirmed), `administration`, `supervisor`, `teammates`,
`client`, `user`.

**Site Assistant holds 573 permissions.** Category breakdown (verb + entity):

| Domain | Grants (approx) |
|---|---|
| `estimate` (create/edit/view any+own) | ~225 across 45 bundles |
| `estimate_tasks` | ~76 |
| `material` (create/edit/view) | ~74 |
| form-mode "use The form mode…" | 74 |
| `estimate_items`, `estimate_notes`, `estimate_action_log` | ~24 |
| `material_suppliers`, `property_backflow_device`, `backflow_device_status_log`, `contacts`, `contract_*` audit | ~20 |

**Sensitive check:** Site Assistant has **no** `administer …` god-grants, **no** user/permission/
config/module admin, and only one delete (`delete own contacts entities of bundle emergency_contacts`).
It *does* hold **field-execution-child** grants we must avoid: `create/edit wo_material_list`,
`create/edit wo_material_list_item`, `edit own wo_material_dumping`.

**Critical result:** Site Assistant holds **ZERO** of our six operations — no `work_order`
create/edit/view (global *or* per-bundle), no `wo_notes`, no `properties` view, no `scheduling`,
no `access content`. It is an **estimate/materials** assistant, not a WO dispatcher. **It is the
wrong reference role for this job; the intersection yields nothing to keep** (see §3).

---

## 2. The six operations → exact permission strings (and whether Site Assistant holds them)

| # | Operation | Exact permission string(s) — actual on this install | SA holds? |
|---|---|---|---|
| 1 | **Create work_order (all 36 bundles)** | `create work_order entities` (global — covers all 36 via the OR in §0). *Alt:* 36× `create work_order entities of bundle <bundle>`. | ❌ No |
| 2 | **Edit work_order** | `edit own work_order entities` *or* `edit any work_order entities` (global only — per-bundle edit perms are dead, §0). | ❌ No |
| 3 | **Create wo_notes** | `create wo_notes entities of bundle note` (only bundle is `note`). *Alt:* global `create wo_notes entities`. | ❌ No |
| 4 | **Scheduling transition** | `create scheduling entities of bundle work_order` (see trace below). *Alt global:* `create scheduling entities`. | ❌ No |
| 5 | **Read properties** | `view any properties entities of bundle property` (only bundle is `property`). *Alt:* global `view any properties entities`. | ❌ No |
| 6 | **Read services taxonomy** | `access content` (core `TermAccessControlHandler` requires it for viewing published terms; vocab machine name = `services`). | ❌ No |

### 2d — Scheduling transition trace (the least obvious; shown, not asserted)

Moving a WO to **Scheduled (1091)** + date/crew is **not** a direct `field_status` edit and is
**not** gated by any custom permission:

1. The unit of scheduling is a **`scheduling` ECK entity, bundle `work_order`** (its only
   bundle). Relevant fields: `field_work_order` (ref to the WO), `field_date` /
   `field_scheduled_date_and_time`, `field_assigned_to` (crew), `field_scheduled` (flag),
   `field_scheduling_note`.
2. `wo_schedule.module` (`hook_entity_insert`/`update` on `scheduling:work_order`) reacts to its
   creation. When the record **has a date**, it calls
   `wo_schedule_create_wo_status_update(..., $STATUS_SCHEDULED = 1091, 1)` (lines ~324-390),
   which writes a `wo_status_updates` entry; the `wo_status_updates` module then propagates the
   **1091** status back onto the WO. (Without a date it uses Assigned/1090 — so **the API must
   include a date** to land on Scheduled.)
3. These cascade saves are **programmatic** (`$entity->save()` bypasses entity *access* checks).
   → The service account needs **only** `create scheduling entities of bundle work_order`.
   It does **NOT** need `edit work_order`, `field_status` field access, or any `wo_status_updates`
   permission to achieve the WO→1091 flip.

Confirmed negatives: `bos_scheduling` and `admin_calendar` define **no** `*.permissions.yml`
(no custom scheduling permission). `field_permissions` and `permissions_by_term` are **not
enabled**, so there is no field-level gate on `field_status` and no per-vocabulary term-read
permission.

> *Reschedule note:* editing an existing scheduling record would need global
> `edit own scheduling entities` (per-bundle dead, §0). Initial scheduling — the stated op —
> needs create only. If reschedule-by-edit is in scope, flag for the role-build step.

---

## 3. Intersection / proposed role (ANALYSIS ONLY — not created)

### KEEP (Site Assistant grants that map to our six ops)
**NONE.** Site Assistant holds none of the six-op permissions. Do not derive this role from Site
Assistant — it maps to estimates/materials, not WO creation.

### ADD (our ops need; Site Assistant lacks all of them)
| Permission string | For op | Notes |
|---|---|---|
| `create work_order entities` | 1 | Global; covers all 36 bundles (incl. legacy `estimate` — see flags) |
| `edit own work_order entities` | 2 | Minimal; assumes WOs keep the service account as owner. ⚠ decision: use `edit any work_order entities` if the API must edit WOs it did not create, or if BOS reassigns WO ownership on save |
| `create wo_notes entities of bundle note` | 3 | Single bundle; tighter than the global |
| `create scheduling entities of bundle work_order` | 4 | Drives WO→1091 via programmatic cascade (§2d) |
| `view any properties entities of bundle property` | 5 | Single bundle; tighter than the global |
| `access content` | 6 | Only path to term reads; broader than just `services` — see flags |
| *(likely)* `view own work_order entities` | — | **Not in the stated six ops.** But a REST/JSON:API `GET`/`PATCH` round-trip needs view to read a WO back. Flag for scope confirmation; pair with whichever edit scope is chosen (`own`↔`own`, `any`↔`any`) |

### EXCLUDE (every Site Assistant grant — none map; all 573 dropped)
Deliberately dropping the entire Site Assistant grant set. The categories (from §1) are all
out of scope: `estimate*` (~325), `material*`/`material_suppliers` (~76), `estimate_tasks`
(~76), `estimate_items/notes/action_log`, form-mode "use" perms, backflow device views,
contacts/contract audit. **Explicitly sensitive exclusions to keep out:**
- **Field-execution children** SA holds: `create/edit wo_material_list`,
  `create/edit wo_material_list_item`, `edit own wo_material_dumping` — these are exactly the
  execution-data children the service account must never touch.
- SA's lone delete (`delete own contacts … emergency_contacts`) — no delete of anything.
- (SA has no config/user/module admin to drop — it never had any.)

---

## 4. Danger flags

1. ✅ **No god-grant required.** All six ops are reachable via narrow ECK perms + `access content`.
   **None** of `administer work_order`, `administer work_order fields/display/form display`, or
   any `administer …` is needed. Do not accept one.
2. ✅ **The six ops do NOT force any excluded capability.** Verified:
   - **Completion** (`wo_complete_info` / `wo_sign_off`) — a separate entity with its own create
     permission that we do **not** grant. Creating/editing/scheduling a WO never requires it.
   - **Field-execution children** (`wo_time_clock`, `wo_material_list[_item]`, `wo_chemicals_used`,
     `wo_rental_equipment`, `wo_material_dumping`, `wo_spraying_conditions`) — none required.
   - **Delete** — no delete permission needed for any of the six ops.
   - **Config / users / other entity types** — none required.
3. ⚠ **Programmatic status cascade (by design, but note it).** Granting
   `create scheduling entities of bundle work_order` lets the service account trigger a WO
   status change (→1091) it could **not** make directly (it has no `field_status` access). This
   is the intended data-model path and is fully auditable (append-only `wo_status_updates`), but
   the role-build review should acknowledge that scheduling-create is, indirectly, a status-write.
4. ⚠ **`access content` is broader than "read Services."** It is the *only* way to read taxonomy
   terms through the entity-access system (no per-vocab read perm; `permissions_by_term` off). It
   grants view of **all** published content site-wide. Low practical risk in BOS (no nodes carry
   sensitive ops data), but it is the widest grant in the proposed role — accept knowingly.
5. ⚠ **Dead per-bundle edit/view perms (trap).** The 36×3 per-bundle edit/view/delete
   work_order perms are not honored by the ECK handler (§0). Anyone defining the role later might
   try to scope edit per-bundle with them — it won't work. Edit is global `own`/`any`.
6. ⚠ **Global create includes the legacy `estimate` work_order bundle.** Op 1 wants all 36, so
   `create work_order entities` is correct, but it technically permits creating the deprecated
   `estimate` bundle. Enforce "don't target the estimate bundle" at the API layer, not via perms.

---

## 5. Recommended minimal role (for the LATER build step — not created here)

```
system_integration:
  - create work_order entities                          # op1 (all 36 bundles)
  - edit own work_order entities                        # op2  (decision: own vs any)
  - view own work_order entities                        # REST read-back (confirm scope)
  - create wo_notes entities of bundle note             # op3
  - create scheduling entities of bundle work_order     # op4 (→1091 via cascade; include a date)
  - view any properties entities of bundle property     # op5
  - access content                                       # op6 (services term reads)
```

Open decisions to resolve before building: (a) edit/view **own vs any**; (b) whether
**read-back** (`view own work_order`) and **reschedule** (`edit own scheduling entities`) are in
scope. No other permission is needed, and none of the excluded capabilities are forced.

**STOP — Gate 0 complete. Role defined/created in a later step after review.**
