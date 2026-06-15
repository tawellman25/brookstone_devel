# Backflow Device System — Architecture Decision Record

**Status:** Locked design; as-built through Gate 2.
**Author:** Claude (architect role).
**Date:** 2026-06-14.
**Branch:** `backflow-device-system`.
**Gate commits:** Gate 1 = `1a011f9a` (config), Gate 2 = `153a9e2c` (`backflow_device` module).
**Scope:** Asset-management system for testable backflow prevention devices — the device record, its test events, its status history, the public/QR compliance surface, and the testing work order. This document records the locked design and reflects what is actually built on the branch (field lists pulled from the as-built config, not from memory).

---

## 1. Principle

> **A backflow device is an asset. A test is an event. A work order is a transaction.**

- **Assets persist forever.** A `property_backflow_device` is created once and lives for the life of the physical device. It is never deleted and never replaced by a test or a work order — when a device is physically swapped, the old record is marked `replaced` and points (via `field_replaced_by`) to its successor record.
- **Events and transactions reference the asset; they never become it.** A `wo_tasks_list:backflow_testing` test record (event) and a `work_order` of bundle `backflow_testing` (transaction) both reference the device. Many of each accumulate against one device over its lifetime.
- **QR codes are permanent and are never reissued.** The QR encodes the device's immutable canonical URL. The device record — status, last test date, owner of the underlying property, even the property's street address — changes *underneath* a fixed URL. A printed tag affixed to a valve in the field must remain scannable for the life of the device, so nothing the QR encodes may ever change.

This principle is the reason for nearly every decision in §3. When in doubt: the asset is the spine; everything else hangs off it and is additive.

---

## 2. Entity Model

Five new structures plus two `teammate_profile` additions. All are ECK except the taxonomy vocabulary and the profile fields.

### 2.1 `property_backflow_device` — the asset

- **Entity type / bundle:** `property_backflow_device` / `device`
- **Family:** Property-detail sub-entity family (see `Entities/property_detail_entities.md`). Attaches to a property; **not** a child of `property_ss_sources` (see §3.1).
- **Base fields:** `title` (the Device ID, system-set — see §3.2), `uid`, `created`, `changed`.
- **Persistence:** Permanent. No delete permission granted to any role. Lifecycle is expressed through `field_current_status`, not deletion.

| Field | Type | Notes |
|---|---|---|
| `field_property` | entity_reference → `properties` [property] | **Required.** The parent property. Required relationship for the whole family. |
| `field_serial_number` | string | Manufacturer serial as found on the device. |
| `field_material_backflow` | entity_reference → `material` [backflow] | Spec reference to the catalog material (make/model), not a copy of it. |
| `field_device_type` | entity_reference → `taxonomy_term` [backflow_device_types] | PVB / RP / DCVA / SVB. See §2.5. |
| `field_ss_source` | entity_reference → `property_ss_sources` [all] | Optional cross-link to the irrigation water source this device protects. |
| `field_physical_location` | string_long | Free-text "where on site" the device is. |
| `field_device_photos` | image (unlimited) | Field photos of the installed device. |
| `field_last_test_date` | datetime (date+time) | "Last completed" snapshot, written on test completion (Gate 3). |
| `field_last_pass_date` | datetime (date+time) | Last *passing* test snapshot (Gate 3). |
| `field_next_due_date` | datetime (date only) | Next test due. The hook point for the future reminders engine (§6). |
| `field_test_frequency_months` | integer (default 12) | Per-device frequency; supports per-water-district variation (§6). |
| `field_current_status` | list_string, default `active` | `active` / `failed` / `repaired` / `out_of_service` / `replaced`. Self-documenting; see §3.5. |
| `field_replaced_by` | entity_reference → `property_backflow_device` [all] | Self-reference. On physical swap, old record → new record. |
| `field_qr_code` | image (single) | Generated PNG of the canonical URL, written once on insert (§3.3). |

### 2.2 `backflow_device_status_log` — the audit trail (append-only)

- **Entity type / bundle:** `backflow_device_status_log` / `log`
- **Base fields:** `uid`, `created`, `title`. **`changed` is disabled by design** — a log row is written once and never edited.
- **Governance:** **Append-only, system-written only.** No create/edit/delete permissions are granted to any UI role; rows are written by code (Gate 3) bypassing access. Modeled on `wo_status_updates`. Conforms to BOS Architectural Rule #9 (audit trails are append-only, via entity lifecycle hooks).

| Field | Type | Notes |
|---|---|---|
| `field_backflow_device` | entity_reference → `property_backflow_device` [all] | **Required.** The asset this row describes. |
| `field_from_status` | list_string | Prior status (same allowed values as `field_current_status`). |
| `field_to_status` | list_string | New status (same allowed values). |
| `field_triggered_by_wo` | entity_reference → `work_order` [all] | The transaction that caused the change, when applicable. |
| `field_status_change_note` | string_long | Free-text reason / context. |

### 2.3 `wo_tasks_list:backflow_testing` — the test/task record (WO child)

- **Entity type / bundle:** `wo_tasks_list` / `backflow_testing`
- **Family:** Work Order task-list child — the service's TASK record, the same model every other service uses (`lawn_mowing`, `aerating`, …). For backflow the task *is* the test, so the readings live here. (Originally modeled as a separate `wo_backflow_test` entity on the `wo_spraying_conditions` compliance-child pattern; retired as a modeling correction — see §3.9.)
- **Base fields:** `uid`, `created`, `changed`, `title`.
- **Cardinality intent:** **One testing work order → many `wo_tasks_list:backflow_testing` children** (one visit can test many assemblies on a commercial site). The device reference lives here on the child, not on the WO (§3.4).

| Field | Type | Notes |
|---|---|---|
| `field_work_order` | entity_reference → `work_order` [all] | **Required.** The parent testing transaction. |
| `field_backflow_device` | entity_reference → `property_backflow_device` [all] | Which assembly this test result is for. |
| `field_test_date` | datetime (date+time) | When the test was performed. |
| `field_tester` | entity_reference → `user` [all] | The certified tester. Defaults to uid 1 on add (Gate 3a, `hook_entity_prepare_form`). |
| `field_certification_number` | string | **Snapshot** of the tester's cert number, copied from `teammate_profile` on presave (Gate 3a); frozen once the WO is Complete. |
| `field_pass_fail` | list_string `{pass\|fail}` | Test result. |
| `field_is_initial_test` | boolean | Initial vs. periodic re-test. |
| `field_line_pressure_psi` | decimal (6,2) | Reading. |
| `field_check_valve_1_psid` | decimal (6,2) | Reading. |
| `field_check_valve_2_psid` | decimal (6,2) | Reading. |
| `field_relief_valve_psid` | decimal (6,2) | Reading. |
| `field_air_inlet_psid` | decimal (6,2) | Reading. |
| `field_check_valve_psid` | decimal (6,2) | Reading. |
| `field_repairs_recommended` | text_long | Tester's repair notes. |
| `field_report_pdf` | file (ext: pdf) | Generated report (Gate 3b). |
| `field_report_image` | image (ext: png gif jpg jpeg pdf) | Legacy-scan fallback only. |

> Per-device-type reading visibility (showing only the readings a PVB vs. RP needs) is implemented (Gate 3a) via a server-side `hook_form_alter` keyed on the device's `field_type_code` — `#access`, explicitly **not** `conditional_fields`. With no device chosen yet, all readings show.

### 2.4 `work_order` bundle `backflow_testing` — the transaction

The existing `backflow_testing` work order bundle, brought to **sibling parity** with a standard service WO (Gate 1). 22 operational/billing field instances were cloned from the `aerating` sibling: `field_property`, `field_status`, `field_service`, `field_supervisor`, `field_scheduled`, `field_invoiced`, `field_printed`, `field_labor_total`, `field_material_chemical_total`, `field_wo_total`, `field_total_time`, `field_trucks`, `field_trip_fee`, `field_work_order_id`, `field_work_order_notes`, `field_work_todo_description`, `field_estimated_price`, `field_billing_adjustment`, `field_billing_notes`, `field_client_app_wo_number`, `field_dump_fee_total`, `field_rental_total` (the latter two for parity; not expected to carry values for testing). `field_work_order_id` and `field_work_order_notes` are left off the form display to match the sibling.

- **Labor rate source:** `config_pages:business_setting.field_sprinkler_technician_rate` (the testing crew is the sprinkler/irrigation crew).
- **No reading fields and no device reference live on the WO bundle** — they live on `wo_tasks_list:backflow_testing` so one visit can carry many tests.
- **Critical invariant (CLAUDE.md):** `work_order.bundle` must equal `field_service.term.field_service_bundle`. A Services taxonomy term with `field_service_bundle = backflow_testing` must exist before scheduling relies on `field_service` (Services terms are content, not config — verify in the environment).

### 2.5 `backflow_device_types` taxonomy vocabulary

Vocabulary `backflow_device_types`, four seed terms (PVB, RP, DCVA, SVB). Type is a taxonomy (not a list field) because public/training landing pages were wanted for *types* (see §3.5).

| Field | Type | Notes |
|---|---|---|
| `field_type_code` | list_string `{PVB\|RP\|DCVA\|SVB}` | **Required.** Stable machine code for logic (per-type reading visibility in Gate 3). |
| `field_public_description` | text_long | Training / public-facing copy. Reuses the shared `taxonomy_term.field_public_description` storage. |

Public landing view `backflow_device_types_landing` at `/services/backflow-prevention` (anonymous access). Term pathauto pattern: `services/backflow-prevention/[term:name]`.

### 2.6 `teammate_profile` additions

| Field | Type | Notes |
|---|---|---|
| `field_certification_number` | string | Tester's backflow cert number; snapshotted onto each test in Gate 3. |
| `field_signature` | image | Used on the generated report (Gate 3) — no double entry. |

### 2.7 Relationship diagram

```
properties (1) ──< (many) property_backflow_device   [field_property, required]
                              │
   material [backflow] ───────┤  field_material_backflow   (spec reference)
                              │
   property_ss_sources ───────┤  field_ss_source           (optional cross-link)
                              │
   property_backflow_device ──┘  field_replaced_by          (self, on physical swap)

property_backflow_device (1) ──< (many) backflow_device_status_log          [field_backflow_device, required, append-only]
property_backflow_device (1) ──< (many) wo_tasks_list:backflow_testing       [field_backflow_device]

work_order [backflow_testing] (1) ──< (many) wo_tasks_list:backflow_testing  [field_work_order, required]
   (one testing visit → many test/task children → each child → one device)

taxonomy backflow_device_types (1) ──< (many) property_backflow_device   [field_device_type]
```

### 2.8 Completion write-back & status lifecycle (as-built, Gate 3a)

When a `backflow_testing` WO completes, the `wo_backflow_testing` module reads
the `wo_tasks_list:backflow_testing` children and writes the result back onto
each referenced device asset, appending an audit row only when the device's
status actually changes.

**Completion trigger (coupling to note):** a `backflow_testing` WO reaches
Complete (term **1097**) when the crew submits a `wo_complete_info` of the
**`irrigation_crew`** sign-off bundle. `wo_sign_off` sets `field_status` = 1097
and saves the WO (`wo_sign_off.module:156`), which fires
`wo_backflow_testing_entity_update` → the write-back. **Backflow write-back
therefore depends on `irrigation_crew` remaining an in-scope `wo_sign_off`
bundle** — if the testing crew's sign-off bundle ever changes, the trigger
moves with it. Test children are entered during execution, so they are already
persisted at completion.

**Per-device write-back** (children grouped by device; if one device has
multiple tests on one visit, the latest `field_test_date` wins):

- **PASS** → device `field_current_status` = `active`; `field_last_test_date`
  and `field_last_pass_date` set to the test date; `field_next_due_date` =
  last pass + `field_test_frequency_months` (default 12), computed with PHP
  `DateTime` in the **site timezone** and stored **date-only** (`Y-m-d`) — never
  SQL `FROM_UNIXTIME` (live MariaDB session TZ ≠ PHP TZ).
- **FAIL** → device `field_current_status` = `failed`; `field_last_test_date`
  set; **`field_last_pass_date` and `field_next_due_date` left untouched** so the
  device reads non-compliant with its last-known-good dates intact.

**Status log (`backflow_device_status_log`):** append-only; exactly **one row
per actual status transition**, change-detected against the device's prior
`field_current_status` (captured before the write). `field_from_status` /
`field_to_status` / `field_triggered_by_wo` / an auto note / `uid` are recorded.
A PASS on an already-`active` device, or a FAIL on an already-`failed` device,
writes **no** row.

**Idempotency:** re-saving a Complete WO with no status-relevant change writes
no log row and leaves device dates unchanged — device field writes are
deterministic from the children, and the log row is gated on a real transition.

**Not test-produced:** `repaired`, `out_of_service`, and `replaced` are manual
/ future statuses; the write-back never sets them.

**Cert snapshot** (`wo_tasks_list:backflow_testing` presave): the tester's
`teammate_profile.field_certification_number` is copied onto the test child's
`field_certification_number`. It **mirrors the currently-selected tester while
the parent WO is not yet Complete**, and **freezes once the WO is Complete**
(1097) — the freeze reads the WO status **fresh by id** (not via the cached
`->entity` reference), so it holds even when a child loaded before completion is
re-saved in the same request. If the tester has no cert on profile, the snapshot
is left blank (no error). This is the snapshot the Gate 3b report/tag will read
— no double entry.

**WO-page surface (EVA + Add Test).** The test children render on the
`work_order:backflow_testing` page via the `wo_tasks_list` EVA view
(display `entity_view_5`, the backflow bundle), filtered to the current WO by the
`id` argument — the same mechanism every other task-list service uses. An **"Add
Test"** button (in the EVA empty + footer areas) links to the ECK content add
route `/admin/content/wo_tasks_list/add/backflow_testing`, prefilling
`field_work_order` to the current WO and setting `destination` back to the WO
page. The button stays available after a save so a second device on the same
property can be tested.

---

## 3. Key Decisions & Rationale

### 3.1 Device is in the property-detail family, not a child of `property_ss_sources`

A backflow device attaches to the **property**, not to the sprinkler graph. Devices exist on domestic and irrigation lines, on commercial sites with no BOS sprinkler model, and survive irrigation-system redesigns. Making it a property-detail entity (required `field_property`) keeps it standalone from the sprinkler data model while still allowing an **optional** `field_ss_source` cross-link where the device does protect a modeled irrigation source. Coupling it under `property_ss_sources` would have orphaned every device on a property with no sprinkler system record.

### 3.2 Device ID is the entity ID rendered into the title (`BF-NNNNNN`), set directly — not an AEL token

The Device ID is `BF-` + zero-padded entity id (e.g. `BF-000005`). It is set **directly** in `backflow_device_entity_insert` after the id is known, **not** via an Auto Entity Label `[id]` token. This is deliberate: the `cabb8a6e` sentinel bug showed that AEL's `[id]` token is unresolved during presave-on-insert, leaving the literal `%AutoEntityLabel: <uuid>%` sentinel stuck in the title (and the pathauto alias) on programmatic creates. Direct-set in `hook_entity_insert` sidesteps that entirely. There is **no separate `field_device_id`** — the id *is* the title, single source of truth.

### 3.3 QR encodes the immutable canonical URL (forced), never the alias

The QR encodes `toUrl('canonical', ['alias' => TRUE])->setAbsolute()` → `https://…/property_backflow_device/{id}`. The `['alias' => TRUE]` option is **required**: without it, `toString()` substitutes the pathauto alias, which is **address-derived** (`/{property-path}/backflow/bf-nnnnnn`). A printed field tag must survive a property address correction, an owner change, and an irrigation redesign — so it must encode the one URL that never changes: the canonical route. Drupal's Redirect module (enabled; `route_normalizer_enabled: true`, `auto_redirect: true`, 301) forwards canonical → the human-readable property-path alias when a person scans it, so usability is preserved without baking a mutable path into the tag.

**Regeneration guard:** the QR (and title) are written once, in `hook_entity_insert` only, behind a guard that no-ops when the title is already `BF-NNNNNN` and `field_qr_code` is non-empty, plus a re-entrancy guard around the single follow-up save. A normal later `->save()` is an update and never re-enters the insert hook, so the QR is never reissued. Verified: re-save leaves title and QR file byte-identical.

### 3.4 Readings live on the `wo_tasks_list:backflow_testing` child, not the WO bundle

A single testing visit to a commercial site tests many assemblies. Putting readings and the device reference on the WO bundle would force one WO per assembly. Instead, one `work_order` (the billable transaction/visit) owns many `wo_tasks_list:backflow_testing` children (one per assembly), each carrying its own readings and `field_backflow_device`. This mirrors the established WO **task-list** pattern that every service uses (`lawn_mowing`, `aerating`, …) — see §3.9 for why the task list, not a bespoke compliance child.

### 3.5 `field_current_status` is a `list_string`; device *type* is a taxonomy

Status is a `list_string` so the status-log snapshots (`field_from_status` / `field_to_status`) store self-documenting values (`failed`, `repaired`) with no taxonomy-term-ID hardcoding to drift across environments. Device **type**, by contrast, is a taxonomy because the business wanted public/training landing pages and stable per-type copy for the four device types — content that belongs on a term, with a stable `field_type_code` for logic. Statuses needed neither public pages nor referencing entities, so a fielded enum is the lighter correct choice.

### 3.6 Public page exposure is minimal and access-safe

The `full` view mode is the public compliance page. `field_property`'s `entity_reference_label` is access-filtered for anonymous (anon cannot view `properties` entities), so on the `full` mode `backflow_device_entity_view` removes it and renders, **by code**, a pull-through of the property's street address + city/zip plus a static "Maintained by Brookstone Outdoors" line. It exposes **only** those address fields — no owner, contact, gate code, notes, or link to the property entity — and makes **no change to `properties` entity access**. The render is cache-tagged on the property so a later address correction shows the current value (pull-through, not snapshot). `field_property` keeps its normal label formatter on the admin/default displays for privileged users.

### 3.7 Reports and tags are generated from WO data + the tester's stored signature

The test report PDF and the device service tag are generated from `wo_tasks_list:backflow_testing` + `work_order` data plus the Gate 3a cert snapshot and `teammate_profile.field_signature` — no double entry of tester identity or signature. Generation (Entity Print / dompdf) is planned for **Gate 3b** (the HB25-1077 tag).

### 3.8 Inline device-create: hybrid autocomplete + button, not `inline_entity_form` (Gate 3a)

On the `wo_tasks_list:backflow_testing` form, `field_backflow_device` stays an
`entity_reference_autocomplete` and the form adds a **"Create new device for
this property" button** (`wo_backflow_testing` `hook_form_alter`), rather than
switching the field to an `inline_entity_form_complex` widget.

Rationale: the six reading fields are top-level siblings of the device field,
and per-type reading visibility must update **live** when a device is selected.
IEF's AJAX only refreshes its **own** widget wrapper — it cannot live-refresh
sibling fields — so an IEF widget on `field_backflow_device` would break the
per-type readings AJAX. The autocomplete keeps a clean change-event AJAX that
rebuilds the readings, and the separate button covers the "no device on this
property yet" case. The button creates the device inheriting `field_property`
from the parent WO and relies on the Gate 2 `backflow_device` insert hook for
the `BF-NNNNNN` title, QR, and pathauto alias, then auto-selects it.

### 3.9 The test record is a service task list (`wo_tasks_list`), not a bespoke child — `wo_backflow_test` retired

Backflow testing was first built as its own ECK entity, `wo_backflow_test`,
modeled on `wo_spraying_conditions` — a *compliance* child that records ambient
conditions alongside a service. That was the wrong analogy: for backflow the
test **is** the service's task, not side conditions about it. Every other BOS
service records its execution on `wo_tasks_list` (the per-service task record),
and `wo_tasks_list` already carries the established WO-page surface — a per-bundle
EVA with an "Add Test/Task" button on the ECK content add route — that
`wo_backflow_test` lacked (the property-detail family, which `wo_backflow_test`
resembled, has no standalone add route).

So the entire field set was moved onto a new **`wo_tasks_list:backflow_testing`**
bundle and `wo_backflow_test` was **retired** (entity type, bundle, fields,
displays deleted; per-bundle role permissions re-pointed to the new bundle,
mirroring how sibling task-list bundles are granted). All Gate 3a logic moved
across with identical semantics. This is a pure modeling correction — no real
test data existed (Gate 3a content was test-only and cleaned up). The payoff:
backflow now behaves like every other service (same child entity, same EVA/add
pattern, same permission model), which is also why the WO **view display** is
modeled on a sprinkler service (§ aligns with the irrigation crew).

---

## 4. As-Built Status

| Gate | Scope | Status | Commit |
|---|---|---|---|
| **Gate 1** | Config: 3 ECK types + bundles + fields, `backflow_device_types` vocab + 4 terms + landing view, `teammate_profile` fields, `backflow_testing` parity, displays, pathauto, permissions | **DONE** | `1a011f9a` |
| **Gate 2** | `backflow_device` module: `BF-NNNNNN` title, permanent canonical-URL QR, public pull-through address render; endroid/qr-code dependency | **DONE** | `153a9e2c` |
| **Gate 3a** | `wo_backflow_testing` module/form logic: cert snapshot (test-child presave), device write-back + status-log on WO completion (1097), per-type reading visibility (`hook_form_alter` on `field_type_code`), tester uid-1 form default, hybrid inline device-create button (§3.8). | **DONE** | `7608bfdc` |
| **Gate 3a — WO displays** | `backflow_testing` WO form aligned to `sprinkler_repair` + corrected service/work-todo defaults (`f12c1503`); WO view display modeled on `sprinkler_repair` with operational EVAs attached to the bundle (`627317fb`). | **DONE** | `f12c1503`, `627317fb` |
| **Gate 3a — rework** | Folded the test record from a bespoke `wo_backflow_test` entity into **`wo_tasks_list:backflow_testing`** (§2.3, §3.9): moved the full field set, re-pointed all Gate 3a logic, added the WO-page test EVA + "Add Test" button, re-pointed role permissions, and **retired `wo_backflow_test`**. | **DONE** | `a7560b62` |
| **Gate 3b** | Generated PDF test report + HB25-1077 service tag (Entity Print / dompdf), from WO data + cert snapshot + `teammate_profile.field_signature`; SOP authoring for the human field-testing workflow | **PLANNED** | — |
| **Gate 4** | Device-page EVAs (test history + status log on the *device* page) and the compliance dashboard. (The *WO*-page test EVA shipped in the Gate 3a rework.) | **PLANNED** | — |
| **Legacy migration** | Synthesize devices from `property_ss_sources.field_ss_backflow`, idempotent | **PLANNED** | — |

---

## 5. Gotchas & Hazards (captured during build)

- **Config created via import/generator (not the UI) bit us twice.** (1) `property_backflow_device` was missing from `pathauto.settings:enabled_entity_types`, so pathauto never added the computed `path` base field → the alias type was "broken" and alias generation was a **silent no-op** (cim reported success). (2) The three new-entity form displays had malformed widget settings (`settings: null` with leaked top-level keys) from a generator indentation bug → **`ImageWidget` crashed whenever the add/edit form rendered**, invisible until a form was actually built. **Standing rule: after any programmatic config change, render the actual add/edit form AND hit a generated alias. `drush cim` success is not sufficient verification.**
- **`endroid/qr-code ^6`** is a new project dependency (+ `bacon/bacon-qr-code`, `dasprid/enum`); resolves and runs on PHP 8.3 (gd present). `composer require` re-triggered a **dead `drupal/calendar` patch URL** (pre-existing, unrelated). Add to the pre-deploy checklist alongside the `form_mode_control` / `views_bulk_operations` patch reapplication: contrib is excluded from rsync, so patches are re-applied manually on live after `composer install`.
- **The property-detail family has no standalone `/add` content route** (confirmed against `property_ss_sources` too). Devices are created via inline/embedded entity forms and programmatically — both fire `hook_entity_insert`, which is what drives title/QR generation.
- **The "Create new device" button (Gate 3a) requires the work order — and thus the property — to be selected first.** If clicked before a WO is chosen it warns ("Could not create device: select a work order…") and stays open rather than creating a device. This is an **intentional ordering constraint, not a bug**: a device created without `field_property` would have a broken pathauto alias (no property path segment), so the button refuses to create one until it can inherit the property from the parent WO.
- **`config/sync` currently diverges from live-synced active config** in hundreds of unrelated configs on this environment. All Gate 1/2 imports were done as **surgical `cim --partial` from a staging dir of only the changed files** — a full `drush cim` would clobber unrelated active config, and a blind `drush cex` would clobber manually-synced live config. Treat full cim/cex on this branch as forbidden until the divergence is reconciled. **Standing hazard; separate cleanup owed.**
- **`work_order.backflow_testing.field_service` default is stored by content UUID and is env-specific.** The committed default_value points at the "Backflow Testing and Certification" services term by `target_uuid` (`37f908e2-…`, the local term's UUID). Taxonomy terms are content, so the same term on live has a **different** UUID — on deploy, `cim` won't resolve this default and the field_service default will silently not apply on live until re-pointed. This is how **all** WO `field_service` instances behave (created on live with live's UUIDs); the wrinkle here is that the backflow term default was set in local dev. **Pre-deploy / post-deploy checklist:** after this branch deploys, confirm `work_order.backflow_testing.field_service`'s default resolves to live's Backflow Testing term and re-set it on live if blank. (Same caveat applies to any future field default that references a content entity by UUID.)

---

## 6. Open Items / Deferred

- **`field_gps`** — deferred; needs the `geofield` contrib module. (Note: `geofield` is in fact already enabled, so the dependency rationale is moot — deferral is scope-only.)
- **Per-water-district test frequency** — `field_test_frequency_months` exists (default 12); per-district variation is data-entry, no schema change needed.
- **Automated reminders engine** — not built; `field_next_due_date` is the intended hook point.
- **Customer portal exposure** — not built.
- **Water-district compliance export** — not built.
- **SOP** — owed once Gate 3 builds the human field-testing workflow (per CLAUDE.md SOP governance, flag ⚠ SOP NEEDED at that gate; SOP content is authored by Claude Chat, not written inline).
- **`field_tester` form default = uid 1** — a Gate 3 form-level default (not a field default, which would be env-specific by UUID).
