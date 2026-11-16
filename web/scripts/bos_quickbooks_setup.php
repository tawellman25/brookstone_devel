<?php

/**
 * @file
 * BOS QuickBooks (qb_list mirror + bos_quickbooks module) — idempotent setup.
 *
 *   ddev drush php:script web/scripts/bos_quickbooks_setup.php -- --dry-run
 *   ddev drush php:script web/scripts/bos_quickbooks_setup.php
 *   live: drush php:script ~/tmp/bos_quickbooks_setup.php [-- --dry-run]
 *
 * Creates every config object listed in web/scripts/bos_quickbooks_config_files.txt
 * from its config/sync YAML through the entity API, KEEPING the YAML's uuid, so
 * live ends up equal to sync and a later partial cim of the same files sees no
 * difference (the documented BOS workaround for the field-instance silent-skip
 * bug and the uuid-mismatch DELETE+CREATE hazard). Then enables the module and
 * grants the permissions. Each step checks before it writes; a re-run reports
 * "exists" everywhere. Never overwrites an existing config entity.
 *
 * On live the 1 GB PMEM cap can kill a long drush process: re-run until the
 * verify section prints — every step is idempotent.
 */

use Drupal\Core\Cache\Cache;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const QB_ET = 'qb_list';
const QB_BUNDLES = ['customer', 'item', 'class'];
const QB_MODULE = 'bos_quickbooks';
const QB_PERMS_OFFICE = [
  'view quickbooks lists',
  'view any qb_list entities',
  'view any qb_list entities of bundle customer',
  'view any qb_list entities of bundle item',
  'view any qb_list entities of bundle class',
  'edit any qb_list entities of bundle customer',
  'access qb_list entity listing',
];
const QB_PERMS_ADMIN = ['administer quickbooks integration'];
const QB_ROLES_OFFICE = ['administration', 'site_assistant', 'site_admin'];
const QB_ROLES_ADMIN = ['site_admin'];

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}
$list_file = __DIR__ . '/bos_quickbooks_config_files.txt';
if (!is_file($list_file)) {
  throw new \RuntimeException("missing $list_file (run bos_quickbooks_config_gen.php first)");
}
$files = array_values(array_filter(array_map('trim', file($list_file))));
$yaml = static function (string $file) use ($sync): array {
  $path = "$sync/$file";
  if (!is_file($path)) {
    throw new \RuntimeException("missing $path");
  }
  $data = Yaml::parseFile($path);
  unset($data['_core']);
  return $data;
};
$etm = \Drupal::entityTypeManager();
$type_plugin = \Drupal::service('plugin.manager.field.field_type');

$ensure = static function (string $entity_type, string $id, array $data) use ($etm, $dry, $out): bool {
  $storage = $etm->getStorage($entity_type);
  if ($storage->load($id)) {
    $out("   $entity_type $id exists");
    return FALSE;
  }
  if (!$dry) {
    $storage->create($data)->save();
  }
  $out("   $entity_type $id CREATED");
  return TRUE;
};

// Group the list by kind, keeping each kind's order.
$by_kind = ['type' => [], 'bundle' => [], 'override' => [], 'storage' => [], 'instance' => [], 'display' => [], 'view' => []];
foreach ($files as $file) {
  $name = preg_replace('/\.yml$/', '', $file);
  $kind = match (TRUE) {
    str_starts_with($name, 'eck.eck_entity_type.') => 'type',
    str_starts_with($name, 'eck.eck_type.') => 'bundle',
    str_starts_with($name, 'core.base_field_override.') => 'override',
    str_starts_with($name, 'field.storage.') => 'storage',
    str_starts_with($name, 'field.field.') => 'instance',
    str_starts_with($name, 'core.entity_form_display.'), str_starts_with($name, 'core.entity_view_display.') => 'display',
    str_starts_with($name, 'views.view.') => 'view',
    default => throw new \RuntimeException("unexpected config $name"),
  };
  $by_kind[$kind][] = $file;
}

// ── 1. entity type ─────────────────────────────────────────────────────
$out('== 1. ECK entity type ==');
foreach ($by_kind['type'] as $file) {
  $data = $yaml($file);
  if ($ensure('eck_entity_type', $data['id'], $data) && !$dry) {
    $etm->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  }
}

// ── 2. bundles + base-field relabels ───────────────────────────────────
$out('== 2. bundles + base-field overrides ==');
if (!$etm->hasDefinition(QB_ET . '_type')) {
  $out('   (bundle config entity type ' . QB_ET . '_type appears once the entity type exists' . ($dry ? ' — dry run stops here for bundle/field steps)' : ')'));
}
else {
  foreach ($by_kind['bundle'] as $file) {
    $data = $yaml($file);
    $ensure(QB_ET . '_type', $data['type'], $data);
  }
  if (!$dry) {
    \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  }
  foreach ($by_kind['override'] as $file) {
    $data = $yaml($file);
    $ensure('base_field_override', $data['id'], $data);
  }
}

// ── 3. fields ──────────────────────────────────────────────────────────
$out('== 3. field storages + instances ==');
if (!$etm->hasDefinition(QB_ET)) {
  $out('   (skipped — entity type not installed yet)');
}
else {
  foreach ($by_kind['storage'] as $file) {
    $data = $yaml($file);
    if (FieldStorageConfig::loadByName($data['entity_type'], $data['field_name'])) {
      $out("   storage {$data['id']} exists");
      continue;
    }
    // Exported YAML carries settings in CONFIG shape (list_string allowed_values
    // as a value/label sequence); the entity API wants the in-memory shape.
    $def = $type_plugin->getDefinition($data['type']);
    if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
      $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
    }
    if (!$dry) {
      FieldStorageConfig::create($data)->save();
    }
    $out("   storage {$data['id']} CREATED");
  }
  foreach ($by_kind['instance'] as $file) {
    $data = $yaml($file);
    if (FieldConfig::loadByName($data['entity_type'], $data['bundle'], $data['field_name'])) {
      $out("   instance {$data['id']} exists");
      continue;
    }
    $def = $type_plugin->getDefinition($data['field_type']);
    if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
      $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
    }
    if (!$dry) {
      FieldConfig::create($data)->save();
    }
    $out("   instance {$data['id']} CREATED");
  }
}

// ── 4. module ──────────────────────────────────────────────────────────
$out('== 4. module ' . QB_MODULE . ' ==');
if (\Drupal::moduleHandler()->moduleExists(QB_MODULE)) {
  $out('   enabled');
}
else {
  if (!$dry) {
    \Drupal::service('module_installer')->install([QB_MODULE]);
  }
  $out('   ENABLED');
}

// ── 5. displays + views ────────────────────────────────────────────────
$out('== 5. form/view displays + views ==');
if (!$etm->hasDefinition(QB_ET)) {
  $out('   (skipped — entity type not installed yet)');
}
else {
  foreach ($by_kind['display'] as $file) {
    $data = $yaml($file);
    $type = str_starts_with($file, 'core.entity_form_display.') ? 'entity_form_display' : 'entity_view_display';
    $ensure($type, $data['id'], $data);
  }
  foreach ($by_kind['view'] as $file) {
    $data = $yaml($file);
    // Through the entity API so View::postSave registers the page routes.
    $ensure('view', $data['id'], $data);
  }
}

// ── 6. permissions ─────────────────────────────────────────────────────
$out('== 6. permissions ==');
if ($dry && !$etm->hasDefinition(QB_ET)) {
  $out('   (ECK generates the qb_list permissions once the type exists — grants run then)');
}
else {
  if (!$dry) {
    \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  }
  $known = array_keys(\Drupal::service('user.permissions')->getPermissions());
  $grant = static function (string $rid, array $perms) use ($known, $dry, $out): void {
    $role = Role::load($rid);
    if (!$role) {
      $out("   !! role $rid not found — skipped");
      return;
    }
    $added = [];
    foreach ($perms as $p) {
      if (!in_array($p, $known, TRUE)) {
        $out("   !! permission not defined (yet): $p");
        continue;
      }
      if (!$role->hasPermission($p)) {
        $role->grantPermission($p);
        $added[] = $p;
      }
    }
    if ($added && !$dry) {
      $role->save();
    }
    $out(sprintf('   %-16s %s', $rid, $added ? 'GRANTED ' . count($added) . ': ' . implode(' | ', $added) : 'already complete'));
  };
  foreach (QB_ROLES_OFFICE as $rid) {
    $grant($rid, QB_PERMS_OFFICE);
  }
  foreach (QB_ROLES_ADMIN as $rid) {
    $grant($rid, QB_PERMS_ADMIN);
  }
}

// ── 7. verify ──────────────────────────────────────────────────────────
$out('== 7. verify ==');
if ($dry) {
  $out('   (dry run — nothing to verify)');
  return;
}
$etm->clearCachedDefinitions();
$ok = static fn(bool $b) => $b ? 'ok' : 'MISSING';
$out('   entity type: ' . $ok($etm->hasDefinition(QB_ET)));
$schema = \Drupal::database()->schema();
$out('   tables: ' . $ok($schema->tableExists(QB_ET) && $schema->tableExists(QB_ET . '_field_data')));
$n = 0;
foreach ($by_kind['instance'] as $file) {
  $d = $yaml($file);
  $n += (int) (bool) FieldConfig::loadByName($d['entity_type'], $d['bundle'], $d['field_name']);
}
$out('   field instances: ' . $n . ' / ' . count($by_kind['instance']));
foreach (QB_BUNDLES as $b) {
  $out("   displays $b: form " . $ok((bool) $etm->getStorage('entity_form_display')->load(QB_ET . ".$b.default")) . ', view ' . $ok((bool) $etm->getStorage('entity_view_display')->load(QB_ET . ".$b.default")));
}
foreach (['qb_customers', 'qb_items', 'qb_classes'] as $v) {
  $out("   view $v: " . $ok((bool) $etm->getStorage('view')->load($v)));
}
$out('   module enabled: ' . $ok(\Drupal::moduleHandler()->moduleExists(QB_MODULE)));
Cache::invalidateTags(['rendered', 'local_task', 'local_action']);
\Drupal::service('router.builder')->rebuild();
$out('   (router rebuilt; render + menu caches invalidated — run drush cr, or the targeted refresh on live, for the menu link)');
