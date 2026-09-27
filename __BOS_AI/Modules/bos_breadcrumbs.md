# bos_breadcrumbs

**Package:** Custom · **Type:** code-only (a tagged breadcrumb-builder service; no config) · **Shipped:** 2026-09-27

Alias-based visible breadcrumb trails for **public taxonomy term pages**, overriding Drupal
core's hierarchy-based taxonomy breadcrumb for an **explicit vocabulary allowlist only**.

## The problem it solves

Core's `taxonomy_term.breadcrumb` builder (priority **1002**) claims every
`entity.taxonomy_term.canonical` route and builds the trail from the **vocabulary term
hierarchy**. Our public vocabularies are flat, so every term page rendered just **"Home"** and
stopped — losing both the crawler-facing breadcrumb Google renders under a result and the
internal link equity that flows up the trail. View pages (`/services`, `/material`,
`/services/backflow-prevention/uses`) already got correct path-based trails; term pages did not,
and term pages are most of the public catalog.

## What it does

`BosTermAliasBreadcrumbBuilder` implements `BreadcrumbBuilderInterface`, registered at priority
**1010** (above core's 1002, so it claims the allowlisted vocabularies' term pages first).

`build()` mirrors core's `PathBasedBreadcrumbBuilder`: it walks the page's **URL alias**
left-to-right, resolves each accumulated parent path to a route, checks the current user's
access, and adds a crumb using that route's **real page title** (view title / term name — "Our
Services", not "Services"). **Home** is always first; the **current page is omitted** (matching
the view pages). Three deliberate differences from core:

1. A segment that does not resolve to a route, or whose route has no string/markup title, is
   **skipped silently** — never a dead link, never humanised into a fake crumb.
2. A term crumb's title comes from the term's own `label()`, and the term is added as a
   cacheable dependency, so **renaming a parent term invalidates its children's breadcrumbs**.
3. `applies()` returns TRUE only for the allowlisted vocabularies (below); everything else
   returns FALSE and falls through to core unchanged.

**Caching:** contexts `url.path.parent` + `url.path.is_front` (trail depends on the parent
path, not the last segment) + `user.permissions` and each crumb's merged access cacheability
(access-gated crumbs); cache **tags** for every term whose label is used.

## The allowlist — EXPLICIT, do not broaden

`BosTermAliasBreadcrumbBuilder::ALLOWED_VIDS` (a class constant — changing it is a code deploy):

- `services`
- `material_types`
- `backflow_device_types`
- `backflow_uses`

**Why it is an allowlist and never an "all-except" list:** BOS has ~1,300 **operational**
taxonomy pages that are publicly reachable and indexed (a known, separate problem). Giving them
a breadcrumb trail would *improve* their search presence — the exact opposite of what anyone
wants. Only the four public, marketing-facing vocabularies get this treatment. **Do not
"helpfully" broaden this to all taxonomy terms.** Adding an operational vocabulary here is a
bug, not an improvement.

## Out of scope (by design)

- **No BreadcrumbList JSON-LD.** Structured data is a separate deliberate ROADMAP pass (enable
  Metatag core + `schema_metatag`, configure Organization / LocalBusiness / WebPage /
  BreadcrumbList as managed config, retire the two hand-rolled JSON-LD blocks). This builder
  just makes the visible trail correct so that pass has a true hierarchy to serialise.
- No pathauto pattern, vocabulary hierarchy, or operational-vocabulary access changes.

## Verified (anon, live, 2026-09-27)

`/material/backflow` → Home › Materials · `/material/plants/annuals` → Home › Materials ›
Plants · `/services/sprinkler-system` → Home › Our Services ·
`/services/backflow-prevention/reduced-pressure-rp` → Home › Our Services › Backflow Prevention
· `/services/backflow-prevention/uses/irrigation-lawn-sprinkler` → Home › Our Services ›
Backflow Prevention › What Needs Backflow Protection. Unchanged: the `/uses` and `/material`
view pages, homepage, `/winterize`, `/about`, `/contact`, and all operational term pages
(applies() = FALSE). Dead-segment scan: 12 `material_types` terms have nested aliases and all
their parent segments resolve — **0** dead intermediate segments today (the builder handles that
case regardless).

## Deploy

Code-only: rsync the module → `drush en bos_breadcrumbs` → `cr`. No `cim` (the service rides
code, not config).
