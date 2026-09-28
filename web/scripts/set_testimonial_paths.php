<?php

declare(strict_types=1);

/**
 * Put testimonials under /about-us/testimonial/ID0002, and file their public
 * image the same way.
 *
 * The old alias pattern was:
 *   /[testimonial:field_customer:entity:url:path]/[service]-testimonial-[id]
 *
 * field_customer is a USER reference, so a testimonial with a customer linked
 * got an alias like /client/john-smith/repair-testimonial-4 — a customer's name
 * in the URL. The page itself is 403 to anonymous so that was not reachable,
 * but the matching image file would have been: a file in public:// is always
 * fetchable whatever the entity access says. The new pattern carries no
 * customer, no service and no name — just a padded reference, which is also
 * stable (the entity id never changes) and sorts properly.
 *
 * The image filename follows the same reference, so /about-us/testimonial/ID0002
 * and its picture are obviously the same record.
 *
 * Existing aliases are regenerated; the redirect module is on with
 * pathauto update_action=2, so the old URL 301s to the new one by itself.
 * Existing FILES are not moved (retroactive_update stays off) — nothing would
 * be gained and live URLs would break.
 *
 * Idempotent; run per environment (pathauto patterns and aliases are per-env).
 *
 *   drush php:script web/scripts/set_testimonial_paths.php
 */

// --- 1. alias pattern ------------------------------------------------------
$pattern = \Drupal::entityTypeManager()->getStorage('pathauto_pattern')->load('testimonials');
if (!$pattern) {
  print "ABORT: pathauto pattern 'testimonials' not found\n";
  return;
}
$want = '/about-us/testimonial/[testimonial:ref]';
if ($pattern->getPattern() === $want) {
  print "alias pattern already set\n";
}
else {
  printf("alias pattern:\n  was: %s\n  now: %s\n", $pattern->getPattern(), $want);
  $pattern->setPattern($want)->save();
}

// --- 2. image file path ----------------------------------------------------
$field = \Drupal::entityTypeManager()->getStorage('field_config')
  ->load('testimonial.client.field_testimonial_image');
if (!$field) {
  print "ABORT: field_testimonial_image not found\n";
  return;
}
$ffp = [
  'enabled' => TRUE,
  'file_path' => [
    'value' => 'about-us/testimonial',
    'options' => ['slashes' => FALSE, 'pathauto' => FALSE, 'transliterate' => TRUE],
  ],
  'file_name' => [
    // Same reference as the URL, so page and picture match at a glance.
    'value' => '[testimonial:ref].[file:ffp-extension-original]',
    'options' => ['slashes' => FALSE, 'pathauto' => FALSE, 'transliterate' => TRUE],
  ],
  'redirect' => FALSE,
  'retroactive_update' => FALSE,
  'active_updating' => FALSE,
];
if ($field->getThirdPartySettings('filefield_paths') == $ffp) {
  print "image file path already set\n";
}
else {
  printf("image file path:\n  dir:      %s\n  filename: %s\n", $ffp['file_path']['value'], $ffp['file_name']['value']);
  foreach ($ffp as $k => $v) {
    $field->setThirdPartySetting('filefield_paths', $k, $v);
  }
  $field->save();
}

// --- 3. regenerate existing aliases ---------------------------------------
$generator = \Drupal::service('pathauto.generator');
$storage = \Drupal::entityTypeManager()->getStorage('testimonial');
$ids = $storage->getQuery()->accessCheck(FALSE)->execute();
print "\naliases:\n";
foreach ($storage->loadMultiple($ids) as $t) {
  $before = $t->toUrl()->toString();
  $generator->updateEntityAlias($t, 'update');
  $after = \Drupal::entityTypeManager()->getStorage('testimonial')->load($t->id())->toUrl()->toString();
  printf("  %-4s %-36s -> %s\n", $t->id(), $before, $after);
}
print "DONE.\n";
