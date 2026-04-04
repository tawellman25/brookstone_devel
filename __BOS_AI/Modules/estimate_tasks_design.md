# Estimate Tasks Entity Design
## `estimate_tasks` ECK Entity Type

> **Status: Draft/Planning** — This document contains incomplete sections marked with `?` placeholders. It is a design worksheet, not authoritative specification.

Fill in the **Property Sync?** column and add any **Additional Fees** or **Notes** per service.

---

| Service | Bundle Machine Name | Fields | Property Sync? | Additional Fees | Notes |
|---------|-------------------|--------|----------------|-----------------|-------|
| Aerating | `aerating` | sq_ft, estimated_time | | | |
| Aspen Twig Gall | `aspen_twig_gall` | num_trees, avg_height, gallons, estimated_time | | | |
| Backflow Testing | `backflow_testing` | num_backflows | | | |
| Cooley Spruce Gall | `cooley_spruce_gall` | num_trees, avg_height, gallons, estimated_time | | | |
| Deciduous Bore | `deciduous_bore` | num_trees, avg_height, gallons, estimated_time | | | |
| Deer Prevention | `deer_prevention` | num_trees, num_shrubs, estimated_time | | | |
| Dethatching | `dethatching` | sq_ft, estimated_time | | | |
| Dormant Oil | `dormant_oil` | num_trees, avg_height, gallons, estimated_time | | | |
| Fertilizing Lawns | `fertilizing_lawns` | sq_ft, estimated_time | | | |
| Fertilizing Trees & Shrubs | `fertilizing_trees_shrubs` | num_trees, num_shrubs, estimated_time | | | |
| Lawn Mowing | `lawn_mowing` | sq_ft, estimated_time | | | |
| Pinyon Pine Ips Beetle | `pinyon_pine_ips_beetle` | num_trees, avg_height, gallons, estimated_time | | | |
| Special Mowing | `special_mowing` | sq_ft, estimated_time | | | |
| Sprinkler Start Up | `sprinkler_start_up` | water_source, num_zones, controller_type | | | |
| Sprinkler Winterizing | `sprinkler_winterizing` | water_source, num_zones, controller_type | | | |
| Summer Pruning | `summer_pruning` | num_trees, num_shrubs, estimated_time | | | |
| Winter Pruning | `winter_pruning` | num_trees, estimated_time | | | |

---

## Field Definitions (Shared)

| Field Machine Name | Type | Notes |
|-------------------|------|-------|
| `field_estimate` | entity_reference → estimate | Required — parent estimate |
| `field_sq_ft` | decimal | Square footage |
| `field_num_trees` | integer | Number of trees |
| `field_num_shrubs` | integer | Number of shrubs |
| `field_avg_height` | decimal | Average height (feet) |
| `field_gallons` | decimal | Estimated gallons |
| `field_estimated_time` | decimal | Estimated time (hours) |
| `field_num_backflows` | integer | Number of backflow devices |
| `field_num_zones` | integer | Number of sprinkler zones |
| `field_water_source` | list_string | city / ditch |
| `field_controller_type` | list_string | automatic / manual |

---

## Property Sync Targets (fill in)

| Field on estimate_tasks | Syncs to Property Field | Notes |
|------------------------|------------------------|-------|
| `field_sq_ft` | ? | |
| `field_num_trees` | ? | |
| `field_num_shrubs` | ? | |
| `field_num_zones` | ? | |
| `field_num_backflows` | ? | |

---

## Pricing Formula Notes (fill in per service)

| Service | Formula | Additional Fees |
|---------|---------|-----------------|
| Aerating | ? | |
| Aspen Twig Gall | ? | Sprayer fee? |
| Backflow Testing | ? | Trip fee? |
| Cooley Spruce Gall | ? | Sprayer fee? |
| Deciduous Bore | ? | Sprayer fee? |
| Deer Prevention | ? | |
| Dethatching | ? | |
| Dormant Oil | ? | Sprayer fee? |
| Fertilizing Lawns | ? | |
| Fertilizing Trees & Shrubs | ? | |
| Lawn Mowing | ? | |
| Pinyon Pine Ips Beetle | ? | Sprayer fee? |
| Special Mowing | ? | |
| Sprinkler Start Up | ? | Trip fee? |
| Sprinkler Winterizing | ? | Trip fee? |
| Summer Pruning | ? | |
| Winter Pruning | ? | |
