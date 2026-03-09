# BOS Entity — sop

Entity Type ID: `sop`
Storage: ECK

## Purpose
- Standard Operating Procedures. Authoritative procedural records for all BOS service departments.
- SOP codes are immutable once approved — enforced by `sop_code_validation` module globally and per-bundle by `sop_office_admin`, `sop_sprinkler_maintenance`, `sop_system_prosedures` modules.

## Bundles
`landscaping`, `sprinkler_maintenance`, `office_administration`, `system_procedures`, `sop_governance`, `lighting`, `maintenance`, `safety`, `snow_removal`, `spray`, `training`

## Required Relationships
- `field_sop_owner` → `user` (editor/owner)
- `field_parent_sop` → `sop` (optional — self-referential hierarchy)
- `field_related_sops` → `sop` (optional — cross-references)

## Key Fields (all bundles)
- `field_sop_code` — string: immutable SOP code once status = approved
- `field_sop_status` — list: draft, approved, archived, etc.
- `field_sop_version` — version string
- `field_sop_last_reviewed` — datetime of last review
- `field_sop_purpose` — long text: purpose statement
- `field_sop_scope` — long text: scope definition
- `field_sop_steps` — long text: steps and procedures
- `field_sop_responsibilities` — long text: rules and responsibilities
- `field_sop_tools_and_resources` — long text (not present on all bundles)
- `field_sop__kpis` — long text: key performance indicators
- `field_prerequisites` — string: prerequisites
- `field_service` → `taxonomy_term` (not present on `office_administration`)
- `field_materials_involved` → `material` (not present on `office_administration`, `system_procedures`, `sop_governance`, `training`)

### training bundle additional
- `field_required_positions` → `positions` — which positions this training SOP applies to

### office_administration / system_procedures
- No `field_materials_involved`; no `field_sop_tools_and_resources` on `sop_governance`

## Invariants
- `field_sop_code` is immutable once `field_sop_status` = approved. Enforced in code.
- Parent SOPs define scope; child SOPs (via `field_parent_sop`) inherit.
- `sop_system_prosedures` module name has a permanent directory typo (`prosedures`) — do not rename.

## Deletion / Archival
- Do not delete approved SOPs. Set `field_sop_status` to `archived` instead.
- Draft SOPs may be deleted before approval.

---

# BOS Entity — handbook

Entity Type ID: `handbook`
Storage: ECK

## Purpose
- Employee handbook content. Hierarchical structure with cover page and pages.

## Bundles
- `cover` — handbook cover page
- `page` — individual handbook page

## Required Relationships
- `field_parent_page` → `handbook` (optional — parent page for hierarchy)
- `uid` (base) → `user`

## Key Fields
- `title` — page title
- `status` (base) — boolean: published
- `field_body` — long text: main content
- `field_intro` — long text: introduction
- `field_image` / `field_image` — cover/main image
- `field_parent_page` → `handbook` — parent in hierarchy
- `field_weight` — weight field for ordering

## Invariants
- `status` (published) controls visibility to crew.
- Hierarchical via `field_parent_page`.

## Deletion / Archival
- Unpublish (`status = false`) rather than delete.

---

# BOS Entity — manual

Entity Type ID: `manual`
Storage: ECK

## Purpose
- Training and operations manuals. Three-level hierarchy: title page → chapter → page.

## Bundles
- `title_page` — manual title/cover
- `chapter` — chapter within a manual
- `page` — page within a chapter

## Required Relationships
- `chapter`: `field_parent_manual` → `manual` (title_page bundle)
- `page`: `field_parent_chapter` → `manual` (chapter bundle)
- `title_page` / `chapter`: `field_associated_crew` → `crew_types`

## Key Fields

### title_page
- `field_subtitle`, `field_version`, `field_publication_date`
- `field_description` — description/introduction
- `field_cover_image`
- `field_associated_crew` → `crew_types`

### chapter
- `field_chapter_number` — chapter number for ordering
- `field_subtitle`
- `field_description` — chapter introduction
- `field_cover_image`
- `field_parent_manual` → `manual` (title_page)
- `field_associated_crew` → `crew_types`

### page
- `field_description` — page content (long text)
- `field_cover_image` — page image
- `field_parent_chapter` → `manual` (chapter)

## Invariants
- `status` (published) controls visibility.
- `field_associated_crew` scopes manuals/chapters to specific crew types.

## Deletion / Archival
- Unpublish rather than delete.

---

# BOS Entity — lawn_and_garden_pests

Entity Type ID: `lawn_and_garden_pests`
Storage: ECK

## Purpose
- Reference knowledge base for weed and pest identification. Used by spray crew for compliance documentation.
- Referenced by `wo_spraying_conditions.field_weed_types`.

## Bundles
`weed_types` (single bundle)

## Required Relationships
- Referenced by `wo_spraying_conditions.field_weed_types`

## Key Fields
- `field_common_name` — common name
- `field_plant_genus`, `field_plant_species`, `field_plant_family` — botanical classification
- `field_life_cycle` — list: annual, biennial, perennial
- `field_leaf_category` — list: broadleaf, grass, sedge
- `field_size` — typical size
- `field_where` — where it grows
- `field_appearance` — long text: appearance description
- `field_description` — text with summary: general description
- `field_weed_control_tips` — control tips
- `field_weed_categories` → `taxonomy_term`
- Growth stage images: `field_growth_stage_seeded`, `field_growth_stage_succulent`, `field_growth_stage_vegetative`, `field_growth_stage_mature`
- `field_iconic_image`, `field_banner_image`

## Invariants
- Reference data. Do not delete entries referenced by completed spray WO records.

---

# BOS Entity — testimonial

Entity Type ID: `testimonial`
Storage: ECK

## Purpose
- Client testimonials. Used for marketing/public-facing content.

## Bundles
`client` (single bundle)

## Required Relationships
- `field_customer` → `user` (optional — the client who gave the testimonial)

## Key Fields
- `title` — testimonial title
- `status` (base) — boolean: promoted to front/published
- `field_testimony` — long text: testimonial content
- `field_testimonial_by` — string: who gave the testimonial (display name)
- `field_testimony_service` → `taxonomy_term` — which service the testimonial is about
- `field_testimonial_image` — example/associated image

## Invariants
- `status` controls public visibility.

---

# BOS Entity — site_content

Entity Type ID: `site_content`
Storage: ECK

## Purpose
- General site content blocks for public-facing and teammate-facing content areas.

## Bundles
- `public_info` — public website content
- `teammate` — internal teammate-facing content

## Key Fields
- `title` — page location/identifier
- `field_name` — display name
- `field_content_text` — text with summary: main content
- `field_iconic_image` — icon/feature image

## Invariants
- Content is managed by office/admin roles.
- No external system integration.

---

# BOS Entity — site_landing_page

Entity Type ID: `site_landing_page`
Storage: ECK

## Purpose
- Landing page configuration for role-specific BOS dashboards (office administration, supervisor, teammate).

## Bundles
- `office_administration` — office admin dashboard landing page
- `supervisor` — supervisor dashboard landing page
- `teammate` — crew/teammate dashboard landing page

## Required Relationships
- `office_administration`: `field_menu` → `menu` (Drupal menu entity)

## Key Fields
- `title` — landing page title
- `status` (base) — boolean: published
- `field_description` — long text: page description/intro

## Invariants
- The `site_landing_page` module forces the admin theme on `office_administration` bundle routes.
- `field_menu` on `office_administration` bundle links to a Drupal menu for navigation rendering.
- `status` controls whether the landing page is active.

## Deletion / Archival
- Unpublish rather than delete.
