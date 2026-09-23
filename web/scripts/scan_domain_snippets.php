<?php

/**
 * Show snippets around a needle for content/text columns, and flag whether the
 * occurrence is inside an <a href> or a bare URL (a real LINK) vs plain text.
 * Read-only. Run: drush php:script web/scripts/scan_domain_snippets.php
 */

$needle = getenv('BOS_SCAN_NEEDLE') ?: 'sewardslandscape';
$db = \Drupal::database();

// Content/text columns (skip pure email/login fields, which are addresses).
$targets = [
  ['taxonomy_term__field_service_public_desc', 'field_service_public_desc_value', 'entity_id'],
  ['taxonomy_term__field_service_crew_desc', 'field_service_crew_desc_value', 'entity_id'],
  ['node__body', 'body_value', 'entity_id'],
  ['node_revision__body', 'body_value', 'entity_id'],
  ['manufacturer__field_description', 'field_description_value', 'entity_id'],
  ['properties__field_property_description', 'field_property_description_value', 'entity_id'],
  ['work_order__field_work_todo_description', 'field_work_todo_description_value', 'entity_id'],
  ['property_snow_removal_info__a200001995', 'field_snow_removal_instructions_value', 'entity_id'],
  ['address__field_address_to', 'field_address_to_value', 'entity_id'],
  ['wo_status_updates__field_status_change_note', 'field_status_change_note_value', 'entity_id'],
  ['client_app__field_login_name', 'field_login_name_value', 'entity_id'],
];

foreach ($targets as [$t, $c, $idcol]) {
  try {
    $rows = $db->query("SELECT `$idcol` id, `$c` v FROM `$t` WHERE `$c` LIKE :q", [':q' => "%{$needle}%"])->fetchAll();
  }
  catch (\Exception $e) {
    continue;
  }
  if (!$rows) {
    continue;
  }
  print "\n### $t (id column $idcol) — " . count($rows) . " row(s)\n";
  foreach ($rows as $r) {
    $v = $r->v;
    $pos = stripos($v, $needle);
    $snip = preg_replace('/\s+/', ' ', substr($v, max(0, $pos - 70), 160));
    $window = substr($v, max(0, $pos - 250), 500);
    $isLink = (bool) preg_match('#href\s*=\s*["\'][^"\']*sewardslandscape#i', $window)
      || (bool) preg_match('#https?://[^\s"\'<]*sewardslandscape#i', $window)
      || (bool) preg_match('#www\.sewardslandscape#i', $window);
    print '  id ' . $r->id . ' [' . ($isLink ? 'LINK' : 'text/email') . ']: ' . $snip . "\n";
  }
}
print "\nDONE.\n";
