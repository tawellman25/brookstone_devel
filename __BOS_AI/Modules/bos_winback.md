# bos_winback — Winterize Win-Back call list

**Package:** BOS Operations
**Route:** `/admin/office/winterize/win-back` (Office menu ▸ Winterize Win-Back)
**Permission:** `access winterize winback` (granted on install to `administration`, `supervisor`, `site_assistant`, `site_admin`, `administrator`)
**Shipped:** 2026-08-24

## Purpose

A live, clickable call list of **prior-season winterizing customers who have no
work order this year** — the office's win-back / re-book campaign surface.
Replaces the one-off CSV (`web/scripts/winterize_winback_list.php`) with an
in-BOS page so whoever is calling can go straight to updating contact info or
creating a work order.

## What it computes

A **set difference**, recomputed on every page load (nothing stored):

- **Source:** `work_order:sprinkler_winterizing` created in the prior year's
  season (Aug 15 → Dec 31).
- **Target (exclude):** any `sprinkler_winterizing` WO created on/after Jan 1 of
  the current year → the property is already covered.
- **Win-back = source properties not covered**, deduped to the most recent
  source WO per property.

Years are derived from the request date (`targetYear` = current year,
`sourceYear` = prior), so the page carries forward each season with no code
change.

## Contact resolution (the BOS model)

`property.uid` is the **office author**, not the customer. The customer is
resolved through the ownership chain (shared logic with the CSV script):

1. WO `field_contact`
2. property `field_primary_contact_ref` → `field_contacts`
3. **latest `ownership_record` (`field_property_reference`) → `field_property_owner`
   (client user) → `customer_profile.field_primary_contact_ref`**

**Phone** on a `contacts` entity is a *reference*, not a scalar:
`contact.field_phone_number → phone_number → .field_phone_number.value`
(fallback: the client user's `phone_number:profile_phone_numbers` via
`field_user`). **Email:** `contact.field_email`, else the client user account
email — placeholder `@sewardslandscape.com` migration addresses are suppressed.
**City:** `property.field_zipcode_reference → field_city` (a `city` entity) →
label.

## The page

Each customer renders as a BOS status card (`templates/bos-winback-list.html.twig`,
`css/winback.css`):

- Name (→ property page), canceled-last-year badge, address · zones · last date ·
  last total
- **📞 click-to-call** phone (tel:), email (mailto:) when real
- **Create WO** → prefilled `sprinkler_winterizing` add form
  (`/admin/content/work_order/add/sprinkler_winterizing?edit[field_property][widget][0][target_id]={pid}`)
- **Edit** → `/properties/{pid}/edit`
- Call-outcome buttons: **Left msg · No answer · Not interested · Reset**

## Drop-off behavior

- **WO created** for the property → covered → drops off on next load (automatic).
- **Not interested** (declined) → card removed and stays off the list.
- **Left msg / No answer / Reached** → *annotate* the card
  ("Left message · {caller} · {date}") so callers see what's been worked and
  avoid double-calling; **Reset** clears it.

## Call state

Persisted in a KeyValue collection `bos_winback.call_state`, keyed by
`"{year}:{pid}"` → `{outcome, by, time_ts}`. Only `declined` suppresses a row;
the rest annotate. State is per-campaign-year and shared across all callers.

## Recording endpoint

`POST /admin/office/winterize/win-back/mark/{property}` (AJAX,
`_csrf_request_header_token`), body `outcome=left_message|no_answer|reached|declined|clear`.
`js/winback.js` fetches the session token, posts the outcome, and updates or
removes the card.

## Files

- `src/Service/WinbackListService.php` — compute + state (the reusable core)
- `src/Controller/WinbackController.php` — `list()` page + `mark()` endpoint
- `templates/bos-winback-list.html.twig`, `css/winback.css`, `js/winback.js`
- `bos_winback.routing.yml`, `.links.menu.yml`, `.permissions.yml`, `.install`

## Notes / caveats

- Computes on each load (~210 properties + ownership/contact lookups) — a few
  seconds on live; acceptable for an admin page, no caching.
- `last_wo_number` (`field_work_order_id`) is NULL on recent WOs (known BOS
  backfill gap); the entity id is used as the WO handle.
- Read-only over operational data — creates/edits **no** WO, property, or
  contact. The only writes are the call-state KeyValue records.

## Deploy

rsync the module + `drush en bos_winback` (install hook grants the permission) +
`cr`. No cim, no DB migration. Deployed live 2026-08-24.
