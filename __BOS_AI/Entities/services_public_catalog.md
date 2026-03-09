# BOS Services — Public Catalog Structure

This file defines the intended **public-facing Services catalog** for the Brookstone Outdoors website.

Notes:
- Not all Services listed here are Work Order service types.
- Work Order linkage is governed by Services taxonomy fields:
  - field_work_order_service (boolean)
  - field_service_bundle (work_order bundle machine name)

Format:
- ID | Label | WO flag | bundle mapping

---

## Landscape and Lawn Care

372 | Landscape and Lawn Care | WO=0 | bundle=misc_services

### Mowing
397 | Mowing | WO=0 | bundle=mowing
- 377 | Weekly Lawn Mowing | WO=1 | bundle=lawn_mowing
- 395 | Lawn Special Mowing | WO=1 | bundle=special_mowing

### Fertilizing
367 | Fertilizing | WO=1 | bundle=fertilizing

### Trees and Shrubs
417 | Trees and Shrubs | WO=1 | bundle=fertilizing_trees_and_shrubs

### Spraying
366 | Spraying | WO=1 | bundle=spraying
- 379 | Licensed Weed Control | WO=0 | bundle=spraying
- 398 | Licensed Insect Control | WO=0 | bundle=Licensed Insect Control
- 410 | Pre-emergent | WO=1 | bundle=pre_emergent
- 1277 | Weed Control | WO=1 | bundle=weed_spraying
  - 415 | Misc. Areas | WO=0 | bundle=weed_spraying
  - 414 | Landscape Beds | WO=1 | bundle=weed_spraying
- 399 | Aspen Twig Gall | WO=1 | bundle=aspen_twig_gall
- 407 | Cooley Spruce Gall Treatment | WO=1 | bundle=cooley_spruce_gall
- 406 | Deciduous Bore Treatment | WO=1 | bundle=deciduous_bore
- 401 | Dormant Oil | WO=1 | bundle=dormant_oil
- 408 | Grub Prevention | WO=1 | bundle=grub_prevention
- 402 | Pine Needle Scale | WO=1 | bundle=-
- 400 | Pinyon Pine Ips Beetle | WO=1 | bundle=pinyon_pine_ips_beetle
- 405 | Trunk Bore Prevention | WO=1 | bundle=trunk_bore

### Yard Cleanup
376 | Yard Cleanup | WO=0 | bundle=-
- 411 | Spring Cleanup | WO=1 | bundle=spring_cleanup
- 413 | Fall Cleanup | WO=1 | bundle=fall_cleanup

### Aeration
389 | Aeration | WO=1 | bundle=aerating

### Dethatching
404 | Dethatching | WO=1 | bundle=dethatching

### Pruning
388 | Pruning | WO=1 | bundle=-
- 412 | Summer Pruning | WO=1 | bundle=summer_pruning
- 416 | Weed Pulling in Shrubs | WO=1 | bundle=weed_pulling

### Deer Prevention
409 | Deer Prevention Wire | WO=1 | bundle=deer_prevention

### Misc Services
390 | Misc Services | WO=1 | bundle=misc_services

---

## Landscaping

364 | Landscaping | WO=1 | bundle=landscaping
- 374 | New Landscapes | WO=0 | bundle=landscaping
- 380 | Upgrades | WO=0 | bundle=landscaping
- 370 | Design | WO=1 | bundle=landscaping
- 384 | Patios | WO=0 | bundle=landscaping
- 383 | Retaining Walls | WO=0 | bundle=landscaping
- 385 | Rock Work | WO=0 | bundle=landscaping
- 378 | Water Features | WO=0 | bundle=landscaping
- 394 | Tree & Shrub Planting | WO=0 | bundle=landscaping
- 386 | Sodding | WO=0 | bundle=landscaping
- 381 | Hydro Seeding | WO=0 | bundle=landscaping
- 382 | Xeriscaping | WO=0 | bundle=landscaping
- 387 | And More | WO=0 | bundle=landscaping

---

## Sprinkler Systems

365 | Sprinkler Systems | WO=0 | bundle=sprinkler_installation
- 1649 | Backflow Testing and Certification | WO=1 | bundle=backflow_testing
- 393 | Check Up | WO=1 | bundle=sprinkler_check_up
- 371 | Design | WO=1 | bundle=sprinkler_design
- 392 | Installation | WO=1 | bundle=sprinkler_installation
- 368 | Repair | WO=1 | bundle=sprinkler_repair
- 375 | Spring Start Up | WO=1 | bundle=sprinkler_start_up
- 369 | Winterizing | WO=1 | bundle=sprinkler_winterizing

---

## Lighting

1505 | Lighting | WO=0 | bundle=-
- 1647 | Landscape Lighting | WO=1 | bundle=landscape_lighting
- 1648 | Exterior Lighting | WO=1 | bundle=exterior_lighting
- 396 | Holiday Decorations | WO=1 | bundle=christmas_decorations

---

## Snow Removal

373 | Snow Removal | WO=1 | bundle=snow_removal

---

## Internal Only (Not Public)

403 | In House Task | WO=1 | bundle=in_house_tasks

---

## Catalog Integrity Notes (Follow-up Cleanup)

- 402 | Pine Needle Scale has WO=1 but bundle is missing.
- 388 | Pruning has WO=1 but bundle is missing.
- 398 | Licensed Insect Control has a non-machine bundle value but WO=0 (bundle should be blank or a valid machine name).
- Several parent/grouping services have bundle values even when WO=0; prefer blank unless used intentionally for routing/aggregation.
