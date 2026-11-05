<?php

/**
 * @file
 * The material list table must total what the customer is billed, and show
 * cost before markup ONLY to someone allowed to see margin.
 *
 * Each line stores both figures — `field_subtotal` (qty × unit cost) and
 * `field_subtotal_w_markup` (billed) — but the table only ever displayed the
 * billed one, so the office had to open the WO's Cost & Profit panel to learn
 * what a list cost. The panel reports the same Σ `field_subtotal`, per work
 * order; this puts it on the list itself.
 *
 * ⚠ The gate is the point of this test. Crew can open a material list. Price
 * is theirs to see, margin is not — and a render-array mistake here leaks
 * cost to every crew member who opens a job.
 *
 * Read-only: builds and renders the form, writes nothing.
 *
 * Run: drush php:script web/scripts/test_material_list_totals.php
 */

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $good, string $detail = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : '');
};

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$switcher = \Drupal::service('account_switcher');

// A list with several lines, so the totals are a real sum and not one row.
$listId = (int) $db->query('SELECT lid.field_list_id_target_id
  FROM {wo_material_list_item__field_list_id} lid
  JOIN {wo_material_list_item__field_subtotal} s ON s.entity_id = lid.entity_id AND s.deleted = 0
  WHERE lid.deleted = 0
  GROUP BY lid.field_list_id_target_id
  HAVING COUNT(*) > 2
  ORDER BY lid.field_list_id_target_id DESC LIMIT 1')->fetchField();
if (!$listId) {
  print "ABORT: no material list with more than two lines.\n";
  return;
}

// Expected totals, computed from the field tables rather than from the code
// under test.
$expCost = (float) $db->query('SELECT SUM(s.field_subtotal_value)
  FROM {wo_material_list_item__field_list_id} lid
  JOIN {wo_material_list_item__field_subtotal} s ON s.entity_id = lid.entity_id AND s.deleted = 0
  WHERE lid.deleted = 0 AND lid.field_list_id_target_id = :id', [':id' => $listId])->fetchField();
$expBilled = (float) $db->query('SELECT SUM(m.field_subtotal_w_markup_value)
  FROM {wo_material_list_item__field_list_id} lid
  JOIN {wo_material_list_item__field_subtotal_w_markup} m ON m.entity_id = lid.entity_id AND m.deleted = 0
  WHERE lid.deleted = 0 AND lid.field_list_id_target_id = :id', [':id' => $listId])->fetchField();

$fmtCost = number_format($expCost, 2);
$fmtBilled = number_format($expBilled, 2);
$fmtMarkup = number_format($expBilled - $expCost, 2);
printf("list %d — cost %s, billed %s, markup %s\n\n", $listId, $fmtCost, $fmtBilled, $fmtMarkup);

$list = $etm->getStorage('wo_material_list')->load($listId);

$render = function () use ($list): string {
  // A fresh form build per account — the form is cached per request otherwise.
  $form = \Drupal::formBuilder()->getForm(
    'Drupal\wo_material_list_management\Form\MaterialListManagementForm', $list);
  return (string) \Drupal::service('renderer')->renderPlain($form);
};

// --- someone who may see margin ---------------------------------------------
$admin = $etm->getStorage('user')->load(1);
$switcher->switchTo($admin);
$html = $render();
$switcher->switchBack();

printf("as %s (has permission: %s)\n", $admin->getDisplayName(),
  $admin->hasPermission('view wo cost profit') ? 'yes' : 'NO');
$ok('the billed total appears', str_contains($html, $fmtBilled), $fmtBilled);
$ok('the cost before markup appears', str_contains($html, $fmtCost), $fmtCost);
$ok('it is labelled as cost, not just a number', stripos($html, 'Cost before markup') !== FALSE);
$ok('the markup amount is shown', str_contains($html, $fmtMarkup), $fmtMarkup);
$ok('totals render in a <tfoot>, not as a fake item row',
  preg_match('#<tfoot>.*' . preg_quote($fmtCost, '#') . '.*</tfoot>#s', $html) === 1);

// --- a crew member, who may not ---------------------------------------------
// ⚠ Must be a teammate who does NOT hold the permission. Picking the
// lowest-numbered one selects Todd, who is also a teammate AND an admin — the
// gate then cannot be exercised at all and the test reports a false failure.
$candidates = array_map('intval', \Drupal::database()->query("SELECT ur.entity_id FROM {user__roles} ur
  JOIN {users_field_data} u ON u.uid = ur.entity_id AND u.status = 1
  WHERE ur.roles_target_id = 'teammates' AND ur.entity_id <> 1
  ORDER BY ur.entity_id")->fetchCol());
$crew = NULL;
foreach ($candidates as $uid) {
  $candidate = $etm->getStorage('user')->load($uid);
  if ($candidate && !$candidate->hasPermission('view wo cost profit')) {
    $crew = $candidate;
    break;
  }
}
if (!$crew) {
  print "\n  ⚠ no active teammate WITHOUT 'view wo cost profit' — gate untested\n";
}
else {
  $switcher->switchTo($crew);
  $crewHtml = $render();
  $switcher->switchBack();

  printf("\nas %s, role teammates (has permission: %s)\n", $crew->getDisplayName(),
    $crew->hasPermission('view wo cost profit') ? 'YES — gate cannot be tested' : 'no');
  $ok('crew still see the billed total', str_contains($crewHtml, $fmtBilled), $fmtBilled);
  $ok('⚠ crew do NOT see the cost figure', !str_contains($crewHtml, $fmtCost), $fmtCost);
  $ok('⚠ crew do NOT see the cost label', stripos($crewHtml, 'Cost before markup') === FALSE);
  $ok('⚠ crew do NOT see the markup amount', !str_contains($crewHtml, $fmtMarkup), $fmtMarkup);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
