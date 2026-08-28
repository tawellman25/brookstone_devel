# WO Material List — Import Items

**Module:** `wo_material_list_management` (alongside Clone Items)
**Shipped:** 2026-08-28

Import materials onto a WO material list from a vendor invoice/order file (or a
pasted list), matching to the BOS catalog and — optionally — **learning** the
vendor's SKUs and prices for next time.

## Entry point

**Import Items** button on the material-list management page → full page at
**`/wo_material_list/{wo_material_list}/import`** (permission `access content`).
A full page (not a modal) — the preview table is wide and the multi-step is plain
form submits. Also **Export Items** (`/…/export-items`, re-importable CSV) and
**Import Template** (`/wo_material_list/import-template`).

## Flow

1. **Upload** `.csv` / `.xlsx` or **paste** rows; optionally pick a **Supplier**
   (e.g. SiteOne) + "remember item numbers & update prices".
2. **Preview** — each row: Item # · **Description** · match status · a material
   autocomplete (pre-filled with the best guess) · qty · unit cost. Map or skip
   unmatched rows; **never auto-creates a material**.
3. **Import** → creates/merges line items and (if learning) updates the catalog.

## Column detection

Header auto-detected; a SiteOne order CSV works unchanged:
`Product ID → identifier`, `Quantity`, `Your Price → unit cost` (the `$` stripped);
`Retail Price`, `Total`, `Branch#`, `On-Hand Inventory` ignored. Also captures the
`Description`.

## Matching (`MaterialListImportService::matchRow`)

1. Material entity **ID**.
2. **Supplier item number** → `material_suppliers.field_supplier_item_number` → material.
3. **Size-aware description fuzzy**: parse the nominal size to a decimal
   (`extractSize`: 1-1/2→1.5, 1/2→0.5, 3/4→0.75, whole inches, `2"`), narrow
   candidates by the canonical size string as BOS stores it ("1-1/2 in.") — since
   "1/2 in." is a substring of "1-1/2 in." — then rank by word-token overlap and
   keep only same-size candidates. Resolves both size and type (Coupling 1-1/2 in.
   → 1-1/2 in. Coupling).

## Cost, duplicates, learning

- **Unit cost:** the file price if present; else left empty so the existing
  `wo_material_list_management` presave fills it from `material.field_cost_integer`.
  Subtotals compute via `wo_material_item_subtotal`.
- **Duplicates** on the same list **merge** quantities into the existing line.
- **Learn** (supplier + toggle): `upsertSupplierLink()` find-or-updates the
  `material_suppliers` link for (material + supplier) with the SKU +
  `field_supplier_unit_cost`. The `material_supplier` module normalizes the SKU and
  blocks duplicate material+supplier links. So the SiteOne catalog fills in and
  reprices from real invoices; the next import auto-matches on the SKU. Idempotent.

## Files

- `src/Service/MaterialListImportService.php` — parse (CSV/XLSX/paste), match, import, upsert
- `src/Form/ImportItemsModalForm.php` — full-page multi-step (input → preview → import)
- `src/Controller/MaterialListExportController.php` — export + template CSV

## Gotchas baked in

- Multi-step import belongs on a **full page**, not a modal (cramped + flaky
  in-modal AJAX).
- Injected services in a **cacheable** form (`managed_file`) must be **declared,
  not constructor-promoted** — `DependencySerializationTrait` can't re-inject
  promoted props on unserialize. See `Governance/drupal_bos_gotchas.md`.

Pairs with the roadmap SiteOne price-ingestion — the office builds that catalog
incrementally from real jobs.
