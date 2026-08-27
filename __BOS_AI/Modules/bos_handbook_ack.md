# bos_handbook_ack — Online Handbook Acknowledgment

**Package:** BOS Operations
**Shipped:** 2026-08-26
**Depends on:** `eck`, the `handbook` entity

Lets staff record a durable, auditable "I have read and agree to the Team
Handbook" acknowledgment online — the digital counterpart to the printed
wet-ink signature page. Staff either acknowledge here or sign the printed page
(scanned to their HR file).

## Gate 1 — Storage (append-only)

ECK entity **`handbook_acknowledgment`** (bundle `acknowledgment`), **one record
per acknowledgment event** — a legal record, never edited:

- `field_user` (ref → user) — who acknowledged
- `field_acknowledged_on` (timestamp)
- `field_handbook_version` (string) — the version acknowledged (snapshot)
- `field_typed_name` (string) — typed-name e-signature
- `field_ip` (string) — client IP at submit

**Append-only** enforced by `bos_handbook_ack_entity_presave()`: any update to an
existing record throws (`EntityStorageException`) on every path (form, UI, REST,
programmatic). Delete stays superuser-only. Built via
`web/scripts/setup_handbook_ack_entity.php` (entity-API — ECK configs skip on cim).

## Gate 2 — Versioning

`field_handbook_version` (string) on the **handbook `cover`** bundle. The **root
"Team Handbook" cover** (no `field_parent_page`) holds the **current version** —
`HandbookAckService::currentVersion()`. Each acknowledgment snapshots that value.
**Bump the version to require re-acknowledgment**; prior records are retained for
history. Keep this value **in step with the printed copy** (the 2nd Brain stamps
the same version on the printed handbook — see the handbook alignment invariant in
`Entities/content_knowledge_entities.md`). Added via
`web/scripts/add_handbook_version_field.php` (seeds `v1.0 (Effective Aug 2026)`).

## Gate 3 — UX (the acknowledgment form)

`HandbookAcknowledgmentBlock` renders `HandbookAcknowledgmentForm`, placed
(`content_below`, request_path visibility) on the **Acknowledgment page**
`/teammates/training/handbook/acknowledgment` (handbook cover, reachable via the
handbook menu-tree). Gated to **staff roles**. Behavior:

- Not yet acknowledged (current version) → statement + "type your full name" +
  **"I have read and agree"** → creates the record (user + current version +
  timestamp + IP + typed name).
- Already acknowledged the **current** version → shows "✓ You have acknowledged …
  on {date}, signed {name}" instead of the form (no duplicate).
- Version bumped → the form reappears (no record for the new version yet).

## Gate 4 — Management surfaces (two, complementary)

**1. Status & gap report (computed)** — `/admin/operations/training/handbook/acknowledgments`
(admin menu → **Operations → Training → Handbook Acknowledgments**; permission `view handbook
acknowledgments`). For a chosen version (default = current): **acknowledged** (name,
date, signed) + the **version-aware GAP** ("not yet acknowledged") + completion %.
Computed (not a View) because a version-aware gap — acked *this* version? — isn't
expressible in pure Views (same reasoning as Winterize Win-Back).

**2. Acknowledgment Log (View)** — `/admin/operations/handbook-acknowledgments`
(admin menu → **Operations → Training → Handbook Acknowledgment Log**; permission `access
handbook_acknowledgment entity listing`). Both items sit under the **Operations →
Training** section landing (`/admin/operations/training`) — the handbook already
lives under Training — not loose at the Operations top level or the crew "Handbook"
link. The raw audit trail: `views.view.
handbook_acknowledgments`, table over `handbook_acknowledgment_field_data` —
columns staff member (linked) · signed name · version · acknowledged-on (short
date) · IP; sortable, 50/page, newest-first; exposed filters version (contains) +
acknowledged-on date range; total-count header. Built via
`web/scripts/build_handbook_ack_view.php` (idempotent, per env — grants the listing
perm + builds the view; **not** committed-config+cim, matching the
`service_request_admin` precedent). Requested by Claude Chat 2026-08-26; kept
alongside the report (report = who still needs to sign; log = the immutable record).

## Audience

**All staff** — roles `teammates`, `supervisor`, `administration`,
`site_assistant`, `site_admin`, `administrator` (`HandbookAckService::STAFF_ROLES`).
This is both the form audience and the gap-list population (active users only).
Clients/other users (2500+) are excluded.

## Files

- `src/Service/HandbookAckService.php` — version, lookups, append-only writer, staff roster, report rows
- `src/Form/HandbookAcknowledgmentForm.php`, `src/Plugin/Block/HandbookAcknowledgmentBlock.php`
- `src/Controller/HandbookAckReportController.php` + `templates/bos-handbook-ack-report.html.twig`
- `bos_handbook_ack.{module,routing,links.menu,permissions,install,libraries,services}.yml`
- Setup (per env): `setup_handbook_ack_entity.php`, `add_handbook_version_field.php`, `setup_handbook_ack_ui.php`

## Deploy

rsync module + scripts → `drush en bos_handbook_ack` → run the three setup scripts
→ `cr`. No cim, no DB migration. Deployed live 2026-08-26 (25 staff, 0 records at
launch). To require re-acknowledgment, bump the version on the root Team Handbook
cover (`field_handbook_version`) and update the printed copy to match.

## Notes / follow-ups

- The Acknowledgment page is reachable via the handbook menu-tree; a prominent
  "Acknowledge the handbook" nudge on the Employment page or handbook cover is a
  possible enhancement (not built).
- Report covers **online** acknowledgments; printed-page signatures live in HR files.
