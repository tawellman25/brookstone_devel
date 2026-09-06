# Client Email Policy

**Status:** Authoritative rule. Adopted 2026-09-05.

## Background

For years, client User accounts in BOS were created with **fabricated email
addresses built from the person's own name at one of our own domains**
(`@brookstoneoutdoors.com`, `@sewardslandscape.com`). These are not real
mailboxes for those people. A 2026-09-05 audit found that **~78% of
customer-linked contacts (2,734 of 3,519) carry a fabricated our-domain
address; only ~22% (~780) hold a real, external, deliverable email.** The
majority of the fabricated addresses (1,870) are at the legacy
`sewardslandscape.com` domain.

These fabricated addresses and their client User accounts are **load-bearing**:
they are the account identity / login anchor and the spine linking a customer to
their Contact (`customer_profile.field_primary_contact_ref`) and to all their
operational history. They must not be treated as junk to be cleaned up.

## The rule (three parts)

1. **No automated replacement of a client/customer User's email.**
   A client User's `mail` is changed **only** by an **admin-role human editing
   it manually**. No import, backfill, sync, migration, cron, or other automated
   process may create-or-replace an existing client user's email. (Creating a
   brand-new customer with an email at provisioning time is fine — the
   prohibition is on *replacing* an email that already exists.)

2. **No sending to fabricated / our-domain client addresses.**
   Any customer-facing email path MUST skip addresses at
   `@brookstoneoutdoors.com` and `@sewardslandscape.com` (and the usual
   placeholders). Those are not the customer's mailbox — sending to them is at
   best a hard bounce and, for `sewardslandscape.com` if we no longer own it, a
   privacy exposure. An address becomes sendable **only after an admin has
   manually set a real external address.** This gate is *in addition to* the
   opt-in/consent requirement, not a replacement for it.

3. **Never delete client records or their emails.**
   The fabricated emails and the client User accounts are retained as-is. They
   are the identity/login anchor and the link to Contacts. No cleanup job may
   null, blank, or delete them. The fabricated address stays as the account's
   identifier until an admin replaces it with a real one.

## Why it's written this way

- Auto-replacing or deleting these emails would destroy account identity and the
  customer↔Contact↔history links for ~78% of the customer base.
- A naive customer email blast against the raw list would fire ~2,700 hard
  bounces (wrecking sender reputation before reaching the ~780 real addresses)
  and could misdirect mail to whoever controls `sewardslandscape.com`.
- The only safe way an address becomes real is a deliberate human (admin) edit —
  so that is the single sanctioned path.

## Domain ownership (confirmed 2026-09-05, by Todd)

- **`sewardslandscape.com` — NOT owned by us anymore.** Mail to any
  `@sewardslandscape.com` address goes to whoever now controls the domain.
  Treat it as hostile: never send there under any circumstance.
- **`brookstoneoutdoors.com` — a catch-all mailbox will receive all mail** to
  fabricated/unknown local parts (Todd is ensuring this). So mail to fabricated
  `@brookstoneoutdoors.com` addresses lands in an inbox we control (no
  third-party exposure, no hard bounce) — but it is still not the customer's
  mailbox, so it is not a marketing/customer-send target.

### Residual exposure (open for Todd)

The Contact-side our-domain emails are cleared, but the **client User accounts**
still hold them (policy part 1 — no automated change to User emails):
**1,887 ACTIVE client accounts carry a `@sewardslandscape.com` email** (a domain
we don't own). Core Drupal can email `user.mail` (password reset, admin
"notify user" on account edit), so those could reach the third party. Options
for Todd to decide (each is an explicit exception to policy part 1, since it
edits User emails): (a) leave as-is and rely on "no customer-send path + clients
never log in"; (b) rewrite just the domain `@sewardslandscape.com` →
`@brookstoneoutdoors.com` on those accounts (keeps the name/identity, moves it
under our catch-all, kills third-party exposure) — needs unique-email collision
handling; (c) blank the email on those accounts; (d) block the accounts. Not
acted on — awaiting decision.

## Applied

- **2026-09-05 — fabricated Contact emails cleared.** The 2,734 fabricated
  our-domain addresses were removed from `contacts.contact.field_email` (data
  values only; records, names, phones, opt-in flags, titles, and
  customer↔Contact links all retained). Staff mailboxes (9) and one placeholder
  were protected. **Client User emails were NOT touched** (they remain the
  account identity, per part 1). After: ~787 contacts hold an email (the
  real/deliverable set + placeholders). Script:
  `web/scripts/clear_fabricated_contact_emails.php` (domain-guarded; dry-run
  default). Pre-op dump on live: `~/pre-clear-emails-20260905.sql.gz`.

## Enforcement

- **Send-time (when a customer email program is built):** the send path filters
  to real, external, opted-in addresses — never our-domain/placeholder ones.
  No customer-facing send path exists today, so this is a build-time constraint
  on the future program, not a retrofit.
- **Write-time (optional guard, not yet built):** a `user` presave guard could
  refuse a programmatic change to a client account's `mail` unless the actor
  holds an admin role. Offered but not implemented as of 2026-09-05 — the rule
  is currently honored by convention + this policy.

## Related

- Audit evidence: `docs/email-audit-detail-20260905.csv` (gitignored — PII) and
  the audit summary.
- Consent model: `docs/contact-consent-plan.md`. Consent lives on the Contact;
  this policy governs the *address* on the client User. Both gates
  (opted-in AND a real address) must pass before any send.
