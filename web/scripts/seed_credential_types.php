<?php

declare(strict_types=1);

/**
 * Seed the credential_types terms (content — run per env, the same as
 * seed_backflow_device_types.php). Idempotent, matched on field_credential_code.
 *
 * field_number_is_public follows the spec's disclosure rule: LICENSE numbers are
 * public record and publish; insurance POLICY numbers never do (carrier, coverage
 * type and limits are the right disclosure). A CDL number is a personal
 * identifier and is FALSE for the same reason §9 forbids storing a licence scan.
 *
 * field_verification_url is left EMPTY deliberately — the agency lookup links
 * live in marketing's Credentials Page Copy and are not invented here.
 * field_renewal_lead_days is seeded at the documented default 60 for every type;
 * office tunes per type (an insurance renewal and a CDL are not the same lead).
 *
 *   drush php:script web/scripts/seed_credential_types.php
 */

use Drupal\taxonomy\Entity\Term;

$VID = 'credential_types';

// code => [name, default_scope, number_is_public]
$TERMS = [
  'ABPA_TESTER'  => ['ABPA Backflow Tester', 'teammate', TRUE],
  'CDA_QS'       => ['CDA Qualified Supervisor', 'teammate', TRUE],
  'CDA_COMM_APP' => ['CDA Commercial Applicator (Business)', 'company', TRUE],
  'CDA_PRIV_APP' => ['CDA Private Applicator', 'teammate', TRUE],
  'USDOT'        => ['USDOT Registration', 'company', TRUE],
  'GL'           => ['General Liability', 'company', FALSE],
  'WC'           => ["Workers' Compensation", 'company', FALSE],
  'AUTO'         => ['Commercial Auto', 'company', FALSE],
  'BORGERT'      => ['Borgert Certified Installer', 'company', TRUE],
  'CDL'          => ['CDL', 'teammate', FALSE],
];

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$existing = [];
foreach ($storage->loadByProperties(['vid' => $VID]) as $t) {
  $code = (string) ($t->get('field_credential_code')->value ?? '');
  if ($code !== '') {
    $existing[$code] = $t;
  }
}

$created = 0;
$updated = 0;
foreach ($TERMS as $code => [$name, $scope, $numberPublic]) {
  $term = $existing[$code] ?? NULL;
  if (!$term) {
    $term = Term::create(['vid' => $VID, 'name' => $name]);
    $created++;
    $verb = 'created';
  }
  else {
    $updated++;
    $verb = 'updated';
  }
  $term->set('field_credential_code', $code);
  $term->set('field_default_scope', $scope);
  $term->set('field_number_is_public', $numberPublic);
  if ((int) ($term->get('field_renewal_lead_days')->value ?? 0) <= 0) {
    $term->set('field_renewal_lead_days', 60);
  }
  $term->save();
  printf("  %-8s %-12s %-38s scope=%-8s number_public=%s\n", $verb, $code, $name, $scope, $numberPublic ? 'YES' : 'no');
}

printf("\n%d created, %d updated, %d total terms.\n", $created, $updated, count($TERMS));
print "NOTE: field_verification_url left empty — office/marketing fills from the Credentials Page Copy.\n";
