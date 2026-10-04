<?php
$pass = $fail = 0;
$ok = function (string $what, bool $cond, string $extra = '') use (&$pass, &$fail) {
  $cond ? $pass++ : $fail++;
  printf("  [%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $what, $extra ? " ($extra)" : '');
};
$bm = \Drupal::service('plugin.manager.block');
$etm = \Drupal::entityTypeManager();
$defs = $bm->getDefinitions();

// 1. every LB-enabled bundle kept its field_blocks
print "1. kept definitions cover every LB-enabled bundle\n";
$lb = [];
foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $d) {
  if (!empty($d->getThirdPartySettings('layout_builder')['enabled'])) {
    [$et, $b] = explode('.', $id); $lb["$et.$b"] = TRUE;
  }
}
$missing = [];
foreach (array_keys($lb) as $pair) {
  [$et, $b] = explode('.', $pair);
  $has = FALSE;
  foreach (array_keys($defs) as $pid) { if (strpos($pid, "field_block:$et:$b:") === 0) { $has = TRUE; break; } }
  if (!$has) { $missing[] = $pair; }
}
$ok('all ' . count($lb) . ' LB bundles still have field_blocks', !$missing, $missing ? implode(', ', array_slice($missing, 0, 5)) : '');

// 2. a pruned bundle really is gone
print "2. an unreachable bundle is pruned\n";
$pruned = 0;
foreach (array_keys($defs) as $pid) {
  if (strpos($pid, 'field_block:') !== 0) continue;
  $p = explode(':', $pid, 4);
  if (!isset($lb[$p[1] . '.' . $p[2]])) $pruned++;
}
$ok('zero field_blocks for bundles without an LB display', $pruned === 0, "found $pruned");

// 3. a kept field_block can still be instantiated (the UI needs this)
print "3. a kept field_block instantiates\n";
$sample = NULL;
foreach (array_keys($defs) as $pid) { if (strpos($pid, 'field_block:wo_tasks_list:lawn_mowing:') === 0) { $sample = $pid; break; } }
if ($sample) {
  try { $bm->createInstance($sample); $ok("createInstance($sample)", TRUE); }
  catch (\Throwable $e) { $ok("createInstance($sample)", FALSE, $e->getMessage()); }
}
else { $ok('found a lawn_mowing field_block to instantiate', FALSE); }

// 4. LB-rendered displays still produce output
print "4. LB-enabled displays still render\n";
foreach ([['city', 'city'], ['wo_tasks_list', 'lawn_mowing']] as [$et, $bundle]) {
  $ids = $etm->getStorage($et)->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->range(0, 1)->execute();
  if (!$ids) { print "  [skip] no $et.$bundle records\n"; continue; }
  $e = $etm->getStorage($et)->load(reset($ids));
  $vm = $et === 'wo_tasks_list' ? 'wo_display' : 'default';
  // Assert the render COMPLETES, not that it is large. A record whose fields
  // are empty legitimately renders almost nothing — wo_tasks_list.lawn_mowing
  // does exactly that, identically (same md5) with this module enabled and
  // disabled, so a size threshold here tests the data, not the pruning.
  // Coverage of the definitions themselves is tests 1 and 2.
  try {
    $html = (string) \Drupal::service('renderer')->renderInIsolation($etm->getViewBuilder($et)->view($e, $vm));
    $ok("$et.$bundle renders in '$vm' without error", TRUE, strlen($html) . ' bytes');
  }
  catch (\Throwable $err) {
    $ok("$et.$bundle renders in '$vm' without error", FALSE, $err->getMessage());
  }
}

// 5. inline blocks survive
print "5. inline blocks\n";
$ok('inline_block:basic still defined', isset($defs['inline_block:basic']));
$n = (int) \Drupal::database()->select('block_content_field_data', 'b')->condition('reusable', 0)->countQuery()->execute()->fetchField();
$ok('the 20 inline block_content rows are untouched', $n === 20, "found $n");

printf("\n%d passed, %d failed\n", $pass, $fail);
