# BOS — Content & Knowledge Entities

Reference entities for internal knowledge bases, handbooks, manuals,
pest identification, testimonials, and site content.

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
Both bundles (`cover` and `page`) carry the **same five configurable fields**, all
single-value (cardinality 1):

- `title` (base) — page/section title
- `status` (base) — boolean: published (controls crew visibility)
- `field_body` — `text_long`: main content
- `field_intro` — `text_long`: introduction / lead-in
- `field_image` — `image`: labelled "Cover Image" on `cover`, "Main Image" on `page`
- `field_parent_page` — `entity_reference` → `handbook` (self-reference): parent in the hierarchy
- `field_weight` — `weight`: ordering among siblings under the same parent

## Invariants
- `status` (published) controls visibility to crew.
- Hierarchical via `field_parent_page`.
- **The online handbook is the same document as the PRINTED employee handbook and
  must stay in alignment with it.** The two are one handbook in two formats — a
  change to either (a new policy, a reworded section, a removed page) must be
  mirrored to the other in the same revision. When editing handbook content,
  confirm the printed master and the online `handbook` entities match; when the
  printed handbook is revised, update the corresponding online pages, and vice
  versa. Treat a drift between them as a defect to reconcile, not a variation.

## Deletion / Archival
- Unpublish (`status = false`) rather than delete.

## Access / UI
- **Admin / editing:** the `teammate_handbook` view, page display at
  **`/admin/operations/training/handbook`** ("Handbook Admin").
- **Teammate entry point:** crew reach the handbook from the **Employment** landing
  page (`site_landing_page:teammate`, `/teammates/employment`, linked from the
  `teammate-navigation` menu as "Employment"), which features the Team Handbook and
  is the hub for future HR/employment resources. Root cover = "Team Handbook" at
  `/teammates/training/handbook`. (Added 2026-08-26 — before this, teammates had no
  menu door to the handbook.)
- **Crew reading:** pages render at pathauto-aliased URLs, pattern
  `[handbook:field_parent_page:entity:url]/[handbook:title]` (nested to mirror the
  hierarchy — pattern `teammate_handbook_aliases`), navigated via the **handbook
  menu-tree block** (`brookstone_olivero_views_block__handbook_menu_tree_block`,
  from the `teammate_handbook_menu_tree` view).
- **Supporting views:** `teammate_handbook`, `teammate_handbook_menu_tree`,
  `handbook_child_pages`, `handbook_add_child_links`, `subordinate_handbook_pages`,
  `no_subordinate_handbook_page_link`.
- **Content on live (2026-08-26):** 14 `cover` + 87 `page` entities.

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
