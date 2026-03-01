# BOS Entity — Services (Taxonomy)

Entity Type:
- taxonomy_term

Vocabulary ID:
- services

---

## Purpose

Services define the **canonical service catalog** used by BOS for:
- Public-facing website service pages
- Contract Sections service selection
- Work Order classification and automation

Services are the **single source of truth** for mapping:
Contract Sections → Work Order bundle types.

---

## Key Fields (Authoritative)

### field_work_order_service (boolean)
Meaning:
- TRUE: This Service represents an actual Work Order service type (execution-capable).
- FALSE: This Service is public-facing and/or a grouping/category only (not a Work Order type).

Rule:
- If field_work_order_service = TRUE, then field_service_bundle must be populated and valid.

---

### field_service_bundle (string)
Meaning:
- Stores the `work_order` bundle machine name used for execution.

Rules:
- Must exactly match a valid `work_order` bundle machine name.
- Must be populated for all Services where field_work_order_service = TRUE.
- Must not contain labels or human text; machine name only.

---

## How Services Drive BOS

### Contract Sections
- Contract Sections must reference exactly one Service term via `field_service`.
- Contract Sections should restrict selection to Services where:
  - field_work_order_service = TRUE
- Mapping flow:
  Contract Section → Service.term.field_service_bundle → Work Order bundle

### Work Orders
Invariant:
- work_order.bundle must equal work_order.field_service.term.field_service_bundle
  (unless explicitly documented as an exception)

---

## Current Service Inventory (Snapshot)

Format:
term_id | label | WO flag | bundle mapping

372 | Landscape and Lawn Care | WO=0 | bundle=misc_services
397 | Mowing | WO=0 | bundle=mowing
377 | Weekly Lawn Mowing | WO=1 | bundle=lawn_mowing
395 | Lawn Special Mowing | WO=1 | bundle=special_mowing
367 | Fertilizing | WO=1 | bundle=fertilizing
417 | Trees and Shrubs | WO=1 | bundle=fertilizing_trees_and_shrubs
366 | Spraying | WO=1 | bundle=spraying
379 | Licensed Weed Control | WO=0 | bundle=spraying
398 | Licensed Insect Control | WO=0 | bundle=Licensed Insect Control
410 | Pre-emergent | WO=1 | bundle=pre_emergent
1277 | Weed Control | WO=1 | bundle=weed_spraying
415 | Misc. Areas | WO=0 | bundle=weed_spraying
414 | Landscape Beds | WO=1 | bundle=weed_spraying
399 | Aspen Twig Gall | WO=1 | bundle=aspen_twig_gall
407 | Cooley Spruce Gall Treatment | WO=1 | bundle=cooley_spruce_gall
406 | Deciduous Bore Treatment | WO=1 | bundle=deciduous_bore
401 | Dormant Oil | WO=1 | bundle=dormant_oil
408 | Grub Prevention | WO=1 | bundle=grub_prevention
402 | Pine Needle Scale | WO=1 | bundle=-
400 | Pinyon Pine Ips Beetle | WO=1 | bundle=pinyon_pine_ips_beetle
405 | Trunk Bore Prevention | WO=1 | bundle=trunk_bore
376 | Yard Cleanup | WO=0 | bundle=-
411 | Spring Cleanup | WO=1 | bundle=spring_cleanup
413 | Fall Cleanup | WO=1 | bundle=fall_cleanup
389 | Aeration | WO=1 | bundle=aerating
404 | Dethatching | WO=1 | bundle=dethatching
388 | Pruning | WO=1 | bundle=-
412 | Summer Pruning | WO=1 | bundle=summer_pruning
416 | Weed Pulling in Shrubs | WO=1 | bundle=weed_pulling
409 | Deer Prevention Wire | WO=1 | bundle=deer_prevention
390 | Misc Services | WO=1 | bundle=misc_services
364 | Landscaping | WO=1 | bundle=landscaping
374 | New Landscapes | WO=0 | bundle=landscaping
380 | Upgrades | WO=0 | bundle=landscaping
370 | Design | WO=1 | bundle=landscaping
384 | Patios | WO=0 | bundle=landscaping
383 | Retaining Walls | WO=0 | bundle=landscaping
385 | Rock Work | WO=0 | bundle=landscaping
378 | Water Features | WO=0 | bundle=landscaping
394 | Tree & Shrub Planting | WO=0 | bundle=landscaping
386 | Sodding | WO=0 | bundle=landscaping
381 | Hydro Seeding | WO=0 | bundle=landscaping
382 | Xeriscaping | WO=0 | bundle=landscaping
387 | And More | WO=0 | bundle=landscaping
365 | Sprinkler Systems | WO=0 | bundle=sprinkler_installation
1649 | Backflow Testing and Certification | WO=1 | bundle=backflow_testing
393 | Check Up | WO=1 | bundle=sprinkler_check_up
371 | Design | WO=1 | bundle=sprinkler_design
392 | Installation | WO=1 | bundle=sprinkler_installation
368 | Repair | WO=1 | bundle=sprinkler_repair
375 | Spring Start Up | WO=1 | bundle=sprinkler_start_up
369 | Winterizing | WO=1 | bundle=sprinkler_winterizing
1505 | Lighting | WO=0 | bundle=-
1647 | Landscape Lighting | WO=1 | bundle=lighting_landscape
1648 | Exterior Lighting | WO=1 | bundle=lighting_exterior
396 | Holiday Decorations | WO=1 | bundle=christmas_decorations
373 | Snow Removal | WO=1 | bundle=snow_removal
403 | In House Task | WO=1 | bundle=in_house_tasks

---

## Issues to Fix (Mapping Integrity)

These Services are marked WO=1 but have missing or invalid bundle mapping:

- 402 | Pine Needle Scale | WO=1 | bundle=-
  Action: set field_service_bundle to a valid `work_order` bundle machine name (or set WO=0 if it is not executed as a Work Order type).

- 388 | Pruning | WO=1 | bundle=-
  Action: if this is a grouping page only, set WO=0.
          if it is a WO service type, set field_service_bundle (likely summer_pruning or create a pruning WO bundle).

These Services have suspicious non-machine bundle values:

- 398 | Licensed Insect Control | WO=0 | bundle=Licensed Insect Control
  Action: if WO=0, bundle value should be blank or a valid machine name. Prefer blank for non-WO services.

---

## Governance Rules (Non-Negotiable)

- Services are the authority for Work Order bundle mapping.
- Any Service used by Contract Sections must have:
  - field_work_order_service = TRUE
  - a valid field_service_bundle
- field_service_bundle must always be the Work Order bundle machine name.
- Grouping/public-only Services must not be treated as Work Order services.

