# bos_content_coverage — what public copy is actually LIVE

**Report:** `/admin/office/content-coverage` (admin menu → Office → Content coverage)
**Permission:** `view content coverage report` — granted on install to `administration`,
`supervisor`, `site_assistant`, `site_admin`, `administrator`. Anonymous gets **403**.
**Built:** 2026-10-03

## Why it exists

Within two days, marketing and Code each asserted the wrong thing about live content, in
opposite directions. Marketing's copy files carried cross-links written against the structure
we designed (`/deciduous/fruit-trees`) rather than the live alias (`/deciduous/fruit`) — those
would have 404'd. Code, working from an extract, concluded the `/material` view header was not
live when it was, and nearly re-pasted it. Both inferences were reasonable from the artifact in
hand and both were wrong, because **neither a copy file nor a build log is the database.**

A protocol alone decays exactly when things are busy. This answers the question instead.

## Columns

Term · Vocabulary · Parent · **Live URL alias** · Teaser · Public description · Call to action ·
Crew description · **Boilerplate** · Changed.

Copy columns read `n/a` (the vocabulary has no such field), `EMPTY`, or a **plain-text**
character count — so the number means words a reader sees, not markup. A `⚠` on the count
means that field carries boilerplate.

**`n/a` is not a gap.** A vocabulary that never had a teaser slot is not a page missing its
teaser, and conflating the two would invent work.

## Exposed filters

Vocabulary · Carries boilerplate · Missing public description · Missing teaser · Changed before.

## The three things to extend, all constants in `ContentCoverage`

| Constant | Purpose |
|---|---|
| `BOILERPLATE_PHRASES` | The generic agency-register copy being replaced. Case-insensitive, matched anywhere in the value. |
| `FIELD_OVERRIDES` | Vocabularies holding a copy role in a differently-named field. |
| `ADDITIONAL_VIDS` | Vocabularies to cover beyond the public allowlist. Deliberately empty — see below. |

### Coverage is the bos_breadcrumbs allowlist, on purpose

`coveredVids()` returns `BosTermAliasBreadcrumbBuilder::ALLOWED_VIDS` (31 vocabularies, 355
terms). The repo already had one considered answer to "which vocabularies render a public
page", and it is an **allowlist** precisely because ~1,300 operational term pages are publicly
indexed and must not be swept in. Reusing it keeps a single source of truth, so this report
cannot drift from what the site treats as public.

### FIELD_OVERRIDES is why the report is trustworthy

`services` keeps its copy in `field_service_public_desc` (59 of 60 terms) and
`field_service_crew_desc` (55 of 60), **not** the four default field names. A report that knew
only the defaults would have shown all 60 service pages as having no copy — exactly the false
reading it exists to prevent. It also found **2 flagged service terms** a hand count using the
default field names missed.

## Link check

    drush bos:content:linkcheck          # broken + redirecting internal links
    drush bos:content:linkcheck --all    # also list the ones that resolve

Parses `href="/…"` out of every public copy field and classifies each: resolves directly
(alias or route), **resolves via a 301** (works, but should be repointed at the canonical
path), or **does not resolve**. It is a command rather than a page because the answer is a list
of links, not of entities.

Two accuracy guards, both from mistakes made while building it:

- The pattern uses a `~` delimiter. A `#`-delimited pattern containing `#` in its character
  class ends early and never compiles, which is how an earlier audit reported "every link
  resolves" having checked **zero**. A compile failure now throws, and a run finding zero links
  fails loudly rather than implying the copy is clean.
- Redirects are separated from breakage. `/lighting` answers 301 and works fine for a visitor;
  calling it broken would have sent marketing to fix two links that are not broken.

## Deployment

Module code + an idempotent build script (`web/scripts/build_content_coverage_view.php`), not a
cim. A Views page display created from raw config leaves its route unregistered, and a view-only
cim would not carry the permission grant. Verify with
`web/scripts/verify_content_coverage.php`, which **renders** the display — Views config on this
project has shipped clean and broken at render time before.

## Gotcha: the operator a Views filter accepts comes from its base class

The vocabulary scope filter silently returned **995 rows instead of 355, with no WHERE clause at
all**, through two rebuilds. Two separate causes:

1. **An exposed filter with no input does not filter.** A hard scope cannot live on an exposed
   filter. The view now carries a **non-exposed** `vid` floor plus a separate **exposed** `vid`
   for narrowing — the same pattern as the billing views' status floor.
2. **`views`' `Bundle` filter extends `InOperator`, so its operator is `in`.** The rule recorded
   for `list_string` filters — where only `or`/`and`/`not` emit SQL, because those extend
   `ManyToOne` — does **not** transfer. Check which base class the specific plugin extends
   rather than applying a remembered rule.
