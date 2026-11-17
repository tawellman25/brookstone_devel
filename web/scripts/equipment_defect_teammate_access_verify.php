<?php

declare(strict_types=1);

/**
 * @file
 * Verify teammates can enter and edit equipment defects (read-only on live).
 *
 * Checks the `teammates` role (six defect permissions held, no delete), the
 * "Defects" EVA (teammates allowed, "Report a problem" header with the
 * prepopulate link), then — as a real crew-only teammate — the access API and
 * routed GETs: an equipment page shows the EVA and the button, the pre-filled
 * add form renders with the photo upload and an enabled notes field, a defect
 * page and its edit form render, the delete form and the Open Defects board
 * stay 403, and the board still renders for uid 1. The write part (dev only)
 * creates a defect owned by that teammate, checks edit-any for a second crew
 * member, and deletes it.
 *
 * Exits non-zero only on a failed check.
 *
 *   drush php:script web/scripts/equipment_defect_teammate_access_verify.php
 *   drush php:script web/scripts/equipment_defect_teammate_access_verify.php -- --no-write  # live
 */

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

const TV_ROLE = 'teammates';
const TV_VIEW = 'equipment_defects_eva';
const TV_ADD_PATH = '/admin/content/equipment_defect/add/standard';
const TV_PERMS = [
  'view any equipment_defect entities',
  'view any equipment_defect entities of bundle standard',
  'create equipment_defect entities',
  'create equipment_defect entities of bundle standard',
  'edit any equipment_defect entities',
  'edit any equipment_defect entities of bundle standard',
];
const TV_NO_PERMS = [
  'delete any equipment_defect entities',
  'delete any equipment_defect entities of bundle standard',
  'delete own equipment_defect entities',
  'bypass eck entity access',
];
const TV_OFFICE_ROLES = ['supervisor', 'administration', 'site_admin', 'administrator', 'site_assistant'];

$write = !in_array('--no-write', $extra ?? [], TRUE);
$pass = 0;
$fail = 0;
$ok = static function (bool $b) use (&$pass, &$fail): string {
  $b ? $pass++ : $fail++;
  return $b ? 'ok  ' : 'FAIL';
};
$etm = \Drupal::entityTypeManager();

// ------------------------------------------------------------------ 1. role.
echo "== role ==\n";
$role = Role::load(TV_ROLE);
printf("[%s] role %s exists\n", $ok((bool) $role), TV_ROLE);
foreach (TV_PERMS as $p) {
  printf("[%s] holds '%s'\n", $ok($role && $role->hasPermission($p)), $p);
}
foreach (TV_NO_PERMS as $p) {
  printf("[%s] lacks '%s'\n", $ok($role && !$role->hasPermission($p)), $p);
}
printf("[%s] holds 'use text format full_html' (the notes field's only format)\n", $ok($role && $role->hasPermission('use text format full_html')));

// ------------------------------------------------------------------ 2. view.
echo "== view ==\n";
$view = View::load(TV_VIEW);
printf("[%s] view %s exists\n", $ok((bool) $view), TV_VIEW);
$opts = $view ? $view->get('display')['default']['display_options'] : [];
printf("[%s] access plugin is role\n", $ok(($opts['access']['type'] ?? '') === 'role'));
printf("[%s] %s allowed (%s)\n", $ok(isset($opts['access']['options']['role'][TV_ROLE])), TV_ROLE, implode(', ', array_keys($opts['access']['options']['role'] ?? [])));
$area = NULL;
foreach ($opts['header'] ?? [] as $a) {
  if (($a['plugin_id'] ?? '') === 'text' && str_contains((string) ($a['content']['value'] ?? ''), TV_ADD_PATH)) {
    $area = $a;
  }
}
$value = (string) ($area['content']['value'] ?? '');
printf("[%s] header text area links to the add form\n", $ok($area !== NULL));
printf("[%s] link pre-fills field_equipment through prepopulate\n", $ok(str_contains($value, 'edit[field_equipment][widget][0][target_id]={{ raw_arguments.field_equipment_target_id }}')));
printf("[%s] link returns to the machine (destination)\n", $ok(str_contains($value, 'destination=/equipment/{{ raw_arguments.field_equipment_target_id }}')));
printf("[%s] area is tokenized, shown with zero defects, full_html\n", $ok(!empty($area['tokenize']) && !empty($area['empty']) && ($area['content']['format'] ?? '') === 'full_html'));
$inherits = TRUE;
foreach ($view ? $view->get('display') : [] as $d) {
  if (($d['display_plugin'] ?? '') === 'entity_view' && isset($d['display_options']['defaults']['header']) && $d['display_options']['defaults']['header'] === FALSE) {
    $inherits = FALSE;
  }
}
printf("[%s] the entity_view display inherits the header\n", $ok($inherits));
printf("[%s] prepopulate module enabled\n", $ok(\Drupal::moduleHandler()->moduleExists('prepopulate')));
$deps = $view ? ($view->getDependencies()['config'] ?? []) : [];
printf("[%s] view depends on user.role.%s\n", $ok(in_array('user.role.' . TV_ROLE, $deps, TRUE)), TV_ROLE);

// ---------------------------------------------- 3. a real crew-only teammate.
echo "== access API as a crew-only teammate ==\n";
$uids = \Drupal::entityQuery('user')->accessCheck(FALSE)
  ->condition('status', 1)->condition('roles', TV_ROLE)->sort('uid')->execute();
$crew = [];
foreach (User::loadMultiple($uids) as $u) {
  if (!array_intersect($u->getRoles(), TV_OFFICE_ROLES) && !in_array('client', $u->getRoles(), TRUE)) {
    $crew[] = $u;
  }
}
printf("[%s] found %d active teammates holding no office role\n", $ok(count($crew) > 0), count($crew));
if (!$crew) {
  echo "cannot continue without a crew-only teammate\n";
  exit(1);
}
$tm = $crew[0];
echo '   using uid ' . $tm->id() . ' (' . $tm->getAccountName() . ')' . "\n";
$ach = $etm->getAccessControlHandler('equipment_defect');
printf("[%s] create access (bundle standard)\n", $ok($ach->createAccess('standard', $tm)));
$ids = \Drupal::entityQuery('equipment_defect')->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
$defect = $ids ? $etm->getStorage('equipment_defect')->load(reset($ids)) : NULL;
printf("[%s] an existing defect to test against\n", $ok((bool) $defect));
if ($defect) {
  echo '   defect ' . $defect->id() . ' on equipment ' . $defect->get('field_equipment')->target_id . "\n";
  printf("[%s] view access on it\n", $ok($defect->access('view', $tm)));
  printf("[%s] update access on it (edit any — owner is uid %d)\n", $ok($defect->access('update', $tm)), $defect->getOwnerId());
  printf("[%s] NO delete access on it\n", $ok(!$defect->access('delete', $tm)));
}

// ------------------------------------------------------------ 4. routed GETs.
echo "== routed GETs as that teammate ==\n";
$kernel = \Drupal::service('http_kernel');
$switch = \Drupal::service('account_switcher');
$get = static function (string $path) use ($kernel): array {
  $req = Request::create($path, 'GET');
  $req->setSession(new Session(new MockArraySessionStorage()));
  $res = $kernel->handle($req, HttpKernelInterface::SUB_REQUEST);
  return [$res->getStatusCode(), (string) $res->getContent()];
};
$machine = $defect ? (int) $defect->get('field_equipment')->target_id : 0;
$switch->switchTo($tm);
try {
  if ($machine) {
    [$code, $html] = $get("/equipment/$machine");
    printf("[%s] GET /equipment/%d → %d\n", $ok($code === 200), $machine, $code);
    printf("[%s]   shows the Defects EVA\n", $ok((bool) preg_match('~>\s*Defects\s*<~', $html)));
    printf("[%s]   lists defect %d (link)\n", $ok(str_contains($html, '/equipment_defect/' . $defect->id() . '"')), $defect->id());
    $linkRe = '~' . preg_quote(TV_ADD_PATH, '~') . '\?edit(?:\[|%5B)field_equipment(?:\]|%5D)(?:\[|%5B)widget(?:\]|%5D)(?:\[|%5B)0(?:\]|%5D)(?:\[|%5B)target_id(?:\]|%5D)=' . $machine . '(?:&amp;|&)destination=/equipment/' . $machine . '~';
    printf("[%s]   \"Report a problem\" button targets this machine\n", $ok((bool) preg_match($linkRe, $html) && str_contains($html, 'Report a problem with this machine')));

    $addPath = TV_ADD_PATH . '?edit[field_equipment][widget][0][target_id]=' . $machine . '&destination=/equipment/' . $machine;
    [$code, $html] = $get($addPath);
    printf("[%s] GET add form (pre-filled) → %d\n", $ok($code === 200), $code);
    printf("[%s]   field_equipment pre-filled with \"(%d)\"\n", $ok((bool) preg_match('~name="field_equipment\[0\]\[target_id\]"[^>]*value="[^"]*\(' . $machine . '\)"~', $html) || (bool) preg_match('~value="[^"]*\(' . $machine . '\)"[^>]*name="field_equipment\[0\]\[target_id\]"~', $html)), $machine);
    printf("[%s]   photo upload input present\n", $ok((bool) preg_match('~name="files\[field_defect_photos_0\]\[\]"~', $html)));
    printf("[%s]   notes field enabled (no text-format lockout)\n", $ok(str_contains($html, 'field_defect_notes[0][value]') && !str_contains($html, 'This field has been disabled')));
    printf("[%s]   summary + severity + status widgets present\n", $ok(str_contains($html, 'field_defect_summary[0][value]') && str_contains($html, 'field_defect_severity') && str_contains($html, 'field_defect_status')));
  }
  if ($defect) {
    $id = $defect->id();
    [$code, $html] = $get("/equipment_defect/$id");
    printf("[%s] GET /equipment_defect/%d → %d\n", $ok($code === 200), $id, $code);
    printf("[%s]   edit link/tab present\n", $ok(str_contains($html, "/equipment_defect/$id/edit")));
    [$code, $html] = $get("/equipment_defect/$id/edit");
    printf("[%s] GET edit form → %d\n", $ok($code === 200), $code);
    printf("[%s]   summary widget present\n", $ok(str_contains($html, 'field_defect_summary[0][value]')));
    [$code] = $get("/equipment_defect/$id/delete");
    printf("[%s] GET delete form → %d (expected 403)\n", $ok($code === 403), $code);
  }
  [$code] = $get('/admin/operations/equipment/open-defects');
  printf("[%s] GET Open Defects board → %d (office board, expected 403)\n", $ok($code === 403), $code);
}
finally {
  $switch->switchBack();
}
$switch->switchTo(User::load(1));
try {
  [$code, $html] = $get('/admin/operations/equipment/open-defects');
  printf("[%s] GET Open Defects board as uid 1 → %d, columns intact\n", $ok($code === 200 && str_contains($html, 'Equipment') && str_contains($html, 'Severity') && !str_contains($html, 'Warning: ')), $code);
}
finally {
  $switch->switchBack();
}

// ----------------------------------------------------------- 5. write part.
if (!$write) {
  echo "== write part skipped (--no-write) ==\n";
  printf("RESULT: %d ok, %d failed\n", $pass, $fail);
  if ($fail) {
    exit(1);
  }
  return;
}
echo "== write part (dev) ==\n";
if (!$machine) {
  echo "no machine to attach to — skipped\n";
}
else {
  $new = $etm->getStorage('equipment_defect')->create([
    'type' => 'standard',
    'uid' => $tm->id(),
    'field_equipment' => $machine,
    'field_defect_date_reported' => date('Y-m-d'),
    'field_defect_category' => 'other',
    'field_defect_severity' => 'monitor',
    'field_defect_summary' => 'verify: teammate-entered defect (delete me)',
    'field_defect_notes' => ['value' => '<p>verify</p>', 'format' => 'full_html'],
    'field_defect_status' => 'open',
  ]);
  // Validate and save AS the teammate: the text-format constraint checks the
  // current user's "use text format full_html", which drush's uid 0 lacks.
  $switch->switchTo($tm);
  try {
    $violations = $new->validate();
    printf("[%s] teammate-owned defect validates as uid %d (%d violations)\n", $ok(count($violations) === 0), $tm->id(), count($violations));
    foreach ($violations as $v) {
      echo '   - ' . $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage()) . "\n";
    }
    $new->save();
  }
  finally {
    $switch->switchBack();
  }
  printf("[%s] saved as defect %d owned by uid %d\n", $ok((bool) $new->id()), $new->id(), $new->getOwnerId());
  printf("[%s] owner can update it\n", $ok($new->access('update', $tm)));
  printf("[%s] owner cannot delete it\n", $ok(!$new->access('delete', $tm)));
  if (isset($crew[1])) {
    printf("[%s] another crew member (uid %d) can update it — edit any\n", $ok($new->access('update', $crew[1])), $crew[1]->id());
  }
  $new->delete();
  printf("[%s] cleanup: defect removed\n", $ok($etm->getStorage('equipment_defect')->load($new->id()) === NULL));
}

printf("RESULT: %d ok, %d failed\n", $pass, $fail);
if ($fail) {
  exit(1);
}
