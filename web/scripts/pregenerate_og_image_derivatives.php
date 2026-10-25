<?php

/**
 * @file
 * Pre-generate the og_image derivative for every image og:image can point at.
 *
 * WHY THIS IS NOT OPTIONAL. Files live on S3 (s3fs), and image-style
 * derivatives are created on FIRST REQUEST and written back to S3. If
 * Facebook's scraper is the first thing ever to ask for an og_image
 * derivative, that request can time out or return a redirect the scraper will
 * not follow — and Facebook then CACHES the failure. The card renders
 * imageless and stays that way until somebody manually re-scrapes the URL. So
 * every derivative is built before anything is shared.
 *
 * ⚠ It walks TAXONOMY TERMS and ECK ENTITIES, not nodes. BOS's public pages are
 * `taxonomy_term:services` and the `county`/`city`/`state` ECK types; a script
 * that looped over published nodes with an image field — the obvious shape, and
 * what a generic version of this task would do — would find essentially nothing
 * and report success.
 *
 * Sources, matching exactly what the three og:image handlers read:
 *   - taxonomy_term:services   field_home_page_slide   (bos_services)
 *   - county / city / state    field_banner_image      (bos_geo)
 * /winterize and the front page use STATIC module assets with no image style,
 * so they have no derivative to build and are deliberately absent here.
 *
 * Re-runnable and safe: an existing derivative is skipped, never rebuilt. Run
 * it again after any bulk image upload.
 *
 * DRY-RUN unless BOS_APPLY=1 (a dry run still reports exactly what it would do).
 *
 * Run: drush php:script web/scripts/pregenerate_og_image_derivatives.php
 */

use Drupal\image\Entity\ImageStyle;

const OG_STYLE = 'og_image';

$apply = getenv('BOS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

$style = ImageStyle::load(OG_STYLE);
if (!$style) {
  printf("ABORT: image style '%s' does not exist. Run setup_og_image_style.php first.\n", OG_STYLE);
  return;
}

/** Each source: [entity type, bundle or NULL, image field]. */
$sources = [
  ['taxonomy_term', 'services', 'field_home_page_slide'],
  ['county', NULL, 'field_banner_image'],
  ['city', NULL, 'field_banner_image'],
  ['state', NULL, 'field_banner_image'],
];

$generated = $skipped = $failed = $missing = $sourceMissing = 0;
$failures = [];
$missingList = [];

foreach ($sources as [$type, $bundle, $field]) {
  if (!$etm->hasDefinition($type)) {
    printf("  %-16s (entity type not present here)\n", $type);
    continue;
  }
  $storage = $etm->getStorage($type);
  $q = $storage->getQuery()->accessCheck(FALSE)->exists($field);
  if ($bundle !== NULL) {
    $bundleKey = $etm->getDefinition($type)->getKey('bundle');
    if ($bundleKey) {
      $q->condition($bundleKey, $bundle);
    }
  }
  $ids = $q->execute();
  $rowGenerated = $rowSkipped = 0;

  foreach ($storage->loadMultiple($ids) as $entity) {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      continue;
    }
    $file = $entity->get($field)->entity;
    if (!$file) {
      // A reference to a file row that no longer exists — worth counting
      // rather than silently passing, because og:image will be empty.
      $missing++;
      continue;
    }
    $uri = $file->getFileUri();
    $destination = $style->buildUri($uri);

    // ⚠ Distinguish "the SOURCE is not here" from "generation broke". On dev,
    // files live on S3 and stage_file_proxy fetches them on HTTP request, so
    // file_exists() on the source is FALSE and createDerivative() returns FALSE
    // — which looks like a generation failure but is just a local cache miss.
    // On live the source is on S3 and present, so a hit here is a real problem:
    // a referenced file whose bytes are gone, meaning og:image resolves to
    // nothing. Counted separately either way.
    if (!file_exists($uri)) {
      $sourceMissing++;
      $missingList[] = sprintf('%s %s: source not present (%s)', $type, $entity->id(), $uri);
      continue;
    }

    if (file_exists($destination)) {
      $rowSkipped++;
      $skipped++;
      continue;
    }
    if (!$apply) {
      $rowGenerated++;
      $generated++;
      continue;
    }
    try {
      if ($style->createDerivative($uri, $destination)) {
        $rowGenerated++;
        $generated++;
      }
      else {
        $failed++;
        $failures[] = sprintf('%s %s: createDerivative returned FALSE for %s', $type, $entity->id(), $uri);
      }
    }
    catch (\Throwable $e) {
      $failed++;
      $failures[] = sprintf('%s %s: %s', $type, $entity->id(), $e->getMessage());
    }
  }
  printf("  %-16s %-24s %3d to build, %3d already there\n", $type, $field, $rowGenerated, $rowSkipped);
}

printf("\n%s  generated: %d   skipped: %d   failed: %d   source bytes absent: %d   broken refs: %d\n",
  $apply ? 'APPLIED ' : 'DRY RUN ', $generated, $skipped, $failed, $sourceMissing, $missing);
foreach ($failures as $f) {
  print "  FAIL $f\n";
}
foreach (array_slice($missingList, 0, 10) as $m) {
  print "  SOURCE-ABSENT $m\n";
}
if ($sourceMissing > 0) {
  print "  (on DEV this is normal — stage_file_proxy fetches from S3 on request, not on disk.\n";
  print "   on LIVE it means a referenced file's bytes are gone and og:image will be empty.)\n";
}
if (!$apply && $generated > 0) {
  print "\nRe-run with BOS_APPLY=1 to build them.\n";
}
