# BOS Entity — supplier_ingest_config

Entity Type ID: `supplier_ingest_config`
Bundle: `config` (single bundle)
Storage: ECK
Module: `supplier_price_ingest` (Phase 3.1, 2026-05-25)

---

## Purpose

Per-supplier configuration controlling how that supplier's price feeds are parsed and routed through the ingest pipeline. One config record per supplier — the config tells the parser which CSV columns mean what, which UOM to assume when missing, which material bundles to allow into the catalog vs. send to the discovery queue, and where the fuzzy-match thresholds sit.

The config is consulted at parse and match time but is **not** the source of truth for any persisted data — it's purely behavioral configuration for the ingest pipeline.

---

## Required Relationships

| Field | Target | Cardinality | Notes |
|---|---|---:|---|
| `field_supplier` | `supplier` (bundle: `supplier`) | 1 | The supplier this config applies to. |

**Uniqueness invariant:** one `supplier_ingest_config` per supplier. Enforcement deferred to Phase 3.2 presave hook (not in 3.1). Until then, the constraint is documented but not code-enforced — a second config for the same supplier would be saveable. Office UI in 3.3+ will also pre-flight check before allowing save.

---

## Key Fields

### Activation

- `field_active` (boolean, default TRUE) — when unchecked, the ingest pipeline rejects new uploads for this supplier (and ignores their feeds in any scheduled-ingest scenarios).

### Parser configuration

- `field_column_mapping` (text_long, JSON) — see "JSON shape: column_mapping" below.
- `field_default_cost_uom` (list_string) — `each` / `case` / `box` / `bag` / `roll`. Applied to any row whose UOM column is empty or unmapped. Mirrors `material_suppliers.field_cost_unit_of_measure` allowed values.

### Match configuration

- `field_fuzzy_threshold_high` (decimal 5,2, default 90.00) — confidence ≥ this auto-applies (Tier 3 fuzzy high).
- `field_fuzzy_threshold_med` (decimal 5,2, default 70.00) — confidence ≥ this goes to office review (Tier 3 fuzzy medium). Confidence below this lands in the discovery queue (Tier 3 fuzzy low).

### Bundle policy

- `field_bundle_policy` (text_long, JSON) — see "JSON shape: bundle_policy" below.

### Notes

- `field_notes` (text_long) — free-text per-supplier ingest notes. Use for tribal knowledge: "their export drops the header row on Mondays," "their cost UOM column is mislabeled," etc.

---

## JSON shape: `field_column_mapping`

Maps CSV header strings to BOS row-field machine names. The keys are case-insensitive when matched at parse time but stored as written. Unknown headers are ignored (logged in the dry-run report).

```json
{
  "SKU":           "field_supplier_sku",
  "Item #":        "field_supplier_sku",
  "Mfr Part #":    "field_manufacturer_item_number",
  "Manufacturer":  "field_manufacturer_name",
  "Description":   "field_description",
  "Unit Price":    "field_unit_cost",
  "UOM":           "field_cost_uom",
  "Pack Qty":      "field_pack_quantity"
}
```

Multiple CSV header strings can map to the same BOS field (e.g., both `SKU` and `Item #` → `field_supplier_sku`). The parser uses the first non-empty value in CSV column order.

Allowed target field names (Phase 3.1):

- `field_supplier_sku`
- `field_manufacturer_item_number`
- `field_manufacturer_name`
- `field_description`
- `field_unit_cost`
- `field_cost_uom`
- `field_pack_quantity`

Any other value is ignored by the parser. Schema validation of the mapping is added in Phase 3.2.

---

## JSON shape: `field_bundle_policy`

Maps material bundle machine names to ingest policy for this supplier. Controls whether a matched row creates a `material_suppliers` link (matched-only), can also create a new `material` if no match exists (discovery), both, or is excluded entirely.

```json
{
  "irrigation":       "matched_only",
  "pvc":              "both",
  "bulk_material":    "excluded",
  "annuals":          "discovery"
}
```

Allowed values:

- `matched_only` — accept rows that match an existing material; reject discovery rows (don't auto-create new materials for this bundle).
- `discovery` — accept discovery rows (auto-create materials when no match) but skip rows that already match (don't touch existing catalog).
- `both` — accept both matched and discovery rows.
- `excluded` — skip every row whose target material is in this bundle. Used for bundles where the supplier shouldn't be considered a source.

Bundles not listed in the mapping default to `matched_only`. This is the conservative default — explicit opt-in is required for discovery and exclusion.

---

## Invariants (Non-Negotiable)

1. **One config per supplier.** Enforced by Phase 3.2 presave hook (not in 3.1). Documented expectation regardless.
2. **`field_supplier` is immutable once set.** Changing supplier on an existing config would orphan the previously-meaningful config history. Phase 3.2 enforces.
3. **JSON fields must be valid JSON.** Phase 3.2 validates on save. In 3.1, malformed JSON is a soft failure — the parser ignores the config and falls back to defaults.
4. **Threshold sanity:** `field_fuzzy_threshold_high` must be ≥ `field_fuzzy_threshold_med`. Cross-field validation lands in 3.2.

---

## Deletion / Archival

- **Active configs:** deletion blocks future uploads for that supplier but does not affect any already-committed batches (those reference the supplier directly, not via the config).
- **Inactive configs (`field_active = FALSE`):** kept around as a "soft-delete" mechanism. Preferred over hard delete.
- Hard delete is permitted via admin only.

---

## Form / View Display

Form (default): 8 components — supplier + active + default UOM + thresholds → column mapping + bundle policy → notes. JSON blobs as plain textareas in Phase 3.1; structured UI (column-by-column wizard, per-bundle dropdown matrix) lands in 3.3.

View (default): all 8 visible, inline labels, JSON blobs as raw text. Dedicated config edit page lands in 3.3.

---

## Pathauto

Pattern: `/admin/materials/supplier-ingest/config/[supplier_ingest_config:field_supplier:entity:title]`

The supplier's title becomes the slug — e.g., `/admin/materials/supplier-ingest/config/siteone-grand-junction`.

---

## Permissions

`administer supplier price ingest` (defined by the `supplier_price_ingest` module).

---

## Related Entities

- `supplier_price_ingest_batch` — consults this config at parse and match time.
- `supplier` — the target the config belongs to.
- `material` (all bundles) — referenced indirectly via `field_bundle_policy` bundle names.
