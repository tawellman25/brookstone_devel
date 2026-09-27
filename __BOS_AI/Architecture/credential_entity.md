# Credential entity — Gate 0 findings & design decisions

**Status:** **LIVE 2026-09-27**, 18/18 read-only verification on production. Remaining: card CSS for the two card displays, and the profile-field retirement (§8/§E-7b).
**Tier:** T3 — sits behind the estimating epic. Does not block `/about-us/credentials`.
**Spec source:** "BOS — Credentials entity" (marketing project, 2026-09-27).
**Inspection tool:** `web/scripts/inspect_credential_gate0.php` (read-only, re-runnable per env).

---

## A. What the inspection confirmed

| Spec assumption | Verified |
|---|---|
| `credential` entity + `credential_types` vocab are free to create | ✅ neither exists |
| `contacts.contact` is a safe standalone reference target | ✅ **no required fields at all**; 3,705 records; carries first/last name, email, job title, status, notes, and refs out to `phone_number` (multi) + `address` |
| `bos_contact_attach` cannot see a credential reference | ✅ **risk is real** — `_entity_predelete()` cleans exactly two hosts (`profile:customer_profile`, `properties:property`) and only via `field_contacts` / `field_primary_contact_ref`. A `credential.field_renewal_contact` would be left **dangling** on contact delete. |
| Supersede pattern exists to copy | ✅ `property_backflow_device.field_replaced_by` → self-reference |
| Field access is the right mechanism, not view modes | ✅ **JSON:API is enabled, `read_only: true`, and exposes every content entity type by default** — a new ECK type is auto-published at `/jsonapi/credential/credential`. Field access is **mandatory**, not optional. `material.module`'s `hook_entity_field_access` is the working template. |
| Twenty-field ceiling | ✅ 17 proposed on `credential` + 6 on the vocab — under the ceiling |
| Field names within Drupal's 32-char limit | ✅ longest is `field_credential_documents` (26) |

## B. Four places the spec is wrong or needs a decision

**B1. `field_teammate` should target `user`, not `teammate_profile`.**
Both existing `field_teammate` instances (`wo_time_clock.entry`, `contacts.emergency_contacts`) target **user**, and **nothing in BOS references `teammate_profile` as an entity_reference target** — zero precedent. Targeting `user` also makes §5d ("My credentials") and §7 own-records access trivial, since the current user *is* the key; via a profile it is an extra hop. The profile stays reachable (1:1) for anything profile-specific.
→ **Recommend `field_teammate` → `user`**, handler-filtered to the `teammates` role.

**B2. `field_type_code` cannot be reused as specified.**
`taxonomy_term.field_type_code` already exists as **`list_string`** with `allowed_values` = the 7 **backflow** codes (PVB, RP, DCVA, SVB, AVB, DuC, HBVB). A field storage has one type per entity type, so putting credential codes on it means both vocabularies share one allowed-values list — a backflow form would offer `CDL`.
→ **Recommend a distinct `field_credential_code`** (string) on `credential_types`. `field_public_description` (text_long) *is* genuinely reusable.

**B3. `property_backflow_device` has no `field_status`.** §1 cites it as the lifecycle precedent; it does not exist there (the device has `field_replaced_by`, `field_next_due_date`, `field_device_type`, `field_test_frequency_months`). The supersede half of the precedent is real; the status half is not. `field_status` on `credential` would be a new `list_string` — closest precedent is `material_price_history` / `supplier_price_ingest_batch`.

**B4. "Reuse existing storages" mostly does not apply here.** Field storage is scoped **per entity type**, so for a brand-new `credential` type every storage is new regardless of name collisions elsewhere. Reuse only applies to the `credential_types` fields (entity type `taxonomy_term`) — see B2.

## C. Decisions I can make, with reasoning

| Decision | Choice |
|---|---|
| §8 profile-field reconciliation | **Option 1 — retire the profile fields.** Inspection shows **exactly one populated record**: Todd Wellman (uid 1), `field_certification_number = 06-2512234`, `field_certification_association = ABPA`. This is a 1-row change, not a data migration, which removes the only argument for the cache option. `field_signature` is untouched (it feeds generated reports). **Mapping still to be confirmed by Todd before any write.** |
| Date-driven status (§4) | **`hook_cron`**, modelled on `contract_residential_cron` (the only date-driven cron in the custom modules; `backflow_device` has none). ⚠ It must query **only** credentials with an expiration date inside the window — the 2026-07-03 checkup-generator runaway enqueued 95k items because its dispatch had no eligibility filter. |
| URL aliases | **None.** `pathauto.settings:enabled_entity_types` is an explicit ~80-type allowlist; leaving `credential` out avoids the silent-no-op hazard §10 warns about, and credentials need no public per-record URL (the public face is one page). |
| `bos_contact_attach` (§2b ⚠) | Extend `_entity_predelete()` to clean `credential.field_renewal_contact` too, **and** add credentials to the "do not delete referenced contacts" warning in the entity docs. Prefer the bundle's `field_contact_status` = inactive over deletion. |
| Issuing authority / regulators | Agreed — string + `field_verification_url` on the type. No contact records for CDA/ABPA. Not `supplier` (that is procurement and feeds the price-ingest pipeline). |

## D-answered. Todd's decisions (2026-09-27)

| Question | Answer |
|---|---|
| B1 — `field_teammate` target | **`user`** (not `teammate_profile`). Implemented, handler-filtered to the `teammates` role. |
| §8 migration | **Approved.** `06-2512234` / ABPA migrated into credential record id 1 on DDEV. Profile fields not yet retired (§E step 7). |
| §7 / §12.3 visibility | **Any teammate may see credentials**; only their own list on their own profile page (the profile EVA is scoped to the profile owner). |

**Access conflict this surfaced, and how it was resolved.** §7 gates `field_credential_number`
to office/admin, but §5d wants a tech pulling up *their own applicator card at a customer's
door*. Those contradict. Resolved on the axis the spec already established —
`credential_types.field_number_is_public`:

| Viewer | License/cert number (type public) | Insurance policy number (type not public) | Documents · internal notes · renewal contact/URL |
|---|---|---|---|
| office/admin | ✅ | ✅ | ✅ |
| teammate | ✅ | ❌ | ❌ |
| anonymous / client | only if the record is also `publish_publicly` | ❌ | ❌ |

Verified by `verify_credential_gate1.php` (9/9). A GL record flagged `publish_publicly` still
hides its policy number because its TYPE forbids it — §11's exact test case.

## D2. Still open (not blocking)

1. **§12.1** Which credential types are missing from the 10 seeded?
2. **§12.5** Is the insurance agent already a `contacts` record? (`field_renewal_contact` is optional, so this is data entry.)
3. `field_verification_url` is **empty on all 10 types** — deliberately not invented; the agency lookup links live in marketing's Credentials Page Copy.
4. `field_renewal_lead_days` seeded at **60 for every type**; office tunes per type.
5. `BORGERT` default scope was guessed as **company** (installer certification usually sits with the business) — correct it on the term if it is personal.

## D3. Original questions (for the record)

1. **§12.1** Which credential types are missing from the seed list?
2. **§12.2** Who besides you sees the company credentials list — office only, or all staff?
3. **§12.3** Should a tech see only their own credentials, or everyone's? (Drives own-records permission vs a filtered view.)
4. **§12.4** Is there a second backflow certification? **Inspection says no — only yours is recorded in BOS.** If another tester is certified, it exists only on paper.
5. **§12.5** Is your insurance agent already a `contacts` record? (3,705 exist; I have not searched for a specific one.)
6. **§8 confirmation** OK to migrate `06-2512234` / `ABPA` into a credential record and retire the two profile fields?
7. **B1 confirmation** OK to target `user` instead of `teammate_profile`?

## E2. Built so far (DDEV, verified)

| Stage | State | Verification |
|---|---|---|
| 1 · vocab + 10 types | ✅ | seeded, resolved by stable code |
| 2 · entity + 17 fields + perms | ✅ | add form renders; **no delete permission for anyone** (script revokes it) |
| 3 · field access | ✅ | 3×3 matrix exact, incl. a publishable GL record still hiding its policy number |
| 4 · date-driven status (`hook_cron`) | ✅ | 3/3 transitions: active → pending_renewal → expired |
| 6 · backflow integration | ✅ | 7/7 — snapshot reads the credential as of the **test date**, and does **not** move when the credential is later superseded |
| 7a · contact delete-cleanup | ✅ | credential survives; dangling reference cleared |
| 5 · displays | ✅ | 12/12 on dev, all five executed; live 18/18 |
| 7b · profile-field retirement | ⬜ | credential record exists; fields not yet removed |

**Chosen for §4's open question:** `hook_cron`, because it lets the expiring-soon view filter a
STORED status (`pending_renewal`) instead of doing cross-field date maths against the type's
lead-days in Views, which Views cannot express. The query is filtered to credentials that have
an expiration date and an auto-managed status, and saves only on a real change.

**Correction to a Gate 0 finding:** the certification snapshot lives in
**`wo_backflow_testing.module`** (`_wo_backflow_testing_snapshot_cert`), not
`backflow_device.module` — the inspection scanned only three module directories and missed it.
The rewrite kept the semantics exactly (fill-only-if-empty, never overwrite a typed value,
never clear to blank, frozen at WO Complete) and changed only the source, with a fallback to
the profile fields until they are retired.

**Tester filtering (§6) deliberately NOT built.** The spec's own framing is "with several
certified testers" — BOS has **one**. A hard filter on the `field_tester` autocomplete would
make it impossible to record a test for anyone whose credential has not been entered yet. The
non-blocking **warning** is built instead (mirroring the existing
`_wo_backflow_testing_gauge_cal_validate` gauge-calibration warning in the same file), and it
distinguishes "no certification recorded" from "lapsed by that test date" — different fixes.
Revisit the selector filter when a second tester is certified.

## E. Build order once unblocked

1. `credential_types` vocab + 6 fields + idempotent seed script (terms are content — run per env).
2. `credential` entity + bundle + 17 fields; scope validation both directions (§3).
3. `hook_entity_field_access` (copy `material.module`) — **before** any display work, because JSON:API is live.
4. Title auto-set on insert; supersede wiring (§4).
5. Five displays (§5), the expiring-soon view phone-legible.
6. Backflow integration (§6) — keep the snapshot, change only its source; add the expiry validation + tester filtering.
7. `bos_contact_attach` extension; profile-field retirement (§8) last, after the credential records exist.

## F. Explicitly not building (§9)

No notification engine · no document generation · no public expiration dates · no public copy (lives in `Website Copy/Credentials Page Copy.md`) · nothing storing an SSN, DOB or licence-card image.

## G. Live state (2026-09-27)

Deployed: vocabulary + 10 types, entity + 17 fields, permissions (view for teammates,
create/edit for office, **delete for nobody** — the script revokes it), `bos_credential`,
the five displays, and the §8 migration of Todd's ABPA certification
(`06-2512234`, ABPA, **expires 2028-12-31** — supplied by Todd, never invented).

Read-only live verification: **18/18** (`verify_credential_live.php` — creates nothing, so
the dev verifiers' throwaway records stay off production). Live holds exactly **one**
credential record; no test data.

Two findings from the live run:
- The first run reported a false FAIL because the check asserted `LEFT JOIN`. ManyToOne
  (list_string) filters emit **INNER JOIN**. The live query was correct all along
  (`WHERE field_scope_value = 'company'`, 0 rows because there are no company credentials
  yet). Assertion fixed; the lesson is that a verifier can be wrong in the reassuring
  direction as easily as the alarming one.
- `drush updatedb:status` warns that **`migrate_devel`** has a stale `system.schema`
  key/value entry. **Pre-existing and unrelated** — the module was removed from
  `core.extension` on 2026-06-20 and the schema row was left behind. No enabled module or
  theme is missing from disk and **no database updates are required**. Harmless noise;
  clearing the row would silence it.

## H. Remaining

1. **Card CSS** for `page_expiring` + `page_mine` + the profile block — they carry the
   `credential-card` row class but no stylesheet yet, so they render as unstyled rows. §5c
   requires the expiring view to be phone-legible.
2. **§8 retirement** — remove `teammate_profile.field_certification_number` and
   `field_certification_association` once the credential path has been exercised in the
   field. The backflow snapshot currently falls back to them, so retirement is safe to defer
   and unsafe to rush.
3. **Office data entry**: the insurance agent as a `contacts` record (Todd 2026-09-27: not in
   BOS, lives in QuickBooks — this is the single highest-value entry, since it is what makes
   `field_renewal_contact` answer "who do I call at 4pm"); company GL/WC/Auto records; each
   type's `field_verification_url`.
4. Place the public block once a public credentials page exists (neither `/credentials` nor
   `/about-us/credentials` exists today).
