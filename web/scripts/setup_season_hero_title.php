<?php
/**
 * Give the season hero a real caption field, so alt text can go back to its job.
 *
 * Why: the hero subtitle was reading the image's ALT text, which made one field
 * do two jobs -- the description a screen reader announces for the photo, AND
 * the line a customer reads. Good alt text ("a bench on a gravel path beside a
 * maple in full colour") reads badly as a caption, good caption copy ("A bench
 * placed on a path we maintain") is weak alt text, and a screen reader met the
 * same sentence twice.
 *
 * So the image field's `title` sub-field is enabled on the season instance and
 * becomes the caption. `title_field` is an INSTANCE setting (it is absent from
 * the storage settings), so equipment_types and backflow_device_types are
 * untouched.
 *
 * Caption is OPTIONAL by design -- a photo with no caption shows no line, which
 * is better than forcing a sentence onto a photo that does not need one. Alt
 * stays REQUIRED, because an undescribed image is an accessibility hole.
 *
 * Migration without a content cliff: the photos already uploaded have their
 * caption copy sitting in `alt`, so it is COPIED to `title`. Alt is deliberately
 * left as-is rather than blanked -- it is required, and emptying it to force a
 * rewrite would either fail validation or leave photos undescribed. The caption
 * now reads from `title`, so when the alt text is later rewritten as a real
 * description the caption does not move.
 *
 * Idempotent. Never overwrites a title someone has written. Dry-run unless
 * BOS_HERO_TITLE_APPLY=1.
 */
$apply = getenv('BOS_HERO_TITLE_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (set BOS_HERO_TITLE_APPLY=1 to apply)');

$etm = \Drupal::entityTypeManager();
$inst = $etm->getStorage('field_config')->load('taxonomy_term.season.field_banner_images');
if (!$inst) {
  print "  ABORT: field_banner_images is not on the season bundle.\n";
  return;
}

// --- 1. the caption sub-field ---------------------------------------------
if ($inst->getSetting('title_field')) {
  print "  title sub-field: already enabled\n";
}
else {
  print "  title sub-field: ENABLING (optional -- a photo may have no caption)\n";
  if ($apply) {
    $inst->setSetting('title_field', TRUE)
      ->setSetting('title_field_required', FALSE)
      ->save();
  }
}
if (!$inst->getSetting('alt_field_required')) {
  print "  ⚠ alt is NOT required on this instance -- an undescribed image is possible\n";
}
else {
  print "  alt sub-field: still required (unchanged)\n";
}

// --- 2. move the existing caption copy out of alt -------------------------
print "\n  photos:\n";
$moved = 0; $kept = 0; $none = 0;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $term) {
  if (!$term->hasField('field_banner_images') || $term->get('field_banner_images')->isEmpty()) {
    continue;
  }
  $values = $term->get('field_banner_images')->getValue();
  $dirty = FALSE;
  foreach ($values as $d => $v) {
    $alt = trim((string) ($v['alt'] ?? ''));
    $title = trim((string) ($v['title'] ?? ''));
    if ($title !== '') {
      printf("    %-10s #%d  caption already set, left alone: %s\n", $term->label(), $d + 1, $title);
      $kept++;
      continue;
    }
    if ($alt === '') {
      printf("    %-10s #%d  no alt to copy -- no caption\n", $term->label(), $d + 1);
      $none++;
      continue;
    }
    printf("    %-10s #%d  alt -> caption: %s\n", $term->label(), $d + 1, $alt);
    $values[$d]['title'] = $alt;
    $dirty = TRUE;
    $moved++;
  }
  if ($dirty && $apply) {
    $term->set('field_banner_images', $values);
    $term->save();
  }
}
printf("\n  %d copied, %d already had a caption, %d have none\n", $moved, $kept, $none);
print $apply ? "\ndone\n" : "\nnothing written\n";
