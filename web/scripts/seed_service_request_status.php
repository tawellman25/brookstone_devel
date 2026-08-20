<?php

/**
 * Seed the service_request_status taxonomy vocabulary + its lifecycle terms.
 *
 * Taxonomy terms are CONTENT — their TIDs differ between DDEV and live. Run this
 * on BOTH environments. Nothing in code may hardcode a TID for this vocabulary;
 * resolve by name through ServiceRequestStatusResolver (Gate 2). Idempotent.
 *
 *   ddev drush php:script web/scripts/seed_service_request_status.php   (local)
 *   drush php:script web/scripts/seed_service_request_status.php        (live)
 */

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

$vid = 'service_request_status';

if (!Vocabulary::load($vid)) {
  Vocabulary::create([
    'vid' => $vid,
    'name' => 'Service Request Status',
    'description' => 'Lifecycle states for public service requests.',
  ])->save();
  print "created vocabulary: $vid\n";
}
else {
  print "vocabulary exists: $vid\n";
}

// Order matters for the admin queue default sort weighting.
$terms = ['New', 'Needs Review', 'Verified', 'Already Covered', 'Duplicate', 'Rejected', 'Converted'];
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$weight = 0;
foreach ($terms as $name) {
  $existing = $termStorage->loadByProperties(['vid' => $vid, 'name' => $name]);
  if ($existing) {
    printf("  term exists: %-16s tid=%s\n", $name, reset($existing)->id());
  }
  else {
    $t = Term::create(['vid' => $vid, 'name' => $name, 'weight' => $weight]);
    $t->save();
    printf("  created term: %-16s tid=%s\n", $name, $t->id());
  }
  $weight++;
}

print "DONE.\n";
