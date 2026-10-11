<?php
/**
 * Enable the per-photo hero CAPTION field on a vocabulary (the house standard).
 *
 * See `__BOS_AI/Governance/ui_patterns.md` -> "Hero subtitles". A hero caption is
 * per photo, stored in the image item's `title` sub-field, and rotates with the
 * slides. `alt` stays required and describes the photo for screen readers and
 * image search; the caption is optional, because a photo that needs no line
 * should show none.
 *
 * `title_field` is an INSTANCE setting -- it is absent from the image field's
 * storage settings -- so enabling it on one vocabulary leaves every other bundle
 * sharing that field untouched.
 *
 * Usage:
 *   BOS_HERO_VID=season                drush php:script …   (dry run)
 *   BOS_HERO_VID=season BOS_HERO_APPLY=1 drush php:script …
 *
 *   BOS_HERO_FIELD=field_banner_image  for a vocabulary using the singular field
 *                                      (services and the geo entities do).
 *   BOS_HERO_MIGRATE_ALT=1             ALSO copy each photo's existing alt text
 *                                      into its caption.
 *
 * ⚠ BOS_HERO_MIGRATE_ALT is right only where the existing alt text IS the caption
 * copy -- i.e. the surface was previously rendering alt as the visible line
 * (season was, bos_geo still is). Where the subtitle came from somewhere else,
 * copying alt would publish a technical description, or a placeholder, as a
 * customer-facing caption. It is OFF by default for that reason.
 *
 * ⚠ ORDER: run this (with the migration where it applies) BEFORE deploying the
 * builder change that reads `title`. Reversed, every live caption blanks out in
 * between.
 *
 * Idempotent. Never overwrites a caption someone has written.
 */
$vid = getenv('BOS_HERO_VID') ?: '';
$field = getenv('BOS_HERO_FIELD') ?: 'field_banner_images';
$apply = getenv('BOS_HERO_APPLY') === '1';
$migrate = getenv('BOS_HERO_MIGRATE_ALT') === '1';

if ($vid === '') {
  print "  ABORT: set BOS_HERO_VID to the vocabulary machine name.\n";
  return;
}
printf("%s — %s.%s%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (set BOS_HERO_APPLY=1)',
  $vid, $field, $migrate ? '  [+ copying alt into caption]' : '');

$etm = \Drupal::entityTypeManager();
$inst = $etm->getStorage('field_config')->load("taxonomy_term.$vid.$field");
if (!$inst) {
  printf("  ABORT: %s is not on the %s bundle.\n", $field, $vid);
  return;
}
if ($inst->getType() !== 'image') {
  printf("  ABORT: %s is type '%s', not image — it has no title sub-field.\n", $field, $inst->getType());
  return;
}

// --- 1. the caption sub-field --------------------------------------------
if ($inst->getSetting('title_field')) {
  print "  caption sub-field: already enabled\n";
}
else {
  print "  caption sub-field: ENABLING (optional — a photo may have no caption)\n";
  if ($apply) {
    $inst->setSetting('title_field', TRUE)->setSetting('title_field_required', FALSE)->save();
  }
}
print $inst->getSetting('alt_field_required')
  ? "  alt: still required (unchanged)\n"
  : "  ⚠ alt is NOT required here — an undescribed image is possible\n";

// --- 2. optional: move existing caption copy out of alt ------------------
print "\n  photos:\n";
$moved = 0; $kept = 0; $none = 0; $seen = 0;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $term) {
  if (!$term->hasField($field) || $term->get($field)->isEmpty()) {
    continue;
  }
  $values = $term->get($field)->getValue();
  $dirty = FALSE;
  foreach ($values as $d => $v) {
    $seen++;
    $alt = trim((string) ($v['alt'] ?? ''));
    $title = trim((string) ($v['title'] ?? ''));
    $label = sprintf('    %-34s #%d', mb_strimwidth($term->label(), 0, 34, '…'), $d + 1);
    if ($title !== '') {
      printf("%s  caption already set, left alone: %s\n", $label, $title);
      $kept++;
      continue;
    }
    if (!$migrate) {
      printf("%s  no caption (alt: %s)\n", $label, $alt !== '' ? $alt : '(empty)');
      $none++;
      continue;
    }
    if ($alt === '') {
      printf("%s  no alt to copy — no caption\n", $label);
      $none++;
      continue;
    }
    printf("%s  alt -> caption: %s\n", $label, $alt);
    $values[$d]['title'] = $alt;
    $dirty = TRUE;
    $moved++;
  }
  if ($dirty && $apply) {
    $term->set($field, $values);
    $term->save();
  }
}
if ($seen === 0) {
  print "    (no photos attached yet — captions apply as they are uploaded)\n";
}
printf("\n  %d photo(s): %d copied, %d already captioned, %d without\n", $seen, $moved, $kept, $none);
print $apply ? "\ndone\n" : "\nnothing written\n";
