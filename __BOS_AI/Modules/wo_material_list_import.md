# WO Material List — Import Items

**Module:** `wo_material_list_management` (alongside Clone Items)
**Shipped:** 2026-08-28

Import materials onto a WO material list from a vendor invoice/order file (or a
pasted list), matching to the BOS catalog and — optionally — **learning** the
vendor's SKUs and prices for next time.

## Action toolbar

The material-list management page shows a tidy action row (`css/actions.css`,
library `wo_material_list_management/actions`): **Add Item** (primary) ·
**Clone Items** (secondary) · **Import / Export ▾** — a `dropbutton` grouping
*Import Items*, *Import Template*, *Export Items* · **Delete Items** (destructive,
`button--danger`, pushed right).

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

Two passes (`normalizeTable`):

1. **Exact known header names** (authoritative, collision-free) — our own export +
   template headers (`identifier`, `material_name`→ignored, `supplier_item_number`
   →ignored, `quantity`, `unit_cost`) and common vendor headers (`product id`,
   `your price`, `quantity`, `description`). Header cells are canonicalized
   (punctuation/underscores → spaces) before lookup. This is what makes a
   re-imported **export round-trip correctly**.
2. **Fuzzy fallback** (only when no header is recognized) — `\b`-word matching on
   the canonicalized cells for arbitrary invoice layouts.

A SiteOne order CSV works unchanged: `Product ID → identifier`, `Quantity`,
`Your Price → unit cost` (the `$` stripped); `Retail Price`, `Total`, `Branch#`,
`On-Hand Inventory` ignored. Also captures the `Description`.

> **Round-trip bug fixed 2026-08-28.** The export header uses underscores
> (`unit_cost`, `supplier_item_number`), and the old detector used `\b` word
> boundaries — but `_` is a word character, so `\bcost\b` never matched `unit_cost`
> and `unit_cost` silently fell back to its **default column index 2 = the
> supplier_item_number column**. Re-importing an export therefore read each line's
> cost from the SKU (`429-010` → `$429,010`). The exact-header pass above is the
> fix. See `Governance/drupal_bos_gotchas.md`. One-off repair for the affected live
> list: `web/scripts/fix_import_costs.php` (CSV-driven, resets `field_material_cost`
> only on lines whose cost equals the SKU-derived garbage).

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
