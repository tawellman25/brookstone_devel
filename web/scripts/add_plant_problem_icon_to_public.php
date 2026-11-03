<?php

/**
 * @file
 * Show the plant-problem icon on the public page.
 *
 * `field_icon` was cloned onto `plant_problems` with the rest of the season
 * field set and is editable, but it renders on `default`, `teammate_view` and
 * `admin_view` only — NOT on `full`, which is what the public gets. That
 * omission was inherited faithfully: live's own `season.full` display does not
 * carry the icon either.
 *
 * On Seasons that is fine, because a season's icon does its work on a parent
 * listing. Plant Problems has no listing page yet (the spec defers it until
 * there are at least four terms, so a one-card listing is not published as a
 * thin page), so an icon set here renders absolutely nowhere. Todd uploaded one
 * and asked for it to show.
 *
 * Settings mirror the `default` display rather than being invented: image
 * formatter, `medium` style, label hidden. Weight −5 places it below the hero
 * (the banner component sits at −10, though the hero suppresses that copy for
 * public viewers) and above the article at 0.
 *
 * ⚠ This makes plant_problems DIVERGE from season by design. Noted so a future
 * reader does not "resync" the two and silently hide the icon again.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/add_plant_problem_icon_to_public.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;

const VID = 'plant_problems';
const MODE = 'full';
const FIELD = 'field_icon';

$apply = getenv('BOS_APPLY') === '1';

$display = EntityViewDisplay::load('taxonomy_term.' . VID . '.' . MODE);
if (!$display) {
  printf("ABORT: no %s view display for %s.\n", MODE, VID);
  return;
}

// Mirror the settings the bundle already uses, rather than inventing them.
$source = EntityViewDisplay::load('taxonomy_term.' . VID . '.default');
$ref = $source ? $source->getComponent(FIELD) : NULL;
$settings = [
  'type' => $ref['type'] ?? 'image',
  'label' => $ref['label'] ?? 'hidden',
  'settings' => $ref['settings'] ?? ['image_style' => 'medium', 'image_link' => ''],
  'third_party_settings' => $ref['third_party_settings'] ?? [],
  'weight' => -5,
];

$existing = $display->getComponent(FIELD);
printf("%s.%s — %s currently: %s\n", VID, MODE, FIELD,
  $existing ? 'VISIBLE (weight ' . ($existing['weight'] ?? '?') . ')' : 'not shown');
printf("will set: type=%s style=%s label=%s weight=%s\n",
  $settings['type'], $settings['settings']['image_style'] ?? '(original)',
  $settings['label'], $settings['weight']);

if ($existing) {
  print "\nAlready visible — nothing to do.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

$display->setComponent(FIELD, $settings)->save();

// Verify from a fresh read of config, not the object just saved.
$fresh = EntityViewDisplay::load('taxonomy_term.' . VID . '.' . MODE);
$now = $fresh->getComponent(FIELD);
printf("\nsaved. %s on %s: %s\n", FIELD, MODE, $now ? 'VISIBLE' : '** STILL HIDDEN **');
$content = $fresh->get('content');
uasort($content, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
print "order now:\n";
foreach ($content as $k => $v) {
  printf("  %-28s weight=%s\n", $k, $v['weight'] ?? 0);
}
