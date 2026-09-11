# bos_analytics

**Status:** LIVE 2026-09-06. Package: BOS.

Injects the **Google Tag Manager** container (the single tag host) on every page,
and pushes no-PII `dataLayer` conversion events on public-form success. GA4, the
Meta Pixel, and Google Ads conversion are all configured **inside GTM's web UI**
— not in BOS code — so new tags never need a code deploy.

## Container ID — settings.php (env-specific, off-git)

```php
$settings['bos_gtm_container_id'] = 'GTM-WC8F8PCN';
```

Read via `Settings::get()`, format-validated (`GTM-…`). **Nothing renders when
unset**, so dev stays untracked unless the line is added there. Live carries the
line; the module code has no ID baked in. (Live container: `GTM-WC8F8PCN`.)

## What it renders

- `hook_page_attachments` — the GTM head loader (weight −1000, as high in `<head>`
  as core allows).
- `hook_page_top` — the `<noscript>` iframe right after `<body>`.
- Loads on **every** page including `/user/login`; it is a passive snippet and
  never touches sessions or the login/auth flow.

## Conversion events (no PII)

`hook_form_alter` appends a capture submit handler to the public forms (the live
form classes are **not** edited). On success it records a one-shot session flag;
`hook_page_attachments` then emits a single `dataLayer.push` on the confirmation
render (same-request rebuild) or the next request (redirect forms):

| Form | Event |
|---|---|
| `bos_winterize_form` (success = `winterize_done`) | `winterize_submit` |
| `bos_fall_cleanup_form` (success = `fc_done`) | `cleanup_submit` |
| `bos_portal_waitlist_form` (redirect on success) | `portal_waitlist_submit` |

Payload is **`{event, campaign}` only** — the campaign is the normalized `?c=`
code; no names/emails/phones/addresses. The capture handler runs *after* the
record is saved, is `try/catch`-wrapped, and only sets a session value; the
client-side push is self-guarding and async — it **cannot block or break a
form**, even if GTM is blocked/adblocked. Interim/partial saves do not fire.

## GTM-side setup (in tagmanager.google.com, container `GTM-WC8F8PCN`)

BOS provides the pipe + these dataLayer events; the tags are built in GTM:

- **GA4** — Google tag, ID `G-BGR1SF7LTM`, trigger **All Pages**.
- **Meta Pixel** — base tag (Meta Pixel ID), All Pages; + a **Lead** event tag on
  Custom Event = `winterize_submit`.
- **Google Ads conversion** — conversion tag (Ads conversion ID/label) on Custom
  Event = `winterize_submit` (or import the conversion from GA4).
- Trigger variables available: the three events above, each with `campaign`.

## Deploy

rsync module → add the `settings.php` line → `drush en bos_analytics` → `cr`.
Additive; no cim, no DB.

## Owed / notes

- **Browser verification** (GTM Preview, Meta Pixel Helper, GA4 DebugView, an
  end-to-end `?c=fb26` submit) is done in a browser + the vendor consoles — not
  from code.
- **No analytics existed before 2026-09-06** — this is the first tracking on BOS.
- Dev may or may not carry the container id (set it in dev settings.php only if
  you want to test there; leave it out to keep dev traffic off the container).

## Lead-form conversion events (dataLayer → GTM Custom Event triggers)

GTM triggers (container `GTM-WC8F8PCN`) are **Custom Event** triggers: they are
inert until the site pushes the matching `event` into `dataLayer`. `bos_analytics`
is the single push mechanism — a `hook_form_alter` appends a submit handler that,
on **successful** submission, sets a one-shot session flag consumed on the next
page render, which pushes `dataLayer.push({event, campaign})` **once**,
**anonymous-only** (the whole container is anon-gated), **no PII**.

**A form refactor that changes a form ID, or that stops setting its success flag,
silently kills that event's tracking** (a trigger with no event behind it reports
a silent zero). Keep this map in sync with the forms.

| Form ID | Route | Event | Success flag |
|---|---|---|---|
| `bos_winterize_form` | `/winterize` | `winterize_submit` | `winterize_done` |
| `bos_fall_cleanup_form` | `/fall-cleanup` (services term) | `cleanup_submit` | `fc_done` |
| `bos_portal_waitlist_form` | `/user/login` waitlist panel | `portal_waitlist_submit` | redirect form (whitelisted by event name) |
| `bos_contact_form` | `/contact` | `contact_submit` | redirect form (whitelisted by event name) |
| `bos_homepage_request_estimate` | `/request-estimate` | `estimate_submit` | `estimate_done` |

- The dataLayer variable is **`campaign`** (the `?c=` code), NOT `campaign_code`
  — map the GTM variable to `campaign`.
- **Keep estimate vs contact as separate conversions.** An estimate is a
  design-build lead (money event → Primary Google Ads conversion); a contact
  message is frequently a vendor or job-seeker (measurement / Meta Lead only).
  Merging them teaches Ads to optimize toward the cheaper, more plentiful one.
- Campaign is also stored on the BOS **record** for each form: `service_request.
  field_campaign` (winterize/contact) and `estimate_request.field_campaign`
  (request-estimate), so attribution survives on the lead, not only in GA.
