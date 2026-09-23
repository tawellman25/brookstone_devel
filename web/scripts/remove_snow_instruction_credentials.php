<?php

/**
 * Remove plaintext login/password credentials stored in the snow-removal
 * instructions field (a crew-facing free-text field — no place for secrets).
 * Backs up the affected rows first (so a genuinely-needed credential can be
 * moved to proper storage), then blanks the value. Direct SQL, no entity save.
 *
 * Dry-run by default; BOS_APPLY=1 to write:
 *   drush php:script web/scripts/remove_snow_instruction_credentials.php
 */

$apply = getenv('BOS_APPLY') === '1';
$db = \Drupal::database();
$table = 'property_snow_removal_info__a200001995';
$col = 'field_snow_removal_instructions_value';

// Explicit allowlist of (entity_id, delta) rows to blank — real stored secrets
// we've decided to remove. Keeps us from blanking legit instructions.
// Set BOS_TARGETS="4:1,40:2,6:2" to remove more.
$targetSpec = getenv('BOS_TARGETS') ?: '4:1';
$targets = [];
foreach (explode(',', $targetSpec) as $pair) {
  [$e, $d] = array_map('intval', explode(':', trim($pair)));
  $targets[] = ['e' => $e, 'd' => $d];
}
$rows = [];
foreach ($targets as $t) {
  $found = $db->select($table, 'x')
    ->fields('x', ['entity_id', 'revision_id', 'langcode', 'delta', $col])
    ->condition('entity_id', $t['e'])
    ->condition('delta', $t['d'])
    ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  foreach ($found as $f) {
    $f['v'] = $f[$col];
    $rows[] = $f;
  }
}

if (!$rows) {
  print "No credential-like rows found.\n";
  return;
}

$file = 'snow-credentials-backup-' . date('Ymd-His') . '.json';
file_put_contents('../' . $file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
print ($apply ? 'Backed up' : 'Would back up') . " " . count($rows) . " row(s) to (app root)/$file\n";

foreach ($rows as $r) {
  $preview = preg_replace('/\s+/', ' ', trim($r['v']));
  print "  entity {$r['entity_id']} delta {$r['delta']}: " . mb_substr($preview, 0, 60) . ($apply ? '  -> blanked' : '  (would blank)') . "\n";
  if ($apply) {
    $db->update($table)
      ->fields([$col => ''])
      ->condition('entity_id', $r['entity_id'])
      ->condition('revision_id', $r['revision_id'])
      ->condition('langcode', $r['langcode'])
      ->condition('delta', $r['delta'])
      ->execute();
  }
}
print ($apply ? "Removed." : "DRY RUN (set BOS_APPLY=1 to write).") . "\nDONE.\n";
