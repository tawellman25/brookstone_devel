<?php

declare(strict_types=1);

/**
 * Open the material catalog to the public: grant "view any material entities of
 * bundle {bundle}" to the anonymous AND authenticated roles for EVERY material
 * bundle, so anyone (logged out or in) can view the catalog pages + JSON:API.
 *
 * SAFE because material_entity_field_access() (the field guard) denies cost,
 * supplier, stock, pack and internal fields to non-office/non-crew on the VIEW
 * operation — honoured by the page render, JSON:API, REST and Views alike. So
 * public visitors get the client tier only (image, description, specs, price,
 * UOM). Material has no published-status field, so "view any" is the correct
 * (and only) per-bundle view permission.
 *
 * Idempotent (grantPermission is a no-op if already held). Edits active role
 * config via the entity API (same as the permissions UI — NOT a cim). Run per
 * env. Reverse with revokePermission if ever needed.
 *
 *   drush php:script web/scripts/grant_material_public_view.php
 */

use Drupal\user\Entity\Role;

$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('material'));
sort($bundles);
$roles = ['anonymous', 'authenticated'];

foreach ($roles as $rid) {
  $role = Role::load($rid);
  if (!$role) {
    print "role $rid not found — skipping\n";
    continue;
  }
  $added = 0;
  foreach ($bundles as $b) {
    $perm = "view any material entities of bundle $b";
    if (!$role->hasPermission($perm)) {
      $role->grantPermission($perm);
      $added++;
    }
  }
  if ($added > 0) {
    $role->save();
  }
  printf("role %-14s: granted %d new bundle-view perms (of %d bundles)\n", $rid, $added, count($bundles));
}
print "DONE.\n";
