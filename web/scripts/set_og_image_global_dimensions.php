<?php

/**
 * @file
 * Emit og:image:width / :height / :alt alongside the GLOBAL fallback og:image.
 *
 * The global default already points at `home-hero.jpg` — that part was in place
 * before this work and is NOT changed here. What was missing is the dimensions,
 * which let Facebook lay a card out without fetching the file first; without
 * them a first share can render imageless and Facebook then CACHES that.
 *
 * ⚠ Why global is safe, checked rather than assumed. A page that overrides
 * og:image but NOT the dimensions would inherit these and state the wrong size.
 * The only candidates are taxonomy terms carrying a stored `og_image` in
 * field_meta_tags — and all 18 of them also have `field_home_page_slide`, so
 * `bos_services` always sets its own 1200x630 for them. Measured 2026-10-25:
 * zero pages would get mismatched numbers.
 *
 * The values describe home-hero.jpg as it actually is on disk (1920x1080) —
 * deliberately NOT 1200x630. The file is 16:9 and 733 KB, well inside
 * Facebook's 8 MB limit and fine as a card; replacing a working image to chase
 * 1.91:1 over 1.78:1 would be cosmetic risk for no real gain.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/set_og_image_global_dimensions.php
 */

const TAGS = [
  'og_image_width' => '1920',
  'og_image_height' => '1080',
  'og_image_alt' => 'Brookstone Outdoors — landscaping, irrigation and snow removal in Delta and Montrose counties, Colorado',
];

$apply = getenv('BOS_APPLY') === '1';
$config = \Drupal::configFactory()->getEditable('metatag.metatag_defaults.global');
if ($config->isNew()) {
  print "ABORT: metatag.metatag_defaults.global does not exist here.\n";
  return;
}

$tags = $config->get('tags') ?: [];
if (empty($tags['og_image'])) {
  print "ABORT: the global default has no og:image to describe. Nothing to do.\n";
  return;
}
printf("global og:image = %s\n", $tags['og_image']);

$changed = [];
foreach (TAGS as $key => $value) {
  $current = $tags[$key] ?? NULL;
  if ($current === $value) {
    printf("  %-16s already %s\n", $key, $value === '' ? '(empty)' : mb_substr($value, 0, 40));
    continue;
  }
  // Never clobber something the office typed.
  if ($current !== NULL && $current !== '') {
    printf("  %-16s SKIPPED — already set to \"%s\"\n", $key, mb_substr($current, 0, 50));
    continue;
  }
  $tags[$key] = $value;
  $changed[] = $key;
  printf("  %-16s -> %s\n", $key, mb_substr($value, 0, 60));
}

if (!$changed) {
  print "\nNothing to change.\n";
  return;
}
if (!$apply) {
  printf("\nDRY RUN — would set %d tag(s). Re-run with BOS_APPLY=1.\n", count($changed));
  return;
}

$config->set('tags', $tags)->save();
$after = \Drupal::config('metatag.metatag_defaults.global')->get('tags');
printf("\nSaved. Now set: %s\n", implode(', ', array_keys(array_intersect_key($after, TAGS))));
