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
- `field_city_description` — long text description
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

---

# BOS Entity — time_clock_entry

Entity Type ID: `time_clock_entry`
Bundle: `entry` (single bundle)
Storage: ECK
First introduced: 2025-11-29

> **Status: fully configured but not in use.** As of 2026-04-26 the
> entity type is wired up end-to-end (entity, bundle, fields, form
> display, view display, pathauto pattern, auto-label, role
> permissions) but **zero rows exist in production** and **no UI surface
> creates them**. It is a stub for "non-WO payroll punches" that has
> never been activated. Do not assume it is a live system; do not build
> against it without first deciding whether to keep it.

## Purpose (as designed)

A general teammate punch entity for time **not tied to a specific Work
Order**: shop time, training, admin work, drive time, paper time-card
data entry. The bundle's own description in config reads: *"These are
the in and out times of teammates."*

This is **distinct** from `wo_time_clock`, which records crew time
spent on a specific WO and feeds WO labor totals. `time_clock_entry`
was meant to be the bucket for everything that doesn't belong on a WO.

## Bundle: entry

### Fields

| Machine name                  | Type                    | Description                                      |
| ----------------------------- | ----------------------- | ------------------------------------------------ |
| `title`                       | base                    | Auto-generated by auto_entitylabel (see below).  |
| `uid`                         | base                    | Owner (the user who saved the row).              |
| `field_team_member`           | entity_reference → user | The teammate this punch belongs to.              |
| `field_clocking_in_out`       | list_string             | Action enum (see allowed values below).          |
| `field_clocking_in_out_date`  | datetime                | Moment of the punch.                             |
| `field_tasks`                 | string                  | Free-text description of what was being done.    |

### `field_clocking_in_out` allowed values

| Value | Label              |
| ----- | ------------------ |
| `0`   | Clocking In        |
| `1`   | Clocking Out       |
| `2`   | Manually Entered   |

The `Manually Entered` value indicates the design supported retroactive
data entry (e.g., paper time-card transcription) as a first-class
action, not just live in/out punches.

### Auto-generated title

```
[team_member:display-name] [clocking_in_out] - [clocking_in_out_date]
```

So a row would render as something like *"John Smith Clocking In - 2026-04-26 07:15"*.

### Pathauto pattern

`[team_member:url-path]/time-card/[title]` — rows would have lived
under each teammate's profile URL as `/users/<n>/time-card/<auto-label>`.

## Permissions (granted in config but unused in practice)

Configured grants on these roles:

| Role           | Granted                                                                 |
| -------------- | ----------------------------------------------------------------------- |
| `teammates`    | create / view any / view own / edit any / edit own / use default form mode |
| `supervisor`   | create / view any / view own / edit any / edit own / use default form mode |
| `administration` | create / view any / view own / edit any / edit own / publish / unpublish / use default form mode |
| `site_admin`   | full CRUD + administer fields/displays + form-mode/labels + listing access |

No role has these permissions disabled, so the absence of usage is not
an access problem — it is the absence of a UI surface and a documented
business process.

## How it is used today

**It isn't.** Verified end-to-end on 2026-04-26:

- Live row count: **0**.
- Custom module references (`grep` across `web/modules/custom/`):
  **zero**.
- Theme template references: **zero**.
- Menu links, blocks, shortcuts surfacing it: **none**.
- Views with `time_clock_entry` as the base: **none**. (The
  `wo_time_clock_entries*` views are confusingly named but operate on
  `wo_time_clock_field_data` — a name-substring collision, not a
  shared entity.)

The only way to create a row today is to manually navigate to the
canonical ECK add URL — and nothing in the UI links to it.

## Why no rows have been created

Two compounding reasons:

1. **The WO-driven flow absorbed the use case.** Crews clock in *to a
   WO* via the `work_order_timer` flag, which produces a `wo_time_clock`
   row, not a `time_clock_entry` row. Office staff edit time on the WO
   side. Nobody has needed a separate non-WO punch.
2. **No surface was ever built.** No menu link, no admin view, no block,
   no module hook ever pointed users at it. The entity was scaffolded
   but never given a way in.

## Note on the entity-type config `status` field

The entity-type config file (`eck.eck_entity_type.time_clock_entry.yml`)
has `status: false`. **This is the norm for ECK entity types in this
codebase, not a "disabled" flag** — `work_order`, `properties`,
`material`, `wo_time_clock`, and most other active ECK types are also
exported with `status: false`. Only the most recently created types
(`material_price_history`, `manual`, `site_landing_page`, `positions`,
`testimonial`) have `status: true`. So the field is **not** evidence of
intentional disablement; the entity type is fully active in the
storage manager and queryable. Its zero-row state is a usage gap, not
a system gate.

## Decision Required

The entity sits in a limbo state: configured as if real, used as if
imaginary. Pick one:

1. **Remove cleanly** — delete entity type, bundle, four field
   instances + storages, form/view display, pathauto pattern,
   auto_entitylabel setting, form_mode_control entries, and the
   permissions on all four roles. Use Drush so the config tracker
   doesn't drift.
2. **Activate deliberately** — give it a UI (menu link, admin view, or
   form route), a documented business process, and assign rows to a
   specific use case (training time, paid drive time, shop time, etc.).

Until one of those happens, treat it as documented historical intent,
not a usable feature. Do not write new code that depends on it.

## See Also

* `wo_time_clock` — the **active** labor-tracking ECK entity. All time
  tracking on BOS today flows through it. (Field naming differs:
  `field_teammate` vs `field_team_member`, `field_start_time` /
  `field_end_time` vs `field_clocking_in_out_date`.)
