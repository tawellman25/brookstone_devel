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

## Status

- Created 2026-06-21 (status-card pattern, from the My Schedule + backflow card work).
- Living document — add reusable BOS UI patterns here as they're established.
