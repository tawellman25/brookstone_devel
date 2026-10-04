# Holiday Lighting Campaign — `/holiday-lights`

**Built:** 2026-10-03 · **Status:** on `main`, local only, NOT deployed.

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

## Hero image — still to land

`web/modules/custom/bos_service_request/assets/holiday-lights-hero.jpg`,
**1840 × 1026** to match `winterize-hero.jpg`.

The template checks `file_exists` and omits the `<img>` entirely when absent, so
the page renders legibly on the dark spruce ground with no broken-image icon.
Dropping the file in is the only remaining step.

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
