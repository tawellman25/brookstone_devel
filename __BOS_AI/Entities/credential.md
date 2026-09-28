# BOS Entity — credential

**Status: built and live** (2026-09-27; public-page guard added 2026-09-28).
This is the as-built reference. The build diary, the four spec corrections it
produced and Todd's decisions live separately in
`Architecture/credential_entity.md` — that file is a record of *how we got
here* and reads like an unfinished plan; this file is *what exists*.

## Purpose

One record per licence, certification, registration or insurance policy the
company or a teammate holds. It exists so a credential can be looked up from a
phone, so expiry is visible before it bites, and so certification data stops
living in two places (it previously sat on `teammate_profile` as loose fields).

It is **not** a notification engine, a document generator, or a public
compliance page. Those were explicitly out of scope.

## Entity type and bundle

| | |
|---|---|
| Entity type | `credential` (ECK) |
| Bundles | **one** — `credential`. There is no per-type bundle; the *type* is a taxonomy reference. |
| Records on live | 8 |

A single bundle is deliberate: a licence and an insurance policy differ in
which fields are filled, not in shape, and per-type bundles would have meant
ten near-identical field sets.

## The type axis — `credential_types` vocabulary

`field_credential_type` (required) points at this vocabulary. Ten terms:

| Code | Type | Number public? |
|---|---|---|
| `ABPA_TESTER` | ABPA Backflow Tester | yes |
| `BORGERT` | Borgert Certified Installer | yes |
| `CDA_COMM_APP` | CDA Commercial Applicator (Business) | yes |
| `CDA_PRIV_APP` | CDA Private Applicator | yes |
| `CDA_QS` | CDA Qualified Supervisor | yes |
| `USDOT` | USDOT Registration | yes |
| `CDL` | CDL | **no** |
| `AUTO` | Commercial Auto | **no** |
| `GL` | General Liability | **no** |
| `WC` | Workers' Compensation | **no** |

`field_number_is_public` on the TERM is what resolves the contradiction between
"gate the credential number" and "a tech should be able to pull up their own
applicator card". A licence number is public record; an insurance policy number
and a CDL number are not. Because the flag lives on the type, nobody can
publish a policy number by ticking a box on one record.

`field_renewal_lead_days` (60 on all ten) drives the status transition below.

## Scope — the field that must never be inferred

`field_scope` (**required**, `company` | `teammate`) says whose credential it
is. It is validated in **both** directions on presave and on the form:

- `scope = teammate` requires `field_teammate`
- `scope = company` requires `field_teammate` to be EMPTY

This is deliberate. The obvious alternative — treating "no teammate" as
"company" — makes a half-filled form silently become a company credential.
`field_teammate` targets **user**, not `teammate_profile`: nothing else in BOS
references that profile bundle, and keying on the user is what makes "my
credentials" work off the current user.

## Fields

| Field | Type | Notes |
|---|---|---|
| `field_credential_type` | entity_reference → `credential_types` | **required** |
| `field_scope` | list_string | **required** — `company` \| `teammate` |
| `field_teammate` | entity_reference → user | required when scope = teammate |
| `field_credential_number` | string | gated by the type's `field_number_is_public` |
| `field_issuing_authority` | string | |
| `field_issue_date` / `field_expiration_date` | datetime | |
| `field_status` | list_string | `active` \| `pending_renewal` \| `expired` \| `superseded` \| `void` |
| `field_superseded_by` | entity_reference → credential | renewal chain |
| `field_coverage_limits` | string | insurance |
| `field_public_description` | text_long | the copy shown on the public page |
| `field_publish_publicly` | boolean | office opt-in, default off |
| `field_list_order` | integer | public page ordering (licences 10–30, insurance 40–60, fleet 70+) |
| `field_credential_images` | image | scans/photos — **office + crew only** |
| `field_credential_documents` | file | **office only** |
| `field_internal_notes` | text_long | **office only** |
| `field_renewal_contact` | entity_reference → contact | **office only** |
| `field_renewal_url` | link | **office only** |

Title is composed on presave as `{type} — {holder}`, e.g.
*ABPA Backflow Tester — Todd Wellman*, *General Liability — Company*.

## Access — field level, not display level

Enforced in `bos_credential.module` via `hook_entity_field_access`, **not** by
leaving fields off a display. **JSON:API is enabled and auto-exposes every
content entity type**, so a hidden field on a view display is presentation, not
protection.

- **Office only:** documents, internal notes, renewal contact, renewal URL
- **Office + crew:** credential images (a scan can show the number the type-level
  flag exists to suppress)
- **The number:** office always; others only when the TYPE says it is public

One subtlety worth knowing before "fixing" it: the **itemless** check returns
**neutral**, not forbidden. Views calls `fieldAccess()` with no items when
deciding whether a field may appear in a display at all, so failing closed
there made the licence number unrenderable everywhere — including the public
page where it is public record. Every path that exposes a VALUE re-checks with
items, and that was proven, not assumed
(`web/scripts/verify_credential_number_access.php`, 7/7).

**Nobody may delete a credential.** The setup script actively revokes it —
credentials are permanent history a filed test report may reference.

| Role | Access |
|---|---|
| supervisor, administration, site_assistant, site_admin | create, view, edit |
| teammates | view (own credentials on their profile page) |
| client, anonymous | no entity access — the public page is a view, not entity access |

## Lifecycle

`hook_cron` moves status by date: `active` → `pending_renewal` once inside the
type's lead window → `expired` after the date. The query is filtered and saves
only on change (the 5.1M-item checkup-queue runaway is the reason that matters).

Status is STORED rather than computed because the expiring-soon view has to
filter on it, and Views cannot express "expiry date within this type's lead
days".

Renewal **supersedes, never overwrites** (`field_superseded_by`), so a filed
backflow report keeps the number as it was on the test date.

## Where it surfaces

| Path | Who | What |
|---|---|---|
| `/about-us/credentials` | public | approved, **active**, published records; no dates, no documents |
| `/admin/operations/system_content/credentials` | office | all records |
| `…/credentials/company` | office | scope = company |
| `…/credentials/expiring` | office | `pending_renewal` + `expired` |
| `/teammates/credentials` | crew | their own |

Plus a block on the teammate profile page scoped to the **route** user, so a
supervisor viewing a teammate sees that teammate's.

**The public display carries two filters, and both matter:**
`field_publish_publicly = 1` **and** `field_status = active`. The status filter
was added 2026-09-28 after the page was found publicly showing *"CDA Qualified
Supervisor — Gerald Reeves, Licence number: 0000000"* — a placeholder number on
a credential expired since 2025-02-25. The publish flag alone is one tick away
from that.

⚠ **Filter operator on `field_status` is `or`, NEVER `in`.** It is a
`list_string`, so the handler is ManyToOne, which defines no `in` operator —
`in` emits no SQL at all, matches everything, and would publish every record.

## Integration

`wo_backflow_testing` snapshots the tester's certification onto the test record
**as of the test date**, reading the credential and falling back to the legacy
`teammate_profile` fields. A non-blocking warning fires if the tester has no
valid certification on that date, mirroring the existing gauge-calibration
warning. A hard filter was deliberately NOT used: the spec assumed several
certified testers and BOS has one, so blocking would stop a test being recorded.

`bos_contact_attach` clears `field_renewal_contact` when a contact is deleted,
so no dangling reference is left.

## Current data (live, 2026-09-28)

8 records. **Only ABPA Backflow Tester — Todd Wellman publishes.** Seven carry
the `0000000` placeholder with `field_publish_publicly` off.

**Office rule: a record publishes when its number stops being zeros** — and now
also only while its status is `active`.

Outstanding office data entry: real number, expiration, renewal contact and a
scan for the seven placeholders; the insurance agent as a `contacts` record
(per Todd it is not in BOS — it lives in QuickBooks); each type's
`field_verification_url`.

## Deliberately not built

No notification engine, no document generation, no public expiration dates (an
expired date on a live page is worse than none — the verification link pushes
freshness to the issuing agency), and no tester-selector filtering.

## Known open item

`teammate_profile.field_certification_number` and
`field_certification_association` are **not yet retired** — the backflow
snapshot still falls back to them. Retiring them is §8 of the original spec and
is deferred, not forgotten.
