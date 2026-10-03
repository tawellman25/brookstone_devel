<?php

/**
 * Environmental Tolerance characteristic copy.
 *
 * ⚠ ONLY ONE OF THE NINE IS HERE.
 *
 * The brief names `Website Copy/Plant Characteristics - Environmental Tolerance
 * 9 Terms.md` as the source, but that file was not supplied — the only text I
 * have is the Alkaline-Tolerant teaser, quoted verbatim in the preceding brief.
 * The other eight terms need their field_short_description,
 * field_public_description, field_teammate_description and metatags from that
 * file; they are listed below as awaiting copy rather than invented, and this
 * script takes them the moment the file arrives.
 *
 * Terms resolved by name within the vocabulary, then verified to be in the
 * Environmental Tolerance category, so copy cannot land on the wrong term.
 *
 * Usage:
 *   drush php:script web/scripts/seed_environmental_tolerance_copy.php
 *   BOS_ET_APPLY=1 drush php:script web/scripts/seed_environmental_tolerance_copy.php
 */

$apply = getenv('BOS_ET_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_ET_APPLY=1 to write)');

// name => [teaser, body, crew, meta] — NULL means "not yet supplied".
$copy = [
  'Alkaline-Tolerant' => [
    'teaser' => 'Handles soil above pH 7.5 without going chlorotic. On the Western Slope this is less a feature than a requirement, and it is the single most useful filter on a plant list.',
    'body' => NULL,
    'crew' => NULL,
  ],
  // The other eight of the nine, confirmed against the category itself
  // (field_characteristic_category = '2'). Awaiting the copy file —
  // deliberately not invented.
  'Acid-Loving' => NULL,
  'Cold-Hardy' => NULL,
  'Drought-Tolerant' => NULL,
  'Heat-Tolerant' => NULL,
  'Salt-Tolerant' => NULL,
  'Shade-Tolerant' => NULL,
  'Sun-Loving' => NULL,
  'Well-Drained Soil' => NULL,
];

$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'plant_characteristics']) as $t) {
  $terms[mb_strtolower((string) $t->label())] = $t;
}

$set = $same = $awaiting = $missing = 0;
foreach ($copy as $name => $spec) {
  $key = mb_strtolower($name);
  if (!isset($terms[$key])) {
    printf("  %-22s ** no such characteristic term — skipped **\n", $name);
    $missing++;
    continue;
  }
  if ($spec === NULL) {
    printf("  %-22s awaiting copy (teaser, body, crew, metatags)\n", $name);
    $awaiting++;
    continue;
  }
  $term = $terms[$key];
  $fields = ['teaser' => 'field_short_description', 'body' => 'field_public_description', 'crew' => 'field_teammate_description'];
  foreach ($fields as $role => $field) {
    if (($spec[$role] ?? NULL) === NULL || !$term->hasField($field)) {
      continue;
    }
    $item = $term->get($field);
    $cur = $item->isEmpty() ? '' : trim((string) $item->first()->getValue()['value']);
    $fmt = $item->isEmpty() ? 'basic_html' : ($item->first()->getValue()['format'] ?? 'basic_html');
    if ($cur === trim($spec[$role])) {
      printf("  %-22s %-26s already set\n", $name, $role);
      $same++;
      continue;
    }
    printf("  %-22s %-26s SET (%d chars)\n", $name, $role, mb_strlen($spec[$role]));
    $set++;
    if ($apply) {
      $term->set($field, ['value' => $spec[$role], 'format' => $fmt]);
    }
  }
  if ($apply) {
    $term->save();
  }
}

printf("\n%d set, %d already correct, %d awaiting copy, %d term(s) not found\n", $set, $same, $awaiting, $missing);
if (!$apply) { print "\nNothing written. Re-run with BOS_ET_APPLY=1 to apply.\n"; }
