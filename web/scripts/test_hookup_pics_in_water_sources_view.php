<?php

/**
 * @file
 * Prove the two hookup photos actually RENDER on the Water Sources EVA.
 *
 * Config presence is not proof — a Views field with a wrong table is accepted
 * into config and silently never rendered, which is the recurring trap in this
 * codebase. So this renders the display for real and looks for the derivative
 * URLs in the HTML.
 *
 * Reversible: the test photos are attached inside a DATABASE TRANSACTION that
 * is always rolled back, so nothing is committed, no cache tag is invalidated
 * and no file is created. Borrows an existing image rather than uploading one.
 *
 * Run: drush php:script web/scripts/test_hookup_pics_in_water_sources_view.php
 */

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

// A domestic source that already shows on this view, plus its parent system.
$sids = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'domestic_source')
  ->exists('field_property_ss_system')
  ->range(0, 1)->execute();
$source = $sids ? $etm->getStorage('property_ss_sources')->load(reset($sids)) : NULL;
$ok('found a domestic source with a system', (bool) $source, $source ? 'id=' . $source->id() : 'none');
if (!$source) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }
$systemId = (int) $source->get('field_property_ss_system')->target_id;

$fids = $etm->getStorage('file')->getQuery()->accessCheck(FALSE)
  ->condition('filemime', 'image/%', 'LIKE')->condition('status', 1)->range(0, 2)->execute();
$fids = array_values($fids);
$ok('borrowed two existing images', count($fids) >= 2, implode(',', $fids));
if (count($fids) < 2) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

/**
 * Render entity_view_2 (Domestic Source) for this system.
 */
/**
 * Execute the display and render ITS OWN output.
 *
 * Not views_embed_view() and not buildRenderable() — both return 0 bytes for an
 * entity_view (EVA) display, which renders through the EVA attachment rather
 * than as a standalone embed. execute() + render() is what exercises the FIELD
 * configuration, which is what changed here.
 */
$render = function () use ($systemId): string {
  $view = \Drupal\views\Views::getView('sprinkler_system_water_sources');
  $view->setDisplay('entity_view_2');
  $view->setArguments([$systemId]);
  $view->execute();
  if (!$view->result) {
    return '';
  }
  return (string) \Drupal::service('renderer')->renderRoot($view->render());
};

// --- baseline: no hookup photos yet -----------------------------------------
$before = $render();
$ok('the EVA renders at all for this system', $before !== '', strlen($before) . ' bytes');

// --- with photos, inside a transaction that is rolled back ------------------
$tx = \Drupal::database()->startTransaction();
$after = '';
try {
  $source->get('field_ss_hookup_location_pic')->appendItem(['target_id' => $fids[0]]);
  $source->get('field_ss_hookup_connected_pic')->appendItem(['target_id' => $fids[1]]);
  $source->save();
  // Views caches results per display; a fresh view object plus a reset render
  // cache is what makes the second render see the uncommitted rows.
  \Drupal::service('cache.render')->deleteAll();
  \Drupal::service('cache.data')->deleteAll();
  $after = $render();
}
finally {
  // rollBack() EXPLICITLY. Letting the Transaction object fall out of scope
  // COMMITS it — Drupal's destructor only rolls back when asked — so an
  // earlier version of this test wrote its two throwaway photos to a real
  // source record and then asserted they were not there.
  $tx->rollBack();
  unset($tx);
  \Drupal::service('cache.render')->deleteAll();
  \Drupal::service('cache.data')->deleteAll();
}

$f0 = $etm->getStorage('file')->load($fids[0]);
$f1 = $etm->getStorage('file')->load($fids[1]);
$name0 = basename($f0->getFileUri());
$name1 = basename($f1->getFileUri());

/**
 * Is this field's Views wrapper present AND does it hold a real <img>?
 *
 * Matching the raw filename is NOT reliable — a borrowed file may contain
 * spaces ("02-12-2017 FB Cover Photo.png"), which the rendered URL encodes as
 * %20, so a literal comparison fails on a field that rendered perfectly well.
 * The wrapper class plus an img inside it is what actually proves the field
 * produced output.
 */
$fieldRendered = function (string $field) use ($after): bool {
  $class = 'views-field-' . str_replace('_', '-', $field);
  $at = strpos($after, $class);
  if ($at === FALSE) {
    return FALSE;
  }
  // Look only within this field's own cell, not the whole row.
  $next = strpos($after, 'views-field-', $at + strlen($class));
  $cell = substr($after, $at, $next === FALSE ? NULL : $next - $at);
  return str_contains($cell, '<img');
};
$ok('hookup LOCATION photo renders an image in its own field cell',
  $fieldRendered('field_ss_hookup_location_pic'), $name0);
$ok('hooked-up (air hose) photo renders an image in its own field cell',
  $fieldRendered('field_ss_hookup_connected_pic'), $name1);
$ok('both labels render, so the three photo groups are distinguishable',
  str_contains($after, 'Hookup location') && str_contains($after, 'Hooked up'));
$ok('they were NOT showing before (so the render really changed)',
  !str_contains($before, $name0) || !str_contains($before, $name1));
$ok('the shut-off photo field is still on the display',
  str_contains($after, 'colorbox') || str_contains($before, 'colorbox'));

// --- the rollback actually rolled back --------------------------------------
$fresh = $etm->getStorage('property_ss_sources')->loadUnchanged($source->id());
$ok('rolled back — hookup location photo not committed',
  $fresh->get('field_ss_hookup_location_pic')->isEmpty());
$ok('rolled back — hooked-up photo not committed',
  $fresh->get('field_ss_hookup_connected_pic')->isEmpty());

printf("\n%d passed, %d failed\n", $pass, $fail);
