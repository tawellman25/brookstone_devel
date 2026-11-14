<?php

/**
 * @file
 * Equipment Parts — end-to-end verification (dev). Read-only except for the
 * three example parts it seeds (idempotent, keyed on part number, author uid 1)
 * from the brief's HS 82 tune-up kit; pass --no-seed to skip seeding.
 *
 *   ddev drush php:script web/scripts/equipment_parts_verify.php [-- --no-seed]
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$seed = !in_array('--no-seed', $extra ?? [], TRUE);
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$ok = static fn(bool $b) => $b ? 'ok' : '** FAIL **';

// A hedge trimmer in the fleet to hang the example parts on.
$trimmer_tid = (int) $db->query("SELECT tid FROM {taxonomy_term_field_data} WHERE vid = 'equipment_types' AND name = 'Gas Hedge Trimmer'")->fetchField();
$unit_id = (int) $db->query('SELECT entity_id FROM {equipment__field_equipment_type} WHERE field_equipment_type_target_id = :t ORDER BY entity_id DESC LIMIT 1', [':t' => $trimmer_tid])->fetchField();
echo "Gas Hedge Trimmer term $trimmer_tid; example unit equipment $unit_id\n";

$storage = $etm->getStorage('equipment_part');
$examples = [
  ['CMR6H', 'Spark Plug CMR6H', 'spark_plug', 4, 2, 'Example from the HS 82 tune-up kit (brief). Set brand/vendor/price when confirmed.'],
  ['4237 120 1800', 'HS 82 kit part 4237 120 1800', 'other', 1, 2, 'Example from the HS 82 tune-up kit (brief). Category/brand/price to confirm.'],
  ['4229 007 1060', 'HS 82 kit part 4229 007 1060', 'other', 0, 0, 'Example from the HS 82 tune-up kit (brief). Category/brand/price to confirm. No minimum set — never flagged.'],
];
if ($seed) {
  foreach ($examples as [$pn, $name, $cat, $on_hand, $min, $note]) {
    $existing = $db->query('SELECT entity_id FROM {equipment_part__field_part_number} WHERE field_part_number_value = :p', [':p' => $pn])->fetchField();
    if ($existing) {
      echo "  seed $pn exists (id $existing)\n";
      continue;
    }
    $part = $storage->create([
      'type' => 'standard',
      'title' => $name,
      'uid' => 1,
      'field_part_number' => $pn,
      'field_part_category' => $cat,
      'field_equipment_types' => $trimmer_tid ? [['target_id' => $trimmer_tid]] : [],
      'field_fits' => $unit_id ? [['target_id' => $unit_id]] : [],
      'field_on_hand' => $on_hand,
      'field_reorder_min' => $min,
      'field_part_notes' => ['value' => $note, 'format' => 'basic_html'],
    ]);
    $part->save();
    echo "  seed $pn CREATED (id " . $part->id() . ")\n";
  }
}

echo "== reorder flag + alias ==\n";
foreach ($examples as [$pn, , , $on_hand, $min]) {
  $id = (int) $db->query('SELECT entity_id FROM {equipment_part__field_part_number} WHERE field_part_number_value = :p', [':p' => $pn])->fetchField();
  if (!$id) {
    echo "  $pn: not present (seeding skipped)\n";
    continue;
  }
  $p = $storage->loadUnchanged($id);
  $flag = (int) $p->get('field_needs_reorder')->value;
  $expected = ($min > 0 && $on_hand <= $min) ? 1 : 0;
  $alias = \Drupal::service('path_alias.repository')->lookupBySystemPath('/equipment_part/' . $id, 'en');
  printf("  %-14s on_hand=%d min=%d flag=%d (%s)  alias=%s  author uid=%s  active=%s\n", $pn, $on_hand, $min, $flag, $ok($flag === $expected), $alias['alias'] ?? '(none)', $p->getOwnerId(), $p->get('status')->value);
}

echo "== unique part number ==\n";
$has_cmr6h = (bool) $storage->getQuery()->accessCheck(FALSE)->condition('field_part_number', 'CMR6H')->range(0, 1)->execute();
if ($has_cmr6h) {
  $dupe = $storage->create(['type' => 'standard', 'title' => 'dupe test', 'field_part_number' => 'cmr6h ', 'field_part_category' => 'spark_plug']);
  $viol = $dupe->validate();
  $msgs = [];
  foreach ($viol as $v) {
    $msgs[] = $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage());
  }
  echo '  duplicate "cmr6h " (case + space variant) rejected: ' . $ok(count(array_filter($msgs, fn($m) => str_starts_with($m, 'field_part_number'))) > 0) . ' — ' . implode(' | ', $msgs) . "\n";
}
else {
  echo "  duplicate check skipped — no CMR6H part present (run without --no-seed, or add a part first)\n";
}
$fresh = $storage->create(['type' => 'standard', 'title' => 'fresh', 'field_part_number' => 'ZZ-NEVER-USED', 'field_part_category' => 'other']);
echo '  fresh number passes: ' . $ok(count($fresh->validate()) === 0) . "\n";

echo "== maintenance event / defect forms carry the new reference ==\n";
foreach ([['equipment_maintenance_event', 'field_parts_used'], ['equipment_defect', 'field_parts_needed']] as [$et, $f]) {
  $e = $etm->getStorage($et)->create(['type' => 'standard']);
  $d = EntityFormDisplay::collectRenderDisplay($e, 'default');
  $c = $d->getComponent($f);
  echo "  $et form: $f widget=" . ($c['type'] ?? '(hidden)') . ' ' . $ok(!empty($c)) . "\n";
}

echo "== routed GETs ==\n";
$pick = static function (array $need, array $forbid) use ($db): int {
  $q = "SELECT u.uid FROM {users_field_data} u WHERE u.status = 1 AND u.uid > 1";
  foreach ($need as $r) {
    $q .= " AND EXISTS (SELECT 1 FROM {user__roles} r WHERE r.entity_id = u.uid AND r.roles_target_id = '$r')";
  }
  foreach ($forbid as $r) {
    $q .= " AND NOT EXISTS (SELECT 1 FROM {user__roles} r WHERE r.entity_id = u.uid AND r.roles_target_id = '$r')";
  }
  return (int) $db->query($q . ' ORDER BY u.access DESC LIMIT 1')->fetchField();
};
$admin_roles = ['administrator', 'site_admin', 'site_assistant', 'administration'];
$sup = $pick(['supervisor'], $admin_roles);
$crew = $pick(['teammates'], array_merge($admin_roles, ['supervisor']));
echo "  supervisor-only uid $sup; crew-only uid $crew\n";
$kernel = \Drupal::service('http_kernel');
$switch = \Drupal::service('account_switcher');
$get = static function (int $uid, string $path, string $needle = '') use ($kernel, $switch, $etm): string {
  $switch->switchTo($etm->getStorage('user')->load($uid));
  try {
    $req = Request::create($path, 'GET');
    $req->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
    $res = $kernel->handle($req, HttpKernelInterface::SUB_REQUEST);
    $code = $res->getStatusCode();
    $hit = $needle === '' ? '' : (str_contains((string) $res->getContent(), $needle) ? " contains \"$needle\"" : " ** MISSING \"$needle\" **");
    return "$code$hit";
  }
  catch (\Throwable $e) {
    return 'EXC ' . get_class($e) . ': ' . $e->getMessage();
  }
  finally {
    $switch->switchBack();
  }
};
$cmr = (int) $db->query("SELECT entity_id FROM {equipment_part__field_part_number} WHERE field_part_number_value = 'CMR6H'")->fetchField();
$checks = [
  [$sup, '/admin/operations/equipment/parts', 'Spark Plug CMR6H'],
  [$sup, '/admin/operations/equipment/parts', 'Reorder'],
  [$sup, '/admin/operations/equipment/parts/reorder', '4237 120 1800'],
  [$sup, '/admin/content/equipment_part/add/standard', 'Part Name'],
  [$sup, $cmr ? '/equipment_part/' . $cmr : '/equipment_part/0', 'CMR6H'],
  [$sup, $unit_id ? '/equipment/' . $unit_id : '/equipment/0', 'Parts that fit this unit'],
  [$crew, $cmr ? '/equipment_part/' . $cmr : '/equipment_part/0', 'CMR6H'],
  [$crew, '/admin/operations/equipment/parts', ''],
  [$crew, '/admin/content/equipment_part/add/standard', ''],
];
foreach ($checks as [$uid, $path, $needle]) {
  printf("  uid %-6s %-48s %s\n", $uid, $path, $get($uid, $path, $needle));
}
echo "== route + local tasks/actions ==\n";
foreach (['view.equipment_parts.page_1', 'view.equipment_parts.page_2', 'eck.entity.add'] as $r) {
  try { \Drupal::service('router.route_provider')->getRouteByName($r); echo "  $r ok\n"; } catch (\Throwable $e) { echo "  $r ** FAIL ** " . $e->getMessage() . "\n"; }
}
$tasks = \Drupal::service('plugin.manager.menu.local_task')->getLocalTasksForRoute('view.equipment_parts.page_1');
echo '  local tasks on parts list: ' . implode(', ', array_map(fn($t) => implode('|', array_keys($t)), $tasks)) . "\n";
$actions = \Drupal::service('plugin.manager.menu.local_action')->getActionsForRoute('view.equipment_parts.page_1');
echo '  local actions on parts list: ' . implode(', ', array_keys($actions)) . "\n";
echo "== watchdog (last 10 min, severity <= warning) ==\n";
$rows = $db->query('SELECT type, severity, message FROM {watchdog} WHERE timestamp > :t AND severity <= 4 ORDER BY wid DESC LIMIT 8', [':t' => time() - 600])->fetchAll();
echo $rows ? '  ' . implode("\n  ", array_map(fn($r) => "$r->type [$r->severity] " . substr($r->message, 0, 140), $rows)) . "\n" : "  none\n";
