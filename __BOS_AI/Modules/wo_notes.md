# BOS Module — wo_notes (card restyle + structured schedule notes)

Module: `wo_notes` · Package: Work Orders
Deployed to live 2026-06-24 (branch `wo-notes-restyle` → `main`, commit `e684c53c`).

## Purpose

`wo_notes` manages the `wo_notes:note` ECK entity lifecycle and renders the **Notes**
EVA on the Work Order page (`views.view.wo_notes`, display `entity_view_1`, attached to
all 36 work_order bundles). As of June 2026 the notes render as **cards** (matching the
My Schedule crew cards) and the auto-generated scheduling notes are **structured**.

## Schema additions (`wo_notes:note`)

Three fields, all **hidden on the add form + note view display** (manual notes still just
type a body):

| Field | Type | Purpose |
|---|---|---|
| `field_change_summary` | string_long | Machine diff line(s): `Scheduled: …` / `Rescheduled: old → new` / `Assigned: …` |
| `field_note_kind` | list_string | `manual` \| `schedule_insert` \| `schedule_change` (default `manual`; needs `options` module) |
| `field_is_system_note` | boolean | 1 for system/schedule notes, 0 for human notes (drives card accent + the toggle) |

## Card rendering (registered like `backflow_device`)

- `hook_theme()` registers `views_view_fields__wo_notes__entity_view_1` and
  `views_view_unformatted__wo_notes__entity_view_1` (base hooks `views_view_fields` /
  `views_view_unformatted`).
- `wo_notes_preprocess_views_view_fields()` builds the card: attribution = "Name, m/d/Y
  g:i A" from the note's **uid + created** (no verb in the attribution); system notes
  render labeled body lines (Scheduled/Rescheduled, Assigned, Schedule note); manual notes
  render the formatted body. Accent: manual = brand blue, system = muted.
- **Whole card is a modal link** to the note's edit form (`use-ajax` +
  `data-dialog-type="modal"`, destination back to the WO), gated on update access.
- Toolbar has a **"Hide schedule changes"** toggle (`js/wo-notes-toggle.js`, `once()`),
  hiding `.wo-note-card--system`. CSS in `css/wo-notes-cards.css` (My Schedule tokens),
  on the existing `work_order_notes` library.

## Structured schedule notes (`wo_schedule`)

`wo_schedule` now stashes `{summary, note, kind}` instead of one prefixed string. No more
"Scheduled by {actor}, {stamp} —" prefix (the card builds attribution from uid+created):

- INSERT → `kind = schedule_insert`, summary `Scheduled: {date}` + `Assigned: {name}`.
- UPDATE → `kind = schedule_change`, summary `Rescheduled: {old} → {new}` +
  `Assigned: {old} → {new}` (changed fields only); the scheduling note records the **new**
  value only.

## Legacy migration

`web/scripts/migrate_legacy_wo_notes.php` parses old single-string schedule notes into the
structured fields (idempotent; skips genuine manual notes + already-migrated). Run on local
(1,406) and on live (**1,573**) at deploy. Legacy dates keep their original (sometimes
time-laden) text; new notes are date-only.

## Notes / gotchas

- Pre-existing `eck.eck_entity_type.wo_notes` config drift (description / standalone_url)
  is unrelated to this work and was left untouched during the surgical partial-cim deploy.
- The 9 deploy configs: the 6 field configs + `core.entity_form_display.wo_notes.note.default`
  + `core.entity_view_display.wo_notes.note.default` + `views.view.wo_notes`.

## Status

- Created: 2026-06-25 (documenting the 2026-06-24 deploy).
