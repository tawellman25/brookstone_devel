# bos_portal_waitlist

**Status:** LIVE 2026-09-06. Package: BOS.

Customer-portal **waitlist panel** on the login page (`/user/login`). Captures
interest in a future customer portal (a product-validation signal) plus opt-ins,
writing everything onto the **Contact** entity (the consent home). It is **not**
an account signup — no user, no password, no authentication.

## Layout

Two columns on desktop; on mobile they stack with the login first.

- **Left — crew/office login (unchanged).** `hook_form_user_login_form_alter`
  only adds an `<h2>` "Crew & Office Login" heading and a **mobile-only** jump
  link ("A customer? Accounts are coming — put your name down below ↓") to the
  panel. Fields, validation, route, and the reset-password link are untouched.
- **Right — "Coming for customers" panel** (`PortalWaitlistBlock`, theme
  `bos-portal-waitlist-panel`): approved marketing copy + 4 portal-promise
  bullets + the waitlist form. Section id `customer-accounts` (jump target).

Layout is CSS-only (`css/waitlist.css`), scoped by a `bo-login-page` body class
(`hook_preprocess_html` on the `user.login` route) flexing the two blocks in
`.region--content`. The default "Log in" page title is hidden (the form's own
heading replaces it). Block placed via `web/scripts/setup_portal_waitlist_block.php`
(per-env, request_path `/user/login`, theme `brookstone_olivero`) — not cim.

## The form — `PortalWaitlistForm`

Fields: first/last name (required), email (required), mobile, service address,
"already a customer?" (Yes/No/Not sure), + two opt-in ticks. reCAPTCHA + flood
(per-IP 10/hr, per-email 3/day) — BOS public-form standard. Campaign code from
`?c=` (default `portal26`).

**Writes to Contact** (consent = the shipped model; see
`docs/contact-consent-plan.md`):

| Contact field | Set |
|---|---|
| `field_opt_in_service_email` | **on submit** (submitting = a request to be emailed when accounts open) |
| `field_opt_in_marketing_email` | only if the seasonal-reminders tick is checked |
| `field_opt_in_service_sms` | only if the scheduling-text tick is checked **and** a mobile was given |
| `field_consent_updated` / `field_consent_source` | now / `web_form` (only when a flag changed) |
| `field_portal_interest` / `_date` / `_note` | **always** — the product signal (mobile/address/already-customer/campaign go in the note) |

Every opt-in change writes a `consent_log` row via `bos_consent_log`
(source `web_form`, IP captured, actor anon). **Portal interest is deliberately
NOT a consent event** — it stays in plain fields, never the consent log.

**Governance guards (beyond the source spec):**
- **Multi-signal matching** (email → last-name + digits-phone) before create, to
  avoid duplicate Contacts — necessary because the fabricated Contact emails were
  cleared (email-only matching would miss existing customers). Residual dupes are
  a portal-build dedupe task.
- **Opt-out guard** — an existing `opted_out` flag is never silently flipped on
  (logs a notice instead).
- **Fabricated-email override** — if a matched Contact still holds an
  `@brookstoneoutdoors.com` / `@sewardslandscape.com` address, the submitted
  (real) address replaces it. The client **User** email is never touched
  (`__BOS_AI/Governance/client_email_policy.md`).

## Fields

`web/scripts/setup_portal_interest_fields.php` (idempotent, no cim) creates
`field_portal_interest` (boolean), `field_portal_interest_date` (datetime),
`field_portal_interest_note` (string_long) on `contacts.contact`.

## Deploy

rsync module + scripts → `setup_portal_interest_fields.php` →
`drush en bos_portal_waitlist` → `setup_portal_waitlist_block.php` → `cr`.
Additive only — no DB migration, no cim.

## Owed / follow-ups

- **⚠ SOP** — office workflow for handling waitlist leads when the portal ships.
- **Analytics** — none in BOS; the login-button→submit funnel is unmeasured.
  Add before any paid campaign.
- **Send-time consent check** — the future "accounts are open" email MUST filter
  on the opt-in flags + a real external address. Does not exist yet.
- **SMS** — the mobile is stored in the note, so `service_sms` consent isn't
  machine-actionable until a lead is promoted (moot until SMS sending exists).
- **Bullets** are promises about what the portal will expose — keep them honest.
