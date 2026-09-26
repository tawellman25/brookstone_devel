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
| `field_device_type` | entity_reference → `taxonomy_term` [backflow_device_types] | PVB / RP / DCVA / SVB (testable) + AVB / DuC (non-testable) — the *mechanical* axis; carries `field_is_testable`. See §2.5. |
| `field_used_for` | entity_reference → `taxonomy_term` [backflow_uses] | **Optional, single-value.** The *application* axis — what the device protects (irrigation, domestic, fire…). Independent of `field_device_type`. See §2.5a, §3.11. |
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
| `field_tester` | entity_reference → `user` [all] | The certified tester. Entered value kept; **blank → current user** on form save (`bdb9ccd4`; a form `#entity_builder`, so the programmatic "New Test" create never stamps the WO creator). |
| `field_certification_number` | string | **Snapshot** of the tester's cert number, copied from the tester's `teammate_profile` on presave **only when empty** (never overwrites/clears); frozen once the WO is Complete. |
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

Vocabulary `backflow_device_types`, **six seed terms** — the four testable assemblies **PVB, RP, DCVA, SVB** plus two **non-testable** devices **AVB** (Atmospheric Vacuum Breaker) and **DuC** (Dual Check Valve), added to production after the 2026-06-20 seed (see §5). Type is a taxonomy (not a list field) because public/training landing pages were wanted for *types* (see §3.5).

| Field | Type | Notes |
|---|---|---|
| `field_type_code` | list_string `{PVB\|RP\|DCVA\|SVB\|AVB\|DuC}` | **Required.** Stable machine code for logic (per-type reading visibility in Gate 3). `allowed_values` was extended to all six when AVB/DuC were added; every term has a valid code. |
| `field_is_testable` | boolean (default **TRUE**) | **The testable-vs-non-testable guard (added 2026-09-26).** TRUE for PVB/RP/DCVA/SVB, **FALSE for AVB + DuC**. A non-testable device has no test cocks and cannot be gauge-tested, so it must **not** receive a `field_next_due_date` or appear in compliance reminders. Default TRUE fails safe (a future term is presumed testable). Consumers: the device form hides the test-scheduling fields for a non-testable type (`backflow_device_form_alter`), and the compliance dashboard excludes them (`backflow_device_views_query_alter`). Set per-term by the seed script (content). |
| `field_public_description` | text_long | Training / public-facing copy. Reuses the shared `taxonomy_term.field_public_description` storage. |

Public landing view `backflow_device_types_landing` at `/services/backflow-prevention` (anonymous access). Term pathauto pattern: `services/backflow-prevention/[term:name]`.

### 2.5a `backflow_uses` taxonomy vocabulary (application axis)

Vocabulary `backflow_uses`, 14 seed terms (IRRIGATION, DOMESTIC, FIRE, BOILER, FERTIGATION, KITCHEN, COOLING_TOWER, INDUSTRIAL, MEDICAL, POOL, POND, AGRICULTURAL, HOSE_BIBB, MOBILE). Captures *what a backflow protects* — independent of the mechanical device type (§2.5). Referenced from the device via `field_used_for`.

| Field | Type | Notes |
|---|---|---|
| `field_use_code` | string | **Required.** Dedicated stable machine code (key logic off this, never the TID). A separate field — **not** the device-type `field_type_code` `list_string`, whose `allowed_values` are storage-shared and scoped to PVB/RP/DCVA/SVB (see §3.11). |
| `field_public_description` | text_long | Customer / water-district facing. Reuses the shared `taxonomy_term.field_public_description` storage. |
| `field_teammate_description` | text_long | Training / tech facing. Reuses the shared `taxonomy_term.field_teammate_description` storage. |

Term pathauto pattern: `services/backflow-prevention/uses/[term:name]` (pattern `backflow_use_paths`). Term pages are anonymous-viewable via the default `access content` permission (no role change) — descriptions are application info, safe to expose. Seeded by `web/scripts/seed_backflow_uses.php` (terms are content — run on live, see §5).

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

**Tester identification (as-built 2026-09-12, `bdb9ccd4`).** The tester is
`field_tester` (→ `user`) on the test child. Rule: **an entered tester (any uid)
is kept; a blank tester defaults to the current user** — the person recording the
test — applied by a form `#entity_builder`
(`_wo_backflow_testing_default_tester_builder`) that runs **only on a human form
save**. The "New Test" controller creates the task **programmatically**, which
does **not** fire the builder, so the WO creator (usually the office, not the
tester) is never stamped — the record stays tester-blank until whoever records
the test saves the form. The add form also pre-selects the current user when
blank. _(Superseded the original Gate-3a "default to uid 1 on add" behavior, and
a brief 2026-09-12 "default to Todd (uid 1) at creation" pass, `453e02c8`.)_
**Caveat:** because blank → current user, if the **office** ever keys in readings
on a tech's behalf, they must set `field_tester` to the actual tech, or it records
the office user. If multiple certified testers become common, revisit whether the
tester should be a required manual pick.

**Test date default (as-built 2026-09-12, `15a676c9`).** The same form-defaults
`#entity_builder` also defaults **`field_test_date` → now** on save when it is
blank **and** a result (`field_pass_fail`) has been entered — so a tester
recording a result need not type the date, an entered date is kept, and a blank
shell (no result) is never stamped. The test date is load-bearing: it sets the
device's `field_last_test_date` / `field_last_pass_date` and drives
`field_next_due_date` (= test date + `field_test_frequency_months`), plus the
report/tag date. Stored UTC (`Y-m-d\TH:i:s`), displayed in site TZ.

**Cert snapshot** (`wo_tasks_list:backflow_testing` presave): the tester's
`teammate_profile.field_certification_number` is copied onto the test child's
`field_certification_number` **only when the child's cert is empty** — it never
overwrites a hand-entered value and never clears an existing value to blank when
the tester has no cert (fails gracefully). It **freezes once the WO is Complete**
(1097) — the freeze reads the WO status **fresh by id** (not via the cached
`->entity` reference), so it holds even when a child loaded before completion is
re-saved in the same request. This is the frozen snapshot the Gate 3b report/tag
reads — no double entry. _(Was an unconditional "mirror the selected tester"
until 2026-09-12; changed to fill-if-empty in `453e02c8`.)_

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

The Device **code** is `BF-` + zero-padded entity id (e.g. `BF-000005`), returned by `_backflow_device_code()`. It is set **directly** in `backflow_device_entity_insert` after the id is known, **not** via an Auto Entity Label `[id]` token. This is deliberate: the `cabb8a6e` sentinel bug showed that AEL's `[id]` token is unresolved during presave-on-insert, leaving the literal `%AutoEntityLabel: <uuid>%` sentinel stuck in the title (and the pathauto alias) on programmatic creates. Direct-set in `hook_entity_insert` sidesteps that entirely. There is **no separate `field_device_id`** — the code is derived from the id, single source of truth.

**Title format (updated 2026-06-21):** the stored **title** is the device code with the **material model appended when a `field_material_backflow` is set at insert** — e.g. `BF-000005 - 765 Pressure Vacuum Breaker (PVB)` (the model is the referenced material's `field_name`, falling back to its label). Set **once on insert**; the title does **not** auto-update if the material reference is later edited (the code portion is always the stable id, so the title stays valid). Devices created without a material (e.g. the inline test-flow creator) title as the bare `BF-NNNNNN`.

The **clean code** (`_backflow_device_code()`, never the model-bearing title) is what's used for the QR filename, the test report, and the HB25-1077 tag (`wo_backflow_testing` `device_id`) — official artifacts carry the bare id, not the model. AEL is **not** configured on this entity (a short-lived experiment overlaying an AEL `BF#[id] - …` pattern was removed — it overrode the code-set title, made the id non-stable, and is the wrong mechanism here).

### 3.3 QR encodes the immutable canonical URL (forced), never the alias

The QR encodes `toUrl('canonical', ['alias' => TRUE])->setAbsolute()` → `https://…/property_backflow_device/{id}`. The `['alias' => TRUE]` option is **required**: without it, `toString()` substitutes the pathauto alias, which is **address-derived** (`/{property-path}/backflow/bf-nnnnnn`). A printed field tag must survive a property address correction, an owner change, and an irrigation redesign — so it must encode the one URL that never changes: the canonical route. Drupal's Redirect module (enabled; `route_normalizer_enabled: true`, `auto_redirect: true`, 301) forwards canonical → the human-readable property-path alias when a person scans it, so usability is preserved without baking a mutable path into the tag.

**Regeneration guard:** the QR (and title) are written once, in `hook_entity_insert` only, behind a guard that no-ops when the title is already `BF-NNNNNN` and `field_qr_code` is non-empty, plus a re-entrancy guard around the single follow-up save. A normal later `->save()` is an update and never re-enters the insert hook, so the QR is never reissued. Verified: re-save leaves title and QR file byte-identical.

### 3.4 Readings live on the `wo_tasks_list:backflow_testing` child, not the WO bundle

A single testing visit to a commercial site tests many assemblies. Putting readings and the device reference on the WO bundle would force one WO per assembly. Instead, one `work_order` (the billable transaction/visit) owns many `wo_tasks_list:backflow_testing` children (one per assembly), each carrying its own readings and `field_backflow_device`. This mirrors the established WO **task-list** pattern that every service uses (`lawn_mowing`, `aerating`, …) — see §3.9 for why the task list, not a bespoke compliance child.

### 3.5 `field_current_status` is a `list_string`; device *type* is a taxonomy

Status is a `list_string` so the status-log snapshots (`field_from_status` / `field_to_status`) store self-documenting values (`failed`, `repaired`) with no taxonomy-term-ID hardcoding to drift across environments. Device **type**, by contrast, is a taxonomy because the business wanted public/training landing pages and stable per-type copy for the four device types — content that belongs on a term, with a stable `field_type_code` for logic. Statuses needed neither public pages nor referencing entities, so a fielded enum is the lighter correct choice.

### 3.6 Public page exposure is minimal and access-safe

The `full` view mode is the public compliance page. `field_property`'s `entity_reference_label` is access-filtered for anonymous (anon cannot view `properties` entities), so on the `full` mode `backflow_device_entity_view` removes it and renders, **by code**, a pull-through of the property's street address + city/zip plus a static "Maintained by Brookstone Outdoors" line. It exposes **only** those address fields — no owner, contact, gate code, notes, or link to the property entity — and makes **no change to `properties` entity access**. The render is cache-tagged on the property so a later address correction shows the current value (pull-through, not snapshot). `field_property` keeps its normal label formatter on the admin/default displays for privileged users.

### 3.7 Reports and tags: one data builder, report frozen + stored, tag reprintable on demand (Gate 3b)

The compliance report PDF and the HB25-1077 service tag are both generated by Entity Print (dompdf engine) from a **single data builder**, `_wo_backflow_testing_report_data($test_child)`. Decisions and rationale:

- **One source so they can't drift.** Both outputs read the same builder — device facts (BF id, type, serial, location), test date, tester, the frozen cert snapshot, pass/fail, repairs, signature, and device QR. The tag is a strict subset (id, type, last-test date, result, QR). There is no second query that could diverge from the report.
- **Readings are filtered by `field_type_code`.** The report prints only the readings applicable to the device type (RP → its 4; PVB/SVB → air inlet + check valve) using the same `WO_BACKFLOW_TESTING_TYPE_READINGS` map as the form — no "N/A" rows for inapplicable test points.
- **Access-safe pull-through address (no owner).** The service address (street + city/zip) is read from the property by code, the same access-safe approach as the public device page (§3.6) — address yes, owner/contact never.
- **Report is the as-issued compliance artifact: generated once, frozen, stored.** At WO sign-off, for each `wo_tasks_list:backflow_testing` child of the Complete (1097) WO, the report renders to PDF and saves into that child's `field_report_pdf`. Generation is guarded like the QR: if `field_report_pdf` is already populated it is **not** regenerated on subsequent saves of a Complete WO (a compliance document must remain the as-issued snapshot). `field_report_image` stays the legacy-scan fallback only.
- **Tag is reprintable on demand, not stored.** The tag is served by a print route, `/backflow/tag/{wo_tasks_list}` (`BackflowTagController`, `_entity_access: wo_tasks_list.view`), streamed inline as `application/pdf`. It is intentionally not persisted — the stored report PDF is the retained record; the tag is a label the office can reprint any time (it reflects the device's QR, which already encodes the immutable canonical URL).
- **Images embedded as base64 data: URIs.** Signature (`teammate_profile.field_signature`) and QR (`field_qr_code`) are read via the stream wrapper and embedded as `data:` URIs, so dompdf never resolves a filesystem path — this also sidesteps odd signature filenames and degrades gracefully (missing image → blank area, no fatal). No double entry of tester identity or signature.

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

### 3.10 Compliance dashboard: anti-double-count, print-tag resolution, EVA placement (Gate 4)

The compliance dashboard (`backflow_compliance`, `/admin/operations/backflow`) is the office scheduling surface. Three decisions:

- **Anti-double-count is load-bearing and structural.** The buckets the office reads — overdue / due-soon / up-to-date — are all **gated on `status == active`**, distinguished only by `next_due`. A FAILED device has an untouched/stale `next_due` (Gate 3a leaves it alone on fail), so without the status gate it would read as overdue *and* failed. The dashboard prevents this by construction: it is one row per device, and `field_current_status` (whose Views filter is the **string** plugin, exact match — not `list_field`) is the discriminator. Verified both directions: filtering **Status = Active** returns the overdue device but **not** the failed one; **Status = Failed** returns the failed one. A failed device therefore appears in the failed bucket only, never in overdue. Standing filter excludes `replaced` (superseded devices are off the action board).
- **Print-tag resolution.** The Gate 3b tag route is keyed by the test child (`/backflow/tag/{wo_tasks_list}`), so the **per-test "Print Tag" link lives on the Test History EVA** (each row *is* a test child → the route resolves exactly). Device-list rows (the property Devices EVA and the dashboard) instead link the Device ID to the device page, because a base-`property_backflow_device` Views row cannot resolve its single most-recent test child without a row-multiplying reverse relationship. From a device row you reach the tag via Device ID → device page → Test History.
- **EVA placement uses Drupal's default content region**, not explicit footer weights. EVA extra fields default to visible, so the device/property EVAs render on their pages (verified in the device `full` + `default` modes and the property page) without editing the shared, divergent property/device **view-display** configs — keeping Gate 4 to the four new view configs only and respecting the config/sync divergence hazard (§5).

---

### 3.11 Two-axis classification: device *type* vs *use* are independent fields

Mechanical design (`field_device_type` → `backflow_device_types`: PVB/RP/DCVA/SVB) and application (`field_used_for` → `backflow_uses`: irrigation/domestic/fire/…) are **orthogonal** — the same device type serves many uses, and a given use can be met by several device types. They are deliberately two separate single-value reference fields, not one merged taxonomy.

`field_used_for` defaults: **single-value (cardinality 1) and optional.** *[Flagged for Todd — change to unlimited if multi-use devices must be recorded, or required if every device must carry a use. Confirm before relying on either.]*

Use codes live on a **dedicated `field_use_code` string**, not the device-type `field_type_code`. In Drupal a `list_string`'s `allowed_values` are a **storage-level** property shared by every vocabulary instance of that field — so reusing `field_type_code` would have merged the 14 use codes and the 4 type codes into one shared dropdown across both vocabs. A separate plain-string field keeps the two code sets independent (decided with Todd; spec had assumed `field_type_code` was a free string).

### 3.12 Content architecture — the type term is the single canonical hub (as-built 2026-09-12, `bd13361e`)

The "how it's tested" content lives **once**, on the `backflow_device_types` term (its `description` = professional procedure; `field_public_description` = public snippet + booking CTA), reached at `/services/backflow-prevention/{type}`. Materials and work orders **link** to it — they never carry their own copy (duplicate procedure text is a duplicate-content SEO negative and drifts).

- **Materials → type.** New `material.field_backflow_type` (→ `backflow_device_types`, single) on the `backflow` bundle. Backfilled by product name: RPA→RP (9), 765 PVB assembly→PVB (5), 805 DCA→DCVA (4). The 2026-09-12 backfill left the 710 AVB and 810 Dual Check null because AVB/DuC were not yet distinguished device types; **since the non-testable terms + `field_is_testable` landed (2026-09-26) they ARE mapped** — the 710 AVB assemblies (3) → **AVB**, the 810 Dual Check (2) → **DuC** (verified live 2026-09-26). **Repair parts/kits stay null** (rubber kits, check/bonnet/poppet assemblies, test cocks, ball valves, springs) — they are components, not device assemblies. No products map to SVB. The material page renders a hub link via `backflow_device_entity_view` (material branch). The nine appended RP procedure blocks (marked `<!-- rp-test-procedure -->`) + service term 1649's were **stripped** back to their original bodies (`strip_rp_procedure_markers.php`, truncate-at-marker).
- **WO test card → type.** `_backflow_device_test_card()` follows `field_backflow_device → field_device_type` and adds an **audience-aware** link (rendered outside the card anchor — no nested `<a>`): internal/tester roles get *"View {Type} testing procedure"*, clients/anon get *"{Type} — backflow testing & certification"*; both go to the hub. `user.roles` cache context added to the EVA.
- **Device label fallback.** `_backflow_device_finalize()` now appends the device **type** to the title when no product is linked (`BF-000006 - Pressure Vacuum Breaker (PVB)`); `retitle_backflow_devices.php` healed the two bare-code PVB devices (BF-000006/7). _Root cause of the "blank" labels: they had a type but no `field_material_backflow`, so the old title was the bare code._

Setup/backfill/heal are idempotent entity-API scripts (no cim): `setup_material_backflow_type.php`, `backfill_material_backflow_type.php`, `strip_rp_procedure_markers.php`, `retitle_backflow_devices.php`.

### 3.13 Certification billing — base fee + repair addition, no trip (as-built 2026-09-12, `cdf1d0ed`)

`wo_backflow_testing_entity_presave` on a Complete (1097) `work_order:backflow_testing` bills:

- **Base fee** = `business_setting.field_backflow_testing_rate` (**$90**) covering the base testing time = `field_sprinkler_tech_minimum` (**0.5 hr / 30 min**). _(field_backflow_testing_rate already existed but was never wired in — the prior code billed `field_sprinkler_technician_rate` × time with a $35 minimum. No new settings were added.)_
- **Repair addition (labor)** = time beyond the base at `field_sprinkler_technician_rate` (**$70/hr**), rounded **up** to `field_hour_billing_increment` (0.25 hr). E.g. 45 min → +$17.50 = $107.50; 50–60 min → +$35 = $125; 90 min → +$70 = $160.
- **Repair addition (materials)** = `get_backflow_testing_total_material_price()` (Σ `field_subtotal_w_markup`, i.e. marked up) — unchanged.
- **No trip fee.** `field_trip_fee` is **zeroed** for this bundle (the $90 is all-in for the visit), so the WO total and the trip line agree — even if `wo_sign_off` computed one. Re-enable later by adding trip back to the total (a per-bundle setting is the clean seam).
- Rentals + `field_billing_adjustment` still apply. Applies to **future completions only**; existing completed WOs keep their frozen totals unless re-saved.

### 3.14 Test form layout (as-built 2026-09-12, `092441a9`)

The `wo_tasks_list:backflow_testing` default form display is tidied for crews (`setup_backflow_test_form.php`, idempotent, no cim):

- **Title hidden + auto-generated.** The required Title field is removed from the form and set in code (`_wo_backflow_testing_generate_title()` → "Backflow Test — BF-000005 — 09/12/2026"), applied in the form-defaults `#entity_builder` (before validation) and finalized in presave (deterministic from device + test date). So a crew never types a title and the required label is never blank.
- **Field groups:** *Test Details* (date, tester, cert, initial, pass/fail) · **Test Readings** (the 6 gauge fields — were loose and interspersed with meta fields; per-device-type visibility still applied by `hook_form_alter`) · *Repairs & Report* (repairs + generated PDF) · **Office Admin** (collapsed: `uid` Authored-by, `created` Authored-on, `path` URL alias, `field_report_image` legacy scan — moved out of Repairs & Report).
- **Field-format note:** `field_test_date` is a `datetime` (date+time); a bare date-only value ('Y-m-d') won't render in the `datetime_default` widget (shows blank). Values must be stored full ('Y-m-d\TH:i:s'); the auto-default and `fix_backflow_test_dates.php` both write the full form.

### 3.15 Report redesign + gauge asset + cert association (as-built 2026-09-13, `cfe0d130`)

Full test-report rebuild (`backflow-test-report.html.twig`, entity_print/dompdf) + supporting data model. All setup via idempotent entity-API scripts (no cim); the report is still generated once and frozen (regenerate via `regen_backflow_reports.php`).

- **Report:** left letterhead (long logo `brookstone-logo-long.png` embedded as data-URI) with a centered "State of Colorado · Certified through {association}" line; two columns — **Test** (date, type, tester, cert #, test gauge + calibration, result) and **Backflow Assembly** (device id, type, make/model/size, serial, location, service address, next test due); a **Readings** table with a per-reading **gauge photo** column; a **Tag Photos** section; a bottom block with the device **QR** ("Scan for test history" → the device canonical URL), the "Report generated…" line, and a small right-justified signature; and a fixed **page footer** (address · phone · office@brookstoneoutdoors.com · brookstoneoutdoors.com). One page. Photos embedded via the `backflow_report_photo` image style (720px) to keep PDFs small. Company NAP/cert-line are report-data values (`_wo_backflow_testing_report_data()`), not hardcoded in Twig.
- **A — Test gauges as equipment** (`setup_test_gauges_bundle.php` + `seed_test_gauge.php`): new `equipment` bundle **`test_gauges`** reusing make/model/serial/number/status/type/pictures + a new **`field_assigned_to`** (→ user, soft assignment); `equipment_labels` auto-titles it. Calibration uses the existing **`equipment_maintenance_event`** system — a **`calibration`** option added to `field_event_type`; `field_event_date` = calibrated, `field_next_service_due_date` = expires, `field_vendor_or_mechanic` = cal company. Seeded: Mid-West Instrument 845, SN 05261902, Active, assigned Todd + calibration 06/08/2026 → 06/08/2027 (American Backflow Products Company).
- **B — Test → gauge** (`setup_backflow_test_gauge_field.php`): **`field_test_gauge`** (→ equipment[test_gauges]) on the test; defaults to the gauge whose `field_assigned_to` = the tester (form-defaults builder); a **non-blocking form-validate warning** if the gauge's latest calibration is expired as of the test date. Report shows make/model, SN (own line), and "Calibrated … valid through …" from the latest calibration event.
- **Cert association** (`setup_cert_association_fields.php`): **`field_certification_association`** on `teammate_profile` (default "ABPA") snapshotted onto the test beside the cert number (same fill-if-empty freeze-on-Complete logic). Shown as "{cert} · {association}" and drives the header cert line — data-driven so a **second certifying org** later needs no code change (each tester's cert carries its issuer).
- Deploy `cfe0d130`: 5 setup/seed scripts run on live (DB backed up `~/pre-backflow-report-20260913.sql.gz`), 10 existing reports regenerated (+ association backfilled to ABPA). Verified live (BF-000004 report: letterhead, "06-2512234 · ABPA", Febco 825YA / service address / next-due populated, QR). **2nd Brain to backfill** `field_test_gauge` + material make/model on existing devices; gauge photos populate on new tests.

## 4. As-Built Status

| Gate | Scope | Status | Commit |
|---|---|---|---|
| **Gate 1** | Config: 3 ECK types + bundles + fields, `backflow_device_types` vocab + 4 terms + landing view, `teammate_profile` fields, `backflow_testing` parity, displays, pathauto, permissions | **DONE** | `1a011f9a` |
| **Gate 2** | `backflow_device` module: `BF-NNNNNN` title, permanent canonical-URL QR, public pull-through address render; endroid/qr-code dependency | **DONE** | `153a9e2c` |
| **Gate 3a** | `wo_backflow_testing` module/form logic: cert snapshot (test-child presave), device write-back + status-log on WO completion (1097), per-type reading visibility (`hook_form_alter` on `field_type_code`), tester uid-1 form default, hybrid inline device-create button (§3.8). | **DONE** | `7608bfdc` |
| **Gate 3a — WO displays** | `backflow_testing` WO form aligned to `sprinkler_repair` + corrected service/work-todo defaults (`f12c1503`); WO view display modeled on `sprinkler_repair` with operational EVAs attached to the bundle (`627317fb`). | **DONE** | `f12c1503`, `627317fb` |
| **Gate 3a — rework** | Folded the test record from a bespoke `wo_backflow_test` entity into **`wo_tasks_list:backflow_testing`** (§2.3, §3.9): moved the full field set, re-pointed all Gate 3a logic, added the WO-page test EVA + "Add Test" button, re-pointed role permissions, and **retired `wo_backflow_test`**. | **DONE** | `a7560b62` |
| **Gate 3b** | Entity Print + dompdf. Single data builder (`_wo_backflow_testing_report_data`) feeding both outputs; per-test-child report PDF generated at sign-off into `field_report_pdf`, **frozen** (skip if populated); HB25-1077 tag as an on-demand print route `/backflow/tag/{wo_tasks_list}` (streamed, not stored); signature + QR embedded as base64 data: URIs. (SOP authoring still owed — §6.) | **DONE** | `369a9b23` |
| **Gate 4** | Views only: device-page Test History EVA + Status Log EVA (`backflow_test_history_eva`, `backflow_device_status_log_eva`); property-page Devices EVA (`backflow_property_devices_eva`); compliance dashboard `backflow_compliance` at `/admin/operations/backflow` (next-due ASC, standing filter excludes `replaced`, exposed Status + next-due-range filters). Anti-double-count §3.10. (WO-page test EVA shipped in the rework; public test-history EVA is the §5 follow-up.) | **DONE** | `0cb4c4c7` |
| **Legacy migration** | Synthesize devices from `property_ss_sources.field_ss_backflow`, idempotent | **PLANNED** | — |

---

## 5. Gotchas & Hazards (captured during build)

- **Config created via import/generator (not the UI) bit us twice.** (1) `property_backflow_device` was missing from `pathauto.settings:enabled_entity_types`, so pathauto never added the computed `path` base field → the alias type was "broken" and alias generation was a **silent no-op** (cim reported success). (2) The three new-entity form displays had malformed widget settings (`settings: null` with leaked top-level keys) from a generator indentation bug → **`ImageWidget` crashed whenever the add/edit form rendered**, invisible until a form was actually built. **Standing rule: after any programmatic config change, render the actual add/edit form AND hit a generated alias. `drush cim` success is not sufficient verification.**
- **`endroid/qr-code ^6`** is a new project dependency (+ `bacon/bacon-qr-code`, `dasprid/enum`); resolves and runs on PHP 8.3 (gd present). `composer require` re-triggered a **dead `drupal/calendar` patch URL** (pre-existing, unrelated). Add to the pre-deploy checklist alongside the `form_mode_control` / `views_bulk_operations` patch reapplication: contrib is excluded from rsync, so patches are re-applied manually on live after `composer install`.
- **The property-detail family has no standalone `/add` content route** (confirmed against `property_ss_sources` too). Devices are created via inline/embedded entity forms and programmatically — both fire `hook_entity_insert`, which is what drives title/QR generation.
- **The "Create new device" button (Gate 3a) requires the work order — and thus the property — to be selected first.** If clicked before a WO is chosen it warns ("Could not create device: select a work order…") and stays open rather than creating a device. This is an **intentional ordering constraint, not a bug**: a device created without `field_property` would have a broken pathauto alias (no property path segment), so the button refuses to create one until it can inherit the property from the parent WO.
- **`config/sync` currently diverges from live-synced active config** in hundreds of unrelated configs on this environment. All Gate 1/2 imports were done as **surgical `cim --partial` from a staging dir of only the changed files** — a full `drush cim` would clobber unrelated active config, and a blind `drush cex` would clobber manually-synced live config. Treat full cim/cex on this branch as forbidden until the divergence is reconciled. **Standing hazard; separate cleanup owed.**
- **`work_order.backflow_testing.field_service` default is stored by content UUID and is env-specific.** The committed default_value points at the "Backflow Testing and Certification" services term by `target_uuid` (`37f908e2-…`, the local term's UUID). Taxonomy terms are content, so the same term on live has a **different** UUID — on deploy, `cim` won't resolve this default and the field_service default will silently not apply on live until re-pointed. This is how **all** WO `field_service` instances behave (created on live with live's UUIDs); the wrinkle here is that the backflow term default was set in local dev. **Pre-deploy / post-deploy checklist:** after this branch deploys, confirm `work_order.backflow_testing.field_service`'s default resolves to live's Backflow Testing term and re-set it on live if blank. (Same caveat applies to any future field default that references a content entity by UUID.)
- **Taxonomy terms are content — seed scripts must run on live after deploy.** Neither `backflow_device_types` nor `backflow_uses` terms ride `cim`. Post-deploy, run BOTH on live: `web/scripts/seed_backflow_device_types.php` (now **6** type terms + their `field_is_testable` flag) and `web/scripts/seed_backflow_uses.php` (14 use terms). Both idempotent (keyed on their code field), safe to re-run. (Device-type terms seeded on live 2026-06-20; uses pending the deploy of this branch.)
- **Terms were added to `backflow_device_types` outside the seed script.** AVB and Dual Check (DuC) were created directly in production after the 2026-06-20 seed (the seed had only the four testable types, and its `allowed_values` was extended to admit the two new codes). As of 2026-09-26 the **seed script is the mechanism of record for all six terms and the `field_is_testable` flag** — it now creates/reconciles all six and must be re-run on each environment. Lesson: when a vocabulary is the source of truth for logic, add new terms through the seed, not the UI, so environments don't silently diverge.
- **`field_is_testable` is content, not config.** The boolean *field* is created by `web/scripts/setup_backflow_is_testable_field.php` (entity-API — field configs can silently skip on `cim`; sync YAMLs committed for record), but the per-term *values* are set by the seed script and do not ride `cim`. Run both on live post-deploy.
- **S3 stream-wrapper smoke-test owed on live (Gate 3b PDFs).** The report/tag embed the signature and QR by reading the file via its stream wrapper (`file_get_contents($file->getFileUri())`) and base64-encoding it. In DDEV the public files are local, so this proves the *logic* but **not** that dompdf gets the bytes from `s3://` in production (BOS files live on S3 via `s3fs`). **Post-deploy: render one real backflow report on live and open it** — confirm the signature image and QR actually embed from `s3://` before the office relies on the PDF. If they come back blank, the fix is at the stream-read layer (e.g., resolve to a temp local copy), not the template.
- **Pre-deploy patch reapplication checklist (`contrib/` is excluded from rsync — reapply manually after `composer install` on live):**
  - **`drupal/calendar`** — its `3177761` Smart Date patch URL is **dead**; `composer require` re-triggered the failure again in both Gate 2 (endroid) and Gate 3b (entity_print + dompdf). Pre-existing and unrelated to backflow — but every `composer install`/`require` re-extracts calendar unpatched. Decide whether to host the patch locally or drop it; until then, calendar runs unpatched on live.
  - **`form_mode_control`** — the `foreach … ?? []` patch (CLAUDE.md) must be reapplied.
  - **`views_bulk_operations`** — the `viewsFormValidate()` defensive patch (CLAUDE.md) must be reapplied.

---

## 6. Open Items / Deferred

- **`field_gps`** — deferred; needs the `geofield` contrib module. (Note: `geofield` is in fact already enabled, so the dependency rationale is moot — deferral is scope-only.)
- **Per-water-district test frequency** — `field_test_frequency_months` exists (default 12); per-district variation is data-entry, no schema change needed.
- **Automated reminders engine** — not built; `field_next_due_date` is the intended hook point. **Constraint (2026-09-26):** the reminders query MUST exclude devices whose type is non-testable (`field_device_type.field_is_testable` = FALSE) — AVB and dual-check devices have no test and must never be reminded. The compliance dashboard already enforces this via `backflow_device_views_query_alter` (excludes non-testable TIDs); the reminders engine must apply the same exclusion.
- **Customer portal exposure** — not built.
- **Water-district compliance export** — not built.
- **Compliance dashboard AREA filter — deferred (focused follow-up owed).** The dashboard ships Status + next-due-range exposed filters. The intended area filter (by `field_property → properties → field_zipcode_reference`) needs a Views relationship from `property_backflow_device` to `properties`; building that relationship in the Views API threw query errors (e.g. `addcslashes`/empty-table during execute), so area/zip filtering was deferred rather than ship a broken dashboard. Owed as a focused follow-up (add the property relationship, then expose the zipcode/area).
- **Public test-history EVA (§5)** — the reduced, anonymous-facing test history on the device `full` page (date/result/tester/cert/next-due only; no report/WO/repairs links) is the Gate 4 §5 follow-up, pending the field-exposure decision. A clean seam is left (the office Test History EVA is a separate display and is not loosened).
- **⚠ SOP NEEDED — now owed.** Gate 3a/3b built the human field-testing workflow (tech enters a test on the WO, signs off via `irrigation_crew`, the device updates and a frozen report PDF + reprintable HB25-1077 tag are produced). Per CLAUDE.md SOP governance this human-facing workflow needs an SOP — flag raised; SOP content is authored by Claude Chat, not written inline.
- **`field_tester` default (as-built 2026-09-12, `bdb9ccd4`)** — entered value kept; blank → current user on a human form save (form `#entity_builder`, so the programmatic New Test create doesn't stamp the WO creator); add form pre-selects current user. Cert snapshot is fill-if-empty (never overwrites/clears). _Root cause it fixed:_ tests came out cert-blank because the tester was never set — the New Test controller creates the task without one and the old uid-1 default only applied on the add form for a new entity, so controller-created tasks stayed tester-less. _Watch item:_ if the office keys in a tech's readings, they must set the tester manually; consider a required manual pick once several certified testers exist.
- **`field_used_for` form-display config is committed separately by Todd.** The field is on the device add/edit form (active, weight 5 next to Device Type) and on the default + `full` view displays. The two **view**-display configs are committed with this work; the **form**-display config is left to Todd's in-progress UI reorg (an `Office Admin` field_group, weight changes) — it'll carry `field_used_for` when he exports it.
