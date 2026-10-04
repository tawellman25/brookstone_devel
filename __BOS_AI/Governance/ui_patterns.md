# BOS UI Patterns

Reusable front-end conventions for BOS admin/crew surfaces. The goal is genuine
visual consistency across BOS — reuse an established component's tokens, don't
invent a lookalike.

---

## Status-card pattern (lists of stateful records)

**When to use:** any list of records that carry a *state* — work orders,
backflow devices, equipment, items with a status. Prefer a **status card per
record** over a plain Views table. The canonical reference is the **My Schedule
crew cards** (`/teammates/calendar/my-schedule`).

**Default to this** for new list / EVA / status UIs unless there's a specific
reason a table is better (dense tabular data, many columns, sorting/exporting).

### Visual tokens (from `bos_scheduling/css/my_schedule.css`, `.my-schedule-card`)

Reuse these exact values so cards read as the same component:

- **Card container:** `background:#fff; border:2px solid #ddd; border-radius:6px;
  padding:.85rem 1rem;` hover `border-color:#1a5276; box-shadow:0 2px 8px
  rgba(0,0,0,.12);`
- **Left status-accent bar:** `border-left:5px solid <status-color>` — the
  at-a-glance signal.
- **Header row:** `display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;`
  with the **status badge pushed right** via `margin-left:auto`.
- **Badge shape:** `border-radius:3px; padding:.15rem .5rem; font-weight:700;
  font-size:.8rem; letter-spacing:.03em;` (white text on the status color).
- **Typography:** primary id/title ~1.05–1.15rem bold `#1a1a1a`; secondary facts
  ~.9rem `#555`.

### Conventions

- **Color by status, keyed on the machine value** (not the label text) — a stable
  map so a relabel never silently changes colors. The accent bar and the badge
  use the same status color.
- **Don't double-signal.** If a state is already shown by the badge (e.g. a
  FAILED device), don't *also* color a date field as "overdue" — pick one signal.
- **Dates** follow the BOS Date Formatting convention (MM/DD/YYYY; see CLAUDE.md).
  Date/threshold logic goes in PHP (site timezone), not Twig.

### Mechanism — applying it to a View / EVA

Mirrors `bos_spray_route_ui` (CSS attach) and the backflow Property Devices EVA
(row template):

1. **Row style → Unformatted list** (not Table).
2. **Row template** `views-view-fields--<view-id>.html.twig` in the module's
   `templates/` dir. A module template that overrides another module's theme hook
   (`views_view_fields`) is **not auto-discovered** — register the suggestion in
   `hook_theme()` with `'base hook' => 'views_view_fields'`.
3. **Compute card data in `hook_preprocess_views_view_fields()`** (guard on the
   view id), reading `$variables['row']->_entity`. Put status maps + date logic
   here, expose a single `card`-style array to the template.
4. **Attach the card CSS via `hook_views_pre_render()`**:
   `$view->element['#attached']['library'][] = '<module>/<library>';`
   (same as `bos_spray_route_ui` attaches `spray-route.css`).

### Reference implementations

- **My Schedule cards** — `bos_scheduling`:
  `templates/bos-scheduling-my-schedule.html.twig` + `css/my_schedule.css`
  (the canonical component; status accent keyed on WO status TID).
- **Property Devices EVA** — `backflow_device`:
  `css/backflow-cards.css` +
  `templates/views-view-fields--backflow-property-devices-eva.html.twig` +
  the `hook_views_pre_render` / `hook_preprocess_views_view_fields` / `hook_theme`
  in `backflow_device.module` (status accent keyed on the device status machine
  value; active-only Next-Due treatment).

---

## Responsive data tables (mobile)

Views that render as multi-column **tables** run off the right edge on phones —
the rightmost column (often the Edit/Links action) disappears. The crew-facing
WO page hit this across ~11 tables (2026-08-03).

**Fix (reusable):** the `brookstone_olivero/responsive_tables` library —
- **JS** (`js/responsive-tables.js`): for each content table with a `<thead>`,
  copy each column header onto its body cells as `data-bos-label` and add class
  `.bos-stack-table` (`Drupal.behaviors` + `once`, so it re-applies after AJAX).
- **CSS** (`css/responsive-tables.css`, `@media (max-width: 48em)`): stack
  `.bos-stack-table` — hide `thead`, make each row a bordered block, each cell
  full-width with its `data-bos-label` shown above it. Fallback: unlabeled
  tables get `.view-content { overflow-x: auto }` so they scroll in their box
  rather than pushing the page sideways; `img { max-width: 100% }`.

Prefer this for existing table-style Views. For **new** stateful lists, still
reach for the **status-card pattern** above (cards are mobile-friendly by
design). Currently wired into `brookstone_olivero` only (crew/mobile theme);
port to `brookstone_admin` if office staff hit the same on phones.

## Audience view-mode tiers (public-facing pages)

**Rule:** a public-facing content type serves **one canonical URL** and swaps the
body **by the viewer's role** — never a separate route or duplicated page. This
is the BOS default for any content type that has both a public/customer face and
an internal one (service taxonomy terms, equipment types, properties/HOA, and any
future public entity).

### The standard view modes

Author these on the bundle's **Manage Display**, most-detailed → least:

| View mode | Machine name | Audience | Contains |
|---|---|---|---|
| **Default** | `default` | editing baseline / fallback | **everything** — the full field set, labels hidden |
| **Admin View** | `admin_view` | office & admin | identity (icon/name) on top, then **collapsible `field_group` "Details" sections that mirror the audiences** — see below |
| **Teammate View** | `teammate_view` | crew | operational minimum — icon + the **crew "how we do it"** description; nothing else |
| **Public View** | `full` | public & clients | the **public minimum** — icon + **public description** only |

The public tier is the entity's native canonical mode (`full` for taxonomy terms),
so anonymous/cached visitors get the safe display by default and the internal
modes are only ever reached by an explicit role match.

**Admin View structure (reference layout — `equipment_types`).** The office/admin
display is not a flat field list. Identity fields (icon, name — labels hidden) sit
at the top, then the rest is organized into collapsible **`field_group` "Details"**
sections whose labels **mirror the audience tiers**, so office can see, in one
place, exactly what each audience gets plus the internal data:

- **"Public View"** group → the public description (the same field the `full`
  display shows).
- **"Crew View"** group → the crew description (the same field `teammate_view` shows).
- **"Office Admin"** group → all internal-only fields (classification, department,
  imagery, etc.), with **inline** labels.

Inside a single-content section, set the **field's own label to Hidden** so the
group heading is the only title (no double heading). Section headings are styled
as brand section bars by the shared theme library **`brookstone_olivero/audience_admin`**
(the `.bos-admin-view` wrapper + `css/audience-admin.css`) — it targets Olivero's
`details.olivero-details > summary` (and fieldset/html-element group formats), so
any group format picks up the look. **Reuse this shared library** when applying the
pattern to another content type: in the module's `hook_preprocess_HOOK` for the
entity, on the `admin_view` render add class `bos-admin-view` and attach
`brookstone_olivero/audience_admin` (see `bos_equipment` / `bos_services`).

**Public description wording.** The public tier shows a *public-facing*
description. Two accepted forms (the per-content-type variance):
- a **dedicated** `field_*_public_desc` (e.g. `services` → `field_service_public_desc`), or
- the **core taxonomy `description`** relabeled **"Public Description"** on that
  bundle via a base-field override — `web/scripts/relabel_term_description_public.php`
  (bundle-scoped; other taxonomies keep "Description"). Used by `equipment_types`.

### Routing tiers (role → view mode)

Three tiers, **office checked first** so a supervisor-who-is-also-a-teammate gets
the office display:

```
office/admin  (supervisor, administration, site_assistant, site_admin, administrator) -> admin_view
crew          (teammates)                                                             -> teammate_view
everyone else (public, clients)                                                        -> full
```

A content type that only needs **two tiers** (public vs one internal view) simply
omits the tier it doesn't use and lets those roles fall through to `full` — e.g.
`services` today routes internal → `teammate_view`, public → `full` (no office tier).

### Mechanism (per content type)

A small `hook_entity_view_mode_alter` in the content type's home module, plus the
**mandatory** cache context:

```php
// Only rewrite the canonical full-page render; leave token/admin/embedded alone.
function MODULE_entity_view_mode_alter(&$view_mode, EntityInterface $entity) {
  if ($view_mode !== 'full' || $entity->getEntityTypeId() !== 'taxonomy_term'
      || $entity->bundle() !== 'BUNDLE') {
    return;
  }
  $roles = \Drupal::currentUser()->getRoles();
  if (array_intersect(OFFICE_ROLES, $roles))     { $view_mode = 'admin_view'; }
  elseif (array_intersect(CREW_ROLES, $roles))   { $view_mode = 'teammate_view'; }
}

// REQUIRED — the mode is chosen by role, so the render MUST vary by user.roles or
// Dynamic Page Cache / render cache will serve one audience's body to another.
function MODULE_ENTITYTYPE_view_alter(array &$build, EntityInterface $entity, $display) {
  if ($entity->bundle() === 'BUNDLE') {
    $build['#cache']['contexts'][] = 'user.roles';
  }
}
```

Never skip the `user.roles` cache context — it is the difference between a working
pattern and quietly leaking the office display to the public.

**Gotcha — duplicate title on taxonomy-term pages.** Core keys the term template's
`page` flag to the **`full`** view mode. Switching a term's canonical render to
`admin_view`/`teammate_view` makes `page` FALSE, so the template renders the linked
term-name `<h2>` a second time (a duplicate title under the page-title block) for
internal viewers, while the public `full` render stays single. In the module's
`hook_preprocess_HOOK` set `$variables['page'] = TRUE` for the switched view modes.
Reference: `bos_services` / `bos_equipment` (2026-09-19).

### Reference implementations

- **`bos_equipment`** — `equipment_types`, full 3-tier (public / crew / office), reference layout, 2026-09-19.
- **`bos_services`** — `services`, full 3-tier (public / crew / office), 2026-09-19.
- Shared section-heading CSS: **`brookstone_olivero/audience_admin`** (`.bos-admin-view` + `css/audience-admin.css`) — each content module adds the `.bos-admin-view` class and attaches this library on its `admin_view` render. Reuse it; don't re-copy the CSS.
- **`bos_hoa`** — `properties.hoa`, public view mode on the canonical page (2026-09-07); same principle applied to an ECK entity rather than a taxonomy.
- **`bos_geo`** — `state` / `county` / `city` ECK geo entities, 3-tier via the `public` / `teammate` / `admin` view modes (created by `web/scripts/setup_geo_view_modes.php`). Here **supervisor sits in the office/admin tier** (they get the `admin` view). `bos_geo` also owns the geo pages' hero, SEO meta tags, and footer links (see below). It absorbed the former `bos_state` module on 2026-09-20 — geo presentation now lives in one module.
- **`bos_backflow_types`** — `backflow_device_types` + `backflow_uses`, 3-tier (office→`admin_view`, crew→`teammate_view`, else `full`); device types also get a full-bleed rotating hero. 2026-09-26.
- **`bos_spray_types`** — the six spray vocabularies (`carrier`, `spraying_locations`, `spraying_methods`, `wind_direction`, `spraying_wind_speed`, `spraying_soil_moisture_levels`), 3-tier. Added 2026-09-27 specifically to **close a public leak**: `field_teammate_description` (crew instructions) had been rendering on the single `default` display, so crew notes were visible to anonymous visitors on every leaf term page. The `full` (public) display = the vocab's current public fields **minus** `field_teammate_description`; `teammate_view` = crew instruction; `admin_view` = everything. Displays built by `web/scripts/build_spray_audience_displays.php`. **Lesson (also in the backflow note):** a vocab that carries `field_teammate_description` but has only a `default` display leaks it publicly — always gate the crew field behind the view-mode tiers, and confirm with an anon render before writing crew copy.

## Public banner hero (full-bleed rotating)

Public pages whose content type has a multi-value banner image field show it as a
**full-bleed rotating hero** at the top, with the record's name + subtitle overlaid
on a bottom gradient scrim (crossfade auto-advance, dots, swipe, honors
`prefers-reduced-motion`; one image → static hero).

**Reusable piece (theme):**
- Theme hook **`bo_hero_banner`** (`brookstone_olivero.theme`) + template
  `templates/bo-hero-banner.html.twig`.
- Library **`brookstone_olivero/bo_hero`** (`css/hero-banner.css` + `js/hero-banner.js`) —
  full-bleed via the `width:100vw; margin-left:calc(50% - 50vw)` break-out so it
  fills edge-to-edge even inside Olivero's constrained content region.

**Wiring (per content type, in the module's `hook_preprocess_HOOK`):** on the
**public** render, build `['#theme' => 'bo_hero_banner', '#images' => [{src, alt}…],
'#title' => …, '#subtitle' => …]` (style the source with `max_2600x2600`), add it
to `content` with a low weight, set the raw image field `#access = FALSE`, and attach
`brookstone_olivero/bo_hero`. Because the hero carries the page's single H1, also
**suppress the core `page_title_block`** for public viewers on those records
(`hook_block_access`, with `user.roles` + `route` cache contexts) so there's no
duplicate title. Reference impl: `bos_services` (`field_banner_image`), 2026-09-19;
also `bos_geo` for the `state`/`county`/`city` geo pages (2026-09-20).

> **Field-name variance:** `services` uses `field_banner_image`; `equipment_types`
> uses `field_banner_images` (plural). Read the bundle's actual field when reusing.

> **ECK title suppression — opcache trap (2026-09-20).** The `page_title_block`
> suppression is `hook_block_access` returning `forbidden` when the record has a
> banner; the decision is render-cached. On live, restarting/rebuilding is not
> enough on its own — a stale `lsphp` worker can render the page once with old
> opcode and **bake the wrong decision (allowed) into the block cache**, which
> then persists past `drush cr`. See `drupal_bos_gotchas.md` → "Module swap on
> live: restart lsphp before priming caches."

## Public SEO: per-entity meta tags + auto description

Geo/public pages get their SEO from the **Metatag** module. Two layers:
1. **Auto** — `hook_metatags_alter` fills `description` / `og_description` from the
   record's own description field (tags→space, trimmed to 300) and `og_image` from
   its banner via the `max_1300x1300` image style (FB-safe). Alter the tag **values**
   during generation (value stage) — editing rendered attachments does not stick on
   entity pages.
2. **Override** — a per-entity `field_meta_tags` (type `metatag`, widget
   `metatag_firehose`) box on the edit form lets the office set a custom title /
   description / OG; whatever they fill **wins**, and only the blank tags are
   auto-filled. Reference impls: `bos_services` (services taxonomy) + `bos_geo`
   (state/county/city, `web/scripts/setup_geo_metatag_field.php`), both 2026-09.

## Footer "Service Area" name links

The footer Service-Area block is plain editorial text (a counties line + a
"·"-separated city line). `bos_geo` (`hook_preprocess_block` on
`block_content:footer_service_area`) links each name to its geo landing page —
counties line → county pages (+ "Colorado" → the state), city line → city pages —
matching on the short name (stripping "County" / "City of" / "Town of"), **only for
published records**, leaving the wording exactly as typed. Cache-tagged
`county_list` / `city_list` / `state_list` so it rebuilds as records are published.

## Child-listing cards (EVA of a term's children)

To surface a taxonomy term's **direct children** as cards on the term's own page
(e.g. "Our {Category} Services" on a services page), use an **EVA** — same shape as
the geo "We Serve" cards but the argument is the term's *parent* field:
- View base `taxonomy_term_field_data`; contextual argument
  `taxonomy_term__parent.parent_target_id` (plugin `numeric`) fed the host term id
  via EVA `argument_mode: id` → returns the terms whose parent IS the host = its
  children. Filter `vid` + `status=1`.
- Header area with `empty: false` so it renders **only when there are children** (a
  leaf term shows nothing). A module `hook_views_pre_render` attaches the grid CSS
  and rewrites the header to name the current term (read `$view->args[0]`).
- **Card blurb = the field SUMMARY, not the trimmed body.** The public-description
  field is `text_with_summary`; a `hook_preprocess_views_view_field` outputs the
  summary only (stripped to plain text, blank when empty) so the office writes a
  short card-specific line and the card never dumps trimmed body HTML (headings,
  etc.). The full body stays the in-depth copy on the service page.
- **Compact responsive grid** (2-up mobile / 3–4-up desktop) so a long child list
  never runs down the page — `grid-template-columns: repeat(2,1fr)` then
  `repeat(auto-fill, minmax(220px,1fr))` at ≥34rem. **Grid the `.view` element
  itself** (make the `<header>` span `1 / -1`) — an EVA default-style display
  renders its `.views-row`s directly under `.view` with **no `.view-content`
  wrapper**, so targeting `.view-content` silently does nothing.
- **Nests for free** at every depth (child page shows *its* children). Prefer this
  over deepening the nav: Olivero's primary nav only collapses **two** levels (a
  third renders flat), and in-page contextual links are better SEO than a giant
  menu (descriptive anchors, topical clustering, no link-equity dilution).

Reference impl: `bos_services` `service_children`
(`web/scripts/build_service_children_view.php`), 2026-09-21.

### Variant — a standalone *landing view* of child terms

Where the parent is not a term page but its own **landing View** (the spray axes:
`land_spray_location`, `land_backflow_uses`, …), apply the same card component to
that view instead of adding an EVA:
- Style = **Unformatted list** — the Views plugin id is **`default`**, not
  `unformatted`; set `row_class` for the card and the display's **`css_class`** for
  the CSS scope. Copy a working field/style block from an existing landing
  (`land_backflow_uses`) rather than hand-writing one.
- **Check the rendered HTML before writing the CSS.** These landing views render
  their `.views-row`s **directly under the wrapper with no `.view-content`
  element**, so the grid goes on the wrapper and the header/footer areas need
  `grid-column: 1 / -1`. A rule targeting `.view-content` is silently inert.
- Teaser field = the vocabulary's `field_short_description` (that is what it is
  for). Render it whole — it is authored as a one-liner, so trimming reads worse.
- Attach the CSS from **`hook_preprocess_views_view()`**, gated on the view id, NOT
  `hook_views_pre_render()`: preprocess comes from the theme registry (rebuilt by
  `drush cr`), so it works on an already-enabled module, whereas a brand-new
  `hook_views_pre_render()` is not registered by `cr` (see
  `drupal_bos_gotchas.md`).

Reference impl: `bos_spray_types` + `web/scripts/build_spray_location_landing_cards.php`
(`css/landing-cards.css`), 2026-09-27.

## Status

- Created 2026-06-21 (status-card pattern, from the My Schedule + backflow card work).
- 2026-08-03 — added the responsive data-tables pattern (mobile stacking).
- 2026-09-19 — added the **audience view-mode tier** rule for public-facing pages
  (Default / Admin View / Teammate View / Public View + role routing + user.roles
  cache context), generalized from `bos_services` + `bos_equipment`.
- 2026-09-20 — consolidated `bos_state` into `bos_geo` (one geo-presentation
  module); added the **per-entity meta-tags override** and **footer name-links**
  patterns; noted the ECK title-suppression opcache trap.
- 2026-09-21 — added the **child-listing cards** pattern (EVA of a term's children,
  `bos_services` service_children); note that Olivero's nav is two-level only.
- 2026-09-27 — added the **landing-view variant** of child-listing cards (Unformatted style id is `default`; grid the wrapper because these views have no `.view-content`; attach CSS from `hook_preprocess_views_view`).
- Living document — add reusable BOS UI patterns here as they're established.

## The three-field content model for public taxonomy terms — and which field goes where

Every public-facing vocabulary (the 31 in the `bos_breadcrumbs` allowlist) carries exactly
three copy fields, configured identically — `text_long`, cardinality 1, optional,
**`allowed_formats: ['full_html']`**, `text_textarea` widget at 5 rows:

| Field | What it is | Where it renders |
|---|---|---|
| `field_short_description` | The one-line teaser | **VIEWS ONLY** — the card or list text on the **parent** term's page. **Never on any view mode.** |
| `field_public_description` | The public body | The term's own page, public view modes |
| `field_teammate_description` | Crew instructions | `teammate_view` / `admin_view` only — **never a public display** |

### ⚠ `field_short_description` is never on a view mode. This is enforced in code.

The teaser is what the level **above** says about a term. The term's own page shows its
**public description**. Those are different jobs and the same text rarely does both well.

`bos_content_coverage_entity_view_display_presave()` **removes** the field from any
`taxonomy_term` view display on save and logs a warning naming the display. Views are
untouched — a Views field is not an entity view display, and views are the consumer the field
exists for.

**Why it is enforced rather than documented:** the rule was broken twice in one day — once by
the original build, and once on 2026-10-03 by Code, when a marketing brief said "unhide it" and
Code followed the brief instead of the model. A written rule does not survive that, because the
brief is what gets read last. **When a brief and this model disagree, the model wins** — raise
the conflict, do not implement it.

### Corollaries that have each been got wrong once

- **A card never shows a truncation of the body** *as its permanent design*. Trimming a body to
  160 characters cuts mid-sentence and the first line was not written to open anything.

  **On the empty case, the rule is about VISIBILITY, not about rendering nothing.** The
  original objection was that a fallback *"hid this for weeks"* — and what hid it was that the
  fallback lived in a **preprocess hook**, where no one looking at the view could see it. Three
  live card listings now sit at different points, deliberately:

  | Listing | Behaviour | Why |
  |---|---|---|
  | `plant_characteristic_children` | teaser, **no fallback** | the set was being completed in the same pass; a blank card is the to-do |
  | `material_children` | fallback **in a PHP hook** | transitional, but invisible in the Views UI — the shape the rule was written against |
  | `county_cities` | teaser + **Views-native fallback** | 1 of 12 written; a hard switch blanks 11 live cards |

  **So: a transitional fallback is allowed while a set is being filled, on two conditions** —
  it is **visible where the view is edited** (a field's "No results behavior", not a hook), and
  the gap is tracked somewhere a human reads (`/admin/office/content-coverage`). Remove it once
  the set is written. A fallback that is neither visible nor tracked is the thing being banned.

  Views-native fallback: put the body field **above** the teaser in the field list, exclude it
  from render, and set the teaser's "No results behavior" to its token — field ORDER is
  load-bearing, because a token only exists for fields that precede it.
- **Never promote the teaser to cover a missing body.** If a vocabulary's body is empty, write
  one. (Where this had already happened — `wind_direction`, `brookstone_tags` — the teaser was
  **copied** into the body, not moved, so the cards kept theirs.)
- **Core `description` is not used on any public vocabulary.** It is a base field, so it cannot
  be deleted per vocabulary; it is emptied and removed from the form and every display.
- **A vocabulary may hold a role under a different field name** (`services` uses
  `field_service_public_desc` / `field_service_crew_desc`). The coverage report maps those via
  `ContentCoverage::FIELD_OVERRIDES`; do not add the generic fields alongside.

### Checking the state, instead of inferring it

`/admin/office/content-coverage` shows every public term's alias and the state of each field.
**Live state comes from there or from fetching the page — never from a copy file (which says
what was written) or a build log (which says what was built).**


## Full-bleed bands with contained content (marketing pages)

The `/winterize`, `/winterize/week`, `/winterize/bear-creek`, fall-cleanup and
`/holiday-lights` pages share the `.bo-winterize` wrapper. Until 2026-10-04 that wrapper
carried `max-width: 1440px; margin: 0 auto`, which capped the **whole page** — so past
1440px every band stopped short with paper gutters down both sides. It went unnoticed for
months because winterize's hero is light and blends into the paper ground; a **dark photo
hero made it obvious at a glance.**

**Do not simply remove the cap.** Without it the hero copy column stretches to ~1100px of
20px serif on a 2560 screen, which is an unreadable measure. The page bleeds and the
*content* holds the measure:

```css
.bo-winterize {
  --bo-measure: 1440px;
  --bo-gutter: clamp(16px, 5vw, 72px);
  --bo-bleed: max(0px, calc((100% - var(--bo-measure)) / 2));
}
.bo-topbar    { padding-inline: max(var(--bo-gutter), var(--bo-bleed)); }
.bo-hero__copy{ margin-right: var(--bo-bleed); }
```

Three things that are easy to get wrong:

- **Only containers WITHOUT an inner wrapper need this.** Every other band already centres
  its content at 940–1160px and needs nothing. Check before adding rules.
- **`margin`, not `padding`, on a width-constrained column.** `box-sizing: border-box` is
  set page-wide, so padding comes out of the column's own width — and a column declared
  `width: min(38%, 30rem)` reaches a *negative* content width on an ultra-wide screen. The
  topbar is the exception and uses padding deliberately, so its bottom hairline still spans
  the full width.
- **Below the measure, nothing changes** — `max()`/`calc()` collapse to the values that
  were already there, so there is no mobile regression to re-test.

This is a different technique from the **Public banner hero** above, which breaks a single
element out of a constrained page with `width:100vw; margin-left:calc(50% - 50vw)`. Use the
break-out for one element inside an otherwise contained page; use this when the whole page
should bleed and only a few content columns need holding.

## Photo-strip card listings need photos

A listing converts from a table to cards-with-a-left-photo-strip **only when its rows
reliably carry an image**. Measure the bundle's image coverage first; do not infer it
from a sibling listing that looks identical in config.

Measured 2026-10-03 on the material catalogue:

| Rows | Items | With a photo |
|---|---|---|
| Plant bundles — trees, shrubs, plants, annuals | 129 | **95%** (trees 100%, shrubs 98%) |
| Everything else — irrigation, PVC, brass, galv, copper … | 2,839 | **15%** (galv 1%, copper 0%) |

So `material_characteristic_items` is cards (plant-scoped, strip always fed) and
`material_type_items` / `material_subcategory_items` stay tables (they serve hardware
categories where most rows have no image). The three views are **identical in config**,
which is exactly why the decision has to come from the data and not from the config.

A card with no image is either a dead gutter or a second row shape beside the first; a
table with no image is just a narrow empty cell. That asymmetry is the whole rule.

