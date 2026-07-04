<?php

/**
 * Reference diagnostic for services terms 366 (Spraying) and 388 (Pruning).
 * Read-only. Scans every *_target_id column and config for the tids.
 */

$targets = [366 => 'Spraying', 388 => 'Pruning'];
$db = \Drupal::database();

// All entity-reference value columns in the schema.
$cols = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%_target_id'")->fetchAll();

foreach ($targets as $tid => $name) {
  print "=== term $tid ($name) ===\n";
  $hits = [];
  foreach ($cols as $c) {
    try {
      $n = (int) $db->query("SELECT COUNT(*) FROM {$c->TABLE_NAME} WHERE {$c->COLUMN_NAME} = :t", [':t' => $tid])->fetchField();
      if ($n > 0) {
        $hits[] = "  {$c->TABLE_NAME}.{$c->COLUMN_NAME} = $n";
      }
    }
    catch (\Throwable $e) {
      // skip unreadable
    }
  }
  print $hits ? implode("\n", $hits) . "\n" : "  NO entity references anywhere.\n";

  // Config references (field default_value, views filters, etc.) — string scan.
  $names = \Drupal::configFactory()->listAll();
  $cfgHits = [];
  foreach ($names as $cn) {
    $raw = serialize(\Drupal::config($cn)->getRawData());
    if (preg_match('/[isb]:' . $tid . ';/', $raw) || str_contains($raw, "\"$tid\"") || str_contains($raw, ":$tid:")) {
      // crude — confirm the tid appears as a target-ish value
      if (str_contains($raw, (string) $tid)) {
        $cfgHits[] = $cn;
      }
    }
  }
  // Filter to configs that plausibly reference a term (avoid coincidental digits).
  $cfgHits = array_values(array_filter($cfgHits, fn($cn) => str_contains($cn, 'field.') || str_contains($cn, 'views.') || str_contains($cn, 'default')));
  print $cfgHits ? "  config mentions: " . implode(', ', array_slice($cfgHits, 0, 10)) . "\n" : "  no field/view/default config references.\n";

  // Is it flagged as a WO service + its bundle mapping?
  $term = \Drupal\taxonomy\Entity\Term::load($tid);
  $wo = $term->get('field_work_order_service')->value;
  $bundle = $term->get('field_service_bundle')->value;
  print "  field_work_order_service=" . ($wo ? 'TRUE' : 'false') . "  field_service_bundle='" . ($bundle ?: '(empty)') . "'\n";
}
