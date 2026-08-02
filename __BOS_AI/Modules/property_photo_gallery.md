# Feature — Property Photo Gallery (archive + WO photos)

**Added 2026-08-02.** A per-property photo/video gallery: a **staff tab** showing
every photo linked to the property (archive + work-order, published + held) with
a public opt-in, and a **public gallery** embedded on the property page (opted-in
photos only) with a Colorbox lightbox and SEO alt/captions.

## Data model

Photos are **media entities that reference the property** (reverse reference), so
one query per property unions every source — no duplication, scales to
thousands. Decision rationale: BOS already models all photos as media
(`wo_images`/`wo_videos`), so more media entities is the idiomatic, scalable
choice; a multi-value field on the property was rejected (bloat + would force
duplicating WO photos).

- **`media.property_photo`** / **`media.property_video`** — archive/historical
  photos & videos (from the old customer folders). Fields: source image/video +
  `field_property` (→ properties) + provenance/SEO (`field_source_customer`,
  `field_date_taken`, `field_match_method`, `field_match_confidence`,
  `field_original_path`).
- **`field_property`** also added to **`wo_images`** / **`wo_videos`**, backfilled
  from `media.field_work_order → work_order.field_property`, so WO photos are
  directly discoverable by property.
- **`field_public_ok`** (boolean, "Show in public gallery") on all four bundles —
  the uniform public gate. Archive photos are auto-flagged on for confident
  matches; WO photos default off (staff opt in per photo).
- **`gallery` media view mode** — images render with the **Colorbox** formatter
  (thumbnail → lightbox with prev/next, alt as caption for SEO); videos render as
  an inline HTML5 player.

Setup script: `web/scripts/setup_property_photo_media.php` (idempotent; creates
types, fields, flag, view mode + per-bundle gallery displays). Backfill:
`web/scripts/backfill_wo_photo_property.php`.

## Import — `bos_photo_import`

Drush **`photo:import <mapping.csv> <media_root>`** (`--limit`, `--type`). Reads
the Photo↔Property association mapping and creates `property_photo`/
`property_video` media, **idempotent on `field_original_path`**. **Confidence
gate:** `field_public_ok = 1` only when the match is NOT customer-fuzzy AND NOT
Low confidence; everything else imported published-but-held for review. Alt text
auto-built as `"{nickname} — {full address}"`. Files written via the file API to
`public://property-photos/{pid}` (or `property-videos/{pid}`) → **s3fs on live
writes them to S3**; unique paths, no risk to existing files.

> Gotcha: the mapping CSV's BOM sits before the first field's quote, breaking
> `fgetcsv` enclosure on the header's first cell — headers are normalized
> (strip BOM + stray quotes) so `Type` matches.

## Gallery — `bos_property_gallery`

- **Staff "Gallery" tab** (`/properties/{properties}/gallery`, local task beside
  Work Orders; staff-role access) — a controller renders **all** gallery media
  for the property in the `gallery` view mode, each with a Public / Not-public
  badge + edit-to-toggle link.
- **Public gallery** — `hook_ENTITY_TYPE_view` (properties, `full` mode) appends
  the gallery of `field_public_ok = 1` + published media. The property default
  display is not Layout Builder, so the hook renders. Colorbox + SEO alt/captions.

## Dev vs live note

In **dev**, s3fs is off and stage_file_proxy can't render S3-only **WO photos** —
so a property whose gallery is only WO photos shows the item frames but no
images. **Imported archive photos are local and DO display in dev.** On **live**,
both work: archive photos are written to S3 by the import; WO photos are already
on S3.

## Not yet / follow-ups

- Optional image sitemap / structured data (needs a contrib module — not added).
- A bulk "mark public" VBO on the staff tab (currently per-photo via edit link).
