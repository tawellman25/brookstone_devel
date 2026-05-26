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

**Uniqueness invariant:** one `supplier_ingest_config` per supplier. **Enforced as of Phase 3.2** via `hook_ENTITY_TYPE_presave` in `supplier_price_ingest.module`. Attempts to save a second config for the same supplier throw `Drupal\Core\Entity\EntityStorageException` with the conflicting config's ID in the message. The form alter surfaces this as a form error rather than a WSOD.

---

## Key Fields

### Activation

- `field_active` (boolean, default TRUE) — when unchecked, the ingest pipeline rejects new uploads for this supplier (and ignores their feeds in any scheduled-ingest scenarios).

### Parser configuration

- `field_column_mapping` (**string_long**, JSON) — see "JSON shape: column_mapping" below. **Converted from `text_long` → `string_long` on 2026-05-25.** Structured-JSON storage requires plain raw text without a text-format dependency; `text_long` routes input through a text format (and CKEditor by default), which silently mangles JSON via smart quotes, paragraph tags, and editor auto-formatting. `string_long` removes that surface area at the storage layer — any form / API / View path that reads or writes this field now gets the raw JSON string. See `__BOS_AI/Governance/drupal_bos_gotchas.md` "text_long vs string_long for structured-text fields."
- `field_default_cost_uom` (list_string) — `each` / `case` / `box` / `bag` / `roll`. Applied to any row whose UOM column is empty or unmapped. Mirrors `material_suppliers.field_cost_unit_of_measure` allowed values.

### Match configuration

- `field_fuzzy_threshold_high` (decimal 5,2, default 90.00) — confidence ≥ this auto-applies (Tier 3 fuzzy high).
- `field_fuzzy_threshold_med` (decimal 5,2, default 70.00) — confidence ≥ this goes to office review (Tier 3 fuzzy medium). Confidence below this lands in the discovery queue (Tier 3 fuzzy low).

### SKU transformations (Phase 3.10)

- `field_sku_transformations` (**string_long**, JSON, optional) — distributor-specific SKU prefix/suffix transformations applied BEFORE normalization in Tier 1 / Tier 2 matching. See "JSON shape: sku_transformations" below.

### Bundle policy

- `field_bundle_policy` (**string_long**, JSON) — see "JSON shape: bundle_policy" below. **Converted from `text_long` → `string_long` on 2026-05-25** for the same reason as `field_column_mapping` above.

### Notes

- `field_notes` (text_long) — free-text per-supplier ingest notes. Use for tribal knowledge: "their export drops the header row on Mondays," "their cost UOM column is mislabeled," etc.

---

## JSON shape: `field_column_mapping`

Top-level keys: `source_columns` (required), plus optional parser controls (`header_row`, `skip_rows_until_header`, `case_sensitive_headers`, `trim_whitespace`).

`source_columns` maps CSV header strings → one or more BOS row-field machine names. Header matching is case-insensitive at parse time but stored as written. Unknown headers are ignored (their raw values still ride along in `field_raw_data`).

**Destination shape — two forms (both valid, can be mixed across headers):**

- **1:1 (string):** the source cell is written to exactly one BOS field.
- **1:many (array of strings):** the same source cell is written to every BOS field named in the array. Use when the supplier re-uses a single column for both their SKU and the manufacturer item number — common with SiteOne.

```json
{
  "source_columns": {
    "SKU":          "field_supplier_sku",
    "Mfr Part #":   "field_manufacturer_item_number",
    "Manufacturer": "field_manufacturer_name",
    "Description":  "field_description",
    "Unit Price":   "field_unit_cost",
    "UOM":          "field_cost_uom",
    "Pack Qty":     "field_pack_quantity"
  },
  "header_row": 1,
  "case_sensitive_headers": false,
  "trim_whitespace": true
}
```

**Worked example — SiteOne (1:many):** SiteOne's catalog does not provide a separate manufacturer-item-# column; their `supplier_item_number` doubles as both their SKU and (often R-prefixed) the manufacturer item number. The matcher's strip_prefix transformation (`field_sku_transformations: {"strip_prefix":["R"]}`) handles `R15H → 15H`, but only when `field_manufacturer_item_number` is also populated on the row — otherwise the Tier-1 manufacturer-# lookup has nothing to query. The 1:many destination closes that gap:

```json
{
  "source_columns": {
    "supplier_item_number":   ["field_supplier_sku", "field_manufacturer_item_number"],
    "product_name":           "field_description",
    "manufacturer_inferred":  "field_manufacturer_name",
    "your_price":             "field_unit_cost",
    "cost_uom":               "field_cost_uom"
  },
  "header_row": 1
}
```

A CSV cell value of `R15H` lands in BOTH `field_supplier_sku` and `field_manufacturer_item_number`. The matcher then attempts Tier 1 against the (transformed) `15H`, against a BOS material whose `field_manufacturer_item_number = "15H"`.

Multiple CSV header strings can also map to the same BOS field (e.g., both `SKU` and `Item #` → `"field_supplier_sku"`). The parser uses the first non-empty value in CSV column order.

Allowed target field names:

- `field_supplier_sku`
- `field_manufacturer_item_number`
- `field_manufacturer_name`
- `field_description`
- `field_unit_cost`
- `field_cost_uom`
- `field_pack_quantity`

For the 1:many shape, every entry in the array must be one of the names above, and the array must be non-empty. Per-destination transformations are not supported — when an array is used, the source cell is written verbatim (subject only to the parser's whitespace-trim) to every named field. If you need different normalization per field, use 1:1 entries (this is not a current need; the matcher's SKU transformations operate at lookup time, not at write time).

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

## JSON shape: `field_sku_transformations` (Phase 3.10)

Optional, distributor-specific SKU transformations applied BEFORE the Tier 1 / Tier 2 normalization layer. Empirical motivation: SiteOne prefixes Rain Bird nozzle SKUs with "R" (their `R15H` is Rain Bird's native `15H`); without stripping the prefix at the lookup layer, the matcher fails to find perfectly-good Tier 1 candidates.

```json
{
  "strip_prefix": ["R"],
  "strip_suffix": []
}
```

**Shape:**

- Both keys (`strip_prefix`, `strip_suffix`) are optional. Empty arrays are valid. Both default to empty arrays when unset.
- Values are arrays of strings — not strings, not nested objects. The presave validator rejects non-string entries.
- Strips happen in the order listed; first match wins; applied ONCE (not iteratively). Stripping `R` from `RR15H` yields `R15H`, not `15H`. Avoids surprising over-strips.
- Matching is case-sensitive at this stage — the matcher's normalization layer (which runs AFTER transformation) handles case afterwards.

**Order of operations in Tier 1 / Tier 2:**

```
row.field_manufacturer_item_number              ("R15H")
  → applySkuTransformations()                    ("15H")
  → normalizeSku() — lowercase + strip ws/-/.    ("15h")
  → lookup in per-batch normalized index
  → match against BOS material #N (whose own
    field_manufacturer_item_number "15H" was
    normalized to "15h" when the index was built)
```

The same chain runs on Tier 2 supplier-SKU lookups.

**Audit trail.** When a match required transformation or normalization (not exact string equality), `field_resolution_notes` on the row gets one of:

- `Matched via mfr item # normalization: row '1806PRS' → BOS '1806-PRS'.`
- `Matched via mfr item # transformation: row 'R15H' stripped to '15H', normalized to BOS '15H'.`

Exact matches stay silent (preserves the simple-case audit signal: empty notes means clean Tier 1 hit, no drift).

**Supplier seeds — copy-paste from Chat:**

SiteOne (Pioneer):

```json
{
  "strip_prefix": ["R"],
  "strip_suffix": []
}
```

CPS / Denver Brass / other suppliers — add as their SKU drift patterns are discovered.

---

## Invariants (Non-Negotiable)

1. **One config per supplier.** Enforced by Phase 3.2 presave hook (not in 3.1). Documented expectation regardless.
2. **`field_supplier` is immutable once set.** Changing supplier on an existing config would orphan the previously-meaningful config history. Phase 3.2 enforces.
3. **JSON fields must be valid JSON.** **Enforced as of Phase 3.2** via `hook_ENTITY_TYPE_presave`. Empty values are allowed (incremental edits welcome); non-empty values must parse. See "Phase 3.2 presave validation contract" below for the full rules.
4. **Threshold sanity:** `field_fuzzy_threshold_high` must be ≥ `field_fuzzy_threshold_med`. Cross-field validation deferred to 3.3 (when the matcher consumes them).

---

## Phase 3.2 Presave Validation Contract

Implemented in `supplier_price_ingest.module` → `supplier_price_ingest_supplier_ingest_config_presave()`. Throws `Drupal\Core\Entity\EntityStorageException` on any violation; the form alter (`supplier_price_ingest_form_alter`) surfaces these as form errors.

### `field_column_mapping` validation

If the field is non-empty, validates that the JSON:

- Parses as a JSON object.
- Contains a `source_columns` key whose value is an object.
- Every value in `source_columns` is either:
  - a string in the allowed-target whitelist (1:1), OR
  - a non-empty array of strings, every one of which is in the allowed-target whitelist (1:many).
- Allowed-target whitelist: `field_supplier_sku`, `field_manufacturer_item_number`, `field_manufacturer_name`, `field_description`, `field_unit_cost`, `field_cost_uom`, `field_pack_quantity`.
- Across all destinations (string + array entries combined), maps at least one identifier (`field_supplier_sku` OR `field_manufacturer_item_number` OR `field_description`).
- Across all destinations (string + array entries combined), maps `field_unit_cost`.

Specific rejection messages:

- Empty array as a destination → "source_columns[\"…\"] is an empty array. A destination must name at least one BOS field…"
- Array entry that is not a string or not on the whitelist → "source_columns[\"…\"] array contains invalid entry \"…\". Each entry must be a non-empty string in: …"
- String destination not on the whitelist → "source_columns[\"…\"] = \"…\" is not a valid supplier_price_ingest_row field. Allowed: … (or an array of those names for 1:many destinations)."

Other keys in the JSON (`header_row`, `case_sensitive_headers`, etc.) are accepted with sensible defaults at parse time — see `IngestParser` for the defaults.

### `field_bundle_policy` validation

If the field is non-empty, validates that the JSON:

- Parses as a JSON object.
- Every key is a real `material` bundle machine name (validated against `entity_type.bundle.info.material` at save time).
- Every value is in the allowed-policy set: `matched_only`, `discovery`, `both`, `excluded`.

**Default for bundles not listed:** `matched_only`. This is the conservative default — known catalog gets matched, unknown bundles don't generate discovery-queue noise. Consumed by the matcher (3.3); the parser ignores `field_bundle_policy`.

### `field_sku_transformations` validation (Phase 3.10)

If the field is non-empty, validates that the JSON:

- Parses as a JSON object.
- Only contains the recognized top-level keys: `strip_prefix`, `strip_suffix`. Unknown keys throw with the allowed-key list.
- Each value is an array of strings (not strings, not nested objects, not arrays of mixed types). Empty arrays are valid.

The validator is permissive on order — `strip_suffix` without `strip_prefix` is fine; both keys omitted is fine; the whole field empty is fine (defaults to no-op transformation chain).

### Pre-submit guard (UX layer)

A form-alter pre-submit handler runs JSON-decode before the presave hook fires. Catches the JSON-syntax case early and routes the error to the right form field (`field_column_mapping` or `field_bundle_policy`) so the user sees focused feedback. The presave hook still backstops — anything the pre-submit misses, the presave catches.

### Seed JSON — copy-paste from Chat (no button)

Phase 3.2 originally shipped a "Load default bundle policy" button and Phase 3.7 added a "Load SiteOne column mapping" button next to the JSON textareas. **Both buttons were removed on 2026-05-25** — Office staff paste seed JSON directly from Claude Chat output, which is faster than maintaining the button-mediated workflow (Drupal's rebuild-doesn't-update-textarea-from-form_state mechanic made the buttons unreliable; the underlying limitation is documented in `__BOS_AI/Governance/drupal_bos_gotchas.md`).

The reference snippets for the default bundle policy (Phase 2 §6 matrix) and the SiteOne column mapping live in `__BOS_AI/Modules/supplier_price_ingest.md` under "Seed JSON for supplier_ingest_config (copy-paste from Chat)." Same content the buttons used to inject; same overwrite semantics (the textarea is now-or-never — paste over existing content deliberately).

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
