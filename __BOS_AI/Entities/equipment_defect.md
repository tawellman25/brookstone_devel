# BOS Entity — Equipment Defect

Entity Type ID: `equipment_defect`
Storage: ECK

## Purpose
Track actionable equipment defects until resolved. Defects are created
from inspection findings or reported directly. A defect is not resolved
until a verified maintenance event closes it.

## Bundle
`standard` — Standard defect record

## Fields (18)

| Field | Type | Label | Required |
|---|---|---|---|
| `field_equipment` | entity_reference → equipment (all bundles) | Equipment | Yes |
| `field_source_inspection` | entity_reference → equipment_inspection | Source Inspection | No |
| `field_defect_date_reported` | datetime (date only) | Date Reported | Yes |
| `field_defect_category` | list_string | Defect Category | Yes |
| `field_defect_severity` | list_string | Defect Severity | Yes |
| `field_defect_summary` | string | Defect Summary | Yes |
| `field_defect_notes` | text_long (formatted; allowed format **`full_html` only** — Todd's call 2026-11-13 20:15 MT, after a first cut with basic_html + full_html; BOS convention, every defect-editing role has it, so no format selector shows) | Defect Notes / Repair Detail | No — longer repair notes, part numbers, repair-strategy decisions; the Summary stays the one-line description (added 2026-11-13 at Todd's request, **LIVE 20:05 MT**: the 255-char summary could not hold them, so detail was landing on the parent equipment record). Single value, no length cap (`longtext`). **Programmatic writers must set `format` (`full_html`)** — a value saved without one renders through the fallback format, which is what the inspection-workflow-written `field_resolution_notes` rows do today. |
| `field_defect_photos` | image (unlimited; `public://equipment/defects`; `png gif jpg jpeg webp`; alt field on but optional, no title field; no size/resolution cap, like the other equipment photo fields) | Defect Photos | No — evidence photos of the defect itself (the broken part, the leak, the damage). General pictures of the machine stay on `equipment.field_pictures`. Added 2026-11-17 at Todd's request, **LIVE 09:38 MT** (`7409b6b4`). Uploads ride the entity's edit access — an image widget has no permission of its own — so whoever can edit a defect — administrator + site_admin until 2026-11-17 11:07 MT, **every teammate since** (see Access) — can attach photos. |
| `field_defect_status` | list_string | Defect Status | Yes (default: open) |
| `field_repeat_issue` | boolean | Repeat Issue | No (default: 0) |
| `field_out_of_service_required` | boolean | Out of Service Required | No (default: 0) |
| `field_estimated_repair_cost` | decimal | Estimated Repair Cost | No |
| `field_actual_repair_cost` | decimal | Actual Repair Cost | No |
| `field_resolution_notes` | text_long | Resolution Notes | No |
| `field_resolved_on` | datetime (date only) | Resolved On | No |
| `field_assigned_to` | entity_reference → user | Assigned To | No |
| `field_parts_needed` | entity_reference → equipment_part (unlimited) | Parts Needed | No — Shop parts this repair will need (added 2026-11-13, see `equipment_part.md`) |

### field_defect_category values
engine, transmission, brakes, steering_front_end, tire_wheel,
lights_electrical, glass_visibility, body, suspension, cooling,
leak, abs, hydraulic, blades_deck, spray_system, paperwork,
trailer, safety, other

### field_defect_severity values
monitor, repair_soon, urgent, out_of_service

### field_defect_status values
open, scheduled, in_repair, resolved, deferred, retired_with_equipment

## Displays

First saved form + view display added **2026-11-13** (`web/scripts/equipment_parts_display_bootstrap.php`): before it the add/edit form rendered **no field widgets** (D11 behaviour without a saved display — deferred #37). Default widget/formatter per field; title/author/created hidden (auto-labelled). **2026-11-13 (later):** `field_defect_notes` placed directly below `field_defect_summary` on both displays (`text_textarea` 8 rows / `text_default` inline label; later fields shifted one weight) by `web/scripts/equipment_defect_notes_setup.php` (idempotent, keeps the sync uuids); verify: `equipment_defect_notes_verify.php` (`--no-write` on live). **2026-11-17:** `field_defect_photos` placed directly below `field_defect_notes` on both displays (`image_image`, thumbnail preview / `colorbox` — `medium` thumbnails on the page, `max_1300x1300` in the lightbox, one gallery per defect, label above — the gallery pattern of `equipment.heavy_equipment.field_pictures`; status and every later field shifted one weight, 17 stored components each, weights unique) by `web/scripts/equipment_defect_photos_setup.php` (idempotent, keeps the sync uuids, fails loudly if colorbox or a style is missing); verify: `equipment_defect_photos_verify.php` (`--no-write` on live = 30 checks; the dev run also round-trips a generated PNG through a defect page and checks the Open Defects board, 43). Both environments converged to sync exactly — partial cim had nothing to import. No `cr` was needed: the entity API clears the field-definition caches and the display saves invalidate their config tags (the live add form showed the file input on the next routed GET).

## Access (since 2026-11-17 11:07 MT, `1139fdd7`)

Every internal BOS user holds the `teammates` role ("All admin and supervisors are teammates first" — Todd, 2026-11-17), so defect access is granted there once:

| Role | view any | create | edit any | delete |
|---|---|---|---|---|
| `teammates` (every internal user) | ✅ | ✅ | ✅ — **not** "edit own": a defect belongs to the machine, not to whoever logged it | ❌ |
| `site_admin` | ✅ (`bypass eck entity access`) | ✅ | ✅ | ✅ |
| `administrator` | ✅ (Drupal admin role) | ✅ | ✅ | ✅ |

Type-level + bundle-level (`standard`) permission pair (ECK + `eck_bundle_permissions`), the shape BOS's other ECK grants use. `supervisor`, `administration` and `site_assistant` carry no defect permission of their own — they have it as teammates. Photos ride edit access (an image widget has no permission of its own). Notes: `teammates` already held `use text format full_html`, the field's only format.

**Crew entry point:** the "Defects" EVA (`views.view.equipment_defects_eva`) on every equipment page is open to `teammates` and carries the header button **"Report a problem with this machine"** → `/admin/content/equipment_defect/add/standard?edit[field_equipment][widget][0][target_id]=<equipment id>&destination=/equipment/<equipment id>` — `prepopulate` fills the machine (the same pattern as the "Change Equipment Status" button in `equipment_status_updates`), shown even when the machine has no defects yet. Existing defects link to `/equipment_defect/<id>`, whose Edit tab (olivero tabs block) opens `/equipment_defect/<id>/edit`. The Open Defects board stays an office board (administrator, site_admin, administration, supervisor).

**Form:** everyone sees the full form — status, assigned to, costs, resolution included. A crew-side read-only guard on those fields is **deferred (#46)**: `form_mode_control` cannot provide it on BOS because it chooses the default form mode by the user's *heaviest* role and `teammates` outranks every office role (gotcha "`form_mode_control` picks the form mode by the user's HEAVIEST role"); it would be a `hook_entity_field_access` in a module, which needs a cache rebuild.

Scripts: `web/scripts/equipment_defect_teammate_access_setup.php` (idempotent, `--dry-run`, fails loudly; role config is never staged for cim — run per environment) and `equipment_defect_teammate_access_verify.php` (`--no-write` on live: 44 checks as a real crew-only teammate; dev 50 incl. a teammate-owned round-trip). Both environments: partial cim of the view had nothing to import.

## Views
- Open Defects Board: `/admin/operations/equipment/open-defects` (office roles)
- Equipment Defects EVA: on all equipment entity pages — open to `teammates` since 2026-11-17, with the "Report a problem with this machine" header button

## Invariants
- A defect must only be closed when a verified maintenance event references it — a **convention**, not enforced by the form: anyone with edit access can set `field_defect_status`, and since 2026-11-17 that is every teammate (guard deferred #46)
- field_equipment targets ALL equipment bundles
- Defect merge: same equipment + category + open within 30 days = repeat flag

Created: April 2026
