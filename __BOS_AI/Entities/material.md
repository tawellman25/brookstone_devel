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

Rules:
- Material bundles may store:
  - cost (internal)
  - retail price (customer-facing)
  - installed price (our installed price)
- wo_material_list_item snapshots unit cost into:
  - field_material_cost (decimal)

Invariant:
- Changing Material pricing must not retroactively change historical Work Order totals.
- The Work Order snapshot field is the authority for “price at time of use”.

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
