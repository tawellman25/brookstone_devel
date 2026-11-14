<?php

/**
 * @file
 * equipment_defect.standard.field_defect_notes — verification.
 *
 *   drush php:script web/scripts/equipment_defect_notes_verify.php              # dev: round-trips a test defect (then deletes it)
 *   drush php:script web/scripts/equipment_defect_notes_verify.php -- --no-write  # live: read-only
 *
 * Read-only part: field definition, both displays, the add form (routed GET
 * as uid 1 — defects have no role grants beyond site_admin's bypass) showing
 * the textarea in full_html (the only allowed format, so no selector), which
 * roles may edit defects and which text formats each may use.
 * Write part (dev only): saves a defect with ~2 KB of full_html, reloads it,
 * checks value length / format / rendered markup, deletes it.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$write = !in_array('--no-write', $extra ?? [], TRUE);
$ok = static fn(bool $b): string => $b ? 'ok' : '** FAIL **';
$etm = \Drupal::entityTypeManager();

echo "== definition ==\n";
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('equipment_defect', 'standard');
$d = $defs['field_defect_notes'] ?? NULL;
echo '  field_defect_notes present: ' . $ok((bool) $d) . "\n";
if ($d) {
  printf("  type=%s (%s)  cardinality=%d (%s)  required=%s (%s)  max length: none (text_long = longtext column)\n", $d->getType(), $ok($d->getType() === 'text_long'), $d->getFieldStorageDefinition()->getCardinality(), $ok($d->getFieldStorageDefinition()->getCardinality() === 1), $d->isRequired() ? 'yes' : 'no', $ok(!$d->isRequired()));
  $af = array_values($d->getSetting('allowed_formats') ?? []);
  printf("  label=\"%s\"  allowed_formats=%s (%s)\n", $d->getLabel(), json_encode($af), $ok($af === ['full_html']));
  echo '  help text: ' . $d->getDescription() . "\n";
}
$schema = \Drupal::database()->query("SHOW COLUMNS FROM {equipment_defect__field_defect_notes}")->fetchAllKeyed(0, 1);
echo '  column field_defect_notes_value: ' . ($schema['field_defect_notes_value'] ?? 'MISSING') . ' ' . $ok(($schema['field_defect_notes_value'] ?? '') === 'longtext') . "\n";

echo "== displays ==\n";
foreach (['form' => EntityFormDisplay::class, 'view' => EntityViewDisplay::class] as $kind => $class) {
  $disp = $class::load('equipment_defect.standard.default');
  $c = $disp?->getComponent('field_defect_notes');
  $a = $disp?->getComponent('field_defect_summary');
  printf("  %s: %s %s  (summary @%s, notes @%s — directly below: %s)\n", $kind, $c['type'] ?? 'not placed', $ok(!empty($c)), $a['weight'] ?? '?', $c['weight'] ?? '?', $ok(isset($a, $c) && (int) $c['weight'] === (int) $a['weight'] + 1));
}

echo "== who can edit defects / which formats ==\n";
foreach ($etm->getStorage('user_role')->loadMultiple() as $r) {
  if (in_array($r->id(), ['anonymous', 'authenticated'], TRUE)) {
    continue;
  }
  $p = $r->getPermissions();
  $edit = $r->isAdmin() || in_array('bypass eck entity access', $p, TRUE) || in_array('edit any equipment_defect entities', $p, TRUE) || in_array('edit any equipment_defect entities of bundle standard', $p, TRUE);
  $fmts = $r->isAdmin() ? ['(all)'] : array_map(static fn($x) => substr($x, 16), array_filter($p, static fn($x) => str_starts_with($x, 'use text format ')));
  $default = ($r->isAdmin() || in_array('full_html', $fmts, TRUE)) ? 'full_html' : 'NONE — field disabled (no full_html)';
  printf("  %-16s edit defects: %-3s  formats: %-45s → default on this field: %s\n", $r->id(), $edit ? 'yes' : 'no', implode(', ', $fmts), $default);
}

echo "== add form (routed GET as uid 1) ==\n";
$kernel = \Drupal::service('http_kernel');
$switch = \Drupal::service('account_switcher');
$switch->switchTo($etm->getStorage('user')->load(1));
try {
  $req = Request::create('/admin/content/equipment_defect/add/standard', 'GET');
  $req->setSession(new Session(new MockArraySessionStorage()));
  $res = $kernel->handle($req, HttpKernelInterface::SUB_REQUEST);
  $html = (string) $res->getContent();
  $has_textarea = (bool) preg_match('/<textarea[^>]*name="field_defect_notes\[0\]\[value\]"/', $html);
  $default_fmt = preg_match('/<select[^>]*name="field_defect_notes\[0\]\[format\]".*?<option value="([^"]+)" selected/s', $html, $m) ? $m[1] : (preg_match('/name="field_defect_notes\[0\]\[format\]"[^>]*value="([^"]+)"/', $html, $m2) ? $m2[1] . ' (single, hidden)' : 'no selector found');
  $opts = preg_match('/<select[^>]*name="field_defect_notes\[0\]\[format\]".*?<\/select>/s', $html, $mm) ? (preg_match_all('/<option value="([^"]+)"/', $mm[0], $oo) ? $oo[1] : []) : ['(none — single allowed format, no selector)'];
  printf("  status %d %s  textarea: %s  format default: %s %s  options: %s\n", $res->getStatusCode(), $ok($res->getStatusCode() === 200), $ok($has_textarea), $default_fmt, $ok(str_starts_with($default_fmt, 'full_html')), implode(',', $opts));
  echo '  label on form: ' . $ok(str_contains($html, 'Defect Notes / Repair Detail')) . '  help text on form: ' . $ok(str_contains($html, 'repair-strategy decisions')) . "\n";
}
finally {
  $switch->switchBack();
}

if (!$write) {
  echo "== round-trip skipped (--no-write) ==\ndone\n";
  return;
}

echo "== round-trip (dev): save ~2 KB full_html, reload, render, delete ==\n";
// Validate as uid 1: the format property's allowed values are the CURRENT
// account's usable formats, and drush runs as anonymous.
$switch->switchTo($etm->getStorage('user')->load(1));
$storage = $etm->getStorage('equipment_defect');
$eq = $etm->getStorage('equipment')->getQuery()->accessCheck(FALSE)->range(0, 1)->execute();
$first = static fn(string $f) => array_key_first($defs[$f]->getSetting('allowed_values') ?? []);
$body = '<p><strong>Repair strategy:</strong> replace carb diaphragm kit, not the whole carb.</p><ul>' . str_repeat('<li>Part 4237 120 1800 — air filter — 2 on hand</li>', 20) . '</ul><p>' . str_repeat('Long narrative detail. ', 40) . '</p>';
$e = $storage->create([
  'type' => 'standard',
  'title' => 'VERIFY field_defect_notes (temporary)',
  'uid' => 1,
  'field_equipment' => $eq ? reset($eq) : NULL,
  'field_defect_summary' => 'verify: long formatted notes',
  'field_defect_category' => $first('field_defect_category'),
  'field_defect_severity' => $first('field_defect_severity'),
  'field_defect_date_reported' => date('Y-m-d'),
  'field_defect_notes' => ['value' => $body, 'format' => 'full_html'],
]);
$viol = $e->validate();
echo '  validation: ' . $ok(count($viol) === 0) . (count($viol) ? ' — ' . implode(' | ', array_map(static fn($v) => $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage()), iterator_to_array($viol))) : '') . "\n";
$e->save();
$id = $e->id();
$storage->resetCache([$id]);
$r = $storage->load($id);
$v = $r->get('field_defect_notes');
printf("  saved id %d: stored length %d (sent %d) %s  format=%s %s\n", $id, strlen($v->value), strlen($body), $ok(strlen($v->value) === strlen($body)), $v->format, $ok($v->format === 'full_html'));
$build = $etm->getViewBuilder('equipment_defect')->view($r, 'default');
$rendered = (string) \Drupal::service('renderer')->renderInIsolation($build);
echo '  rendered keeps <strong> and <li>: ' . $ok(str_contains($rendered, '<strong>Repair strategy') && substr_count($rendered, '<li>') === 20) . "  label shown: " . $ok(str_contains($rendered, 'Defect Notes / Repair Detail')) . "\n";
$r->delete();
echo '  test defect deleted: ' . $ok(!$storage->load($id)) . "\n";
$switch->switchBack();
echo "done\n";
