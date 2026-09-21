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
- **Compact responsive grid** (2-up mobile / 3–4-up desktop) so a long child list
  never runs down the page — `grid-template-columns: repeat(2,1fr)` then
  `repeat(auto-fill, minmax(220px,1fr))` at ≥34rem.
- **Nests for free** at every depth (child page shows *its* children). Prefer this
  over deepening the nav: Olivero's primary nav only collapses **two** levels (a
  third renders flat), and in-page contextual links are better SEO than a giant
  menu (descriptive anchors, topical clustering, no link-equity dilution).

Reference impl: `bos_services` `service_children`
(`web/scripts/build_service_children_view.php`), 2026-09-21.

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
- Living document — add reusable BOS UI patterns here as they're established.
