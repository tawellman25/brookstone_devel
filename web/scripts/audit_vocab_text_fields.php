<?php

declare(strict_types=1);

/**
 * Read-only audit of a vocabulary's public/crew text fields + its meta-description
 * source, so the state of a live environment is never assumed.
 *
 * Per term: byte length of core `description`, field_short_description,
 * field_public_description, field_teammate_description, and whether a
 * field_meta_tags override carries a `description`. Then the field list of every
 * view display, and what the term's meta description will actually resolve from.
 *
 *   BOS_VID=wind_direction drush php:script web/scripts/audit_vocab_text_fields.php
 */

$vid = getenv('BOS_VID') ?: 'spraying_locations';
$etm = \Drupal::entityTypeManager();

$FIELDS = ['description', 'field_short_description', 'field_public_description', 'field_teammate_description'];

$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$terms = $etm->getStorage('taxonomy_term')->loadMultiple($tids);
if (!$terms) {
  print "No terms in vocabulary '$vid'.\n";
  return;
}
uasort($terms, fn($a, $b) => strcmp($a->label(), $b->label()));

$hasMeta = (bool) \Drupal::service('entity_field.manager')
  ->getFieldDefinitions('taxonomy_term', $vid)['field_meta_tags'] ?? FALSE;

printf("VOCAB: %s   (field_meta_tags instance: %s)\n\n", $vid, $hasMeta ? 'YES' : 'no');
printf("%-26s %10s %7s %8s %6s %9s\n", 'NAME', 'desc(core)', 'short', 'public', 'crew', 'meta.desc');
print str_repeat('-', 78) . "\n";

foreach ($terms as $t) {
  $len = [];
  foreach ($FIELDS as $f) {
    $len[$f] = $t->hasField($f) ? strlen(trim((string) ($t->get($f)->value ?? ''))) : -1;
  }
  $metaDesc = 0;
  if ($t->hasField('field_meta_tags')) {
    $raw = (string) ($t->get('field_meta_tags')->value ?? '');
    $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
    $metaDesc = strlen(trim((string) ($tags['description'] ?? '')));
  }
  printf(
    "%-26s %10d %7d %8d %6d %9s\n",
    mb_substr($t->label(), 0, 26),
    $len['description'],
    $len['field_short_description'],
    $len['field_public_description'],
    $len['field_teammate_description'],
    $t->hasField('field_meta_tags') ? (string) $metaDesc : '—'
  );
}

print "\nDISPLAYS:\n";
foreach (['default', 'full', 'teammate_view', 'admin_view'] as $mode) {
  $d = $etm->getStorage('entity_view_display')->load("taxonomy_term.$vid.$mode");
  printf("  %-14s %s\n", $mode, $d ? ('status=' . ($d->status() ? 'on' : 'OFF') . ' [' . implode(', ', array_keys($d->get('content') ?? [])) . ']') : 'MISSING');
}

print "\nMETA DESCRIPTION SOURCE:\n";
$tax = \Drupal::config('metatag.metatag_defaults.taxonomy_term')->get('tags') ?? [];
$glob = \Drupal::config('metatag.metatag_defaults.global')->get('tags') ?? [];
printf("  taxonomy_term default description : %s\n", $tax['description'] ?? '(none)');
printf("  global default description        : %s\n", $glob['description'] ?? '(NONE — no fallback)');
printf("  per-term override field           : %s\n", $hasMeta ? 'field_meta_tags present' : 'ABSENT — only the defaults above apply');
