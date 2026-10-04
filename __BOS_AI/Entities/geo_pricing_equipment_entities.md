# BOS Entity — city

Entity Type ID: `city`
Storage: ECK

## Purpose
- Geographic reference for cities in BOS service areas and data records.

## Bundles
`city` (single bundle)

## Required Relationships
- `field_county` → `county`
- `field_state` → `state`

## Key Fields
- `field_city_name` — city name
- `field_type` — type classification (list)
- `field_city_description` — long text description. The page BODY, and nothing else:
  it is not the card text (see below).
- `field_short_description` — **the card text on the parent county page's "Towns We
  Serve" list. VIEWS-ONLY: it is on the form, on no view display, and never renders
  on the city's own page.** Added 2026-10-04 because the card had been rendering
  `field_city_description` trimmed to 180 characters, which is the truncated-body
  anti-pattern (`Governance/ui_patterns.md`). The `county_cities` view prefers this
  field and falls back to the trimmed body where a city has not been written yet —
  12 cities, 1 written, so a hard switch would have blanked 11 live cards. The
  fallback is Views-native, so the office can see it; it comes out once the set is
  written.
- `field_meta_tags` — per-entity SEO override (title + description). **Set it.** Left
  empty it auto-generates from the first 255 characters of the body, which is how a
  Salt Lake City ZIP code reached the Montrose search result (2026-10-04).
- `field_banner_image` — banner image

## Invariants
- Reference data. Do not delete cities referenced by active records.

---

# BOS Entity — county

Entity Type ID: `county`
Storage: ECK

## Purpose
- Geographic reference for counties. Supports Delta and Montrose county service area context.

## Bundles
`county` (single bundle)

## Required Relationships
- `field_state` → `state`

## Key Fields
- `field_county_name` — county name
- `field_county_description` — long text description
- `field_county_records_search` — link to county records search
- `field_gis_map_url` — link to GIS map
- `field_banner_image` — banner image

## Invariants
- Reference data. Do not delete.

---

# BOS Entity — state

Entity Type ID: `state`
Storage: ECK

## Purpose
- Geographic reference for states. Top level of the geographic hierarchy.

## Bundles
`state` (single bundle)

## Key Fields
- `field_state_name` — full state name
- `field_abbreviation` — two-letter abbreviation
- `field_type` — type classification (list)
- `field_state_description` — long text description
- `field_banner_image` — banner image

## Invariants
- Reference data. Do not delete.

---

# BOS Entity — sq_ft_break_points

Entity Type ID: `sq_ft_break_points`
Storage: ECK

## Purpose
- Area-based pricing breakpoint tables for lawn services. Referenced from `config_pages:business_setting`.
- Each record defines a sq footage range and the rate that applies within that range.

## Bundles
- `aeration` — aerating rate breakpoints
- `dethatching` — dethatching rate breakpoints
- `overseeding_labor` — overseeding labor rate breakpoints (hours per 1,000 sq ft)
- `overseeding_seed_markup` — overseeding seed markup percentage breakpoints

## Key Fields
- `title` — breakpoint label
- `field_min_sq_ft` — minimum sq footage for this tier
- `field_max_sq_ft` — maximum sq footage for this tier
- `field_rate` — rate for this tier (decimal; meaning varies by bundle: price for aeration/dethatching, hours/1k for overseeding_labor, markup % for overseeding_seed_markup)

## Invariants
- Read by `wo_aerating` and `wo_dethatching` modules to calculate billing based on `property_landscape_details.field_turf_sq_footage`.
- Ranges must be contiguous and non-overlapping — gaps or overlaps cause billing errors.
- Do not delete active breakpoint records — billing stops working.

## Deletion / Archival
- Reference data. Modify in place; do not delete active tiers.

---

# BOS Entity — sprinkler_system_types

Entity Type ID: `sprinkler_system_types`
Storage: ECK

## Purpose
- Reference lookup for irrigation system types (e.g., drip, rotor, spray). Used on `property_sprinkler_system`.

## Bundles
`types` (single bundle)

## Key Fields
- `field_ss_type_name` — type name
- `field_crew_description` — crew-facing description
- `field_public_description` — public-facing description

## Required Relationships
- Referenced by `property_sprinkler_system.field_system_type`

## Invariants
- Reference data. Do not delete types referenced by active systems.

---

# BOS Entity — sprinkler_types

Entity Type ID: `sprinkler_types`
Storage: ECK

## Purpose
- Reference lookup for individual sprinkler head types. Used on `property_ss_zones`.

## Bundles
`types` (single bundle)

## Key Fields
- `title` — sprinkler type name
- `field_teammate_description` — crew-facing description
- `field_public_description` — public-facing description
- `field_main_image` — image of the sprinkler type

## Required Relationships
- Referenced by `property_ss_zones.field_ss_zone_sprinkler_types`

## Invariants
- Reference data. Do not delete types referenced by active zone records.

---

# BOS Entity — equipment_check_in_out

Entity Type ID: `equipment_check_in_out`
Storage: ECK

## Purpose
- Records check-in and check-out events for equipment. Tracks condition at time of check-in/out.

## Bundles
`check_in` (single bundle)

## Required Relationships
- `field_equipment_name` → `equipment`
- `uid` (base) → `user` (who checked in/out)

## Key Fields
- `field_checking_equipment` — list: checking in or checking out
- `field_equipment_condition` — condition rating at time of check-in/out (list)
- `field_note` — notes about condition or issues

## Invariants
- Append-only event log — do not edit after creation.
- Managed by `equipment_actions` module context.

## Deletion / Archival
- Do not delete. Equipment history record.

---

# BOS Entity — equipment_status_update

Entity Type ID: `equipment_status_update`
Storage: ECK

## Purpose
- Append-only status change log for equipment. Each status change creates a new entity.
- Propagated back to the equipment entity by `equipment_status_updates` module.

## Bundles
`update` (single bundle)

## Required Relationships
- `field_status_of` → `equipment`
- `field_status` → `taxonomy_term` (equipment status vocabulary)
- `uid` (base) → `user` (who made the change)

## Key Fields
- `field_status` → taxonomy term: new equipment status
- `field_reason_for_change` — reason for the status change
- `created` (base) — when the change occurred

## Invariants
- Append-only. Never edit or delete.
- `equipment_status_updates` module propagates status back to the equipment entity.

## Deletion / Archival
- Do not delete. Permanent equipment history.

