<?php

/**
 * @file
 * READ-ONLY. Where would the clock block land on every work_order bundle?
 *
 * `wo_clock_work_order_view()` anchors the clock UI relative to one component,
 * so a change to that anchor moves the buttons on all 36 bundles at once.
 * Before changing it, measure: for each bundle and each enabled view mode,
 * print the description weight, the Notes group weight, and what the current
 * and proposed rules would compute.
 *
 * Run: drush php:script web/scripts/audit_wo_clock_anchor.php
 */

$etm = \Drupal::entityTypeManager();
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));
sort($bundles);

printf("%-32s %-8s %-6s %-7s %-7s %-7s %-7s %s\n",
  'BUNDLE', 'MODE', 'DESC', 'NOTESGR', 'NOTESFLD', 'NOW', 'PROPOSED', 'EFFECT');
printf("%s\n", str_repeat('-', 118));

$moved = 0;
$same = 0;
$problems = [];

foreach ($bundles as $bundle) {
  foreach (['default', 'full'] as $mode) {
    $d = $etm->getStorage('entity_view_display')->load("work_order.$bundle.$mode");
    if (!$d || !$d->status()) {
      continue;
    }
    $desc = $d->getComponent('field_work_todo_description');
    $descW = (is_array($desc) && isset($desc['weight'])) ? (float) $desc['weight'] : NULL;

    // The Notes field GROUP is a field_group third-party setting, not a
    // component — getComponent() cannot see it.
    $groups = $d->getThirdPartySettings('field_group') ?: [];
    $notesGrW = NULL;
    $notesGrName = '';
    foreach ($groups as $name => $g) {
      if (isset($g['label']) && strcasecmp(trim($g['label']), 'Notes') === 0
        && ($g['region'] ?? 'content') === 'content') {
        $notesGrW = (float) ($g['weight'] ?? 0);
        $notesGrName = $name;
        break;
      }
    }
    $notesFld = $d->getComponent('wo_notes_entity_view_1');
    $notesFldW = (is_array($notesFld) && isset($notesFld['weight'])) ? (float) $notesFld['weight'] : NULL;

    // Current rule.
    $now = $descW !== NULL ? $descW + 0.5 : NULL;

    // Proposed: half a step ABOVE the Notes group, so anything the office
    // places between the description and Notes sits above the buttons. Only
    // when that actually lands below the description — otherwise keep today's
    // behaviour rather than floating the clock above the job description.
    $proposed = $now;
    if ($notesGrW !== NULL && ($notesGrW - 0.5) > ($descW ?? -INF)) {
      $proposed = $notesGrW - 0.5;
    }
    elseif ($notesGrW === NULL && $notesFldW !== NULL && ($notesFldW - 0.5) > ($descW ?? -INF)) {
      $proposed = $notesFldW - 0.5;
    }

    $effect = ($now === $proposed) ? 'unchanged' : sprintf('MOVES %s -> %s', $now ?? '-', $proposed ?? '-');
    if ($now === $proposed) {
      $same++;
    }
    else {
      $moved++;
    }
    if ($descW === NULL) {
      $problems[] = "$bundle/$mode: no description field";
    }
    if ($notesGrW === NULL && $notesFldW === NULL) {
      $problems[] = "$bundle/$mode: no Notes group or notes view";
    }

    printf("%-32s %-8s %-6s %-7s %-8s %-7s %-9s %s\n",
      $bundle, $mode,
      $descW ?? '-', $notesGrW !== NULL ? "$notesGrW" : '-', $notesFldW ?? '-',
      $now ?? '-', $proposed ?? '-', $effect);
  }
}

printf("\n%d displays unchanged, %d would move.\n", $same, $moved);
foreach (array_unique($problems) as $p) {
  printf("  NOTE %s\n", $p);
}
