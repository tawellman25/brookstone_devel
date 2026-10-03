<?php

/**
 * Verify the core-description retirement against the brief's checklist.
 *
 * Renders pages as ANONYMOUS, because cim/save success proves nothing here —
 * display config on this project has shipped clean and broken at render time.
 */

$etm = \Drupal::entityTypeManager();
$am = \Drupal::service('path_alias.manager');
$base = getenv('BOS_BASE') ?: 'https://brookstoneoutdoors.com';
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %-60s %s\n", $good ? 'PASS' : 'FAIL', $what, $got);
};
$fetch = function (string $path) use ($base): string {
  $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => TRUE], 'ssl' => ['verify_peer' => FALSE, 'verify_peer_name' => FALSE]]);
  return (string) @file_get_contents($base . $path, FALSE, $ctx);
};

// 1. all 67 populated.
foreach (['plant_characteristics' => 41, 'growth_zone' => 26] as $vid => $expected) {
  $pop = $core = 0;
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
    if ($t->hasField('field_public_description') && !$t->get('field_public_description')->isEmpty()
      && trim(strip_tags((string) $t->get('field_public_description')->first()->getValue()['value'])) !== '') { $pop++; }
    if (!$t->get('description')->isEmpty() && trim(strip_tags((string) $t->get('description')->value)) !== '') { $core++; }
  }
  $ok("$vid: public description populated on all $expected", $pop === $expected, "$pop of $expected");
  $ok("$vid: core description now EMPTY on every term", $core === 0, "$core still populated");
}

// 2. material_types has no stray core description.
$stray = 0;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'material_types']) as $t) {
  if (!$t->get('description')->isEmpty() && trim(strip_tags((string) $t->get('description')->value)) !== '') { $stray++; }
}
$ok('material_types has no stray core description', $stray === 0, "$stray found");

// 3. core description is off every form and display for the two vocabularies.
$onDisplay = [];
foreach (['plant_characteristics', 'growth_zone'] as $vid) {
  foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $d) {
    if (strpos($id, 'taxonomy_term.' . $vid . '.') === 0 && $d->getComponent('description')) { $onDisplay[] = $id; }
  }
  $f = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $vid . '.default');
  if ($f && $f->getComponent('description')) { $onDisplay[] = $vid . ' FORM'; }
}
$ok('core description is off every display and form', !$onDisplay, $onDisplay ? implode(', ', $onDisplay) : 'clean');

// 4. no view still reads core description for these vocabularies.
$views = [];
foreach ($etm->getStorage('view')->loadMultiple() as $vid => $v) {
  $disp = $v->get('display');
  if (!is_array($disp)) { continue; }
  foreach ($disp as $did => $d) {
    $json = (string) json_encode($d['display_options'] ?? []);
    if (!preg_match('/plant_characteristic|growth_zone/', $json)) { continue; }
    foreach (array_keys($d['display_options']['fields'] ?? []) as $f) {
      if (strpos($f, 'description__value') === 0) { $views[] = "$vid/$did"; }
    }
  }
}
$ok('no view still sources core description for these vocabs', !$views, $views ? implode(', ', $views) : 'clean');

// 5. meta descriptions survive — the trap.
$noMeta = [];
$checked = 0;
foreach (['plant_characteristics', 'growth_zone'] as $vid) {
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
    $alias = $am->getAliasByPath('/taxonomy/term/' . $t->id());
    if ($alias === '/taxonomy/term/' . $t->id()) { continue; }
    $checked++;
    $html = $fetch($alias);
    if (!str_contains($html, 'name="description"')) { $noMeta[] = (string) $t->label(); }
  }
}
$ok("meta descriptions render on all $checked term pages", !$noMeta, $noMeta ? count($noMeta) . ' missing: ' . implode(', ', array_slice($noMeta, 0, 5)) : 'all present');

// 6. the Environmental Tolerance category page.
$cat = $fetch('/material/plants/characteristics/environmental-tolerance');
$ok('category page renders', strlen($cat) > 2000, strlen($cat) . ' chars');
$ok('it lists all nine characteristics', count(array_unique(preg_match_all('~characteristics/environmental-tolerance/[a-z0-9-]+~', $cat, $m) ? $m[0] : [])) >= 9);
$ok('Alkaline-Tolerant shows the NEW teaser', str_contains($cat, 'Handles soil above pH 7.5'));
$ok('the stale body text is gone from the list', !str_contains($cat, 'thrive in soils'));
$ok('it no longer says "shrubs" on a characteristic', stripos($cat, 'and shrubs thrive') === FALSE);

// 7. a leaf page keeps its body.
$leaf = $fetch('/material/plants/characteristics/environmental-tolerance/alkaline-tolerant');
$ok('the leaf page still shows its body copy', str_contains($leaf, 'thrive in soils'));
$ok('the leaf page has a meta description', str_contains($leaf, 'name="description"'));

// 8. growth_zone unchanged in appearance, teasers intact.
$land = $fetch('/material/plants/growth-zone');
$ok('growth-zone landing still shows zone bodies', str_contains($land, 'Zone 10a stays above'));
$teasers = 0;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'growth_zone']) as $t) {
  if (!$t->get('field_short_description')->isEmpty()) { $teasers++; }
}
$ok('growth_zone still has its 13 teasers', $teasers === 13, "$teasers");

// 9. the untouched vocabularies.
$mt = $pcc = 0;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'material_types']) as $t) {
  if (!$t->get('field_public_description')->isEmpty()) { $mt++; }
}
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'plant_character_categories']) as $t) {
  if (!$t->get('field_public_description')->isEmpty()) { $pcc++; }
}
$ok('material_types untouched (38 bodies)', $mt === 38, (string) $mt);
$ok('plant_character_categories untouched (8 bodies)', $pcc === 8, (string) $pcc);

// 10. coverage report count.
$cov = \Drupal::service('bos_content_coverage.coverage');
$flagged = count($cov->matchingTids('boilerplate'));
$ok('coverage report still answers', is_int($flagged), "$flagged boilerplate-flagged");

printf("\n%d passed, %d failed\n", $pass, $fail);
