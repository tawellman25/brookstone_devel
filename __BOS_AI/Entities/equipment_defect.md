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
| `field_defect_photos` | image (unlimited; `public://equipment/defects`; `png gif jpg jpeg webp`; alt field on but optional, no title field; no size/resolution cap, like the other equipment photo fields) | Defect Photos | No — evidence photos of the defect itself (the broken part, the leak, the damage). General pictures of the machine stay on `equipment.field_pictures`. Added 2026-11-17 at Todd's request, **LIVE 09:38 MT** (`7409b6b4`). Uploads ride the entity's edit access — an image widget has no permission of its own — so whoever can edit a defect (administrator + site_admin today) can attach photos. |
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

## Views
- Open Defects Board: `/admin/operations/equipment/open-defects`
- Equipment Defects EVA: on all equipment entity pages

## Invariants
- A defect must only be closed when a verified maintenance event references it
- field_equipment targets ALL equipment bundles
- Defect merge: same equipment + category + open within 30 days = repeat flag

Created: April 2026
