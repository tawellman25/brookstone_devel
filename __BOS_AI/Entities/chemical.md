# BOS Entity — Chemical

Entity Type ID:
- chemical

Storage:
- ECK entity type

---

## Purpose

Chemical entities represent products Brookstone applies or uses during services, including:
- fertilizers
- herbicides
- insecticides
- fungicides
- surfactants/adjuvants
- indicators/dyes

Chemicals are used for:
- compliance documentation (EPA, labels, SDS)
- estimating and current/default unit cost reference
- Work Order chemical usage snapshots and totals (via wo_chemicals_used)

Important:
- Work Orders must not use Chemical pricing live.
- Work Orders snapshot pricing at time of use into wo_chemicals_used.field_chemical_cost.

---

## Bundles (Machine Name | Label)

fertilizer | Fertilizer  
fungicide | Fungicide  
herbicide | Herbicide  
indicator | Indicator  
insecticide | Insecticide  
surfactant | Surfactant  

---

## Global Fields (Present in all bundles)

System/base:
- id | integer | ID
- uuid | uuid | UUID
- langcode | language | Language
- type | entity_reference | Type
- title | string | (bundle label varies; used as display title)
- created | created | Authored/Entered on
- changed | changed | Changed/Updated
- default_langcode | boolean | Default translation
- path | path | URL alias

Core chemical identity:
- field_name | string | Name
- field_description | text_long | Description
- field_material_form | list_string | Material Form
- field_applications | list_string | Applications

Supplier/manufacturer:
- field_manufacturer | entity_reference | Manufacturer
- field_supplier | entity_reference | Supplier

Pricing (current/default):
- field_material_cost | decimal | Material Cost (Per Each)
  - Current/default unit cost used for snapshotting onto Work Orders.

Unit of measure:
- field_unit_of_measure_fertilizer | list_string | Unit of Measure
  - Must align with how wo_chemicals_used calculates subtotals (per unit, per gallons, etc.).

Invariants:
- field_material_cost is required for any chemical used in wo_chemicals_used.
- Unit of measure must be consistent with dosage/rate calculations.
- Chemical identity must be stable over time; do not reuse chemicals for different products.

---

## Compliance & Safety Fields (Present in most bundles)

Most bundles include:
- field_epa_number | string | EPA Number
- field_signal_word | entity_reference | Signal Word
- field_safety_data_sheet | file | Material Safety Data Sheet (MSDS)
- field_product_label_file | file | Product Label File
- field_msds_sheet_update_link | link | MSDS Sheet Update Link
- field_label_pic | image | Label Pic
- field_logo | image | Logo
- field_banner_image | image | Top Banner Image

Invariants:
- If EPA Number exists for the bundle, it must be populated for regulated products.
- SDS/Label files should be preserved for audit history.
- MSDS/Label update links are allowed, but do not replace stored files if historical audit is needed.

---

## Bundle Extensions (Unique Fields)

### fertilizer (Fertilizer)
Nutrient analysis / fertilizer-specific:
- field_fertilizer_rate | decimal | Steve's Fertilizer Rate
- field_nitrogen | integer | Nitrogen (N)
- field_phosphate_p_ | integer | Phosphate (P)
- field_potassium_k_ | integer | Potassium (K)
- field_iron_fe | integer | Iron (Fe)
- field_sulfur | integer | Sulfur (S)
- field_organic | boolean | Organic

### fungicide / herbicide / insecticide
Common pesticide fields:
- field_absorption_type | entity_reference | Absorption Type
- field_organic | boolean | Organic

Noted oddity:
- herbicide has field_temp_type | string | temp_type
  - Consider documenting intended use or removing if unused.

### indicator (Indicator)
Simpler chemical type (often dye/marker):
- No EPA/label/SDS fields listed in current schema output (only core fields + logo).

### surfactant (Surfactant)
Adjuvant-like product:
- Has EPA/label/SDS fields in current schema output.

---

## Integration With Work Orders (Authoritative)

Work Order chemical usage is recorded through:
- wo_chemicals_used (child of work_order)

Key fields on wo_chemicals_used:
- field_chemical (entity_reference → chemical)
- field_chemical_cost (decimal) — snapshot unit cost at time of use
- field_subtotal (decimal)
- plus dosage/tank-mix fields per bundle

Snapshot Rule:
- When a chemical is selected on a Work Order, BOS must:
  - read chemical.field_material_cost (current/default unit cost)
  - write it into wo_chemicals_used.field_chemical_cost
  - calculate subtotals from snapshot cost + quantities

Invariant:
- Changing chemical.field_material_cost must not retroactively change completed Work Order history.

---

## Deletion / Archival

Default:
- Do not delete chemicals.

Preferred:
- Add a “discontinued” pattern if needed (not currently in schema).

Hard delete:
- Only allowed if chemical is not referenced by any Work Orders or usage records.
- Avoid deleting regulated/compliance items.

---

## Issues / Notes (Observed)

- The entity uses both title and field_name.
  - field_name should be treated as the authoritative product name.
- Some bundles include “Authored” language, others “Entered” language. This is cosmetic only.
- field_unit_of_measure_fertilizer naming is awkward for non-fertilizer bundles, but keep consistent unless refactoring.

