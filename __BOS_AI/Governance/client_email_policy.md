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
