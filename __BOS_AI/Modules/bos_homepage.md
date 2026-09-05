# bos_homepage — Public homepage

**Package:** BOS
**Front page:** `/` → `/home` (`system.site:page.front`)
**Settings:** `/admin/config/bos/homepage` (perm `administer site configuration`)
**Shipped:** live 2026-09-05

## Purpose

Replaces the old front page (which was set to `/user/login` — the site opened on
the crew login screen) with a real marketing homepage. Public conversion surface;
the design-build lead path is the #1 job.

## How it renders (important)

The bands render **directly in the page template**, NOT through `{{ page.content }}`.
`{{ page.content }}` wraps content in Olivero's constrained content-region grid
(`region--content grid-full layout--pass--content-medium`), which boxed the
full-bleed bands. Instead:

- Route `/home` → `HomepageController::page()` → `_bos_homepage_render_array()`
  (theme `bos_homepage`, template `templates/bos-homepage.html.twig`).
- `hook_preprocess_page()` also calls `_bos_homepage_render_array()` and hands it
  to `page--front.html.twig` (in `brookstone_olivero`) as `bos_home`, which prints
  it full-width inside a plain `<main>` — bypassing the content region. This
  mirrors how `/winterize` renders full-bleed.
- `.page-wrapper--front` lifts Olivero's site max-width on the front page.

## Bands (six) — editable copy

All copy lives in `bos_homepage.settings` (config/install defaults) and is edited
at `/admin/config/bos/homepage` (`HomepageSettingsForm`) — no deploy to change
words. The band **structure** is in the template; only the words are config.

1. **Hero** — full-bleed photo (`assets/home-hero.jpg`), dark overlay, eyebrow,
   H1, subhead, "Get a Free Estimate" button. Then a black trust strip and the
   **orange promo banner** (see below).
2. **Services** — 6 cards → real service-page paths.
3. **Proof** — portfolio grid, **conditional**: only renders when the portfolio
   has **≥ 4** photos (hidden otherwise, so it never shows 1–2 near-identical
   shots). Populated by the import command below.
4. **How a project works** — 4 steps + CTA.
5. **Who we are** — body + 4 stat tiles + service-area line.
6. **Careers** — quiet strip → `/careers` (a Basic-page node, editable).
   Footer with **Crew Login** link (→ `/user/login`; auth untouched).

**Seasonal promo banner** — full-width orange band under the trust strip
(`promo_*` settings, toggleable). Default: "BOOKING NOW · Sprinkler winterization
… · Book Sprinkler Winterization →" (→ `/winterize`). Editable each season.

## Public estimate form

`/request-estimate` (`RequestEstimateForm`) — reCAPTCHA + flood + server-side
property match; creates an **`estimate_request`** design-build lead (flows into
the Estimates pipeline; `estimate_intake` cascades an Estimate + Contact). The
hero + How-it-works CTAs point here.

## Sitewide header (brookstone_olivero)

- **Branding block override** (`templates/block--system-branding-block.html.twig`)
  renders the winterize lockup on every public page: round emblem
  (`images/bo-logo-round.png`) + "BROOKSTONE OUTDOORS" (Oswald) + tagline.
- **Header recolor** (`css/bo-brand.css`): the bar is the sand tone; the outer
  `.site-header` is black so the sticky-collapse slide reveals black, not white;
  branding vertically centered; Services caret → brand-blue chevron; phone as an
  SVG icon (brand orange), placed in the **secondary-menu** row so it never
  collapses the primary nav.
- **No header-height / toggle geometry overrides** — Olivero's default sticky
  header is kept intact (shortening it repeatedly clipped the hamburger). Height
  is deliberately left at the theme default.

## Sitewide phone

`PhoneBlock` (`bos_homepage_phone`) — tap-to-call, number from
`bos_service_request.settings:office_phone`. Placed in `secondary_menu`.

## Portfolio import (Proof band)

`drush bos_homepage:portfolio-import` (alias `bos-hp-portfolio`) reads
`<source>/<address> - <Town>/` folders, resizes to 1200px, saves to
`public://homepage-portfolio/` (S3 on live), parses the **town** from the folder
name, and records `{fid, town, alt}` in config. Options: `--source`,
`--per-folder`, `--dry-run`. On live, stage the photos on the server and pass
`--source=<path>` (the dev `/mnt/d` path isn't there). The Proof band stays
hidden until ≥ 4 photos are imported.

## Deploy

`drush en bos_homepage` (imports config/install) → `setup_homepage.php`
(page.front → /home; creates /careers; places the phone block) → rsync module +
theme + `cr`. No `drush cim`. Login route/auth never touched.

## Not built / later

- Hero image is a module asset (swap via the file; a UI image field is a possible
  follow-up).
- Full site rebuild (Jan–Feb 2027) is a separate project; this is the quick-fix
  homepage.
