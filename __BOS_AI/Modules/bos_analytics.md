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
