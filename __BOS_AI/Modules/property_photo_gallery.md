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

## Gallery — Views (built by `web/scripts/build_gallery_views.php`)

**Both galleries are Drupal Views** so the office can adjust filters/sorting/
columns in the Views UI (a first controller/hook build was replaced per the
"list UIs must be Views" rule — see `feedback_prefer_views_ask_before_bespoke`).

- **`property_photos`** — STAFF "Gallery" tab: a **page display + local task** at
  `properties/%properties/gallery` (beside Work Orders/Contracts/Estimates),
  staff-role access. Contextual filter on `media.field_property` (from the URL).
  Shows **all** photo media for the property (archive + WO, public + held) as a
  flat responsive grid, newest first, with a **Public/Held** badge + edit
  operation per item so staff review and opt photos into the public gallery.
- **`property_photos_public`** — PUBLIC gallery: an **EVA** (`entity_view`
  display, `argument_mode: token`) that auto-attaches to the property full page,
  filtered to `field_public_ok = 1` + published. Flat grid, public access.

Both render the media **source fields directly** — `field_media_image_1` via the
**Colorbox** formatter (thumbnail `medium`, lightbox `max_1300x1300`,
**page-level** gallery so prev/next pages through every photo, `alt` as caption
for SEO) and `field_media_video_file_1` via the video player — NOT
`rendered_entity` (which doesn't render reliably inside a view).

**One tile per photo (not per media entity).** `field_media_image_1` is
multi-value (cardinality -1) — crews upload many photos into a single
`wo_images`/`wo_videos` entity (985 such entities; one holds 54). The image field
is set **`group_rows = FALSE`** so each image value explodes into its own view
row → its own grid tile, instead of stacking a media entity's images in one cell.

**Layout + staff caption bar.** Both views use the **unformatted list** style;
the `bos_property_gallery` module's CSS (`hook_views_pre_render` →
`bos_property_gallery/gallery`) lays the rows out as a responsive card grid. The
staff view adds a **row template** (`views-view-fields--property-photos`,
registered via `hook_theme` + `hook_preprocess_views_view_fields`) rendering a
tidy caption bar: a colored **Public** (green) / **Held** (amber) badge + a
single **Edit** link (`destination` back to the gallery) — replacing the raw
labelled field + operations dropbutton. Note: `field_public_ok` is per **media
entity**, so a multi-photo WO media publishes all-or-nothing; archive photos (one
per media) toggle individually.

> The `gallery` media view mode created by the setup script is now unused by the
> views (they render source fields directly); left in place, harmless.

## Dev vs live note

In **dev**, s3fs is off and stage_file_proxy can't render S3-only **WO photos** —
so a property whose gallery is only WO photos shows the item frames but no
images. **Imported archive photos are local and DO display in dev.** On **live**,
both work: archive photos are written to S3 by the import; WO photos are already
on S3.

## Not yet / follow-ups

- Optional image sitemap / structured data (needs a contrib module — not added).
- A bulk "mark public" VBO on the staff tab (currently per-photo via edit link).
