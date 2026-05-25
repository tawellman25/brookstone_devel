# BOS Entity — Material

Entity Type ID:
- material

Storage:
- ECK entity type

---

## Purpose

Material entities represent items Brookstone uses, installs, applies, stocks, or purchases.

Materials are used for:
- estimating and pricing
- internal reference/cataloging
- supplier/manufacturer lookup
- Work Order material usage snapshots

Important:
- Work Orders do not use Material pricing live.
- Work Orders snapshot pricing at time of use into wo_material_list_item.field_material_cost.

---

## Bundles (Machine Name | Label)

annuals | Annuals  
brass | Brass  
copper | Copper  
decorative_rock | Rock  
electric | Electric  
galv | Galvanized  
irrigation | Irrigation  
landscape | Landscape  
misc | Miscellaneous  
pavers | Block and Pavers  
plants | Plants  
poly | Poly  
pumps | Pumps  
pvc | PVC  
shrubs | Shrubs  
sod | Sod  
supplies | Supplies
trees | Trees
xmas | Christmas Lights
mulch | Mulch
backflow | Backflow
bulk_material | Bulk Material  *(added 2026-05-24 — non-decorative bulk: topsoil, fill dirt, compost, lime, gypsum, sulfur, non-decorative sand/gravel, soil amendments. See [material_bulk_material.md](material_bulk_material.md).)*

---

## Global Fields (Present in all bundles)

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Type
- title | string | Name (bundle varies: Name / Common Name / Variety Name)
- uid | entity_reference | Entered by / Authored by
- created | created | Entered on / Authored on
- changed | changed | Updated / Changed
- default_langcode | boolean | Default translation
- path | path | URL alias

Common inventory controls:
- field_discontinued | boolean | Discontinued
  - Indicates the material should no longer be used for new work.

- field_replaced_by | entity_reference → material | Replaced By
  - **Added 2026-05-25** as part of Phase 3.1 of the supplier-pricing-ingest pipeline. For a discontinued material, points to its current-generation equivalent. Empty when no replacement is known (the common state on day one).
  - Cardinality 1. Optional. Self-referential entity reference. Autocomplete widget revealed after `field_discontinued` on the form.
  - **Present on 17 bundles** (all hard-goods + plant bundles where discontinuation-and-replacement is a real concept): `irrigation`, `pvc`, `brass`, `copper`, `galv`, `electric`, `poly`, `pumps`, `backflow`, `landscape`, `pavers`, `supplies`, `xmas`, `plants`, `shrubs`, `trees`, `annuals`.
  - **NOT present on 5 bundles** by deliberate decision: `bulk_material`, `mulch`, `decorative_rock`, `sod`, `misc`. Rationale: bulk goods and sod don't have "discontinued part with replacement" semantics — when a topsoil source changes you re-source it, you don't redirect a discontinued SKU. `misc` is too loose — including the field would invite inappropriate uses; revisit only if a clear pattern emerges.
  - Backfill state: ships empty on day one (Phase 3.1). The apprentice's catalog-cleanup work surfaces values into it over time. Rich UI surfacing (banners, warnings on estimate-line-add) lands in Phase 3.9; for Phase 3.1 the field only carries the data, the view display renders it as an inline label.
  - **Consumed by the supplier-price-ingest matcher (Phase 3.3).** When a Tier 1 or Tier 2 match resolves to a discontinued material, the matcher reads `field_replaced_by` and retargets the row to the replacement material with confidence **95** (a slight discount from the unambiguous 100 to reflect the structural inference). If `field_replaced_by` is empty on a discontinued match, the row is tagged `skipped_discontinued` and the resolution note prompts the reviewer to consider setting `field_replaced_by` if the inbound row is in fact a replacement candidate. The matcher is single-hop: it does not chase chains. See `__BOS_AI/Modules/supplier_price_ingest.md` for the full discontinued-handling decision tree.

- field_price_updated | boolean | Price Updated
  - Internal flag for price maintenance workflow.

Supplier references:
- field_suppliers | entity_reference | Supplier
  - One material may have multiple suppliers.

Invariant:
- If a material is used for Work Order costing, it must have a usable unit cost/price source field for that bundle (see “Pricing Source of Truth”).

---

## Pricing Source of Truth (Critical)

BOS uses Materials as the source for current/default costs and pricing.
Work Orders snapshot costs at time of use.

### Pricing fields on the material entity

The exact fields present vary by bundle:

- `field_cost_integer` (decimal, label varies “Cost” / “Cost Integer”) — **internal cost**, used by estimating and snapshotted into `wo_material_list_item`.
- `field_installed_price` (decimal, label varies “Our Installed Price” / “Price”) — **our installed/sale price**, computed from `field_cost_integer × business_setting.field_markup`.
- `field_price` (decimal) — present on `decorative_rock`, `shrubs`, `mulch` bundles only. Bulk-material price.
- `field_price_updated` (boolean) — flag for the price-maintenance workflow.
- `field_retail_price_disclaimer` (string) — public-facing disclaimer text.
- `field_unit_of_measure` (list_string) — the UOM `field_cost_integer` applies to.

### Auto-sync from material_suppliers (Critical Behavior)

`field_cost_integer` and `field_installed_price` are **NOT manually maintained** for bundles with `material_suppliers` links. They are auto-recalculated whenever a supplier link is inserted, updated, or deleted.

**Implemented in:** `material.module → material_sync_material_pricing_from_supplier_links()`

**Logic:**

1. Load all `material_suppliers` records for this material.
2. Filter out:
   - Links with no/zero `field_supplier_unit_cost`
   - Links whose effective status (override OR supplier-level) = `do_not_use`
3. Take the **MAX (most expensive)** unit cost across remaining eligible links.
4. Write that max to `field_cost_integer`.
5. If `business_setting.field_markup` is set: write `max × markup` to `field_installed_price`. Integer fields are rounded to whole numbers.
6. Save the material entity (only when values actually change).

**Why MAX:** Pricing jobs at worst-case cost protects margin if the cheaper supplier is out of stock.

**Safety:** If no eligible supplier costs exist, the material is **not zeroed out** — existing values remain.

### Snapshot rules

- `wo_material_list_item` snapshots `field_cost_integer` into `field_material_cost` at execution time.
- The snapshot field is the authority for “price at time of use” on historical work orders.

### Invariants

- Changing Material pricing must NEVER retroactively change historical Work Order totals.
- The auto-sync function MUST NOT touch any Work Order or wo_material_list_item entity.
- The cost source-of-truth chain is:
  ```
  material_suppliers.field_supplier_unit_cost (MAX, eligible only)
    → material.field_cost_integer
      → material.field_installed_price (× business markup)
        → wo_material_list_item.field_material_cost (snapshot at use)
  ```

---

## Common Product/Inventory Pattern Bundles (Hardware/Parts)

These bundles share a large common field set:
- brass, copper, electric, galv, landscape, misc, pavers, poly, pumps, pvc, xmas, irrigation (mostly)

Common fields:
- field_name | string | Product Name (or Paver Name)
- field_description | text_with_summary | Description
- field_cost_integer | decimal | Cost Integer (bundle label varies)
- field_installed_price | decimal | Our Installed Price (or Price)
- field_unit_of_measure | list_string | Unit of Measure
- field_size | string | Size (where present)
- field_carton_quantity | string | Carton Quantity (where present)
- field_discontinued | boolean | Discontinued
- field_price_updated | boolean | Price Updated
- field_supplier | entity_reference | Supplier (some bundles)
- field_suppliers | entity_reference | Supplier (many bundles)
- field_supplier_item_number | string | Supplier Item Number
- field_supplier_website_item_link | link | Supplier Website Item
- field_manufacturer | entity_reference | Manufacturer
- field_manufacturer_item_number | string | Manufacturer Item Number
- field_manufacturer_website_item | link | Manufacturer Website Item
- field_material_tags | entity_reference | Material Tags
- field_main_image | image | Main Image
- field_supporting_images | image | Supporting Images
- field_banner_images | image | Internal banner image(s)
- field_slideshow_image | image | Homepage Slideshow image
- field_front_promoted | boolean | Promoted to Home (Front) Page
- field_instructional_video | entity_reference | Instructional Video
- field_documentation | file | Documentation
- field_safety_data_sheet | file | Material Safety Data Sheet (MSDS)
- field_retail_price_disclaimer | string | Retail Price Disclaimer
- field_subheader_text | string | Subheader Text

Invariants:
- If a bundle uses unit-of-measure-based costing, field_unit_of_measure must be populated.
- If used for stocked costing, field_cost_integer must be populated (or the bundle’s equivalent cost field).

---

## Bundle Extensions (Unique Fields)

### decorative_rock (Rock)
Inventory + bulk-material fields:
- feeds_item | feeds_item | Feeds item
- field_price | decimal | Price
- field_quantity_in_stock | integer | Quantity in Stock
- field_last_restocked_date | timestamp | Last Restocked Date
- field_lead_time | integer | Lead Time
- field_est_wt_per_yard | integer | Est Wt Per Yard
- field_yard_per_ton | decimal | Yard Per Ton
- field_rock_type | entity_reference | Rock Type

### pumps (Pumps)
Pump specification fields:
- field_pump_discharge_size | list_string | Discharge Size
- field_pump_suction_size | list_string | Suction Size
- field_pump_size | list_string | Horse Power
- field_pump_phase | list_string | Phase
- field_pump_volts | list_string | Volts
- field_pump_model_number | string | Model Number

### shrubs (Shrubs)
Plant/inventory fields:
- field_bloom_time | entity_reference | Bloom Time
- field_care_instructions | text_long | Care Instructions
- field_cultivar | string | Cultivar
- field_fall_color | string | Fall Color
- field_flower_color | string | Flower Color
- field_height | string | Height
- field_width | string | Width
- field_water_use | list_string | Water Use
- field_price | decimal | Price
- field_quantity_in_stock | integer | Quantity in Stock
- field_last_restocked_date | timestamp | Last Restocked Date
- field_lead_time | integer | Lead Time
- field_plant_characteristics | entity_reference | Plant Characteristics
- field_plant_color | string | Plant Color
- field_plant_family | string | Family
- field_plant_genus | string | Genus
- field_plant_species | string | Species
- field_supplier_item_number | string | Supplier Item Number
- field_supplier_website_item_link | link | Supplier Website Item

### plants (Plants)
Taxonomic fields:
- field_name | string | Common Name
- field_plant_family | string | Family
- field_plant_genus | string | Genus
- field_plant_species | string | Species
- field_suppliers | entity_reference | Supplier

### trees (Trees)
Minimal current fields:
- field_suppliers | entity_reference | Supplier

### sod (Sod)
Minimal current fields:
- field_suppliers | entity_reference | Supplier

### annuals (Annuals)
- field_plant_characteristics | entity_reference | Plant Characteristics
- field_suppliers | entity_reference | Supplier
- field_discontinued | boolean | Discontinued
- field_price_updated | boolean | Price Updated

### irrigation (Irrigation)
Notable differences vs common hardware pattern:
- field_cost_integer label differs (Cost)
- field_installed_price label differs (Price)
- (no field_supplier field present; uses field_suppliers)

### mulch (Mulch)
Cloned from decorative_rock bundle. Bulk landscape material:
- Shares decorative_rock field set (quantity in stock, lead time, est weight, yard per ton)
- field_rock_type → Rock Type taxonomy (shared with decorative_rock)

### backflow (Backflow)
Cloned from irrigation bundle. Backflow prevention devices:
- Shares irrigation field set (common hardware/parts pattern)
- Created to separate Febco backflow items from general irrigation materials
- 52 Febco items moved from irrigation → backflow via `dev_scripts/move_backflow_materials.php`

### bulk_material (Bulk Material)
**Added 2026-05-24.** Cloned from `decorative_rock`'s field profile. Holds non-decorative bulk materials sold by the cubic yard or ton:
- Same 22 shared fields as `decorative_rock` (bulk pricing shape: `field_est_wt_per_yard`, `field_yard_per_ton`, `field_unit_of_measure`, etc.)
- `field_rock_type` replaced by `field_bulk_material_type` → new `bulk_material_types` vocabulary (15 seed terms: Topsoil, Fill Dirt, Compost, Lime, Sulfur, Gypsum, Sand, Gravel, Decomposed Granite, etc., plus Other / Soil Amendment (Other) pinned to bottom)
- **No entries migrated from `decorative_rock`** as part of bundle creation — that's a deferred decision
- See [material_bulk_material.md](material_bulk_material.md) for full field list, vocabulary details, and permissions

---

## Integration With Work Orders (Authoritative)

Work Order material usage is recorded through:
- wo_material_list (child of work_order)
- wo_material_list_item (child of wo_material_list)

Stocked usage:
- wo_material_list_item.field_parts_used → material
- Snapshot unit cost → wo_material_list_item.field_material_cost
- Quantity → wo_material_list_item.field_quantity

Purchased usage:
- Uses alternate name + supplier fields on wo_material_list_item
- Still snapshots unit cost into field_material_cost

Invariant:
- Material entity pricing may change over time; historical Work Orders must remain accurate via snapshots.

---

## Deletion / Archival

Default:
- Do not delete Materials.

Preferred:
- Mark as discontinued using field_discontinued.

Hard delete:
- Only if not referenced anywhere (work orders, lists, etc.).
- Avoid hard deletes to prevent broken historical references.

---

## Issues / Notes (Observed from current schema)

- Some bundles use field_cost_integer as a decimal (label “Cost Integer”).
  - Naming is confusing; keep as-is unless you decide to refactor.
- Some bundles have both field_supplier and field_suppliers.
  - field_supplier (singular) is LEGACY — entity_reference → User. From old system where
    suppliers were expected to update their own prices. Never implemented. Marked for removal.
  - field_suppliers (plural) is CURRENT — entity_reference → supplier entity. Use this one.
  - Already removed from supplies bundle (April 2026). Remaining bundles need cleanup.
- Some plant bundles (trees/sod) are minimal and may need cost/price fields if used for job costing.
