<?php

/**
 * @file
 * Generate a sample Snow Removal Service Agreement PDF for visual review.
 * Creates a temp snow contract with sample pricing (on a real property so the
 * customer/property section is populated), renders the PDF, deletes the temp
 * contract. Prints the output path.
 * Run: ddev drush php:script web/scripts/gen_snow_agreement_sample.php <out.pdf>
 */

use Drupal\contract_snow\Controller\SnowAgreementController;

$out = $extra['args'][0] ?? '/tmp/snow-agreement-sample.pdf';
$etm = \Drupal::entityTypeManager();

// Resolve the "2\" or more" snow_trigger term for the sample.
$trig = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'snow_trigger', 'name' => '2" or more']);
$trig_tid = $trig ? reset($trig)->id() : NULL;

// A real property with a primary contact + address, for a realistic sample.
$pid = $etm->getStorage('properties')->getQuery()
  ->accessCheck(FALSE)
  ->exists('field_primary_contact_ref')
  ->exists('field_full_address')
  ->range(0, 1)
  ->execute();
$pid = $pid ? reset($pid) : NULL;

$c = $etm->getStorage('contracts')->create([
  'type' => 'snow_removal',
  'field_contract_year' => '2026',
  'field_property' => $pid ? ['target_id' => $pid] : NULL,
  'field_snow_service_method' => 'automatic',
  'field_snow_trigger' => $trig_tid ? ['target_id' => $trig_tid] : NULL,
  'field_snow_ice_authorized' => TRUE,
  'field_shoveling_labor_included' => TRUE,
  'field_plow_rate_0_2' => '75.00',
  'field_plow_rate_2_4' => '110.00',
  'field_plow_rate_4_6' => '150.00',
  'field_plow_rate_6_plus' => '200.00',
  'field_salt_rate' => '45.00',
  'field_mag_rate' => '60.00',
  'field_shovel_rate' => '40.00',
  'field_snow_property_instructions' => 'Plow the north lot first. Watch for the fire hydrant near the SE corner and the low curb by the loading dock.',
]);
$c->save();
print "temp contract {$c->id()} number=" . $c->get('field_snow_contract_number')->value . " property={$pid}\n";

$controller = SnowAgreementController::create(\Drupal::getContainer());
$response = $controller->pdf($c);
file_put_contents($out, $response->getContent());
print 'wrote ' . filesize($out) . " bytes to $out\n";

$c->delete();
print "deleted temp contract\nDONE\n";
