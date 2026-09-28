<?php

declare(strict_types=1);

/**
 * Correct the founding year: 1995 -> 1997.
 *
 * TARGETED, not a global search-and-replace. "1995" also appears in a street
 * address (9103 1995 Rd., Austin), a work order number, a contract id, a
 * teammate's birthday and a crew note about an irrigation clock resetting
 * itself — none of which are the founding year. So this edits named entities
 * only, and reports what it changed in each.
 *
 * It also corrects the claims that are ARITHMETIC off the year. 2026 - 1997 is
 * 29, so "30+ years" and "over 30 years" stop being true the moment the year
 * moves; leaving them would put a contradiction on the same page as the fix.
 * They become "since 1997" instead of a smaller number, so they never go stale
 * and never need this script run again.
 *
 * NOT touched: Steve Ward's bio, which says he has over 32 years of experience
 * in the green industry. That is his own career, not the company's age, and
 * nothing here establishes it is wrong.
 *
 * Dry run unless BOS_YEAR_APPLY=1. Writes a backup JSON first. Idempotent.
 *
 *   drush php:script web/scripts/fix_founding_year.php
 *   BOS_YEAR_APPLY=1 drush php:script web/scripts/fix_founding_year.php
 */

$apply = getenv('BOS_YEAR_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$backup = [];
$changes = 0;

print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_YEAR_APPLY=1 to apply)\n\n";

/** Swap text, reporting how many hits. */
$swap = function (string $text, array $pairs, int &$n): string {
  foreach ($pairs as $from => $to) {
    $text = preg_replace($from, $to, $text, -1, $c);
    $n += $c;
  }
  return $text;
};

// The founding claims — matched by PHRASE, not by the bare year.
//
// A blanket \b1995\b on a page body is a trap: /about-us now also carries
// Gerald's bio, which says he "started his first landscaping company in 1995".
// That is a different company and a true statement about his career, and a
// year-only rule would rewrite it to 1997 the next time anyone ran this. The
// same reasoning that kept this script off street addresses and work-order
// numbers applies inside a page body.
$YEAR = [
  '/(Outdoor Spaces Since) 1995/i' => '$1 1997',
  '/(serving|Serving|since|Since) 1995/' => '$1 1997',
  '/(Ward began in) 1995/i' => '$1 1997',
];
$DERIVED = [
  '/\bOver 30 years on the Western Slope\b/i' => 'On the Western Slope since 1997',
  '/\b30\+ years on the Western Slope\b/i' => 'On the Western Slope since 1997',
  '/\bover 30 years serving\b/i' => 'serving',
  '/, over 30 years\./i' => ', since 1997.',
  '/\b30\+ years\b/i' => 'Since 1997',
];

// --- content -------------------------------------------------------------
$targets = [
  ['node', 105, 'body', 'value', $YEAR, '/services/landscape-lawn-care/maintenance-program'],
  ['node', 109, 'body', 'value', $YEAR + [
    // "began in 1997 ... For 30 years" reads as 2027. The Wards' actual tenure
    // is not derivable from anything here and is not ours to invent, so this
    // says something true instead of a made-up figure. Office can put the real
    // number in.
    '/\bFor 30 years, Steve and Eunice\b/' => 'For decades, Steve and Eunice',
  ], '/about-us'],
  ['block_content', 136, 'field_fl_lineage', 'value', $YEAR, 'sitewide footer lineage line'],
  ['taxonomy_term', 369, 'field_subtitle', 'value', $DERIVED, 'Winterizing subtitle'],
  ['taxonomy_term', 369, 'field_service_public_desc', 'summary', $DERIVED, 'Winterizing summary'],
];

foreach ($targets as [$type, $id, $field, $prop, $pairs, $where]) {
  $entity = $etm->getStorage($type)->load($id);
  if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
    printf("  MISS   %s %s.%s — not found\n", $type, $id, $field);
    continue;
  }
  $item = $entity->get($field)->first();
  $old = (string) $item->{$prop};
  $n = 0;
  $new = $swap($old, $pairs, $n);
  if ($n === 0) {
    printf("  ok     %-16s %s (nothing to change)\n", "$type $id", $where);
    continue;
  }
  printf("  change %-16s %s — %d replacement(s)\n", "$type $id", $where, $n);
  foreach (preg_split('/(?<=[.!?])\s+/', strip_tags($new)) as $sentence) {
    if (preg_match('/1997/', $sentence)) {
      printf("           → %s\n", trim(preg_replace('/\s+/', ' ', $sentence)));
    }
  }
  // Refuse to touch a sentence that is someone's own career history.
  if (preg_match('/first landscaping company in 199\d/i', $new, $m)) {
    if (!preg_match('/first landscaping company in 1995/i', $new)) {
      printf("  ABORT  %s %s — would have rewritten \"%s\"; that is a personal\n"
        . "         bio, not the company founding year. Nothing saved.\n", $type, $id, $m[0]);
      continue;
    }
  }
  $backup[] = ['type' => $type, 'id' => $id, 'field' => $field, 'prop' => $prop, 'old' => $old];
  $changes += $n;

  if ($apply) {
    $values = $entity->get($field)->getValue();
    $values[0][$prop] = $new;
    $entity->set($field, $values);
    if ($entity instanceof \Drupal\Core\Entity\RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if (method_exists($entity, 'setRevisionLogMessage')) {
        $entity->setRevisionLogMessage('Correct the founding year to 1997.');
      }
    }
    $entity->save();
  }
}

// --- active config -------------------------------------------------------
print "\nactive config:\n";
$cfg = \Drupal::configFactory()->getEditable('bos_homepage.settings');
$trust = (array) $cfg->get('trust');
$n = 0;
// NOT array_map with an arrow function: those capture by value, so the
// by-reference counter inside $swap never comes back and the change reports as
// "nothing to do" while silently not saving.
$newTrust = [];
foreach ($trust as $t) {
  $newTrust[] = $swap((string) $t, $DERIVED, $n);
}
if ($newTrust === $trust) {
  print "  ok     bos_homepage.settings trust (nothing to change)\n";
}
else {
  printf("  change bos_homepage.settings trust — %d replacement(s)\n", $n);
  foreach ($newTrust as $t) { printf("           → %s\n", $t); }
  $backup[] = ['type' => 'config', 'id' => 'bos_homepage.settings', 'field' => 'trust', 'old' => $trust];
  $changes += $n;
  if ($apply) {
    $cfg->set('trust', array_values($newTrust))->save();
  }
}

// --- view header/footer copy ---------------------------------------------
// The /services landing carries its own intro in the view header.
print "\nview areas:\n";
foreach ([['services', 'default', 'header', 'area']] as [$vid, $did, $area, $key]) {
  $v = \Drupal::configFactory()->getEditable('views.view.' . $vid);
  $path = "display.$did.display_options.$area.$key.content.value";
  $old = $v->get($path);
  if (!is_string($old)) {
    printf("  MISS   %s/%s/%s/%s — no content\n", $vid, $did, $area, $key);
    continue;
  }
  $c = 0;
  $new = $swap($old, $YEAR, $c);
  if ($c === 0) {
    printf("  ok     %s/%s (nothing to change)\n", $vid, $did);
    continue;
  }
  printf("  change %s/%s/%s — %d replacement(s)\n", $vid, $did, $area, $c);
  foreach (preg_split('/(?<=[.!?])\s+/', strip_tags($new)) as $sent) {
    if (str_contains($sent, '1997')) { printf("           → %s\n", trim(preg_replace('/\s+/', ' ', $sent))); }
  }
  $backup[] = ['type' => 'config', 'id' => 'views.view.' . $vid, 'field' => $path, 'old' => $old];
  $changes += $c;
  if ($apply) { $v->set($path, $new)->save(); }
}

// --- metatag defaults ----------------------------------------------------
print "\nmetatag defaults:\n";
$metatags = [
  'front' => ['description' => $YEAR],
  'global' => ['og_description' => ['/ — over 30 years\./i' => ', since 1997.']],
];
foreach ($metatags as $id => $tagPairs) {
  $md = \Drupal::configFactory()->getEditable('metatag.metatag_defaults.' . $id);
  $tags = (array) $md->get('tags');
  $dirty = FALSE;
  foreach ($tagPairs as $tag => $pairs) {
    if (!isset($tags[$tag]) || !is_string($tags[$tag])) {
      printf("  MISS   %s.%s — not set\n", $id, $tag);
      continue;
    }
    $c = 0;
    $new = $swap($tags[$tag], $pairs, $c);
    if ($c === 0) {
      printf("  ok     %s.%s (nothing to change)\n", $id, $tag);
      continue;
    }
    printf("  change %s.%s — %d replacement(s)\n           → %s\n", $id, $tag, $c, $new);
    $backup[] = ['type' => 'config', 'id' => 'metatag.metatag_defaults.' . $id, 'field' => $tag, 'old' => $tags[$tag]];
    $tags[$tag] = $new;
    $changes += $c;
    $dirty = TRUE;
  }
  if ($dirty && $apply) {
    $md->set('tags', $tags)->save();
  }
}

if ($apply && $backup) {
  $path = 'temporary://founding-year-backup-' . date('Ymd-His') . '.json';
  file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  print "\nbackup: " . \Drupal::service('file_system')->realpath($path) . "\n";
}

printf("\n%d replacement(s) %s.\n", $changes, $apply ? 'applied' : 'pending');
