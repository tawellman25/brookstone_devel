<?php

declare(strict_types=1);

/**
 * Read-only audit of the spraying_locations vocabulary's text fields.
 *
 * Reports, per term: the URL-alias slug, and the byte length of core
 * `description`, field_short_description, field_public_description and
 * field_teammate_description — so it is obvious which terms still depend on the
 * legacy core `description` and which carry the three dedicated fields.
 *
 * Also prints the field list of each audience view-mode display.
 *
 *   drush php:script web/scripts/audit_spraying_locations_fields.php
 */

$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';

$FIELDS = [
  'description' => 'desc(core)',
  'field_short_description' => 'short',
  'field_public_description' => 'public',
  'field_teammate_description' => 'crew',
];

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]);
uasort($terms, fn($a, $b) => strcmp($a->label(), $b->label()));

printf("%-28s %-34s %10s %7s %8s %6s\n", 'NAME', 'ALIAS SLUG', 'desc(core)', 'short', 'public', 'crew');
print str_repeat('-', 100) . "\n";

$needCore = [];
foreach ($terms as $t) {
  $alias = \Drupal::service('path_alias.manager')
    ->getAliasByPath('/taxonomy/term/' . $t->id());
  $slug = basename($alias);
  $len = [];
  foreach (array_keys($FIELDS) as $f) {
    $len[$f] = $t->hasField($f) ? strlen(trim((string) ($t->get($f)->value ?? ''))) : -1;
  }
  printf(
    "%-28s %-34s %10d %7d %8d %6d\n",
    mb_substr($t->label(), 0, 28),
    mb_substr($slug, 0, 34),
    $len['description'],
    $len['field_short_description'],
    $len['field_public_description'],
    $len['field_teammate_description']
  );
  // A term "depends on core description" when it has core text but no public body.
  if ($len['description'] > 0 && $len['field_public_description'] <= 0) {
    $needCore[] = $t->label() . " (tid {$t->id()}, slug $slug)";
  }
}

print "\nterms: " . count($terms) . "\n";
print "DEPENDS ON CORE description (has core text, no field_public_description):\n";
print $needCore ? '  - ' . implode("\n  - ", $needCore) . "\n" : "  (none)\n";

print "\nDISPLAYS:\n";
foreach (['default', 'full', 'teammate_view', 'admin_view'] as $mode) {
  $d = $etm->getStorage('entity_view_display')->load("taxonomy_term.$vid.$mode");
  if (!$d) {
    printf("  %-14s MISSING\n", $mode);
    continue;
  }
  $content = array_keys($d->get('content') ?? []);
  printf("  %-14s status=%s [%s]\n", $mode, $d->status() ? 'on' : 'OFF', implode(', ', $content));
}
