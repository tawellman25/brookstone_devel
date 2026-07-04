<?php

/**
 * Gate 2A test-target discovery. Read-only. Run: drush php:script <this>.
 */

$ps = \Drupal::entityTypeManager()->getStorage('properties');

$town = function ($p) {
  if (!$p->get('field_zipcode_reference')->isEmpty()) {
    $z = $p->get('field_zipcode_reference')->entity;
    if ($z && !$z->get('field_city')->isEmpty()) {
      $c = $z->get('field_city')->entity;
      return $c ? $c->label() : '';
    }
  }
  return '';
};

// #3: a surname (first comma token) appearing in >= 2 towns.
$ids = $ps->getQuery()->accessCheck(FALSE)->condition('type', 'property')
  ->condition('field_nickname', '%,%', 'LIKE')->range(0, 2500)->execute();
$sur = [];
foreach ($ps->loadMultiple($ids) as $p) {
  $nick = (string) $p->get('field_nickname')->value;
  $tw = $town($p);
  if (!$tw) {
    continue;
  }
  $s = strtolower(trim(explode(',', $nick)[0]));
  if (preg_match('/^[a-z]+$/', $s)) {
    $sur[$s][$tw][] = $p->id();
  }
}
print "=== surnames in >=2 towns (for #3 town filter / conflict) ===\n";
$found = 0;
foreach ($sur as $s => $towns) {
  if (count($towns) >= 2) {
    $line = "  \"$s\": ";
    foreach (array_slice($towns, 0, 3, TRUE) as $t => $pids) {
      $line .= "$t(P#" . implode(',', $pids) . ")  ";
    }
    print $line . "\n";
    if (++$found >= 5) {
      break;
    }
  }
}

// #4: business nicknames (no comma) with a town.
print "=== business candidates (no comma, single/short name) ===\n";
$bids = $ps->getQuery()->accessCheck(FALSE)->condition('type', 'property')
  ->exists('field_nickname')->range(0, 2500)->execute();
$shown = 0;
foreach ($ps->loadMultiple($bids) as $p) {
  $nick = trim((string) $p->get('field_nickname')->value);
  if ($nick === '' || str_contains($nick, ',')) {
    continue;
  }
  $tokens = preg_split('/\s+/', $nick);
  // "business-ish": 1-2 tokens, has a town, not obviously "First Last"
  if (count($tokens) <= 2 && $town($p) !== '' && !preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $nick)) {
    printf("  P#%d | \"%s\" | %s\n", $p->id(), $nick, $town($p));
    if (++$shown >= 8) {
      break;
    }
  }
}
