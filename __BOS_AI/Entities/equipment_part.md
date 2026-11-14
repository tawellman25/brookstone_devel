# BOS Entity — Equipment Part (Parts & Consumables)

Entity Type ID: `equipment_part` · Storage: ECK · Bundle: `standard` · Module: `bos_equipment_parts`
**Built on dev and deployed LIVE 2026-11-13** from the My 2nd Brain brief ("Build a Parts & Consumables entity in BOS"). Build commit `e2b16dad`; live run 19:25–19:45 MT (record below). No parts seeded on live — the office adds them with **Add part**.

## Purpose

Internal shop stock for our **own** equipment — spark plugs, air/fuel filters, carb kits,
fuel line, trimmer line, blades, belts, oil, gearhead grease, starter cords, hardware — for
small engines, mowers, trucks and ATVs. It answers four shop questions: *what fits this
machine, where is it on the shelf, how many do we have, and what needs ordering.*

It is **not** a billable material. `material` is the catalog every estimate and work-order
material picker draws from; a part must never be offered there.

## Design decisions (settled — state the why before changing one)

| Decision | Choice | Why |
|---|---|---|
| **New entity type vs. bundle on `material`** | **New ECK entity type `equipment_part`**, one bundle `standard` | `material` carries billable semantics (WO snapshot pricing, supplier links, price history) and every WO/estimate material reference field is a picker over it — a new bundle would leak into pickers unless every target_bundles list were curated forever. BOS already models equipment satellites as their own single-bundle ECK types (`equipment_defect`, `equipment_maintenance_event`, `equipment_fuel_transaction`); parts follow that pattern. Separation is structural, not a filter. |
| Brief's `equipment_type` list (Mower, Blower, …) | **Entity reference → `equipment_types` taxonomy** (multi-value) | The fleet is already classified by that vocabulary (`equipment.field_equipment_type`); a second, coarser list would drift from it. Multi-value covers the brief's "Multiple". Two terms the brief needs were missing and were added: **ATV**, **Pole Saw**. |
| Brief's `vendor` (string) | **Entity reference → `supplier`** | BOS has 78 supplier records (Amazon, hardware stores, dealers). A free-text vendor can't be filtered or linked to ordering details. Add a missing vendor under Suppliers first. |
| Brief's `name` (string) | **The ECK `title`**, relabelled **Part Name** | One label, not two; the title is what every reference, view and alias uses. |
| Retiring a part | **ECK `status` relabelled "Active (stocked)"**, default on | BOS rule 4 — no deletion of operational history. Inactive parts stay referenced from maintenance events / defects but drop out of the active list. No role below `site_admin` can delete. |
| Reorder flag | **Stored boolean `field_needs_reorder`, computed on every save** (`On Hand ≤ Reorder Minimum`, only when a minimum > 0 is set) | Views cannot compare two fields; a stored flag is filterable, sortable and auditable. Logic in code (rule 10), data in a field. **0 = no minimum = never flagged**, so an untracked part with nothing on the shelf does not clutter the reorder list. |
| Part-number uniqueness | **Own constraint `UniquePartNumber`** (whitespace-normalised, case-insensitive, excludes self) | Core's UniqueField compares strictly — `cmr6h ` would sit beside `CMR6H`. The presave normalises whitespace the same way. |
| Added beyond the brief | `field_manufacturer` (→ `manufacturer`), `field_part_photo` (image), `field_oem_cross` multi-value | Brand matters for parts (NGK vs. Champion); a shelf photo is the fastest match; a part is usually known by several numbers. All optional. |
| Stock movement from maintenance events | **Not automatic** | `field_parts_used` is a plain reference with no quantity; decrementing stock from it would be a hidden side effect (rule 6) and wrong for "two plugs". Phase 2 (below) adds usage lines with a quantity. |

## Fields

Base fields (ECK): `title` → **Part Name** (required) · `status` → **Active (stocked)** (default on) · `uid` author · `created` / `changed`.

| Field | Type | Label | Required | Notes |
|---|---|---|---|---|
| `field_part_number` | string (64) | Part Number | Yes | Vendor/manufacturer number as printed. **Unique** (normalised). Builds the URL. |
| `field_part_category` | list_string | Category | Yes | `spark_plug` Spark Plug · `air_filter` · `fuel_filter` · `carb_kit` Carb Kit / Diaphragm · `fuel_line_primer` · `cutting_line` · `cutting_blade` · `belt_pulley` · `oil_fluid` · `gearhead_grease` · `starter_cord` · `hardware` · `other` |
| `field_manufacturer` | entity_reference → `manufacturer` | Brand / Manufacturer | No | |
| `field_oem_cross` | string (255), unlimited | OEM / Cross-Reference Numbers | No | One per line. |
| `field_part_photo` | image, unlimited | Photo | No | `equipment/parts/`, alt required. |
| `field_equipment_types` | entity_reference → taxonomy `equipment_types`, unlimited | Fits Equipment Type(s) | No | Select widget. |
| `field_fits` | entity_reference → `equipment` (all bundles except `it_equipment`), unlimited | Fits These Units | No | Drives "Parts that fit this unit" on the machine page. |
| `field_bin_location` | string (64) | Bin / Shelf Location | No | |
| `field_on_hand` | integer ≥ 0 | On Hand | No | default 0 |
| `field_reorder_min` | integer ≥ 0 | Reorder Minimum | No | default 0 = not tracked |
| `field_needs_reorder` | boolean | Needs Reorder | — | **Computed** on presave; hidden on the form. |
| `field_vendor` | entity_reference → `supplier` | Vendor | No | |
| `field_last_price` | decimal (10,2) | Last Price | No | `$` prefix |
| `field_date_priced` | datetime (date) | Date Priced | No | Displayed `m/d/Y`. |
| `field_part_notes` | text_long | Notes | No | |

Form: `field_group` details — **Part** (name, number, category, brand, cross-refs, photo) · **What it fits** · **Stock** · **Purchasing** · **Notes & status**.

### Links from the maintenance machinery

| Entity | Field | Type | Label |
|---|---|---|---|
| `equipment_maintenance_event.standard` | `field_parts_used` | entity_reference → `equipment_part`, unlimited | Parts Used |
| `equipment_defect.standard` | `field_parts_needed` | entity_reference → `equipment_part`, unlimited | Parts Needed |

> ⚠ **Surfaced while adding these:** neither `equipment_maintenance_event` nor `equipment_defect`
> had **ever** had a saved form or view display (dev and live alike), and Drupal 11 then renders
> their add/edit forms with **no field widgets at all** — title, author, date only. This build
> gives both a first display (`web/scripts/equipment_parts_display_bootstrap.php`, default
> widget/formatter per field in a working order). `equipment_inspection` (6 bundles) has the same
> gap and is left for its owner — **deferred #37**. Gotcha recorded in `drupal_bos_gotchas.md`.

## Behaviour — `bos_equipment_parts` (package: Equipment)

- `hook_entity_bundle_field_info_alter` — attaches **`UniquePartNumber`** to `field_part_number` (`src/Plugin/Validation/Constraint/`). Message names the existing part and id.
- `hook_equipment_part_presave` — normalises the part number (trim, single internal spaces); computes `field_needs_reorder`.
- `hook_equipment_view` — on every equipment page (`full` view mode, all bundles except `it_equipment`) embeds the **`equipment_parts_fit`** view (argument = machine id) under the heading **"Parts that fit this unit"**; hidden when empty. Same placement pattern as `equipment_similar`. List columns/sort are the view's — adjust in the Views UI.
- `hook_views_data_alter` — switches the `field_fits` / `field_vendor` / `field_manufacturer` reference columns from core's bare-ID `numeric` filter to core's **`entity_reference`** filter (autocomplete). Term references already get core's taxonomy select filter.
- `links.task.yml` — tabs **All parts** / **Reorder list**; `links.action.yml` — **Add part** button on both.

## Views

| View | Where | What |
|---|---|---|
| `equipment_parts` → `page_1` | `/admin/operations/equipment/parts` · admin menu **Equipment ▸ Parts & Consumables** (weight 5) | Sortable table: Part # · Part Name · Category · Equipment type · Fits units · Bin · On hand · Min · **⚠ Reorder** · Vendor · Last price · Priced · Operations. Exposed: Active (default yes) · Part name · Part # · Category · Equipment type · Fits unit (autocomplete) · Needs reorder. 50/page, items-per-page exposed. |
| `equipment_parts` → `page_2` | `/admin/operations/equipment/parts/reorder` (tab **Reorder list**) | Active parts with `needs_reorder = 1`; category / equipment-type filters. |
| `equipment_parts_fit` → `embed_1` | every equipment page | Parts whose **Fits These Units** includes the machine: Part # · Name · Category · Bin · On hand · Reorder. |

Access to the admin list: roles `administrator`, `site_admin`, `site_assistant`, `administration`, `supervisor` (view-level role access, like the fuel transactions view). The embed uses the `view any equipment_part entities` permission.

## Access (explicit — BOS rule 3)

| Role | create | edit any | view any | delete | parts list |
|---|---|---|---|---|---|
| `administrator`, `site_admin` | ✔ (admin / `bypass eck entity access`) | ✔ | ✔ | ✔ | ✔ |
| `administration`, `supervisor`, `site_assistant` | ✔ | ✔ | ✔ | ✖ | ✔ |
| `teammates` | ✖ | ✖ | ✔ (part pages + "fits this unit") | ✖ | ✖ |
| `client`, `user`, anonymous | ✖ | ✖ | ✖ | ✖ | ✖ |

Granted by `web/scripts/equipment_parts_setup.php` step 8 (role config entities are saved, so the
grants survive config operations); the sync-dir `user.role.*.yml` files are known-stale and are
**not** edited (deferred #22). `access equipment_part entity listing` is included for the three
editing roles (ECK's `/admin/content/equipment_part`).

## URLs

Pathauto `equipment/parts/[equipment_part:field_part_number]` → e.g. `/equipment/parts/cmr6h`,
`/equipment/parts/4237-120-1800`. `equipment_part` is registered in `pathauto.settings`
`enabled_entity_types` (mandatory for ECK — gotchas). ECK canonical `/equipment_part/{id}` also works.

## Build + deploy

**Config:** 47 files, listed in `web/scripts/equipment_parts_config_files.txt`, generated by
`web/scripts/equipment_parts_config_gen.php` (standalone; re-run after a spec change — uuids are kept)
and then re-exported from active so `dependencies`/`cache_metadata` are Drupal's own. Plus
`pathauto.settings.yml` (+ `equipment_part`) and `core.extension.yml` (+ `bos_equipment_parts`).

**Dev (done 2026-11-13):** `equipment_parts_setup.php` → partial cim of the 47 files + `pathauto.settings.yml` ("no changes to import" = sync equals active) → `drush cr` → `equipment_parts_verify.php` (seeds the brief's three HS 82 example parts as uid 1; checks flag, alias, uniqueness, both forms, routed GETs as supervisor-only and crew-only accounts, routes, tabs, action).

**Live — RUN 2026-11-13, 19:25–19:45 MT (Friday evening, off crew hours):**
1. `web/error_log` copied to the session scratchpad; deploy dry-run showed only the 47 config files, the module, the five scripts and the expected size changes; live deploy with the allow-listed string (`--skip-composer --skip-cr --no-maintenance`), foreground, 19:32.
2. `equipment_parts_setup.php -- --dry-run` (live already had an **ATV** term, tid 1997; Pole Saw to be created). The real run was **killed by the 1 GB per-process memory limit twice** — pass 1 died right after the entity type (`eck_entity_type` saved, tables + installed definitions complete, `needsUpdates()` false); pass 2 created the bundle, both overrides and all 17 fields and died inside the module install (module recorded in `core.extension`, `system.schema` = 8000); pass 3 did displays, two views, pattern, `pathauto.settings`, **Pole Saw tid 1999**, the four role grants, and its verify step reported every item ok. Each pass skipped what existed — this is why the script is idempotent. **Expect this on live: re-run until a pass ends with the verify block.**
3. **UUID check before the partial cim** (new rule, see gotchas "A UUID mismatch makes cim DELETE + CREATE"): a read-only compare of live active vs the deployed sync files showed all 47 identical **including UUIDs** — the setup script creates every entity *with the sync YAML's uuid*, which is what makes a later partial cim a no-op instead of a delete-and-recreate. Then the 47 files + `pathauto.settings.yml` (never `core.extension.yml`) → `drush cim --partial` → one "update pathauto.settings" (list-order normalisation, same as on dev), nothing else.
4. `drush cr` (off hours), then `/`, `/winterize`, `/colorado`, `/services/sprinkler-system` warmed sequentially — all 200.
5. `equipment_parts_verify.php -- --no-seed`: supervisor-only uid 6740 — parts list 200 (Reorder tab present), reorder tab 200, add form 200 ("Part Name"), equipment page 200 (the parts section correctly hidden while no part fits); crew-only uid 77735 — list 403, add 403. Both sibling forms carry the new reference widget. Routes, local tasks, local action registered. Watchdog clean apart from the test 403/404s. Record-level checks (flag, alias, duplicate rejection) need a part and were verified on dev only; the verify script now says so instead of failing when no CMR6H exists.

**Live runbook (as executed, for the next entity built this way):**
1. Off crew hours. Copy `web/error_log` first. Deploy code with the allow-listed deploy string (dry-run first) — ships the module + scripts.
2. `scp` nothing extra: the setup script is in `web/scripts/`. Run `drush php:script web/scripts/equipment_parts_setup.php -- --dry-run`, then for real — **re-run after each memory kill** until the verify block prints. It creates the entity type, bundle, overrides, fields, displays, views, pattern **from the deployed YAML, keeping each file's uuid**, registers pathauto, **enables the module**, adds the two terms, grants the roles. This is the documented silent-skip mitigation: every field instance exists before cim.
3. `drush php:script web/scripts/config_uuid_audit.php -- <the staged files>` → must report zero "UUID DIFFERS". Stage the 47 files + `pathauto.settings.yml` (**never `core.extension.yml`** — live's is drifted and a partial import of it uninstalls modules) → `drush cim --partial --source=…` → expect "no changes" (or only the `pathauto.settings` list-order update).
4. Cache: the module install already rebuilt the container and router. Then the targeted refresh (`library_info`, twig, assets, `rebuildContainer`) or a `cr` if off-hours; warm the four public pages.
5. `equipment_parts_verify.php -- --no-seed` on live (read-only without seeding), as a supervisor-only and crew-only account.

## Verified on dev (2026-11-13 06:10 MT)

Flag: on_hand 4/min 2 → OK; 1/2 → **⚠ Reorder**; 0/0 → OK (no minimum). Aliases generated. Duplicate `cmr6h ` rejected with the existing part named; fresh number passes. Both sibling forms show the new reference widget. Supervisor-only account: parts list 200 with the parts and "Reorder", reorder tab 200, add form 200 ("Part Name"), part page 200, equipment page 200 with "Parts that fit this unit". Crew-only: part page 200, list 403, add 403. Routes + tabs + action registered. Partial cim: no changes.

## Phase 2 — noted, not built

- **`part_kit`** — a machine's tune-up kit as a grouping (e.g. *HS 82 = CMR6H + 4237 120 1800 + 4229 007 1060*). Either a second bundle `kit` with a multi-value `field_kit_parts` → `equipment_part`, or a child entity with quantities. "Add kit to maintenance event" then expands to its parts.
- **Usage lines with quantity** — `equipment_part_usage` (part, qty, maintenance event) replacing the bare `field_parts_used`; **then** stock can be decremented traceably (rule 6) and `field_cost_parts` summed from `qty × last price`.
- **Receive stock** action (qty + price + vendor → raises On Hand, stamps Last Price / Date Priced) — the only writes the shop does daily.
- **Price history** on the `material_price_history` pattern.
- **Low-stock digest** email to the shop (reuses the notifications pattern).
- Bin labels / QR (entity_print, as backflow does).

## SOP

**MNT-PRT-001 — Shop Parts and Consumables: Add, Receive, Reorder, Retire** — drafted by Code at Todd's request 2026-11-13 (adding a part, receiving stock, using a part on a repair, the reorder review, retiring a part); entered in BOS as a Draft for Todd's review. Source: `__BOS_AI/SOPs/MNT-PRT-001/`.
