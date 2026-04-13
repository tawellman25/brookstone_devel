# BOS Views — Material Types & Material Landing

## Material Types Landing Page

- **View ID:** `material_types_landing`
- **Path:** `/material`
- **Base table:** `taxonomy_term_field_data`
- **Vocabulary:** `material_types`
- **Access:** administrator, site_admin, site_assistant, administration, supervisor, teammates
- **Pager:** none
- **Sort:** weight ASC, name ASC
- **Filter:** `field_material_bundle` is not empty (excludes unlinked terms)

**Fields:**
- Name — rendered as `<h4>` heading, linked to taxonomy term page
- Public Description — 300-char trim, stripped tags

**Purpose:** Crew- and office-facing landing page listing all material categories. Each term links to its taxonomy term page, which shows the Material Type Items EVA view below.

---

## Material Type Items (EVA)

- **View ID:** `material_type_items`
- **Base table:** `material_field_data`
- **Display:** EVA (Entity View Attachment) on `taxonomy_term.material_types`
- **Access:** administrator, site_admin, site_assistant, administration
- **Pager:** full, 50 items per page
- **Sort:** title ASC
- **Argument:** `type` field (material bundle machine name), supplied via EVA token `[term:field_material_bundle:value]`

**Fields:**
- Main Image — thumbnail, linked to content
- Name — bold, linked to entity
- Description — 200-char trim, stripped tags

**Header:** Dynamic `<h4>{{ type }} Materials</h4>`

**How it works:** Each `material_types` taxonomy term has a `field_material_bundle` field storing the material bundle machine name (e.g., `irrigation`, `backflow`). When a user visits a term page, the EVA display passes that value as the contextual filter to show only materials of that bundle.

---

## field_material_bundle on material_types Taxonomy

- **Field:** `field_material_bundle` (string)
- **On:** `taxonomy_term.material_types`
- **Purpose:** Links each material_types term to its corresponding `material` entity bundle machine name
- **Populated by:** `dev_scripts/populate_material_bundle_field.php` (maps all 21 TIDs to bundle names)
- **Used by:** `material_type_items` EVA view as contextual filter argument
- **Display:** Hidden in public views; visible in admin_view, default, full, teammate_view displays

---

## Dev Scripts (One-Time Data)

| Script | Purpose |
|---|---|
| `populate_material_bundle_field.php` | Sets `field_material_bundle` on all 21 material_types terms |
| `create_material_type_terms.php` | Creates Mulch (TID 1773) and Backflow (TID 1774) terms |
| `move_backflow_materials.php` | Moves 52 Febco items from `irrigation` to `backflow` bundle |

---

Created: April 2026
