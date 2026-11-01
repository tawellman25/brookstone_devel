<?php

/**
 * @file
 * Protect role permissions across a `material` module reinstall.
 *
 * WHY A REINSTALL IS NEEDED AT ALL: the hero and og:image work adds four hooks
 * — preprocess_page, preprocess_html, block_access, metatags_alter — that
 * `material` has NEVER implemented, and a brand-new hook on an already-enabled
 * module is not registered by `drush cr` (drupal_bos_gotchas.md). Zero config
 * entities depend on `material`, so the uninstall deletes no config.
 *
 * ⚠ BUT IT DOES COST PERMISSIONS, which the config check does not reveal.
 * Uninstalling a module REMOVES the permissions it provides from every role,
 * and re-enabling does NOT put the grants back. Proven on dev: the reinstall
 * silently dropped three grants.
 *
 *   review material price changes      (wo_material_price_sync)
 *   administer supplier price ingest   (supplier_price_ingest)
 *
 * `material` itself provides none — the ~880 material permissions are ECK
 * entity permissions, which survive.
 *
 * ⚠ IT ALSO CASCADES. Uninstalling `material` takes **wo_material_price_sync**
 * and **supplier_price_ingest** with it, because they depend on it. They must
 * be re-enabled too, and that is easy to miss because drush reports it in one
 * line among many.
 *
 * USAGE — the whole point is that the snapshot happens BEFORE:
 *
 *   BOS_MODE=snapshot  drush php:script …   # writes the JSON, changes nothing
 *   (reinstall: drush pmu -y material ; drush en -y material wo_material_price_sync supplier_price_ingest)
 *   BOS_MODE=restore   drush php:script …   # re-grants anything missing
 *   BOS_MODE=verify    drush php:script …   # compares against the snapshot
 *
 * The snapshot lives outside the docroot so a deploy cannot clobber it.
 */

const SNAPSHOT = '/home/brookstoneadmin/tmp/material_perms_snapshot.json';
// Permissions provided BY the modules this reinstall takes down.
const AT_RISK = [
  'review material price changes',
  'administer supplier price ingest',
];
const MODULES = ['material', 'wo_material_price_sync', 'supplier_price_ingest'];

$mode = getenv('BOS_MODE') ?: 'snapshot';
$path = getenv('BOS_SNAPSHOT_PATH') ?: SNAPSHOT;
$roleStorage = \Drupal::entityTypeManager()->getStorage('user_role');

/** Current holders of each at-risk permission. */
$current = function () use ($roleStorage): array {
  $out = [];
  foreach (AT_RISK as $perm) {
    $out[$perm] = [];
    foreach ($roleStorage->loadMultiple() as $rid => $role) {
      // is_admin roles hold everything implicitly and cannot lose a grant.
      if ($role->isAdmin()) {
        continue;
      }
      if (in_array($perm, $role->getPermissions(), TRUE)) {
        $out[$perm][] = $rid;
      }
    }
  }
  return $out;
};

if ($mode === 'snapshot') {
  $data = [
    'taken' => date('c'),
    'modules_enabled' => array_values(array_filter(MODULES,
      fn($m) => \Drupal::moduleHandler()->moduleExists($m))),
    'grants' => $current(),
  ];
  @mkdir(dirname($path), 0755, TRUE);
  file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
  printf("Snapshot written to %s\n", $path);
  printf("modules enabled now: %s\n", implode(', ', $data['modules_enabled']));
  foreach ($data['grants'] as $perm => $roles) {
    printf("  %-38s %s\n", $perm, $roles ? implode(', ', $roles) : '(nobody — nothing to lose)');
  }
  print "\nSafe to reinstall now.\n";
  return;
}

if (!file_exists($path)) {
  printf("ABORT: no snapshot at %s — run BOS_MODE=snapshot BEFORE the reinstall.\n", $path);
  return;
}
$data = json_decode((string) file_get_contents($path), TRUE);
if (!is_array($data) || empty($data['grants'])) {
  printf("ABORT: snapshot at %s is unreadable.\n", $path);
  return;
}
printf("Snapshot from %s\n", $data['taken'] ?? '?');

// Modules back on?
$off = array_values(array_filter($data['modules_enabled'] ?? [],
  fn($m) => !\Drupal::moduleHandler()->moduleExists($m)));
printf("modules still OFF after the reinstall: %s\n", $off ? implode(', ', $off) . '  ** re-enable them **' : 'none');

$now = $current();
$missing = [];
foreach ($data['grants'] as $perm => $roles) {
  foreach ($roles as $rid) {
    if (!in_array($rid, $now[$perm] ?? [], TRUE)) {
      $missing[] = [$rid, $perm];
    }
  }
}

if ($mode === 'verify') {
  printf("\ngrants missing against the snapshot: %d\n", count($missing));
  foreach ($missing as [$rid, $perm]) {
    printf("  MISSING %-16s %s\n", $rid, $perm);
  }
  print $missing ? "\nRun BOS_MODE=restore.\n" : "\nAll grants intact.\n";
  return;
}

// restore
if (!$missing) {
  print "\nNothing missing — all grants already intact.\n";
  return;
}
foreach ($missing as [$rid, $perm]) {
  $role = $roleStorage->load($rid);
  if (!$role) {
    printf("  SKIP %-16s role no longer exists\n", $rid);
    continue;
  }
  $role->grantPermission($perm)->save();
  printf("  restored %-16s %s\n", $rid, $perm);
}
$after = $current();
$stillMissing = 0;
foreach ($data['grants'] as $perm => $roles) {
  foreach ($roles as $rid) {
    if (!in_array($rid, $after[$perm] ?? [], TRUE)) {
      $stillMissing++;
    }
  }
}
printf("\nrestored %d grant(s); still missing: %d\n", count($missing), $stillMissing);
