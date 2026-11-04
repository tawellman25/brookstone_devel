<?php

/**
 * @file
 * The clock buttons must sit under the job description, above Notes — and on
 * pre_emergent, below the season field as well.
 *
 * Two assertions, because the risk here is collateral: the injection hook is
 * global to all 36 work_order bundles, so a change made for one bundle can
 * silently move the buttons on the other 35. The audit on 2026-11-03 showed
 * re-anchoring to the Notes group would have moved 24 of them, burying
 * snow_removal at weight 10.5.
 *
 *   1. REAL RENDER on a pre_emergent WO: season renders above the buttons,
 *      buttons above the Notes group.
 *   2. NO COLLATERAL: on every other bundle the anchor is still exactly
 *      description + 0.5.
 *
 * Read-only — renders, writes nothing.
 *
 * Run: drush php:script web/scripts/test_wo_clock_anchor.php
 */

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $good, string $detail = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : '');
};

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$storage = $etm->getStorage('entity_view_display');

// Render as a real account; an anonymous user gets no clock UI at all.
$uid = (int) $db->query('SELECT uid FROM {users_field_data} WHERE uid = 1')->fetchField();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($etm->getStorage('user')->load($uid));

try {
  // --- 1. pre_emergent, real render ------------------------------------------
  $woId = (int) $db->query("SELECT id FROM {work_order_field_data}
    WHERE type = 'pre_emergent' ORDER BY id DESC LIMIT 1")->fetchField();
  if (!$woId) {
    print "  (no pre_emergent work order to render)\n";
  }
  else {
    $wo = $etm->getStorage('work_order')->load($woId);
    $display = $storage->load('work_order.pre_emergent.default');
    $build = [];
    wo_clock_work_order_view($build, $wo, $display, 'full');

    printf("pre_emergent WO %d\n", $woId);
    if (!isset($build['wo_clock_ui'])) {
      $ok('the clock UI renders at all', FALSE, 'nothing injected — cannot test position');
    }
    else {
      $clockW = (float) $build['wo_clock_ui']['#weight'];
      $descW = (float) $display->getComponent('field_work_todo_description')['weight'];
      $seasonW = (float) $display->getComponent('field_pre_emergent_season')['weight'];
      $notesGrW = (float) $display->getThirdPartySetting('field_group', 'group_notes')['weight'];

      printf("  description %s | season %s | CLOCK %s | Notes group %s\n",
        $descW, $seasonW, $clockW, $notesGrW);

      $ok('the season field renders ABOVE the clock buttons', $seasonW < $clockW,
        "season $seasonW < clock $clockW");
      $ok('the buttons still render BELOW the job description', $descW < $clockW,
        "desc $descW < clock $clockW");
      $ok('the buttons still render ABOVE the Notes group', $clockW < $notesGrW,
        "clock $clockW < notes $notesGrW");
      $ok('the buttons cannot tie with a real component', $clockW !== floor($clockW),
        "weight $clockW is a half step");
    }

    // The Notes group should be expanded on arrival.
    $notes = $display->getThirdPartySetting('field_group', 'group_notes');
    $ok('the Notes group is open by default', !empty($notes['format_settings']['open']),
      'open=' . (!empty($notes['format_settings']['open']) ? 'true' : 'false'));
  }

  // --- 2. no collateral on the other bundles ---------------------------------
  print "\nevery other bundle still anchors at description + 0.5:\n";
  $bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));
  sort($bundles);
  $drift = [];
  $checked = 0;
  foreach ($bundles as $bundle) {
    if ($bundle === 'pre_emergent') {
      continue;
    }
    $display = $storage->load("work_order.$bundle.default");
    if (!$display || !$display->status()) {
      continue;
    }
    $desc = $display->getComponent('field_work_todo_description');
    if (!is_array($desc) || !isset($desc['weight'])) {
      // No description — falls back to the old flag position, not this rule.
      continue;
    }
    $woId = (int) $db->query('SELECT id FROM {work_order_field_data}
      WHERE type = :t ORDER BY id DESC LIMIT 1', [':t' => $bundle])->fetchField();
    if (!$woId) {
      continue;
    }
    $build = [];
    wo_clock_work_order_view($build, $etm->getStorage('work_order')->load($woId), $display, 'full');
    if (!isset($build['wo_clock_ui'])) {
      continue;
    }
    $checked++;
    $expected = (float) $desc['weight'] + 0.5;
    $actual = (float) $build['wo_clock_ui']['#weight'];
    if ($actual !== $expected) {
      $drift[] = sprintf('%s: expected %s, got %s', $bundle, $expected, $actual);
    }
  }
  $ok(sprintf('%d bundles unmoved', $checked), $drift === []);
  foreach ($drift as $d) {
    printf("      DRIFT %s\n", $d);
  }
}
finally {
  $switcher->switchBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
