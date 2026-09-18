# Missing category / landing pages (sitemap tree gaps)

Generated 2026-09-18. These are parent paths that URL aliases point *into* but
that have **no page yet** — e.g. `/material/plants/characteristics/aesthetic-features/flowering`
exists, but its parent `/material/plants/characteristics/aesthetic-features` does not.

Each should become a short **description + a list of links to its children**.
The number is how many child pages sit under it (higher = higher value).

Detection: parents derived from every taxonomy-term alias, minus paths that
already resolve to a real page. Re-run to refresh:
`web/scripts/` → the analysis in the 2026-09-18 session (path_alias → parent
prefixes → path.validator).

## Public — Materials catalog
- [ ] `/material/plants/characteristics` — 41
- [ ] `/material/plants/characteristics/aesthetic-features` — 5  ← **prototype built**
- [ ] `/material/plants/characteristics/environmental-tolerance` — 9
- [ ] `/material/plants/characteristics/maintenance-behavior` — 8
- [ ] `/material/plants/characteristics/growth-habit` — 6
- [ ] `/material/plants/characteristics/special-uses` — 4
- [ ] `/material/plants/characteristics/origin` — 3
- [ ] `/material/plants/characteristics/seasonal-interest` — 3
- [ ] `/material/plants/characteristics/wildlife-interaction` — 3
- [ ] `/material/plants/growth-zone` — 26
- [ ] `/material/plants/bloom-time` — 10
- [ ] `/material/rock` — 9
- [ ] `/material/bulk` — 15
- [ ] `/material/hardscape` — 8

## Public — Services
- [ ] `/services/landscape-lawn-care/spraying/location` — 20
- [ ] `/services/landscape-lawn-care/spraying/wind-direction` — 9
- [ ] `/services/landscape-lawn-care/spraying/methods` — 8
- [ ] `/services/landscape-lawn-care/spraying/chemicals` — 4  (+ `/chemicals/signal-words` — 4)
- [ ] `/services/landscape-lawn-care/spraying/frequency` — 3
- [ ] `/services/landscape-lawn-care/spraying/wind-speed` — 3
- [ ] `/services/landscape-lawn-care/spraying/carrier` — 2
- [ ] `/services/backflow-prevention/uses` — 14
- [ ] `/services/christmas-decorations/lights` — 19  (+ `/lights/colors` — 12)
- [ ] `/services/snow-removal/levels` — 5
- [ ] `/services/sprinkler-system/sprinkler-system-check` — 4
- [ ] `/services/sprinkler-system/operation` — 3

## Public — About
- [ ] `/about-us/our-equipment` — 54
- [ ] `/about-us/seasons` — 5

## Internal (crew, behind login — likely do NOT need public landings)
- [ ] `/teammate` — 44
- [ ] `/teammate/employment` — 15
- [ ] `/teammate/manual/website/contracts/status` (and intermediate `/teammate/manual/*`) — 12
- [ ] `/teammate/snow-removal/routes` — 17

## Notes
- `/material` and `/material/plants` already resolve — the gaps are the mid-level categories.
- Two build patterns: **(a)** hand-built Basic pages (full editorial control of each
  description; child links generated at build time), or **(b)** an auto-view/controller
  that renders any category landing from the URL (DRY, stays in sync as terms are
  added, but per-category description needs a home). The plant-characteristic
  categories are `list_integer` field values (keys 1–10), not entities, so a
  Basic page per category is the simplest place to hold each description.
- Prototype: `/material/plants/characteristics/aesthetic-features` built as a Basic
  page (description + auto-generated child links) via
  `web/scripts/build_aesthetic_features_landing.php`.

---

## Status — 2026-09-18 (built)

**Done (live):**
- Plant-characteristics tree — 8 category views + `/material/plants/characteristics` index page
  (`web/scripts/build_characteristics_tree.php`).
- 18 vocabulary landings (`web/scripts/build_vocab_landings.php`): `/material/rock`, `/material/bulk`,
  `/material/hardscape`, `/material/plants/bloom-time`, `/material/plants/growth-zone`,
  spraying location/wind-direction/methods/frequency/wind-speed/carrier/chemicals-signal-words,
  `/services/backflow-prevention/uses`, `/services/christmas-decorations/lights` + `/lights/colors`,
  `/services/snow-removal/levels`, `/services/sprinkler-system/operation` + `/sprinkler-system-check`.

Each = its own View: editable header description (Views UI) + auto-updating child list with aliased
links. Descriptions are placeholders — refine in the Views UI (edit the view → Header text).

**Still to do:**
- `/about-us/our-equipment` (54) and `/about-us/seasons` (5) — same vocab-landing pattern; confirm
  the exact child vocab + path first.
- `/services/landscape-lawn-care/spraying/chemicals` — an index page (its only sub-list is
  signal-words); needs a small Basic index page or fold signal-words up.
- Internal `/teammate/*` — likely no public landing needed.

**Pattern reference:** vocab landing = `build_vocab_landings.php`; category-of-a-field =
`build_characteristics_tree.php`; non-entity index (categories) = a Basic page.

## Status — 2026-09-18 (round 2)

**Also built (live):**
- `/about-us/our-equipment` (equipment_types, 54) and `/about-us/seasons` (season, 5) —
  vocab landings (`build_vocab_landings.php`).
- `/services/landscape-lawn-care/spraying/chemicals` — Basic index page linking to Signal Words
  (`build_spray_chemicals_index.php`).

That clears the public list. Remaining: only the internal `/teammate/*` paths (behind login;
likely no public landing needed).

**Known nit:** the season term "Winter" has a capital-W alias (`/about-us/seasons/Winter`) while
the other seasons are lowercase — worth normalizing to `/winter` + 301 for consistency.
