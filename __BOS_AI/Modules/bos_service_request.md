# bos_service_request — Public Service-Request Intake Layer

**Added 2026-08-20. Live (Gates 1–5).** A public, unauthenticated path that lets
a customer or prospect request a service, produces a **controlled internal
request record** (`service_request` ECK entity), and creates a real BOS Work
Order **only after a human in the office approves it**.

First workflow: **Sprinkler Winterizing**, 2026 postcard-QR campaign. The layer
accepts additional service bundles without re-architecture.

**Non-negotiable:** intake is never execution. No anonymous path creates,
schedules, or modifies a Work Order, Property, Contact, or Contract. BOS remains
the authoritative operational system; a public submission is *intake*.

Module dependencies: `eck`, `options`, `telephone`, `bos_wo_intake`, `captcha`.

---

## Why a new entity (not `estimate_request`)

Routing winterizing through `estimate_request` is a trap: `estimate_intake`
auto-creates an `estimate` entity for every service term with
`field_estimate_service = TRUE`, and Winterizing (term **369**) has it set — and
`sprinkler_winterizing` is in the `estimate_tasks` allow-list. Every postcard
scan would spawn an estimate + estimate-task and pollute the Estimate Board. So
`service_request` is its own ECK entity, and `bos_service_request` is its own
module (kept out of `bos_wo_intake`'s API-key / `system_integration` access
surface — it consumes `bos_wo_intake`'s services, never re-implements them).

---

## Data model — `service_request` (ECK)

Entity type `service_request`, bundle `sprinkler_winterizing` (bundle machine
name mirrors `field_service_bundle`). Created by the idempotent entity-API script
`web/scripts/setup_service_request_entity.php` (ECK field instances silently skip
on `cim`). 23 fields, all names ≤ 32 chars.

| Field | Type | Notes |
|---|---|---|
| `field_property` | ref → properties | Nullable. Set only on a confident single match. |
| `field_service` | ref → taxonomy:services | 369 for this bundle (authority for the WO bundle). |
| `field_service_year` | integer | Part of the uniqueness key. |
| `field_request_status` | ref → taxonomy:service_request_status | Lifecycle. |
| `field_source` | list_string (`module: options`) | website / postcard_qr / phone / office / email / other. |
| `field_campaign` | string(64) | Normalized against the allowlist. |
| `field_public_ref` | string(12) | Opaque customer-facing ref (`W-XXXXXX`), never the entity id. |
| `field_submitted_*` | name/address/zip/phone/email | Verbatim record of what a stranger typed — evidence, never written back as authoritative data. |
| `field_access_notes` / `field_customer_notes` / `field_office_notes` | string_long | |
| `field_match_candidates` | **string_long** (not text_long) | JSON of candidate property ids + scores when ambiguous. |
| `field_review_flags` | string_long | newline machine flags: no_services, credit_hold, do_not_schedule, no_sprinkler_system, zip_out_of_area, ambiguous_property, unmatched_property, **contract_completed_for_year** (Phase 2 P0.2). |
| `field_existing_work_order` / `field_existing_contract` | refs | What already covered them, if any. |
| `field_work_order` | ref → work_order | The WO created at conversion. |
| `field_converted_by` / `field_converted_on` | user / timestamp | |
| `field_wants_recurring` | boolean | "Add me to the automatic winterizing list each fall." The front of the funnel. |
| `field_wants_startup` | boolean | (Phase 2 P1.2) "Contact me in the spring about turning my system back on." Second cross-sell; recorded intent only. |
| `field_water_supply` | list_string (`module: options`) | (Phase 2 P1.3) city / ditch / well / unsure. Stored raw; NEVER written to `property_ss_sources`. "Not sure" is first-class. |
| `field_notice_version` | string(64) | (Phase 2 P0.5) sha256 of the exact freeze disclaimer shown to this submitter. |

Title `Service Request #{id}` set in `hook_ENTITY_TYPE_insert` (NOT an AEL
`[entity:id]` pattern — nothing heals `service_request` the way `wo_shared` heals
work_order). `field_public_ref` generated in `hook_ENTITY_TYPE_presave`.

Status vocabulary `service_request_status` (seeded by
`web/scripts/seed_service_request_status.php`): New, Needs Review, Verified,
Already Covered, Duplicate, Rejected, Converted. **Terms are content — TIDs
differ per env (dev 1939–1945, live 1932–1938); resolve by NAME via
`ServiceRequestStatusResolver`, never hardcode.**

---

## The eligibility authority (§5) — `ServiceRequestEligibility`

One helper, consumed by the public form, the office convert action, and a
presave backstop. `evaluate(propertyId, serviceTermId, serviceYear,
?excludeRequestId)` returns the **first hit**:

1. Property `field_no_services` → `not_eligible` (flag `no_services`).
2. Owner `field_credit_hold` / `field_do_not_schedule` (on the **User**, via the
   latest `ownership_record` — reuses `estimate_intake.intake_lookup`) →
   `not_eligible`, no customer-facing leak.
3. Existing **non-Canceled** WO of the bundle for the property inside the
   configured service-year window → `already_covered` (WO path). The
   non-blocking set is enumerated positively as `[1098]` (Canceled) — never a
   `NOT IN (done set)`, which has produced a production trap.
4. **Contract coverage** — a current-year residential contract in a covered
   status holding a `contract_sections` row with `field_service = 369` and
   `field_do_you_want IN (1 Yes, 4 Accepted)` → `already_covered` (contract
   path). *The WO may not exist yet — this is the single biggest reason WO-only
   dedup is insufficient.* `field_do_you_want` is `list_string`; "truthy" means
   Yes/Accepted, **not** non-empty (461 Yes vs 383 No on live).
5. Existing active service request (status not Rejected/Duplicate/Converted) →
   `duplicate`.
6. Otherwise → `eligible`.

`COVERED_CONTRACT_STATUS_TIDS = [1123 Approved, 1651 Generate WO, 1124 WO
Created, 1125 Assigned]` — statuses where work IS coming. **(Phase 2 P0.2,
2026-08-22): 1127 "Completed for the Year" was REMOVED from the covered set.**
1127 asserts the season is finished, not that coverage is coming — a customer
writing in against a 1127 contract is a *disagreement*, not proof of coverage.
Per **asymmetric failure** (accept + flag beats silently swallow): a current-year
contract at 1127 holding a wanted term-369 section now returns **eligible** with
review flag `contract_completed_for_year` (via `contractCompletedForYear()`),
never `already_covered`.
Service-year window uses `DrupalDateTime` in site tz (never `FROM_UNIXTIME` — the
live MariaDB session tz is MST-no-DST). Deferred: the "or a linked scheduling
date in the window" augmentation (created-in-window only for now).

---

## Property matching (§6.0/§6.1) — `ServiceRequestPropertyMatcher`

**INVARIANT — the public never sees, selects, or names a property.** No property
element (visible/hidden/`#access:FALSE`), no candidate list, no "did you mean,"
no property identifier accepted by any route. Any injected `property_id` /
`field_property` is ignored and logged. Matching runs entirely server-side in the
submit handler, after the form is committed; the submitter never learns the
outcome. Enforced + tested against rendered HTML (see verifiers).

`match(lastName, street, zip, phone, email)` → matched | ambiguous | unmatched:
- Reuses `bos_wo_intake`'s shared `PropertyMatchNormalizer` (extracted from that
  module's private helpers — one normalizer, one street_suffix_map).
- SQL prefilter is **suffix-agnostic** (drops a trailing street-suffix token and
  wildcards between the rest) so a submitted "Lane" still finds a stored "Ln";
  the precise suffix-normalized compare runs after. (A literal-suffix prefilter
  was the 2026-08-21 bug where `Ln` matched and `Lane` didn't.)
- ZIP filter via `properties.field_zipcode_reference → zipcodes.field_zipcode`;
  unknown ZIP still accepted (flag `zip_out_of_area`).
- Exactly one street+ZIP match → matched; >1 → ambiguous (candidates JSON,
  office-only); 0 → unmatched. Zero properties/contacts are ever created.
- `contactCorroborates(propertyId, phone, email)` gates the disclosure copy (see
  enumeration control) — a street address alone never unlocks it.

---

## Conversion (§8) — `ServiceRequestConverter`

`convert(request, actor)` — the ONLY place a public request becomes execution,
and only by an explicit office action. Locked (`\Drupal::lock`), transactional,
**idempotent** (a double-click / replay / direct URL creates no second WO):

1. Lock + `loadUnchanged`; abort if already Converted or `field_work_order` set.
2. Re-run eligibility (excluding self); refuse if no longer eligible.
3. `WorkOrderIntakeService::createBareWorkOrder(propertyId, 369)` — **all WO
   creation is delegated to `bos_wo_intake`; this class has none of its own.**
   WO lands **Open (1089)**.
4. Set `field_work_todo_description` (customer/access notes) + `field_contract`
   (current-year residential contract) on the WO.
5. Create a `wo_notes:note` (verbatim request text + source/campaign + `Created
   from Service Request {ref}`) — explicit `createAccess()` gate; `field_note_kind
   = manual`, `field_is_system_note = TRUE`. Attribution must survive conversion.
6. Write back `field_work_order` / `field_converted_by` / `field_converted_on` /
   status Converted. Commit, release lock.

---

## Public form (Gate 3) — `/winterize`

Route `/winterize` (+ `?c=<campaign>`), `_access: TRUE`, `no_cache`. Form API.
- captcha (site default challenge) + Drupal `flood` (per IP/hr + per normalized
  address/service-year). Config-driven **open/closed gate** (`signup_open` +
  `open_from`/`open_until`) → a static closed page, not a 404.
- Entity created programmatically as **uid 0** — anonymous holds **no ECK create
  permission** (documented; do not "fix" by granting one). Gotcha-safe: form
  props are protected non-readonly (captcha serialization), `mb_substr`
  truncation, campaign `c` normalized against the allowlist (unknown → `unknown`
  + escaped office note).
- **Confirmation states (§7, verbatim copy):** the "already on our list" /
  duplicate copy fires ONLY when the submitted email/phone corroborates the
  contact AND `disclose_existing_coverage` is on; everything else (matched,
  ambiguous, unmatched, flagged, non-corroborated covered/duplicate) returns the
  identical neutral "received" copy with the `W-` ref. Enumeration control: the
  response never reveals whether an address exists in BOS.

---

## Office workflow (Gate 4)

Queue view **`service_request_admin`** at **`/admin/office/service-requests`**
(built by `web/scripts/build_service_request_admin_view.php`; menu link nested
under **Office**, ordered before Estimates). Rendered as **BOS status cards**
(2026-09-04) — unformatted rows + the row template
`templates/views-view-fields--service-request-admin.html.twig`, card data built
in `bos_service_request_preprocess_views_view_fields()` (`sr_card`), CSS
(`css/request-cards.css`) attached via `hook_views_pre_render`; theme suggestion
registered in `hook_theme` (base hook `views_view_fields`). Each card: name
(links to the request page) + a color-keyed **status badge** (left accent bar
matches), ref + submitted datetime + campaign, **click-to-call phone + email**,
address, matched property (linked), created **Work Order** (linked), interest
tags (auto-each-fall / spring start-up / wants-a-date), review flags, and the
**Operations** dropbutton (rendered view field `fields.operations`). Exposed
filters: **status** (`taxonomy_index_tid`, scoped to `service_request_status`) /
service year / source / campaign. Access: `administer service requests`
(administration, supervisor, site_admin, administrator; granted via
`hook_install` + `hook_update_10001`, plus ECK entity perms by the setup script).
Anon → 403.

Actions are **per-row confirm forms, NOT VBO** (the documented VBO-footgun
family: mass-invoice, Back-button replay, shift-click select):
- **Approve & Create Work Order** (`ServiceRequestConvertForm`) → the converter.
- **Mark Duplicate / Mark Already Covered** (links existing WO/contract) /
  **Reject** (required reason → office notes) (`ServiceRequestActionForm`).
- `hook_entity_operation` exposes these in the dropbutton (hidden once
  converted). Presave backstop blocks status → Converted without a linked WO.

The queue renders as **BOS status cards** (2026-09-04) with exposed **Type**
(bundle) + **Status** + year/source/campaign filters — see the card note under
the queue view. Both service_request bundles (winterize + fall cleanup) share
this one queue.

---

## Fall Cleanup bundle + landing page (2026-09-04)

A second `service_request` bundle, **`fall_cleanup`**, with a public landing page
at the Fall Cleanup **services term** URL
(`/services/landscape-lawn-care/yard-cleanup/fall-cleanup`) — a Google Ads
sitelink target (`?c=goog26-fall`).

- **Page:** the services term (term 413 on dev; resolved per env **by name**) is
  rendered as a marketing landing for **public/client viewers only** — internal
  roles keep the normal term page (bos_services teammate_view crew training).
  Mechanism mirrors `/winterize`: `page__fall_cleanup` theme suggestion +
  `_bos_service_request_preprocess_fall_cleanup()` (gated by
  `_bos_service_request_is_fall_cleanup_page()`), template
  `page--fall-cleanup.html.twig`, library `bos_service_request/fall_cleanup`
  (depends on the winterize CSS). It keeps a **breadcrumb bar** but drops site
  nav (a conversion landing, like winterize).
- **Editable copy:** the long "How we do it" body lives in the term's
  `field_service_public_desc` (editable in the admin UI — no deploy), rendered as
  accordions. The short hero/step/close chrome is in the template + preprocess
  (matches winterize).
- **Form:** `FallCleanupForm` (Form API, reCAPTCHA, flood, `?c=` via a hidden
  field that survives validation). Creates a `service_request:fall_cleanup`
  (ref prefix **F-**). Fall-specific fields: `field_fc_needs` (multi checkboxes),
  `field_fc_tree_count`, `field_fc_wants_winterize/_snow/_landscape`,
  `field_fc_linked_winterize`, `field_fc_linked_estimate`.
- **Cross-sell (eliminates office follow-up):**
  - *Winterize my sprinklers* → also creates a linked
    `service_request:sprinkler_winterizing` (Needs Review, note refs the FC ref)
    so the person lands in the winterize queue.
  - *Spring landscape project* → auto-creates an **`estimate_request`**
    design-build lead (status New - Gathering Info, priority normal, service
    Landscaping). **Note:** this cascades through `estimate_intake` to also spawn
    an Estimate + a Contact — deliberate (full pipeline entry; reCAPTCHA + flood
    guard the spam vector).
  - *Snow removal contract* → boolean + `snow_contract_requested` review flag on
    the card (no snow intake bundle; office follows up).
- **SEO:** title "Fall Cleanup | Brookstone Outdoors" (natural, site name =
  "Brookstone Outdoors") + a page-scoped meta description. No JSON-LD (matches
  winterize).
- **Setup (per env):** `setup_fall_cleanup_service_request.php` (bundle + fields)
  → `setup_fall_cleanup_page.php` (config + body copy; resolves the term by name)
  → `build_service_request_admin_view.php` (rebuild queue with the Type filter).

---

## Campaign report + QR asset (Gate 5)

Tabs on the queue page: **Queue / Report / Postcard QR**.
- **Postcard QR** (`.../qr`) — `ServiceRequestQrController`: a print-ready page
  with the QR (via `endroid/qr-code` v6 Builder, encodes `/winterize?c=pc26`) +
  the URL + office phone printed beside it (the QR is not the only path).
  `.../qr.png` serves the QR inline (on-page `<img>`) or as an attachment
  download (`?download=1`, 1200px) for the print shop. (The `<img>` points at the
  PNG route because `data:` URIs are stripped by `#markup` XSS filtering.)
- **Report** (`.../report`) — `ServiceRequestReportController`: attribution
  roll-up (totals, `wants_recurring` opt-ins, by source / campaign / year /
  status) via GROUP BY aggregates.

---

## Config

`bos_service_request.settings` (config/install + schema; `office_phone`
970-835-9661). Per-bundle: `service_term_id`, `service_year`, **`signup_open`
(instant kill switch — no deploy)**, `open_from`/`open_until`,
`service_year_start`/`_end`, `scheduling_notice`, `disclose_existing_coverage`.
Plus `campaigns` allowlist — **(Phase 2 P0.1, 2026-08-22)** now `[website,
pc26a, pc26b, pc26]`: the 2026 mailing is two variants — **pc26a** ("already on
our list", reassurance) and **pc26b** ("time to schedule", conversion); legacy
**pc26** is kept accepted (may be in a test QR) and reported separately. `flood`
(per_ip_hour 5, per_address_year 2), `office_phone`, and per-bundle
**`freeze_disclaimer`** (Phase 2 P0.5 — freeze-damage notice shown by the submit
button; office-editable in config, seeded by `hook_update_10002`).

**Postcard QR** (`ServiceRequestQrController`): the print page now renders **both
variants** (pc26a + pc26b), each with its own QR + high-res PNG download; `?c=`
still renders a single code. **Report** breaks out A vs B explicitly (a
dedicated "Postcard variants" table) — B's response is the number that justifies
next year's spend and is never pooled with A.

**Live state:** `/winterize` is OPEN (`open_from` set to 2026-08-20 for the
early rollout). Intended production window ran from 2026-08-25.

---

## Verification (BOS standard — idempotent verifier scripts, not PHPUnit)

- `web/scripts/verify_service_request_gate2.php` — 15/15 dev (eligibility,
  matcher, converter incl. idempotency; normalizer parity re-verifies the live
  `/wo-intake` path).
- `web/scripts/verify_service_request_gate3.php` — 13/13 dev (§6.0 invariant vs
  rendered HTML, property injection ignored, unmatched creates zero
  properties/contacts, matched≡unmatched confirmation).
- `web/scripts/verify_service_request_gate4.php` — 10/10 dev (route access,
  operations gating, presave guard, end-to-end convert).
- Read-only subsets pass on live.

---

## Deploy

`scp`/rsync the module + `drush en bos_service_request`, then run
`seed_service_request_status.php`, `setup_service_request_entity.php`,
`build_service_request_admin_view.php`, `move_service_request_view_path.php`,
`reorder_office_menu.php`, `cr`. **No cim, no DB migration.** All setup scripts
are idempotent, run per environment (content TIDs + menu-parent UUIDs differ per
env and are resolved by name/route).

---

## Phase 2 (2026-08-22) — shipped live

- **P0.1** campaign variants (pc26a/pc26b + legacy pc26); QR page renders both,
  report breaks out A vs B (see Config + Gate 5 above).
- **P0.2** 1127 removed from covered → accept + `contract_completed_for_year`
  flag (see §5 above).
- **P0.3 landing page** (`/winterize`): header (banner + `tel:`) → subtitle +
  seasonal notices → **public summary above the form** → the form → **freeze
  disclaimer by the submit button** → "What happens next" → the full public body
  as **JS-free `<details>` accordions below the form** (split by `<h3>`). All
  copy from services term 369 (`field_subtitle` + `field_service_public_desc`
  summary/body via `bos_services`); the crew body is NEVER read here. Every block
  renders nothing when empty. **No outbound links above the form; no
  backflow-service link/checkbox** (owner decision — backflow not promoted).
- **P0.4** `seed_winterizing_service_copy.php` — idempotent, `--force`/`SEED_FORCE`
  guarded, writes subtitle + summary + body to term 369 (plain `<h3>/<p>`; the
  template builds the accordions — no stored `<details>`, no format trap).
- **P0.5** freeze disclaimer by the button + `field_notice_version` (sha256 of
  the shown text). Config `freeze_disclaimer` per bundle.
- **P1.2/P1.3** second opt-in (`field_wants_startup`) + optional water-supply
  select (`field_water_supply`, raw only, never to `property_ss_sources`).
  **`supply_mismatch`** flag when a matched property's `property_ss_sources`
  (reached via `property_sprinkler_info.field_systems → property_sprinkler_system
  ← field_property_ss_system`) disagrees with the submitted supply ("unsure"/
  empty never mismatches; no source records → no flag).
- **P1.4** standing shut-down flag — `property_sprinkler_info.field_ss_shut_down_contract
  = TRUE` at the eligible branch (no current-year WO/covered section) → eligible
  + `standing_flag_no_contract` (flags, never blocks; same asymmetric failure).
- **P1.5** exposed **"Review flag contains"** filter (identifier `flag`) on the
  `service_request_admin` queue (`add_service_request_review_flags_filter.php`).
- **P1.6** coverage-signal disagreement report
  (`report_winterize_coverage_disagreement.php`, read-only): lists every property
  where the three signals (standing flag / current-year section / winterizing WO)
  disagree; category `1_standing_flag_only` = next year's variant-B list.
- **Verifier** `verify_service_request_phase2.php` — 15/15 dev + live (read-only:
  campaign allowlist, 1127 correctness + no-regression, standing flag, supply
  mismatch, landing page, crew-leak, no-backflow-link, no property element, seed
  idempotency).
- **§5** seasonal strings (`scheduling_notice`, `specific_date_notice`,
  `closed_notice`, `freeze_disclaimer`) via `hook_update_10003`; **§7**
  confirmation copy refreshed (multi-paragraph).
- **P2.1 (cross-module)** `bos_services` now adds a `user.roles` cache context to
  the services term render — the role-based view-mode switch was NOT cache-safe
  with Dynamic Page Cache on (confirmed real leak risk; fixed).

**⚠ Flagged conflict:** verifier scenario 8 ("no occurrence of *backflow*")
contradicts the approved copy, which uses "backflow" correctly as a *component*
to protect ("cover your backflow assembly"). Enforced the real intent instead —
**no backflow-service promotion** (no checkbox/section, no link to
`/services/backflow-prevention`) — not a blanket word ban. Reported, not
silently reconciled.

## Remaining (Phase 2 next passes)

- **P2.2 — answered:** the `/services` listing formatter is **Trimmed**
  (`text_trimmed`, 300) → it uses the body, ignores the summary; writing a
  summary does not change the listing teaser.
- **P2.3 / carried:** eligibility "or a linked scheduling date in-window"
  augmentation (created-in-window only today); **Gate 6** — prove the
  abstraction with a second bundle (sprinkler repair / spring start-up).

## New-customer conversion — guided "Create Customer & Property" (2026-08-27, LIVE)

When a public signup doesn't match an existing property (a new customer), the
request lands **Needs Review** with **no `field_property`**. The office queue then
shows a **"Create Customer & Property"** operation (`hook_entity_operation`, shown
only when not converted AND no property).

- **Route:** `bos_service_request.create_customer` →
  `/admin/office/service-requests/{service_request}/create-customer`
  (`administer service requests`). Registered before the generic `{op}` catch-all.
- **Form** `CreateCustomerForm` — prefilled from the request (name split into
  first/last, address, ZIP, phone, email). A **"Look up on Google"** button
  geocodes the address (server-side `geocoder`) and shows a static-map confirm.
- **`CustomerProvisioningService::provision()`** creates, in one DB transaction:
  property → phone_number (contacts bundle) → contact → **client user** (`client`
  role; **username = person's real name "First Last"**, not email) →
  **customer_profile** (`field_client_type` required, default 1 = Person/Couple;
  `field_primary_contact_ref` set so the `customer` module doesn't auto-make a
  blank contact) → **ownership_record** (owner ↔ property). Then links the contact
  onto the property and sets `service_request.field_property` so the existing
  **Approve & Create WO** finishes.
- **Geocoding keys:** the property map + address search use the client-side
  `geofield_map` referrer key; the guided form's server-side lookup uses the
  `geocoder.geocoder_provider.googlemaps` **server key** (IP-locked to live,
  Geocoding API, live active config only + `config_ignore`). See the two-keys
  gotcha in `Governance/drupal_bos_gotchas.md`.

Also this pass: winterize signup forms capture **First + Last name** (matcher
still keys on surname) and reword the "since last year" note to "Anything we
should know about your sprinkler system?".
