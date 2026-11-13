<?php

/**
 * @file
 * Equipment Parts & Consumables (equipment_part) — idempotent setup.
 *
 *   ddev drush php:script web/scripts/equipment_parts_setup.php -- --dry-run
 *   ddev drush php:script web/scripts/equipment_parts_setup.php
 *   live: drush php:script ~/tmp/equipment_parts_setup.php [-- --dry-run]
 *
 * Runs BEFORE the surgical partial cim of the same files (list in
 * web/scripts/equipment_parts_config_files.txt). Everything it creates comes
 * from the config/sync YAML through the entity API, with the YAML's uuid kept,
 * so the cim that follows sees no difference — the point is that the entity
 * type and every field instance already exist when cim arrives, which is the
 * documented BOS workaround for the field-instance silent-skip bug
 * (working_with_claude.md → "The BOS field-instance silent-skip bug").
 * Then it does the parts cim must not touch on live: pathauto registration,
 * module enable, role permissions, two taxonomy terms. Each step checks before
 * it writes; a re-run reports "exists" everywhere.
 *
 * Steps:
 *   1. ECK entity type + bundle (ECK installs the tables on save).
 *   2. Base-field overrides (title → "Part Name", status → "Active (stocked)").
 *   3. Field storages + instances: 15 on equipment_part, field_parts_used on
 *      equipment_maintenance_event, field_parts_needed on equipment_defect.
 *   4. Enable bos_equipment_parts (its views_data_alter must be in place
 *      before the views are saved).
 *   5. Form + view display (also first-ever displays for
 *      equipment_maintenance_event / equipment_defect), the two views, the
 *      pathauto pattern.
 *   6. pathauto.settings enabled_entity_types += equipment_part (gotchas:
 *      "ECK + pathauto: enabled_entity_types registration is mandatory").
 *   7. equipment_types terms the brief needs that the vocabulary lacks:
 *      ATV, Pole Saw.
 *   8. Permissions — administration / supervisor / site_assistant create,
 *      edit any, view any; teammates view any. No delete for anyone below
 *      site_admin (BOS rule 4 — retire with the Active flag instead).
 *   9. Verify.
 */

use Drupal\Core\Cache\Cache;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const EP = 'equipment_part';
const EP_BUNDLE = 'standard';
const EP_MODULE = 'bos_equipment_parts';
const EP_FIELDS = [
  'field_part_number', 'field_part_category', 'field_manufacturer', 'field_oem_cross', 'field_part_photo',
  'field_equipment_types', 'field_fits',
  'field_bin_location', 'field_on_hand', 'field_reorder_min', 'field_needs_reorder',
  'field_vendor', 'field_last_price', 'field_date_priced', 'field_part_notes',
];
const EP_LINK_FIELDS = [
  ['equipment_maintenance_event', 'standard', 'field_parts_used'],
  ['equipment_defect', 'standard', 'field_parts_needed'],
];
const EP_TERMS = ['ATV', 'Pole Saw'];
const EP_PERMS_EDIT = [
  'create equipment_part entities',
  'create equipment_part entities of bundle standard',
  'edit any equipment_part entities',
  'edit any equipment_part entities of bundle standard',
  'view any equipment_part entities',
  'view any equipment_part entities of bundle standard',
  'access equipment_part entity listing',
];
const EP_PERMS_VIEW = [
  'view any equipment_part entities',
  'view any equipment_part entities of bundle standard',
];
const EP_ROLES_EDIT = ['administration', 'supervisor', 'site_assistant'];
const EP_ROLES_VIEW = ['teammates'];

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}
$yaml = static function (string $name) use ($sync): array {
  $file = "$sync/$name.yml";
  if (!is_file($file)) {
    throw new \RuntimeException("missing $file");
  }
  $data = Yaml::parseFile($file);
  unset($data['_core']);
  return $data;
};
$etm = \Drupal::entityTypeManager();

// Create a config entity from its sync YAML if nothing with that id exists.
$ensure_config_entity = static function (string $entity_type, string $id, string $yaml_name) use ($etm, $yaml, $dry, $out): bool {
  $storage = $etm->getStorage($entity_type);
  if ($storage->load($id)) {
    $out("   $entity_type $id exists");
    return FALSE;
  }
  $data = $yaml($yaml_name);
  if (!$dry) {
    $storage->create($data)->save();
  }
  $out("   $entity_type $id CREATED");
  return TRUE;
};

// ── 1. entity type + bundle ────────────────────────────────────────────
$out('== 1. ECK entity type + bundle ==');
$created_type = $ensure_config_entity('eck_entity_type', EP, 'eck.eck_entity_type.' . EP);
if ($created_type && !$dry) {
  // The ECK postSave installs the schema; make sure every cached definition
  // (entity types, bundles, fields, routes) sees the new type before step 3.
  $etm->clearCachedDefinitions();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
}
// ECK derives one bundle config entity type per ECK type: `{type}_type`
// (config prefix eck.eck_type.{type}.*). It exists only once the type does.
if ($etm->hasDefinition(EP . '_type')) {
  $ensure_config_entity(EP . '_type', EP_BUNDLE, 'eck.eck_type.' . EP . '.' . EP_BUNDLE);
  if (!$dry) {
    \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  }
}
else {
  $out('   (bundle ' . EP_BUNDLE . ' would be created once the entity type exists)');
}

// ── 2. base-field overrides ────────────────────────────────────────────
$out('== 2. base-field overrides ==');
if ($dry && !$etm->hasDefinition(EP)) {
  $out('   (title → "Part Name", status → "Active (stocked)" — after the entity type exists)');
}
else {
  foreach (['title', 'status'] as $base) {
    $ensure_config_entity('base_field_override', EP . '.' . EP_BUNDLE . '.' . $base, 'core.base_field_override.' . EP . '.' . EP_BUNDLE . '.' . $base);
  }
}

// ── 3. fields ──────────────────────────────────────────────────────────
$out('== 3. fields ==');
$ensure_storage = static function (string $et, string $field) use ($yaml, $dry, $out): void {
  if (FieldStorageConfig::loadByName($et, $field)) {
    $out("   storage $et.$field exists");
    return;
  }
  $data = $yaml("field.storage.$et.$field");
  // Exported YAML carries settings in CONFIG shape (list_string allowed_values
  // as a value/label sequence); the entity API wants the in-memory shape.
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['type']);
  if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
    $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out("   storage $et.$field CREATED");
};
$ensure_instance = static function (string $et, string $bundle, string $field) use ($yaml, $dry, $out): void {
  if (FieldConfig::loadByName($et, $bundle, $field)) {
    $out("   instance $et.$bundle.$field exists");
    return;
  }
  $data = $yaml("field.field.$et.$bundle.$field");
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['field_type']);
  if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
    $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  $out("   instance $et.$bundle.$field CREATED");
};
if ($dry && !$etm->hasDefinition(EP)) {
  $out('   (entity type not installed yet — field steps would run after it is created)');
}
else {
  foreach (EP_FIELDS as $f) {
    $ensure_storage(EP, $f);
    $ensure_instance(EP, EP_BUNDLE, $f);
  }
}
foreach (EP_LINK_FIELDS as [$et, $bundle, $f]) {
  if ($dry && !$etm->hasDefinition(EP)) {
    $out("   (skip $et.$bundle.$f until the entity type exists)");
    continue;
  }
  $ensure_storage($et, $f);
  $ensure_instance($et, $bundle, $f);
}

// ── 4. module (before the views: their filters rely on its views_data_alter) ──────────────────────────────────────────────────────────
$out('== 4. module ' . EP_MODULE . ' ==');
if (\Drupal::moduleHandler()->moduleExists(EP_MODULE)) {
  $out('   enabled');
}
else {
  if (!$dry) {
    \Drupal::service('module_installer')->install([EP_MODULE]);
  }
  $out('   ENABLED');
}

// ── 5. displays, views, pathauto pattern ───────────────────────────────
$out('== 5. displays, views, pathauto pattern ==');
if ($dry && !$etm->hasDefinition(EP)) {
  $out('   (would create form display, view display, 2 views, pathauto pattern after the fields exist)');
}
else {
  $ensure_config_entity('entity_form_display', EP . '.' . EP_BUNDLE . '.default', 'core.entity_form_display.' . EP . '.' . EP_BUNDLE . '.default');
  $ensure_config_entity('entity_view_display', EP . '.' . EP_BUNDLE . '.default', 'core.entity_view_display.' . EP . '.' . EP_BUNDLE . '.default');
  // Maintenance events and defects never had a saved display — D11 then
  // renders their forms with no field widgets at all (see
  // equipment_parts_display_bootstrap.php). Give them one so Parts Used /
  // Parts Needed (and every other field) can be edited. Never overwrites.
  foreach (['equipment_maintenance_event', 'equipment_defect'] as $et) {
    $ensure_config_entity('entity_form_display', "$et.standard.default", "core.entity_form_display.$et.standard.default");
    $ensure_config_entity('entity_view_display', "$et.standard.default", "core.entity_view_display.$et.standard.default");
  }
  $ensure_config_entity('view', 'equipment_parts', 'views.view.equipment_parts');
  $ensure_config_entity('view', 'equipment_parts_fit', 'views.view.equipment_parts_fit');
  $ensure_config_entity('pathauto_pattern', EP, 'pathauto.pattern.' . EP);
}

// ── 6. pathauto registration ───────────────────────────────────────────
$out('== 6. pathauto.settings enabled_entity_types ==');
$pa = \Drupal::configFactory()->getEditable('pathauto.settings');
$types = $pa->get('enabled_entity_types') ?? [];
if (in_array(EP, $types, TRUE)) {
  $out('   equipment_part already registered');
}
else {
  $types[] = EP;
  if (!$dry) {
    $pa->set('enabled_entity_types', $types)->save();
  }
  $out('   equipment_part ADDED (' . count($types) . ' types)');
}

// ── 7. equipment_types terms ───────────────────────────────────────────
$out('== 7. equipment_types terms ==');
$term_storage = $etm->getStorage('taxonomy_term');
foreach (EP_TERMS as $name) {
  $existing = $term_storage->loadByProperties(['vid' => 'equipment_types', 'name' => $name]);
  if ($existing) {
    $out("   \"$name\" exists (tid " . reset($existing)->id() . ')');
    continue;
  }
  if (!$dry) {
    $term = $term_storage->create(['vid' => 'equipment_types', 'name' => $name]);
    if ($term->hasField('field_common_name')) {
      $term->set('field_common_name', $name);
    }
    $term->save();
    $out("   \"$name\" CREATED (tid " . $term->id() . ')');
  }
  else {
    $out("   \"$name\" would be created");
  }
}

// ── 8. permissions ─────────────────────────────────────────────────────
$out('== 8. permissions ==');
if ($dry && !$etm->hasDefinition(EP)) {
  $out('   (ECK generates the equipment_part permissions once the type exists — grants run then)');
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
  foreach (EP_ROLES_EDIT as $rid) {
    $grant($rid, EP_PERMS_EDIT);
  }
  foreach (EP_ROLES_VIEW as $rid) {
    $grant($rid, EP_PERMS_VIEW);
  }
}

// ── 9. verify ──────────────────────────────────────────────────────────
$out('== 9. verify ==');
if ($dry) {
  $out('   (dry run — nothing to verify)');
  return;
}
$etm->clearCachedDefinitions();
$ok = static fn(bool $b) => $b ? 'ok' : 'MISSING';
$out('   entity type definition: ' . $ok($etm->hasDefinition(EP)));
$schema = \Drupal::database()->schema();
$out('   tables: ' . $ok($schema->tableExists(EP) && $schema->tableExists(EP . '_field_data')));
$n = 0;
foreach (EP_FIELDS as $f) {
  $n += (int) (bool) FieldConfig::loadByName(EP, EP_BUNDLE, $f);
}
$out("   equipment_part instances: $n / " . count(EP_FIELDS));
foreach (EP_LINK_FIELDS as [$et, $bundle, $f]) {
  $out("   $et.$f: " . $ok((bool) FieldConfig::loadByName($et, $bundle, $f)));
}
$out('   form display: ' . $ok((bool) $etm->getStorage('entity_form_display')->load(EP . '.' . EP_BUNDLE . '.default')));
$out('   view display: ' . $ok((bool) $etm->getStorage('entity_view_display')->load(EP . '.' . EP_BUNDLE . '.default')));
foreach (['equipment_parts', 'equipment_parts_fit'] as $v) {
  $out("   view $v: " . $ok((bool) $etm->getStorage('view')->load($v)));
}
$alias_types = \Drupal::service('plugin.manager.alias_type')->getDefinitions();
$out('   pathauto alias type canonical_entities:equipment_part: ' . $ok(isset($alias_types['canonical_entities:' . EP])));
$out('   module enabled: ' . $ok(\Drupal::moduleHandler()->moduleExists(EP_MODULE)));
Cache::invalidateTags(['rendered', 'config:views.view.equipment', 'local_task', 'local_action']);
$out('   (render + menu caches invalidated; run drush cr — or the targeted refresh on live — for the new routes/menu links)');
