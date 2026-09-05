# Contact model discovery — read-only findings

**Date:** 2026-09-05 · **Environment:** local DDEV (Drupal 10.6.15, MariaDB) · **Scope:** read-only. No config, code, or data was changed. Production was not touched.

**Privacy:** counts and patterns only — no names, emails, phones, or addresses.

Evidence is cited as active config objects, entity-field definitions (read via `entity_field.manager`), and `drush sql:query` counts against the local DB. Note: `config/sync` is intentionally drifted from active on this project, so every structural fact below was read from **active** state, not the sync YAMLs.

---

## 1. Answers

### Q1 — How is Contact ↔ Property modelled?

**The reference always points *from* the other entity to Contact (and to Property). There is no bridge entity and no role/type on the link.**

Fields that target the `contacts` entity (all target bundle `contact` unless noted), with cardinality:

| Holder entity | Bundle(s) | Field | Card. |
|---|---|---|---|
| `properties` | property | `field_primary_contact_ref` | 1 |
| `properties` | property | `field_contacts` | **1** |
| `profile` | customer_profile | `field_primary_contact_ref` | 1 |
| `profile` | customer_profile | `field_contacts` | ∞ |
| `profile` | teammate_profile | `field_emergency_contacts` (→ bundle `emergency_contacts`) | ∞ |
| `supplier` | supplier | `field_contacts` | ∞ |
| `estimate_request` | standard | `field_contact` | 1 |
| `work_order` | all 35 service bundles | `field_contact` | 1 |

Fields that target the `properties` entity are numerous (67 instances) and all **card=1**, all target bundle `property`: every `work_order` bundle (`field_property`), both `contracts` bundles, `estimate_request`, all 15 `property_*` detail entities, `ownership_record` (`field_property_reference`), the four `media` photo/video bundles (`field_property`), `property_backflow_device`, and `service_request`.

**Role / type on the person↔property link:** none. There is no "owner / tenant / billing / estimate contact" role field anywhere on the Contact reference. The only differentiation is the field *name* — `field_primary_contact_ref` (the one primary) vs `field_contacts` (others). Evidence: no `entity_reference` field targeting `contacts` carries a companion role field; the handler settings only pin `target_bundles = contact`.

**Substitute for a role, and the key structural split:** "owner" is modelled **on a different entity and as a `user`, not a contact**:
- `ownership_record.record.field_property_owner` → **user** (card 1), plus `field_property_reference` → properties.
- `contracts.residential.field_property_owner` → **user** (card 1).
- `estimate_request.standard.field_owner` → **user**; `testimonial.client.field_customer` → **user**; `profile.customer_profile.field_account_manager` → **user**.

So the same human is a **User** when they are an owner/account and a **Contact** when they are a point of contact, and the two are only tied together by a matching email address (see Q4 overlap). This is the central fact for the "Contact = single person record" question.

> ⚠ **Drift to note:** `properties.field_contacts` is **cardinality 1**, while `customer_profile.field_contacts` and `supplier.field_contacts` are **cardinality ∞**. A property therefore cannot currently hold more than one non-primary contact.

---

### Q2 — Contact email field and its data state

- **Field:** `contacts.contact.field_email` — real Drupal **`email`** field type, **cardinality 1**. It is the **only** email field on the Contact entity (storage scan for type `email` or name containing `mail` returns just `field_email`).
- **Uniqueness / index:** **no unique constraint and no index** on `field_email_value` (`SHOW INDEX FROM contacts__field_email WHERE Column_name='field_email_value'` returns nothing; only the standard `entity_id/revision_id/bundle/langcode` keys exist). Nothing in config enforces uniqueness either.
- **Data quality** (bundle `contact`, from `contacts__field_email`):
  - Rows present / non-empty: **2,912** (every stored value is non-empty).
  - Contains uppercase characters: **1,037** (~36% — would collide on a case-sensitive unique key).
  - Leading/trailing whitespace: **0**.
  - Contains a comma or semicolon (two addresses in one field): **0**.
  - Non-empty but missing `@`: **0**.
- **Second/alternate email on Contact:** none.

---

### Q3 — Contact count and duplicate rate

- **Total contacts:** bundle `contact` = **3,081**; bundle `emergency_contacts` = **5** (total 3,086). Only the `contact` bundle is the customer-facing person record.
- **Contacts with an email:** 2,912 of 3,081 (**169 have no email**, ~5.5%).
- **Exact-duplicate emails** (grouped by `LOWER(TRIM(field_email_value))`, non-empty): **0 groups** — no two contacts share an email. (This is data-driven, not constraint-enforced; see Q2.)
- **Probable duplicates without email** (grouped by `LOWER(TRIM(last_name))` + digits-only phone, phone ≥ 10 digits, joined `contact → field_phone_number → phone_number.field_phone_number`): **3 groups, 6 contacts**, all groups of exactly 2. Distribution: 2-member = 3, 3-member = 0, 4+ = 0.

**Read:** the Contact table is remarkably clean for dedup purposes — effectively no duplication to resolve.

---

### Q4 — State of the User accounts (excluding uid 0 and 1)

- **Total users:** **3,740**. Active (status=1): **2,522**. Blocked (status=0): **1,218**.
- **Never logged in** (`access = 0`): **3,658** (~97.8%).
- Logged in within the last year: **31**. Last login **> 1 year ago**: **51**. (So only ~82 accounts have *ever* logged in.)
- **Password hash set** (`pass <> ''`): **1,514**. Empty: **2,226**.
- **Roles** (uid > 1):

  | Role | Count |
  |---|---|
  | client | 3,570 |
  | teammates | 124 |
  | administration | 11 |
  | supervisor | 5 |
  | site_assistant | 4 |
  | user | 3 |
  | administrator | 1 |
  | site_admin | 1 |
  | system_integration | 1 |
  | (authenticated-only, no extra role) | 46 |

- **Migration sizing — user email also on a Contact** (normalized `LOWER(TRIM())` match): of **3,724** users (uid>1) that have an email, **2,888** have an email that also appears on a `contact` record.

**Read:** the User table is essentially a large dormant customer directory — 3,570 client accounts, almost none of which have ever logged in. It is bigger than the Contact table (3,740 vs 3,086), and ~2,888 overlap by email; ~836 emailed users have no matching contact, and ~193 emailed contacts have no matching user.

---

### Q5 — Existing consent / contact-preference data

**Consent flags already exist — on the `user` entity, not on Contact:**

| Field (on `user`) | Type | Populated (uid>1) |
|---|---|---|
| `field_ok_to_email` | boolean | 277 rows exist; **4** set to a non-zero value |
| `field_sms_consent` | boolean | 207 rows exist; **1** set |
| `field_consent_updated` | timestamp | **0** rows |
| `field_do_not_schedule` | boolean | (operational governance flag, not marketing consent) |

So the consent scaffolding is present but **effectively unused** (4 email opt-ins, 1 SMS opt-in across the whole base). No consent field exists on Contact.

- **Other email-ish fields found** by name/type scan (shows how scattered "email" is): `estimate_request.field_requestor_email`, `profile.customer_profile.field_contact_email`, `service_request.field_submitted_email` (both public-intake bundles), `supplier.field_ordering_email`, `manufacturer.field_email`, `work_order.estimate.field_new_customer_email`, plus core `user.mail`, `comment.mail`, `contact_message.mail`.
- **Free-text opt-out mentions** in note fields (`contacts.field_contact_notes`, `contacts.field_note`, `properties.field_work_order_note`), matching "do not email / no email / unsubscribe / opt out / do not contact / no mail": **0**.
- **Email-marketing / CRM modules** (Mailchimp, SendGrid, Constant Contact, Salesforce, HubSpot, SparkPost, SES, etc.): **none installed or enabled.**

---

### Q6 — How BOS sends email today

- **Mail modules enabled:** `symfony_mailer`, `mailsystem`, `mailer_transport`, `smtp` (present but **`smtp_on = false`**, so unused). Not enabled: swiftmailer, mimemail, sendgrid, etc.
- **Transport:** `mailsystem.settings` routes sender + formatter to **symfony_mailer**; `symfony_mailer.settings.default_transport = sendmail` (plugin `sendmail`). Core `system.mail.interface.default = php_mail` is overridden by mailsystem → symfony_mailer/sendmail is the effective path.
- **Site mail address** (`system.site.mail`): `office@brookstoneoutdoors.com`.
- **Outbound flows:**
  - **Rules module** is enabled but carries only the `rules_examples` demo rules; the only enabled reaction rule is a demo (`delete_the_friendship`). **No production customer email runs through Rules.** `wo_timer_flagged` is disabled.
  - **Custom-module mail** (the real senders):
    - `estimate_notifications` → emails the **assigned estimator** (an internal user) when `estimate_request.field_assigned_to` is set. Internal.
    - `estimate_board` → a follow-up digest to **`office@brookstoneoutdoors.com`**. Internal.
    - `wo_sign_off` → work-order-canceled notice to **`wo_notices@brookstoneoutdoors.com`**. Internal.
  - The public intake modules (`bos_service_request`, `bos_homepage`) send **no email at all** — they only create records.
- **Does anything email customers today?** **No.** Every outbound email currently goes to an internal staff address or an internal user. BOS has never sent mail to a customer, so there is no existing send-time consent/suppression logic to build on.

---

## While-you're-in-there

### E1 — JSON:API / REST / GraphQL exposure

- **`jsonapi`** enabled, **`read_only: true`** (no create/update/delete via JSON:API).
- **`rest`** enabled, exactly **one** resource config: the custom **`wo_intake`** POST endpoint (Cowork Connect), which is guarded by route-scoped `X-API-KEY` auth — not anonymous.
- **`graphql`** not enabled.
- **Can an anonymous user read Property, Work Order, or Contact?** **No.** JSON:API honours entity view access. The anonymous role's view permissions are only: `view any property_backflow_device entities` (+ `…of bundle device`) and `view any/own material entities of bundle annuals`. It has **no** view permission on `properties`, `work_order`, or `contacts`, so JSON:API returns them as access-denied/empty for anon. Evidence: anonymous role permission scan.

  > Note: `property_backflow_device` **is** anonymously readable (likely intentional for the public backflow report/QR flow) — worth a conscious confirmation, but it exposes device/test records, not customer PII tables.

### E2 — Work Order photo/file storage

- **All** work-order and media file/image fields use the **`public`** file system (`uri_scheme: public`) — e.g. `work_order.field_design_plan_images`, and every `media.field_media_*` image/file/video field (including `field_media_image_1`, the property/WO gallery source). No private-scheme file field found.
- **`file_managed` by scheme:** `public://` = **11,911**, `temporary://` = 1. No `private://` files exist.
- On production these `public://` files are served from the S3 public bucket (per project config), i.e. **publicly reachable by URL** if the URL is known. Access control is by URL obscurity, not by permission.

### E3 — Other publicly reachable content

- **`/rss.xml`:** the core `frontpage` view is **enabled** with a `feed_1` (feed) display → the RSS feed is live. Its contents are the **nodes promoted to the front page**.
- **Published nodes:** **28** (18 `help_manual`, 10 `page`).
- **Published + promoted to front page:** **19** (these are what the RSS feed and the front-page listing surface).
- **Published nodes with no path alias** (raw `/node/N`): **22** of 28.

---

## 2. Surprises (not asked, worth knowing)

1. **"Owner" is a User; "Contact" is a Contact — and they're only joined by email.** The person who owns/pays (`ownership_record.field_property_owner`, `contracts.field_property_owner`, both → `user`) is a *different entity* from the point-of-contact (`field_contact*` → `contacts`). Making Contact the single person record means reconciling two parallel person tables (3,740 users vs 3,086 contacts, 2,888 email-overlap) — this is the real shape of the work, not dedup.
2. **The consent flags you're considering adding to Contact already exist on User** (`field_ok_to_email`, `field_sms_consent`, `field_consent_updated`) — and are essentially unused (4 / 1 / 0). Whatever is decided, there are already two candidate homes; putting them on Contact would be a *third* location for person-preference data unless the others are retired.
3. **`properties.field_contacts` is cardinality 1** (vs ∞ on customer_profile/supplier) — a property can hold one primary + one other contact, no more. Likely an oversight/drift.
4. **Contact email is very clean** (0 whitespace, 0 multi-address, 0 exact dups) but **36% contain uppercase** and there is **no unique index** — so a case-insensitive unique key is addable cheaply, but only after a `LOWER()` normalization pass.
5. **The User table is a dormant directory:** 97.8% of accounts have never logged in and 60% have no password hash. As a person-record source it is large but low-signal; the *email* on it is the only reliably useful column for most rows.
6. **All files are public-scheme** (11,911), including customer property/WO photos, served from a public S3 bucket. Not a Contact-model issue, but relevant if "who can see customer data" is part of the same review.
7. **Email is scattered across ~9 fields** on 8 entity types (estimate_request, profile, service_request, supplier, manufacturer, work_order, user, contacts, comment). There is no single authoritative email column today.

## 3. Blockers to "Contact is the single person record, User references Contact"

Facts and rough effort reads — no implementation plan, as requested.

1. **Two person tables to merge, not one to clean (large).** ~2,888 of 3,724 emailed users map to a contact by email; ~836 emailed users have no contact, and ~193 emailed contacts have no user. A "User references Contact" model needs a create-or-match step for the ~836 unmatched users (new contacts) and a decision for the 169 emailless contacts and any userless-owners. The join key is email, and 36% of contact emails need case-normalization first.

2. **Ownership/agreement records point at User, not Contact (large, cross-cutting).** `ownership_record`, `contracts.residential`/`snow_removal`, `estimate_request.field_owner`, `customer_profile` (1:1 with user), `testimonial.field_customer` all bind the *person* as a `user`. If Contact becomes the person of record, either these keep pointing at User (and User keeps pointing at Contact — a two-hop model) or they must be repointed at Contact. The former is far cheaper; the latter touches contracts, estimates, ownership history, and the customer profile.

3. **`field_contact` is on all 35 work_order bundles + estimate_request + property + profile + supplier (medium).** Any change to what Contact *is* (or a new required field / uniqueness) ripples across 35 WO forms and the property/profile/supplier forms. Cardinality-1 everywhere means no data-loss risk, but it is a wide surface for form/display and validation changes.

4. **No uniqueness today (small, but a prerequisite).** There is no unique key on contact email, and User↔Contact is not enforced anywhere. A single-person-record model needs a uniqueness rule and a normalization pass (`LOWER(TRIM())`) before it can be trusted. Cheap to add, but must precede any dedup/merge automation.

5. **Consent has no send-time enforcement because nothing emails customers yet (small–medium, but greenfield).** The flags exist but are unused and live on User; there is no mail flow that reads them. Attaching consent to Contact is easy; the actual work is standing up a customer-facing mail path that *checks* consent at send time — none exists today, so there is nothing to retrofit and nothing proven.

6. **Email is not centralized (medium).** With ~9 email fields across 8 entities and no canonical column, "the person's email" is ambiguous. Choosing Contact as the source of truth means deciding what happens to `user.mail` (used for login/auth on the 82 accounts that log in), `customer_profile.field_contact_email`, `estimate_request.field_requestor_email`, and the two public-intake `field_submitted_email`/`field_requestor_email` fields.
