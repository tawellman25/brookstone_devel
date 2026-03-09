# BOS Entity — Services (Taxonomy)

Entity Type: taxonomy_term
Vocabulary ID: services

---

## Purpose

Services define the **canonical service catalog** used by BOS for:
- Public-facing website service pages
- Contract Sections service selection
- Work Order classification and automation
- Estimate Request routing and estimator assignment
- Estimate entity auto-creation

Services are the **single source of truth** for mapping service types to execution
bundles across all BOS entity types.

---

## Architectural Invariants (Non-Negotiable)

**The `field_service_bundle` value on a Services taxonomy term is the single source
of truth for bundle machine names across `work_order`, `estimate`, and `estimate_tasks`
entity types.**

- `work_order.bundle` must equal `field_service.term.field_service_bundle`
- `estimate.bundle` must equal `field_service.term.field_service_bundle`
- `estimate_tasks.bundle` must equal `field_service.term.field_service_bundle`

Never derive bundle names from term labels (slugification, lowercasing, etc.).
Always read `field_service_bundle` directly from the term.

---

## Complete Field Inventory

### field_service_bundle (string)
Meaning:
- Stores the bundle machine name used for `work_order`, `estimate`, and
  `estimate_tasks` entity types. This is the authoritative mapping field.

Rules:
- Must exactly match a valid bundle machine name.
- Must be populated for all Services where field_work_order_service = TRUE.
- Must not contain labels or human text — machine name only.
- Used by: EstimateRequestAutoCreator, estimate_intake module, WO creation modules.

---

### field_work_order_service (boolean)
Meaning:
- TRUE: This Service generates Work Orders (execution-capable).
- FALSE: Public-facing grouping/marketing term only — no Work Order.

Rule:
- If TRUE, field_service_bundle must be populated and valid.

---

### field_estimate_service (boolean)
Meaning:
- TRUE: This Service supports Estimate entities (estimate-capable).
- FALSE: No estimate entity will be auto-created for this service.

Rule:
- estimate_intake module checks this flag before auto-creating an Estimate.
- If FALSE or empty, auto-creation is silently skipped (no error).
- A Service can be WO-capable without being estimate-capable (e.g., snow removal,
  misc services).

---

### field_default_estimator (entity_reference → user, teammates role)
Meaning:
- The default estimator auto-assigned when an Estimate or Estimate Request is
  created for this service.

Rules:
- Optional. If empty, field_assigned_to on the resulting entities is left unset.
- Handler filter: teammates role only. No other roles are selectable in the UI.
- Default value on new terms: uid 1443 (Gerald Reeves).

Usage:
- EstimateRequestAutoCreator reads this field when auto-creating an estimate_request
  from a Contract Section (field_do_you_want = '3'). Value written to
  estimate_request.field_assigned_to.
- estimate_intake module reads this field when auto-creating an estimate entity.
  Value written to estimate.field_assigned_to.
- Setting this field triggers the estimate_notifications assignment email.

---

### field_estimate_types (entity_reference → taxonomy_term.services, multi)
Meaning:
- Self-referential. Stores allowed estimate types for this service.
- Used to restrict which estimate bundles can be selected in certain workflows.

---

### field_default_estimate_item_temp (field)
Meaning:
- Default estimate item template reference for this service.
- Drives pre-population of estimate line items on landscaping estimates.

---

### field_department (entity_reference)
Meaning:
- Department responsible for this service.
- Used for scheduling, crew assignment, and reporting grouping.

---

### field_parent_service (entity_reference → taxonomy_term.services)
Meaning:
- Parent service term in the BOS hierarchy (not necessarily the taxonomy parent).
- Used for grouping and reporting.

---

### field_parent_component (entity_reference → taxonomy_term.services)
Meaning:
- Parent component term. Used for landscaping sub-service classification.

---

### field_allowed_scope_elements (field)
Meaning:
- Allowed scope element choices for this service on Landscaping estimates.

---

### field_service_name (string)
Meaning:
- Display name override. Used when the taxonomy term label differs from the
  client-facing or crew-facing name.

---

### field_subtitle (string)
Meaning:
- Short subtitle for public-facing display.

---

### field_other_names (string)
Meaning:
- Alternate names or aliases for search and recognition.

---

### field_sop_code (string)
Meaning:
- Standard Operating Procedure code for this service.

---

### field_description (text)
Meaning:
- Full description of the service. Used for public pages and internal reference.

---

### field_list_order (field)
Meaning:
- Sort weight for display ordering in listings.

---

### field_banner_image / field_iconic_image (image/file)
Meaning:
- Public-facing images for service pages.

---

### field_home_page (boolean)
Meaning:
- TRUE: Show this service on the public home page.

---

### field_home_page_slide (field)
Meaning:
- Home page slideshow configuration for this service.

---

### field_brookstone_tags (entity_reference)
Meaning:
- Internal tags for grouping and search.

---

## How Services Drive BOS

### Contract Sections
- Must reference exactly one Service term via `field_service`.
- Should restrict selection to Services where field_work_order_service = TRUE.
- Mapping flow:
  Contract Section → field_service → term.field_service_bundle → Work Order bundle

### Work Orders
Invariant:
- work_order.bundle must equal work_order.field_service.term.field_service_bundle

### Estimate Requests
- EstimateRequestAutoCreator reads field_default_estimator for auto-assignment.
- estimate_intake module reads field_estimate_service and field_service_bundle
  to determine whether and what kind of Estimate to auto-create.

### Estimates
Invariant:
- estimate.bundle must equal estimate.field_service.term.field_service_bundle
  (via estimate_request.field_service)

### Estimate Tasks (planned)
Invariant:
- estimate_tasks.bundle must equal the same field_service_bundle value.

---

## Taxonomy Structure

Services uses a hierarchical taxonomy. Key structural notes:

- **Top-level terms**: Major service categories (Landscaping, Sprinkler Systems,
  Snow Removal, etc.)
- **Child terms**: Specific WO-capable services under each category
- **Landscaping children**: All sub-services (Patios, Outdoor Kitchens, Hardscapes,
  Sod, etc.) have field_service_bundle = 'landscaping'. They share the WO bundle
  but are distinct for reporting and estimate tracking purposes.

### Depth Rule
Currently all WO-capable terms are direct children of their category term.
Grandchildren (2nd-level nesting) are not used for WO-capable terms.

If grandchildren are introduced:
- Add field_service_root (entity_reference → services) to all affected terms
- Set field_service_root = top-level category term
- Update entity reference views to filter by field_service_root instead of
  parent term (avoids TID drift and supports unlimited depth)

---

## Current Service Inventory

Format: tid | label | WO | EST | bundle

### Landscape and Lawn Care
372 | Landscape and Lawn Care     | WO=0 | EST=0 | bundle=misc_services
397 | Mowing                      | WO=0 | EST=0 | bundle=mowing
377 | Weekly Lawn Mowing          | WO=1 | EST=1 | bundle=lawn_mowing
395 | Lawn Special Mowing         | WO=1 | EST=1 | bundle=special_mowing
367 | Fertilizing                 | WO=1 | EST=1 | bundle=fertilizing
417 | Trees and Shrubs            | WO=1 | EST=1 | bundle=fertilizing_trees_and_shrubs
389 | Aeration                    | WO=1 | EST=1 | bundle=aerating
404 | Dethatching                 | WO=1 | EST=1 | bundle=dethatching
388 | Pruning                     | WO=0 | EST=0 | bundle=- (grouping term — children handle execution)
412 | Summer Pruning              | WO=1 | EST=1 | bundle=summer_pruning
    | Winter Tree Pruning         | WO=1 | EST=1 | bundle=winter_pruning (child of Pruning) **[PENDING — term not yet created]**
    | Fruit Tree Pruning          | WO=1 | EST=1 | bundle=fruit_tree_pruning (child of Pruning) **[PENDING — term not yet created]**
416 | Weed Pulling in Shrubs      | WO=1 | EST=0 | bundle=weed_pulling
409 | Deer Prevention Wire        | WO=1 | EST=1 | bundle=deer_prevention
390 | Misc Services               | WO=1 | EST=0 | bundle=misc_services

### Spraying
366 | Spraying                    | WO=1 | EST=0 | bundle=spraying
379 | Licensed Weed Control       | WO=0 | EST=0 | bundle=spraying
398 | Licensed Insect Control     | WO=0 | EST=0 | bundle=Licensed Insect Control (NEEDS FIX)
410 | Pre-emergent                | WO=1 | EST=1 | bundle=pre_emergent
1277| Weed Control                | WO=1 | EST=0 | bundle=weed_spraying
415 | Misc. Areas                 | WO=0 | EST=0 | bundle=weed_spraying
414 | Landscape Beds              | WO=1 | EST=0 | bundle=weed_spraying
399 | Aspen Twig Gall             | WO=1 | EST=1 | bundle=aspen_twig_gall
407 | Cooley Spruce Gall          | WO=1 | EST=1 | bundle=cooley_spruce_gall
406 | Deciduous Bore Treatment    | WO=1 | EST=1 | bundle=deciduous_bore
401 | Dormant Oil                 | WO=1 | EST=1 | bundle=dormant_oil
408 | Grub Prevention             | WO=1 | EST=0 | bundle=grub_prevention
402 | Pine Needle Scale           | WO=1 | EST=0 | bundle=- (NEEDS FIX — see Issues)
400 | Pinyon Pine Ips Beetle      | WO=1 | EST=1 | bundle=pinyon_pine_ips_beetle
405 | Trunk Bore Prevention       | WO=1 | EST=1 | bundle=trunk_bore

### Yard Cleanup
376 | Yard Cleanup                | WO=0 | EST=0 | bundle=-
411 | Spring Cleanup              | WO=1 | EST=0 | bundle=spring_cleanup
413 | Fall Cleanup                | WO=1 | EST=0 | bundle=fall_cleanup

### Landscaping (Design-Build)
364 | Landscaping                 | WO=1 | EST=1 | bundle=landscaping
374 | New Landscapes              | WO=0 | EST=0 | bundle=landscaping
380 | Upgrades                    | WO=0 | EST=0 | bundle=landscaping
370 | Design                      | WO=1 | EST=1 | bundle=landscaping
384 | Patios                      | WO=0 | EST=1 | bundle=landscaping
383 | Retaining Walls             | WO=0 | EST=1 | bundle=landscaping
385 | Rock Work                   | WO=0 | EST=1 | bundle=landscaping
378 | Water Features              | WO=0 | EST=1 | bundle=landscaping
394 | Tree & Shrub Planting       | WO=0 | EST=1 | bundle=landscaping
386 | Sodding                     | WO=0 | EST=1 | bundle=landscaping
381 | Hydro Seeding               | WO=0 | EST=1 | bundle=landscaping
382 | Xeriscaping                 | WO=0 | EST=1 | bundle=landscaping
387 | And More                    | WO=0 | EST=0 | bundle=landscaping

### Sprinkler Systems
365 | Sprinkler Systems           | WO=0 | EST=0 | bundle=sprinkler_installation
1649| Backflow Testing            | WO=1 | EST=1 | bundle=backflow_testing
393 | Check Up                    | WO=1 | EST=0 | bundle=sprinkler_check_up
371 | Design                      | WO=1 | EST=0 | bundle=sprinkler_design
392 | Installation                | WO=1 | EST=1 | bundle=sprinkler_installation
368 | Repair                      | WO=1 | EST=0 | bundle=sprinkler_repair
375 | Spring Start Up             | WO=1 | EST=1 | bundle=sprinkler_start_up
369 | Winterizing                 | WO=1 | EST=1 | bundle=sprinkler_winterizing

### Lighting
1505| Lighting                    | WO=0 | EST=0 | bundle=-
1647| Landscape Lighting          | WO=1 | EST=0 | bundle=landscape_lighting
1648| Exterior Lighting           | WO=1 | EST=0 | bundle=exterior_lighting
396 | Holiday Decorations         | WO=1 | EST=0 | bundle=christmas_decorations

### Other
373 | Snow Removal                | WO=1 | EST=0 | bundle=snow_removal
403 | In House Task               | WO=1 | EST=0 | bundle=in_house_tasks

---

## Issues to Fix (Mapping Integrity)

### Missing or invalid bundle mapping (WO=1 but no valid bundle):
- 402 | Pine Needle Scale | WO=1 | bundle=-
  Action: Set field_service_bundle to a valid work_order bundle machine name,
  or set WO=0 if not executed as a Work Order type.

- 388 | Pruning | WO=0 | bundle=-
  Status: Confirmed grouping term. Children (Summer Pruning, Winter Tree Pruning,
  Fruit Tree Pruning) handle execution. Set WO=0 in UI if not already done.
  Winter Tree Pruning and Fruit Tree Pruning terms + WO/estimate bundles
  pending creation.

### Non-machine bundle value:
- 398 | Licensed Insect Control | WO=0 | bundle=Licensed Insect Control
  Action: If WO=0, clear the bundle field or replace with valid machine name.

---

## Governance Rules (Non-Negotiable)

1. Services are the authority for bundle mapping across work_order, estimate,
   and estimate_tasks entity types.

2. Any Service used by Contract Sections must have:
   - field_work_order_service = TRUE
   - A valid field_service_bundle (exact machine name)

3. field_service_bundle must always contain the bundle machine name only —
   no labels, no human text.

4. Grouping/public-only Services (WO=0) must not be treated as Work Order services
   and must not appear in Contract Section service selectors.

5. field_estimate_service must be explicitly set. Do not assume WO-capable services
   are also estimate-capable.

6. Never derive bundle names by slugifying term labels. Always read
   field_service_bundle directly.