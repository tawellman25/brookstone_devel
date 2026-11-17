<?php

declare(strict_types=1);

/**
 * @file
 * Let every teammate enter and edit equipment defects.
 *
 * Todd, 2026-11-17: "All admin and supervisors are teammates first" — one
 * grant on the `teammates` role covers the whole company.
 *
 * 1. Grants `teammates` view-any / create / edit-any on equipment_defect —
 *    type-level + bundle-level (`standard`), the pair BOS's other ECK grants
 *    use. No delete: deletion stays with the roles that already have it
 *    (administrator; site_admin through "bypass eck entity access").
 *    "edit any", not "edit own": a defect belongs to the machine, not to
 *    whoever logged it first.
 * 2. Opens the "Defects" EVA on every equipment page to teammates (it was
 *    role-gated to the four office roles) so crews see what is already logged.
 * 3. Adds a "Report a problem with this machine" button to that EVA's header:
 *    the add form with field_equipment pre-filled through `prepopulate`
 *    (the pattern the "Change Equipment Status" button already uses) and
 *    `destination` back to the machine. Shown even when the machine has no
 *    defects yet (`empty: true`).
 *
 * Role permissions are active config BOS never stages for cim, so this runs on
 * each environment. The view change is written to the active view here and
 * exported to config/sync from dev, so sync == active on both environments.
 *
 * Idempotent. Fails loudly (RuntimeException, nothing saved) when a permission
 * string is unknown, the role / view / text format is missing, prepopulate or
 * eva is off, the view's access plugin is not `role`, the EVA display overrides
 * the header, or the header already carries an area that is not ours. No cache
 * rebuild needed: role and view saves invalidate their own tags (a view save
 * also marks the router for rebuild, which this drush process performs).
 *
 *   drush php:script web/scripts/equipment_defect_teammate_access_setup.php -- --dry-run
 *   drush php:script web/scripts/equipment_defect_teammate_access_setup.php
 */

use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;

const TA_ROLE = 'teammates';
const TA_VIEW = 'equipment_defects_eva';
const TA_ADD_PATH = '/admin/content/equipment_defect/add/standard';
const TA_FORMAT = 'full_html';
const TA_PERMS = [
  'view any equipment_defect entities',
  'view any equipment_defect entities of bundle standard',
  'create equipment_defect entities',
  'create equipment_defect entities of bundle standard',
  'edit any equipment_defect entities',
  'edit any equipment_defect entities of bundle standard',
];

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void {
  echo $tag . $line . "\n";
};

// Shown on every equipment page; tokens are the EVA's equipment-id argument.
$href = TA_ADD_PATH
  . '?edit[field_equipment][widget][0][target_id]={{ raw_arguments.field_equipment_target_id }}'
  . '&amp;destination=/equipment/{{ raw_arguments.field_equipment_target_id }}';
$headerValue = '<p class="bos-defect-report"><a class="button button--primary" href="' . $href . '">Report a problem with this machine</a></p>';

// ---------------------------------------------------------------- 0. guards.
$out('0. Preconditions');
$known = \Drupal::service('user.permissions')->getPermissions();
foreach (TA_PERMS as $perm) {
  if (!isset($known[$perm])) {
    throw new RuntimeException("Unknown permission '$perm' — ECK's permission strings changed; nothing saved.");
  }
}
$out('   all ' . count(TA_PERMS) . ' permission strings exist (provider: ' . $known[TA_PERMS[0]]['provider'] . ' / ' . $known[TA_PERMS[1]]['provider'] . ')');
$role = Role::load(TA_ROLE);
if (!$role) {
  throw new RuntimeException('Role ' . TA_ROLE . ' not found; nothing saved.');
}
foreach (['prepopulate', 'eva', 'views'] as $module) {
  if (!\Drupal::moduleHandler()->moduleExists($module)) {
    throw new RuntimeException("Module $module is not enabled; the button depends on it. Nothing saved.");
  }
}
if (!\Drupal::entityTypeManager()->getStorage('filter_format')->load(TA_FORMAT)) {
  throw new RuntimeException('Text format ' . TA_FORMAT . ' missing; nothing saved.');
}
$view = View::load(TA_VIEW);
if (!$view) {
  throw new RuntimeException('View ' . TA_VIEW . ' not found; nothing saved.');
}
$out('   role, view, prepopulate, eva and ' . TA_FORMAT . ' present');

// ------------------------------------------------------- 1. role permissions.
$out('1. Role ' . TA_ROLE);
$granted = 0;
foreach (TA_PERMS as $perm) {
  if ($role->hasPermission($perm)) {
    $out("   already: $perm");
    continue;
  }
  $out("   grant:   $perm");
  if (!$dry) {
    $role->grantPermission($perm);
  }
  $granted++;
}
if ($granted && !$dry) {
  $role->save();
}
$out("   $granted permission(s) " . ($dry ? 'would be granted' : 'granted') . '; no delete permission touched');

// --------------------------------------------------------------- 2. the EVA.
$out('2. View ' . TA_VIEW . ' (uuid ' . $view->uuid() . ')');
$viewChanged = FALSE;
$displays = $view->get('display');
$default = &$displays['default']['display_options'];

$access = $default['access'] ?? [];
if (($access['type'] ?? '') !== 'role') {
  throw new RuntimeException('EVA access plugin is "' . ($access['type'] ?? 'none') . '", not "role" — look before changing it. Nothing saved.');
}
if (isset($access['options']['role'][TA_ROLE])) {
  $out('   access: ' . TA_ROLE . ' already allowed (' . implode(', ', array_keys($access['options']['role'])) . ')');
}
else {
  $default['access']['options']['role'][TA_ROLE] = TA_ROLE;
  $viewChanged = TRUE;
  $out('   access: add ' . TA_ROLE . ' → ' . implode(', ', array_keys($default['access']['options']['role'])));
}

// The entity_view display must inherit the header from the default display.
foreach ($displays as $id => $d) {
  if (($d['display_plugin'] ?? '') === 'entity_view' && isset($d['display_options']['defaults']['header']) && $d['display_options']['defaults']['header'] === FALSE) {
    throw new RuntimeException("Display $id overrides the header; it would not inherit the button. Nothing saved.");
  }
}

$header = $default['header'] ?? [];
$ours = NULL;
foreach ($header as $key => $area) {
  if (($area['plugin_id'] ?? '') === 'text' && str_contains((string) ($area['content']['value'] ?? ''), TA_ADD_PATH)) {
    $ours = $key;
  }
}
if ($ours !== NULL) {
  $out("   header: report button already present (area '$ours')");
  if (($header[$ours]['content']['value'] ?? '') !== $headerValue) {
    $default['header'][$ours]['content']['value'] = $headerValue;
    $viewChanged = TRUE;
    $out('   header: markup refreshed to the current text');
  }
}
elseif ($header) {
  throw new RuntimeException('EVA header already has area(s) ' . implode(', ', array_keys($header)) . ' that are not ours — look before adding. Nothing saved.');
}
else {
  $default['header'] = [
    'area' => [
      'id' => 'area',
      'table' => 'views',
      'field' => 'area',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => 'Report a problem button',
      'plugin_id' => 'text',
      'empty' => TRUE,
      'content' => [
        'value' => $headerValue,
        'format' => TA_FORMAT,
      ],
      'tokenize' => TRUE,
    ],
  ];
  $viewChanged = TRUE;
  $out('   header: add "Report a problem with this machine" (prepopulate link, shown with zero defects too)');
}
unset($default);

if ($viewChanged) {
  if (!$dry) {
    $view->set('display', $displays);
    $view->save();
  }
  $out('   view ' . ($dry ? 'would be saved' : 'saved'));
}
else {
  $out('   view unchanged');
}

// ---------------------------------------------------------------- 3. result.
$out('3. Result');
$role = Role::load(TA_ROLE);
$held = count(array_filter(TA_PERMS, static fn(string $p): bool => $role->hasPermission($p)));
$out('   ' . TA_ROLE . ' holds ' . $held . '/' . count(TA_PERMS) . ' defect permissions' . ($dry && $held < count(TA_PERMS) ? ' (all after a real run)' : ''));
$view = View::load(TA_VIEW);
$opts = $view->get('display')['default']['display_options'];
$out('   EVA roles: ' . implode(', ', array_keys($opts['access']['options']['role'] ?? [])));
$out('   EVA header areas: ' . (($opts['header'] ?? []) ? implode(', ', array_keys($opts['header'])) : 'none'));
$out('DONE' . ($dry ? ' (dry run — nothing saved)' : ''));
