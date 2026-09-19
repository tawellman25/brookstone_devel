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
| **Default** | `default` | editing baseline / fallback | **everything** — the full field set |
| **Admin View** | `admin_view` | office & admin | most fields, with the internal-only fields collected in an **"Office Admin" field group at the bottom** |
| **Teammate View** | `teammate_view` | crew | operational essentials — icon/image + the **crew "how we do it"** description + SOP/task links; no pricing/office fields |
| **Public View** | `full` | public & clients | the **public minimum** — icon + **public description** only |

The public tier is the entity's native canonical mode (`full` for taxonomy terms),
so anonymous/cached visitors get the safe display by default and the internal
modes are only ever reached by an explicit role match.

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

### Reference implementations

- **`bos_equipment`** — `equipment_types`, full 3-tier (public / crew / office), 2026-09-19.
- **`bos_services`** — `services`, 2-tier (public / internal), 2026-08-22.
- **`bos_hoa`** — `properties.hoa`, public view mode on the canonical page (2026-09-07); same principle applied to an ECK entity rather than a taxonomy.

## Status

- Created 2026-06-21 (status-card pattern, from the My Schedule + backflow card work).
- 2026-08-03 — added the responsive data-tables pattern (mobile stacking).
- 2026-09-19 — added the **audience view-mode tier** rule for public-facing pages
  (Default / Admin View / Teammate View / Public View + role routing + user.roles
  cache context), generalized from `bos_services` + `bos_equipment`.
- Living document — add reusable BOS UI patterns here as they're established.
