<?php

/**
 * Pathauto patterns + aliases for the spray-condition reference vocabularies so
 * their terms stop appearing as /taxonomy/term/{id} in the sitemap.
 *
 *   spraying_locations  -> /spraying/location/{name}
 *   spraying_frequency  -> /spraying/frequency/{name}
 *   wind_direction      -> /spraying/wind-direction/{name}
 *
 * Idempotent; entity-API (no cim). Run on live:
 *   drush php:script web/scripts/add_spray_condition_term_aliases.php
 */

use Drupal\pathauto\PathautoState;

// Client-facing glossary pages linked from the digitized spray reports, so they
// live under the public Spraying service.
$patterns = [
  'spraying_locations_aliases' => ['spraying_locations', 'Spraying - Location - Path',       '/services/landscape-lawn-care/spraying/location/[term:name]'],
  'spraying_frequency_aliases' => ['spraying_frequency', 'Spraying - Frequency - Path',      '/services/landscape-lawn-care/spraying/frequency/[term:name]'],
  'wind_direction_aliases'     => ['wind_direction',     'Spraying - Wind Direction - Path', '/services/landscape-lawn-care/spraying/wind-direction/[term:name]'],
];

$out = [];
$patStorage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$am = \Drupal::service('path_alias.manager');

foreach ($patterns as $id => [$vocab, $label, $pat]) {
  $p = $patStorage->load($id);
  if (!$p) {
    $p = $patStorage->create([
      'id' => $id,
      'label' => $label,
      'type' => 'canonical_entities:taxonomy_term',
      'pattern' => $pat,
    ]);
    $p->addSelectionCondition([
      'id' => 'entity_bundle:taxonomy_term',
      'bundles' => [$vocab => $vocab],
      'negate' => FALSE,
      'context_mapping' => ['taxonomy_term' => 'taxonomy_term'],
    ]);
    $p->save();
    $out[] = "pattern $id created ($pat)";
  }
  elseif ($p->getPattern() !== $pat) {
    $p->setPattern($pat)->save();
    $out[] = "pattern $id updated -> $pat";
  }
  else {
    $out[] = "pattern $id already $pat";
  }

  $tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vocab)->execute();
  $done = 0;
  foreach ($termStorage->loadMultiple($tids) as $t) {
    $existing = $aliasStorage->loadByProperties(['path' => '/taxonomy/term/' . $t->id()]);
    if ($existing) {
      $aliasStorage->delete($existing);
    }
    if ($t->hasField('path')) {
      $t->get('path')->pathauto = PathautoState::CREATE;
    }
    $t->save();
    $done++;
  }
  $miss = 0;
  foreach ($tids as $tid) {
    if ($am->getAliasByPath('/taxonomy/term/' . $tid) === '/taxonomy/term/' . $tid) {
      $miss++;
    }
  }
  $sample = $tids ? $am->getAliasByPath('/taxonomy/term/' . reset($tids)) : '(none)';
  $out[] = "$vocab: aliased $done/" . count($tids) . " (missing $miss) e.g. $sample";
}

print implode("\n", $out) . "\nDONE.\n";
