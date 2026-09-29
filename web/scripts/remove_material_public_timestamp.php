<?php

declare(strict_types=1);

/**
 * Drop the unlabelled created/author from customer-facing material displays.
 *
 * A material item page rendered a bare "Wed, 02/18/2026 - 09:26 AM" between the
 * tabs and the main image. Unlabelled, next to a product, a date reads as
 * availability or as the age of the listing. The author (uid) is in the same
 * displays and is no more meaningful to a customer.
 *
 * Removed from the `public` and `client` displays only — both are customer
 * facing. `teammate`, `admin` and `default` keep them, so the information is
 * still one role away, labelled, for anyone who needs it.
 *
 * Idempotent. Dry-run by default; BOS_TS_APPLY=1 to write.
 */

$apply = getenv('BOS_TS_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('material'));
sort($bundles);

$hit = 0;
foreach ($bundles as $bundle) {
  foreach (['public', 'client'] as $mode) {
    $d = $storage->load('material.' . $bundle . '.' . $mode);
    if (!$d) {
      continue;
    }
    $drop = array_values(array_filter(['created', 'uid'], static fn($f) => (bool) $d->getComponent($f)));
    if (!$drop) {
      continue;
    }
    printf("  %s %-18s %-8s remove %s\n", $apply ? 'WRITE ' : 'would ', $bundle, $mode, implode(' + ', $drop));
    $hit++;
    if ($apply) {
      foreach ($drop as $f) {
        $d->removeComponent($f);
      }
      $d->save();
    }
  }
}
printf("\n  %s displays touched: %d\n", $apply ? 'wrote' : 'would touch', $hit);
if (!$apply) {
  print "\nDRY RUN. BOS_TS_APPLY=1 to write.\n";
}
