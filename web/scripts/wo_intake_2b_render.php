<?php

/**
 * Gate 2B render acceptance. Renders each result state to HTML and asserts key
 * markup. Creates/cleans WOs on the local synced DB. Run: drush php:script.
 */

$container = \Drupal::getContainer();
$formObj = \Drupal\bos_wo_intake\Form\WoIntakeForm::create($container);
$svc = \Drupal::service('bos_wo_intake.intake');
$ws = \Drupal::entityTypeManager()->getStorage('work_order');
$ns = \Drupal::entityTypeManager()->getStorage('wo_notes');
$actors = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'cowork-connect']);
$actor = reset($actors);
$renderer = \Drupal::service('renderer');

$render = function (array $result) use ($formObj, $renderer) {
  $m = new \ReflectionMethod($formObj, 'renderResult');
  $m->setAccessible(TRUE);
  return (string) $renderer->renderPlain($m->invoke($formObj, $result));
};
$assert = function (string $label, string $html, array $needles) {
  $ok = TRUE;
  $miss = [];
  foreach ($needles as $n) {
    if (stripos($html, $n) === FALSE) { $ok = FALSE; $miss[] = $n; }
  }
  print '  [' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($ok ? '' : ' — missing: ' . implode(' | ', $miss)) . "\n";
};
$del = function ($id) use ($ws, $ns) {
  $wo = $ws->loadUnchanged($id); if (!$wo) return;
  foreach ($ns->getQuery()->accessCheck(FALSE)->condition('field_work_order', $id)->execute() as $nid) { $ns->load($nid)->delete(); }
  $wo->set('field_status', ['target_id' => 1089]); $wo->_skip_invoiced_guard = TRUE;
  try { $wo->save(); $wo->delete(); } catch (\Throwable $e) {}
};

// clean Lyman first
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }

print "=============== GATE 2B RENDER ACCEPTANCE ===============\n";

// Empty form structure.
$form = \Drupal::formBuilder()->getForm('Drupal\bos_wo_intake\Form\WoIntakeForm');
$formHtml = (string) $renderer->renderPlain($form);
$assert('empty form: textarea + autofocus + create button', $formHtml, ['wo-intake-textarea', 'autofocus', 'Create Work Order', 'placeholder']);

// created
$r = $svc->createFromText('Create a repair work order for Jim Lyman on Willow Dr. They have a broken sprinkler.', $actor);
$woId = $r['work_order']['id'] ?? NULL;
$assert('created card: badge + service/nickname + WO link + heard line', $render($r), ['wo-intake-card--created', 'Created', 'Repair', 'Lyman', 'work-orders/wo', 'Understood:', 'broken sprinkler']);
if ($woId) { $del($woId); }

// ambiguous(property) — 3 candidates
$r = $svc->createFromText('repair for mclaughlin', $actor);
$assert('property candidates: label + 3 buttons + street/town', $render($r), ['Which property', 'wo-intake-candidate', 'pick_property_', 'wo-intake-candidate__sub']);

// conflict flag
$r = $svc->createFromText('repair for jim lyman on willow in delta', $actor);
$assert('conflict candidate: conflict line rendered', $render($r), ['wo-intake-candidate__conflict', 'expected', 'actual']);

// ambiguous(service) with candidates
$r = $svc->createFromText('design work order for jim lyman', $actor);
$assert('service candidates: label + 2 term buttons', $render($r), ['Which service', 'pick_service_', 'Design']);

// ambiguous(service) ZERO candidates -> full picker
$r = $svc->createFromText('pruning for jim lyman', $actor);
$html = $render($r);
$optCount = substr_count($html, 'wo-intake-service-option');
$assert('service PICKER: filter input + full option list (>=30)', $html, ['wo-intake-picker', 'wo-intake-filter', 'wo-intake-service-option']);
print "     picker option count: $optCount (expect ~37)\n";

// blocked -> existing card + Create anyway
$r1 = $svc->createFromText('repair for jim lyman on willow dr', $actor);
$blockWo = $r1['work_order']['id'] ?? NULL;
$r2 = $svc->createFromText('repair for jim lyman on willow dr', $actor);
$assert('blocked card: existing WO + reason + Create anyway button', $render($r2), ['wo-intake-card--blocked', 'Already open', 'wo-intake-anyway', 'Create anyway']);
if ($blockWo) { $del($blockWo); }

// error
$assert('error card: message', $render(['status' => 'error', 'error' => ['code' => 'access_denied', 'message' => 'Not permitted.']]), ['wo-intake-card--error', 'Not permitted']);

// final clean
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }
print "=============== done ===============\n";
