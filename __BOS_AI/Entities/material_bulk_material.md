# BOS Entity — Material bundle: `bulk_material`

Entity Type ID: `material`
Bundle Machine Name: `bulk_material`
Bundle Label: **Bulk Material**
Created: 2026-05-24

---

## Purpose

The `bulk_material` bundle holds **non-decorative bulk materials sold by the cubic yard or ton** — topsoil, fill dirt, compost, lime, gypsum, sulfur, sand, non-decorative gravel, decomposed granite, soil amendments, and similar goods that move by volume or weight rather than by piece.

This bundle was created on **2026-05-24** as part of the bulk-material catalog reorganization. **Existing `decorative_rock` entries are not migrated by this change** — that's a deferred phase decision. New bulk-material entries should land here going forward; legacy entries stay where they are until a deliberate migration pass.

### How `bulk_material` differs from `decorative_rock` and `mulch`

| Bundle | Scope | Category source |
|---|---|---|
| `decorative_rock` | River rock, pea gravel, decorative landscape stone — items chosen for **appearance** | `field_rock_type` → `rock_types` vocabulary |
| `mulch` | Wood / bark / colored mulch — items chosen for **bed coverage + appearance** | `field_rock_type` → `rock_types` vocabulary (legacy) |
| `bulk_material` | Functional bulk — soil, amendments, lime, sulfur, non-decorative aggregates — items chosen for **substrate or chemistry** | `field_bulk_material_type` → `bulk_material_types` vocabulary (new, this bundle only) |

All three share the same **bulk pricing shape** (`field_est_wt_per_yard`, `field_yard_per_ton`, `field_price`, `field_unit_of_measure`). The classification axis is what separates them.

---

## Fields (23 total)

The field profile mirrors `decorative_rock` exactly, with one swap: `field_rock_type` → `field_bulk_material_type`. Field instance settings, widgets, and view formatters were copied from `decorative_rock` so the two bundles behave as siblings.

| Field | Type | Purpose |
|---|---|---|
| `title` (base) | string | Material name (entered as Name on the form). |
| `field_name` | string | Product Name — additional naming context (carried from decorative_rock). |
| `field_bulk_material_type` | entity_reference (taxonomy) | **Required.** Categorization into `bulk_material_types` vocabulary. Primary type axis for this bundle. |
| `field_description` | text_with_summary | Long-form description. |
| `field_main_image` | image | Primary catalog image. |
| `field_banner_images` | image (multi) | Internal banner images. |
| `field_slideshow_image` | image | Homepage slideshow image. |
| `field_unit_of_measure` | list_string | Sale unit (yard, ton, bag, etc.). |
| `field_est_wt_per_yard` | integer | Estimated weight per cubic yard (lb) — used for ton↔yard conversion when ordering and billing. |
| `field_yard_per_ton` | decimal | Conversion factor for ton-priced material. |
| `field_cost_integer` | decimal | Cost (BOS's purchase cost). |
| `field_price` | decimal | Retail/installed price. |
| `field_price_updated` | boolean | Price-maintenance workflow flag. |
| `field_suppliers` | entity_reference (multi) | Primary supplier reference. Authoritative. |
| `field_supplier` | entity_reference | **DEPRECATED** — legacy single-supplier reference, kept on the form for parity with `decorative_rock` but should not be used for new entries. |
| `field_supplier_item_number` | string | Supplier SKU. |
| `field_supplier_website_item_link` | link | Supplier product page. |
| `field_quantity_in_stock` | integer | On-hand quantity. |
| `field_last_restocked_date` | timestamp | Last restock date. |
| `field_lead_time` | integer | Lead time in days. |
| `field_material_tags` | entity_reference (taxonomy) | Cross-cutting tags (`material_tags` vocabulary, shared across all material bundles). |
| `field_front_promoted` | boolean | Promote to homepage. |
| `field_discontinued` | boolean | Hide from active catalog. |
| `feeds_item` | feeds_item | Feeds-module attribution (hidden on the form display). |

### Form display order

`field_name` → `field_bulk_material_type` → `field_description` → `field_main_image` → `field_banner_images` → `field_slideshow_image` → `field_unit_of_measure` → `field_est_wt_per_yard` → `field_yard_per_ton` → `field_cost_integer` → `field_price` → `field_price_updated` → `field_suppliers` → `field_supplier` → `field_supplier_item_number` → `field_supplier_website_item_link` → `field_quantity_in_stock` → `field_last_restocked_date` → `field_lead_time` → `field_material_tags` → `field_front_promoted` → `field_discontinued`.

`feeds_item` is hidden on the form display.

### View display

Mirrors `decorative_rock`'s default view display verbatim, substituting `field_bulk_material_type` for `field_rock_type` (same formatter shape — `entity_reference_label`).

---

## `bulk_material_types` vocabulary

Vocabulary ID: `bulk_material_types`
Label: **Bulk Material Types**
Description: Categorization for bulk material catalog entries. Used as the primary type axis on the `bulk_material` bundle.

### Initial seed (15 terms)

Order spec: alphabetical at weight 0; `Soil Amendment (Other)` and `Other` pinned to the bottom by higher weights (100 / 101).

| Term | Weight | Notes |
|---|---:|---|
| Compost | 0 | |
| Decomposed Granite | 0 | Non-decorative use (paths, sub-base) — decorative-rock variants belong in `decorative_rock`. |
| Fill Dirt | 0 | Unscreened. |
| Garden Mix | 0 | Blended soil for planting beds. |
| Gravel | 0 | Non-decorative aggregate (drainage, base). |
| Gypsum | 0 | Soil amendment. |
| Iron Sulfate | 0 | Soil amendment / lawn green-up. |
| Lime | 0 | Soil pH amendment. |
| Manure (Composted) | 0 | |
| Sand | 0 | Non-decorative — masonry / leveling. |
| Screened Topsoil | 0 | |
| Sulfur | 0 | Soil pH amendment. |
| Topsoil | 0 | Unscreened. |
| Soil Amendment (Other) | 100 | Catch-all for amendments not yet promoted to their own term. |
| Other | 101 | Last resort for items that don't fit any other category. |

### Adding new terms

Two paths:

1. **One-off / single term:** `/admin/structure/taxonomy/manage/bulk_material_types/add` via the admin UI. New term lives in active config-less DB state (taxonomy terms are content) — no config export needed.
2. **Bulk / repeatable:** add the term to the `$SEEDS` array in [`web/scripts/seed_bulk_material_types.php`](../../web/scripts/seed_bulk_material_types.php) and re-run the script. The script is idempotent — existing terms are skipped, new ones are added.

When adding a term that should sort above the catch-alls, leave weight at `0`. To pin a new term to the bottom, give it weight `>= 100`.

### Vocabulary itself is config

The vocabulary definition (`taxonomy.vocabulary.bulk_material_types`) is config and IS exported to `config/sync/`. Terms are content and are NOT — `seed_bulk_material_types.php` is the canonical seeding mechanism.

---

## Permissions

Granted role-by-role to mirror `decorative_rock` exactly:

| Role | Create | Edit own | Edit any | Delete own | Delete any | View own | View any |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `site_admin` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `administration` | ✓ | ✓ | ✓ | ✓ |   | ✓ | ✓ |
| `supervisor` | ✓ | ✓ | ✓ |   |   | ✓ | ✓ |
| `site_assistant` | ✓ | ✓ | ✓ |   |   |   |   |
| `teammates` |   | ✓ | ✓ |   |   |   | ✓ |
| `client` |   |   |   |   |   |   | ✓ |
| `authenticated` |   |   |   |   |   |   | ✓ |

The `authenticated` view-any grant is inherited from `decorative_rock`'s permission shape — confirm this is desired, since it means bulk_material entries are visible to any logged-in user.

---

## Out of scope (deferred)

The following were **explicitly excluded** from this bundle creation pass:

- **Migration of existing `decorative_rock` entries** into `bulk_material`. Decisions about which existing entries (gravel, sand variants currently sitting in `decorative_rock`) belong in `bulk_material` are a separate phase.
- **Deletion of the empty `mulch` bundle.** It stays.
- **Modification of `decorative_rock` or `field_rock_type`.** Untouched.
- **Front-end displays / Views surfacing `bulk_material`.** No Views, blocks, or front-end displays were updated. That's a follow-up once the bundle has entries to show.
- **Pricing system updates.** `material_suppliers` and `material_price_history` reference materials by entity ID, not by bundle, so the new bundle is invisible to pricing as-is.

---

## Related files

- Setup script (one-off, run after `eck:clone-bundle`): [`web/scripts/setup_bulk_material_bundle.php`](../../web/scripts/setup_bulk_material_bundle.php)
- Seed terms script (idempotent, run post-`cim` on each environment): [`web/scripts/seed_bulk_material_types.php`](../../web/scripts/seed_bulk_material_types.php)
- Parent doc: [`material.md`](material.md)
- Sibling bundle (decorative): see decorative_rock section in `material.md`
- Sibling bundle (mulch): see mulch section in `material.md`
