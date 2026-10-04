# Holiday Lighting Campaign — `/holiday-lights`

**Built:** 2026-10-03 · **Reviewed and accepted:** 2026-10-04
**Deployed to live:** 2026-10-04, verified anonymously.
**Status:** LIVE at https://brookstoneoutdoors.com/holiday-lights

The holiday equivalent of `/winterize`: a campaign landing page that converts,
sitting alongside the evergreen Holiday Decorations service page that ranks.

## Route and mechanism

| | |
|---|---|
| Path | `/holiday-lights` |
| Route | `bos_service_request.holiday_lights`, `options: no_cache: 'TRUE'` |
| Form | `Drupal\bos_service_request\Form\HolidayLightsForm` — the route's `_form` IS the page |
| Template | `brookstone_olivero/templates/page--holiday-lights.html.twig` |
| CSS | `bos_service_request/css/bo-holiday.css` (library `holiday`), on top of the shared `winterize` stylesheet |

Identical in shape to `/winterize`. Marketing chrome only — no site navigation.

## Where a lead lands — NO new ECK bundle

A holiday quote is a **`service_request:general_inquiry`** with
**`field_topic = 'quote'`**. Both already existed and are already wired into the
office queue, the office notification and the admin card view. What distinguishes
a holiday lead is **`field_campaign`**, which is how BOS already attributes
`fb26` / `goog26` / `react26`.

Cloning a 28-field bundle to carry one extra meaning would have been the
expensive wrong answer. Nothing here needs `--cim`.

`field_source` is resolved through `CampaignSource::forCode()` — it is the
**channel**, not the service. `holiday` prefix → `website`, so a visitor with no
tracked `?c=` files correctly; `?c=goog26` still files as Google Ads.

## Price — Business Settings, not State, not page copy

| | |
|---|---|
| Field | `field_holiday_light_per_foot` on `config_pages:business_setting` |
| Group | **Holiday Lighting** (per-service convention, like Snow Removal) |
| Seeded | `8.00`, confirmed by Todd 2026-10-03 |
| Update hook | `bos_service_request_update_10005` (seeds only if unset) |
| Read | `\Drupal::service('config_pages.loader')->load('business_setting')` |

**Not State, and the usual drift argument does not apply.** Despite the module
name, `config_pages` field *values* are entity data in the database; only field
definitions and displays are in `config/sync`. A price changed in production
does **not** drift from sync and is **not** reverted by a partial cim. State
would also have put one price somewhere none of the other 60 live.

**No custom cache tag is needed.** The route carries `no_cache`, and the service
page's token substitution attaches the config page's own entity cache tag.
Verified: changing the price in Business Settings changes both pages on the next
load with no manual rebuild.

### Add-on rates — created EMPTY on purpose

`field_holiday_tree_wrap`, `field_holiday_wreath`, `field_holiday_shrub`,
`field_holiday_column_wrap`, `field_holiday_walkway`, `field_holiday_min_job`.

None is seeded and none is published — marketing's copy says add-ons are quoted
at the walkthrough and that stays true. **Nobody supplied these rates, and a
seeded guess becomes a quote somebody honours.** The unit in each label (per
tree, each, per linear foot) is an assumption; if the office prices one
differently, change the label, not the number.

`field_holiday_min_job` is empty because the $750 figure in marketing's draft
was never confirmed. The page omits any minimum unless that field is filled.

## Page order

Hero → why → price → how it works → commercial → urgency → **form** → how we do it → footer.

The form sits **above** the accordions, on Todd's call: make the ask while the photo and
the price are still in view, and leave the detail for anyone who still wants it. `/winterize`
puts its form second for the same reason. The hero CTA anchors to `#request`.

## Full-width bands

This page bleeds edge to edge. The shared `.bo-winterize` wrapper used to carry
`max-width: 1440px`, which capped every band — see **Full-bleed bands with contained
content** in `Governance/ui_patterns.md` for the technique and the three traps. The fix is
shared, so it applies to `/winterize` and fall cleanup too.

## Imagery

| | |
|---|---|
| Hero | `assets/holiday-lights-hero.jpg` — **1842 × 1028**, 182 KB, a lit house at dusk |
| Commercial | `assets/holiday-commercial.jpg` — daylight C9 run on a commercial facade |

The hero is laid out **photo left, copy right** on the dark ground — Todd's call
after seeing a copy-over-photo version. The template still wraps the `<img>` in a
`file_exists` check, so swapping or removing the file cannot produce a broken
image. **The current hero is explicitly a placeholder Todd is content to ship**
("I will keep looking for a better picture"); a replacement at the same path and
roughly the same dimensions needs no code change.

The commercial shot is **Todd's own photograph of a Brookstone install** in
**Hotchkiss** (Delta County), taken from a public place — so copyright is ours and
no release is needed. I had flagged the customer's signage being legible as
needing permission; **that was overstated**, and showing commercial work is
ordinary practice for a contractor.

Because we installed it, the figure carries a **caption** saying so — which is the
point of it being there at all: the Commercial section argues entirely in prose,
and this is the only evidence on the page. The caption names **Hotchkiss** rather
than the county (the stronger locality signal, and the only mention of that town
on the page) and deliberately **does not name the client**: their sign being in
frame is incidental, but writing a customer into marketing copy is a different
claim and theirs to agree to.

## "How we do it" accordions — reused, not rewritten

The section is built from the **Holiday Decorations service term's own body**
(term 396) by `_bos_service_request_accordions()`, the same helper `/winterize`
and the fall-cleanup page use. One body, three surfaces.

Two things had to change to make the reuse honest:

- The splitter matched `<h3>` only; this body uses `<h2>`. Widened to `<h[23]>`
  after **measuring** that winterize (h2=0, h3=7) and fall cleanup (h2=0, h3=12)
  contain no `<h2>` — so the change cannot alter them. Re-verified afterwards: 7
  and 12 accordions, unchanged.
- The service page's own closing CTA is **stripped** before reuse, so the
  accordion intro does not link the visitor to the page they are already on.

### Lesson: reusing a component means reading its markup contract

Three separate rounds of rework on this page, all the same mistake — borrowing a
class *name* from `/winterize` without checking which element actually carries
the rule:

| Borrowed | What actually carries it |
|---|---|
| `.bo-step` | `.bo-next__grid` holds the grid; steps stacked with 38px gaps without it |
| `.bo-step__n` | nothing — the numeral had no rule at all and rendered at body size |
| `.bo-row` (invented) | `.bo-grid-2` is the existing two-up row; every input fell to browser default and Last name ran off the page |

A fourth, `.bo-detail__body`, was caught before shipping by reading
`page--winterize.html.twig` instead of recalling it. **Diff the markup against
the page you are copying from before claiming a component is reused.**

## Conversion tracking

`bos_analytics` maps form ID → event. Added
`'bos_holiday_lights_form' => 'holiday_submit'`. Campaign code `holiday26` added
to the allowlist in `bos_service_request.settings`.

## The two pages, two jobs

The Holiday Decorations service page keeps its path, menu placement and content,
and gained a CTA to `/holiday-lights`. **No redirect** — the service page ranks,
the landing page converts.

Its published `$8 per foot` is now a `[holiday-price]` token substituted by
`bos_services_preprocess_field()`, so the rate has one home.

## Deliberately not published

Multi-year discounts and the 50% deposit are sales-conversation terms, kept off
the page.
