<?php

/**
 * @file
 * Backfill field_snow_contract_number (SNOW-{year}-{id}) on existing snow
 * contracts that predate the contract_snow module (its auto-number only fires
 * on insert). Idempotent — skips contracts that already have a number.
 * Run: ddev drush php:script web/scripts/backfill_snow_contract_numbers.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('contracts');
foreach ($storage->loadByProperties(['type' => 'snow_removal']) as $c) {
  if (!$c->hasField('field_snow_contract_number') || !$c->get('field_snow_contract_number')->isEmpty()) {
    continue;
  }
  $year = date('Y');
  if ($c->hasField('field_contract_year') && !$c->get('field_contract_year')->isEmpty()
      && preg_match('/(\d{4})/', (string) $c->get('field_contract_year')->value, $m)) {
    $year = $m[1];
  }
  $c->set('field_snow_contract_number', 'SNOW-' . $year . '-' . $c->id());
  $c->save();
  print 'contract ' . $c->id() . ' -> ' . $c->get('field_snow_contract_number')->value . "\n";
}
print "DONE\n";
