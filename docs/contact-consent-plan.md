# Plan — Opt-in / consent on Contact

**Status:** built & verified on **dev**; **not yet on live** (live deploy needs a DB dump + go-ahead). **Companion evidence:** [`contact-model-discovery.md`](contact-model-discovery.md).

## As-built (dev-verified 2026-09-05)

Decision taken: **split opt-in flags** — marketing vs service, per channel — **plus an append-only consent log** for provenance. Scripts (all idempotent, dry-run by default) + one module:
- `web/scripts/setup_contact_consent_fields.php` — Stage 1 (add flags to Contact).
- `web/scripts/setup_consent_log_entity.php` — the `consent_log` ECK entity.
- `bos_consent_log` module — auto-writes the log on every flag change; append-only guard; maintains the consent cache on Contact.
- `web/scripts/backfill_contact_primary_links.php` — Stage 3 (link/create primary contacts).
- `web/scripts/migrate_user_consent_to_contact.php` — Stage 2 (move legacy consent).
- `web/scripts/retire_user_consent_fields.php` — cleanup (retire User consent fields).

Dev results: link gap **649 → 37** (8 linked + 604 created; **37 left for manual review** = 11 profiles with no valid user + 26 users whose name isn't usable). Legacy consent: of 4 users with a value, **2 migrated** to `field_opt_in_service_email` (2 have no linkable contact → manual). Marketing flags correctly stayed **0** (never inferred). Three legacy User consent fields retired; operational flags (`field_do_not_schedule`, `field_credit_hold`, `field_service_suspension_reason`) kept. Consent-log verified: flag change → 1 row (channel/type/old→new/source/actor/IP/note), multi-flag save → N rows, no-op save → 0 rows, edits refused.

## Consent log (append-only provenance)

The boolean flags are the fast "current state"; the **`consent_log`** ECK entity is the audit trail that answers *when / who / from what / opt-in vs opt-out vs bad import*. One immutable row per flag change: `field_contact`, `field_channel` (email/sms), `field_consent_type` (marketing/service), `field_old_state` / `field_new_state` (unknown/opted_in/opted_out), `field_event_source` (web_form/phone/paper/import/staff/system), `field_actor` (user), `field_ip`, `field_note`, plus the ECK `created` timestamp.

Written automatically by `bos_consent_log` (`hook_entity_presave` detects changes + stamps the cache; `insert`/`update` write the rows). Callers attribute a save via hints on the contact — `$contact->_consent_source` / `_consent_actor` / `_consent_ip` / `_consent_note` (default source: `staff` if authenticated, `web_form` if anon). The log can't drift from the flags because the same save writes both. Edits are refused on every path (`bos_consent_log_consent_log_presave` throws unless the row is new). View permission granted to office/ops roles only.

## The model (confirmed)

| Record | Holds | Access |
|---|---|---|
| **User (client) + `customer_profile`** | **All secure/business data** — payment terms, tax status, invoice delivery, QB IDs, statuses. *Unchanged.* | Staff Users with appropriate roles only. Clients don't log in. |
| **Contact** (`contacts.contact`) | The **person** — name, phone, email — **plus opt-in/consent**. Nothing sensitive. | No BOS access. Data record only. |
| **Link** | `customer_profile.field_primary_contact_ref → Contact` is the spine (82% populated today). The property-level contact fields are vestigial (4/2,538) — ignore them. | — |

**Guardrail:** Contact must continue to hold *nothing sensitive*. Opt-in flags + identity only. If it isn't safe to show without a role, it stays on User/customer_profile.

**No relocation of any customer_profile data. Owners stay Users. Only opt-ins live on Contact.**

---

## Work — three additive stages + one cleanup

### Stage 1 — Add opt-in fields to Contact (additive, zero risk)

New fields on `contacts.contact` (identity fields already exist) — **split marketing vs service, per channel**:

| Field | Type | Purpose |
|---|---|---|
| `field_opt_in_marketing_email` | boolean | Promotional email (campaigns, offers, win-back) |
| `field_opt_in_marketing_sms` | boolean | Promotional text/SMS |
| `field_opt_in_service_email` | boolean | Transactional/service email ("we're coming", reminders) |
| `field_opt_in_service_sms` | boolean | Transactional/service text/SMS |
| `field_consent_updated` | datetime | When any opt-in was last captured/changed |
| `field_consent_source` | list_string | How captured: `web_form`, `phone`, `paper`, `import`, `staff` |

**Consent semantics (important):** a `NULL` `field_consent_updated` means **"never asked / unknown"**, which is *not* the same as "opted out." Marketing sends must treat unknown as **not opted in**; transactional/service messages ("we're coming Tuesday") are unaffected. This mirrors the existing (unused) `field_consent_updated` on User.

Built via an idempotent entity-API setup script (`web/scripts/setup_contact_consent_fields.php`) — ECK/field configs skip `cim`, per BOS convention. Placed on the Contact form + view displays.

**Decision (resolved):** split marketing from service, per channel (four boolean flags above). Marketing consent is never inferred from a legacy flag — it must be captured explicitly.

### Stage 2 — Migrate the existing User consent values → Contact

The consent flags on User today are essentially empty: **4** `field_ok_to_email`, **1** `field_sms_consent`, **0** `field_consent_updated`. For each User that has a value, copy it to that user's **linked Contact** (via `customer_profile.field_primary_contact_ref`), stamping `field_consent_source = import` and `field_consent_updated = now`. Trivial volume; idempotent; dry-run first.

### Stage 3 — Close the link gap (649 unlinked customer_profiles)

**~649 of 3,566 customer_profiles (18%) have no `field_primary_contact_ref`** — those clients have no person record to hang opt-ins on. Backfill, per unlinked customer_profile, in this order (idempotent, `--dry-run` default):

1. **Match** — is there an existing Contact whose normalized email equals the client User's email? (~180 contacts aren't anyone's primary contact — some are these.) If exactly one, link it.
2. **Create** — else create a Contact from the User's identity (name, `user.mail`, phone if present), then link it.
3. **Link** — set `customer_profile.field_primary_contact_ref`.
4. **Log** counts only (no PII): matched / created / skipped / ambiguous.

Ambiguous cases (multiple email matches, or a User with no usable name/email) are **left for manual review**, never guessed.

> Note: also normalize contact email to lowercase first (**1,037 contacts have uppercase emails**) so the match step is reliable and a future unique key is possible.

### Cleanup — retire the User consent flags

After Stage 2, remove the now-orphaned marketing-consent field **instances** from User (`field_ok_to_email`, `field_sms_consent`, `field_consent_updated`). **Keep** `field_do_not_schedule`, `field_credit_hold`, `field_service_suspension_reason` — those are operational governance flags, not marketing consent, and stay on User. Leaves one authoritative home for consent: the Contact.

---

## Downstream (not this migration, but note it)

- **Intake writes consent to Contact.** The public forms (`bos_service_request` winterize/fall-cleanup, `bos_homepage` estimate) already collect email; going forward they should write the opt-in to the Contact they create/match. Small follow-on per form.
- **Send-time enforcement.** BOS emails **no customers today** (all mail is internal). When a customer-facing send is built, it **must read `field_opt_in_*` on the Contact** and skip unknowns for marketing. There's nothing to retrofit — just build it right the first time.

---

## Migration order & deploy

1. Stage 1 (add fields) → verify on dev.
2. Stage 3 backfill **dry-run** on a live DB copy → review counts → apply on dev → verify.
3. Stage 2 (migrate the 5 values).
4. Cleanup (retire User consent instances).
5. Deploy each stage dev → live (rsync scripts + run + `cr`; **no cim**; DB dump before Stage 3/cleanup). ROADMAP row + `Modules`/`Entities` doc update.

## Risk

- Stages 1–2 are near-zero risk (additive + 5 records).
- Stage 3 is the only one that writes at scale (creates/links ~649 contacts). Mitigated by dry-run, match-before-create, ambiguous-skip, and a DB dump. It **only sets empty `field_primary_contact_ref`** and creates new Contacts — never overwrites an existing link or edits customer_profile business data.
- No secure data ever moves; no access change; owners/contracts untouched.

## Remaining for live

Order (DB dump first): **Stage 1 fields → `setup_consent_log_entity.php` → `drush en bos_consent_log` → Stage 3 (dry-run reviewed, then apply) → Stage 2 → cleanup → `cr`.** Enable the log module *before* Stage 2 so the migrated legacy consents get logged (source = `import`).

1. Hand the office the **37 unlinked-profile** + **2 unmigrated-consent** remainders for manual review.
2. Follow-on: intake forms write opt-in to the Contact (set `_consent_source='web_form'` + `_consent_ip`); send-time consent check when a customer-facing mail path is built; optional office UI/View over `consent_log`.
