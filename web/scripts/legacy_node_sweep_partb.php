<?php
/**
 * Legacy node sweep, Part B. Approved by Todd 2026-10-10.
 *
 * Three jobs, from the Part A report:
 *
 *  RESTRICT  85 (IT document with mail server settings), 55 (Teammate Manual),
 *            96 (crew snow-removal page). These stay PUBLISHED so crews keep
 *            them; a field plus hook_node_access keeps the public out.
 *
 *  REMOVE    75, 61, 62, 66. Unpublished, never deleted, each with a 301 to the
 *            page that now answers the question. 61/62/66 solicit business in
 *            the present tense under the old company name; 75 puts "Brookstone
 *            Outdoors" on a 2020 node and then credits building S&E Wards.
 *
 *  PUBLISH   The 13 orphaned 2020 care guides get real URLs under
 *            /new-landscape-care, with node 56 as the index it was written as.
 *
 * Dry run by default. BOS_SWEEP_APPLY=1 to write.
 */
$apply = getenv('BOS_SWEEP_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$aliasStorage = $etm->getStorage('path_alias');
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_SWEEP_APPLY=1 to apply)');

$RESTRICT = [85 => 'IT document: IMAP/SMTP server settings', 55 => 'Teammate Manual', 96 => 'crew snow-removal page'];
$REMOVE = [
  75 => ['/about-us', 'puts the Brookstone name on a 2020 page and credits building S&E Wards'],
  61 => ['/services/landscape-lawn-care/fertilizing', '"S & E Ward\'s Landscaping applies fertilizers … give us a call"'],
  62 => ['/services/landscape-lawn-care/spraying/weed-control', '"or hire S & E Ward\'s to assist you"'],
  66 => ['/services/landscape-lawn-care/fertilizing', '"When your sod was installed S & E Ward\'s applied fertilizer"'],
];
$PUBLISH = [
  56 => '/new-landscape-care',
  57 => '/new-landscape-care/your-new-lawns',
  58 => '/new-landscape-care/hydro-mulch',
  59 => '/new-landscape-care/watering-your-newly-seeded-lawn',
  60 => '/new-landscape-care/mowing-new-seeded-lawns',
  63 => '/new-landscape-care/watering-new-sod-lawns',
  64 => '/new-landscape-care/mowing-new-sod',
  65 => '/new-landscape-care/adjusting-sprinklers',
  67 => '/new-landscape-care/your-plants-and-trees',
  68 => '/new-landscape-care/your-irrigation-system',
  70 => '/new-landscape-care/sprinkler-system-maintenance',
  72 => '/new-landscape-care/spring-start-up',
  81 => '/new-landscape-care/winter-watering',
];

// --- 1. RESTRICT ----------------------------------------------------------
print "1. RESTRICT — stays published for crews, denied to the public\n";
foreach ($RESTRICT as $nid => $why) {
  $n = $etm->getStorage('node')->load($nid);
  if (!$n) { printf("   node/%-3d MISSING\n", $nid); continue; }
  $has = $n->hasField('field_internal_only');
  printf("   node/%-3d %-36s field:%s %s\n", $nid, substr($n->label(), 0, 36),
    $has ? 'yes' : 'NO (run the field setup first)', $why);
  if ($apply && $has) { $n->set('field_internal_only', TRUE); $n->save(); }
}

// --- 2. REMOVE ------------------------------------------------------------
print "\n2. REMOVE — unpublish (never delete) + a single 301\n";
foreach ($REMOVE as $nid => [$target, $why]) {
  $n = $etm->getStorage('node')->load($nid);
  if (!$n) { printf("   node/%-3d MISSING\n", $nid); continue; }
  if (!\Drupal::service('path.validator')->isValid($target)) {
    printf("   node/%-3d TARGET INVALID (%s) — SKIPPED\n", $nid, $target);
    continue;
  }
  $existing = \Drupal::entityTypeManager()->getStorage('redirect')->loadByProperties(['redirect_source__path' => 'node/' . $nid]);
  printf("   node/%-3d %-32s -> %-52s %s\n", $nid, substr($n->label(), 0, 32), $target,
    $existing ? '(redirect already there)' : '');
  if (!$apply) { continue; }
  if ($n->isPublished()) { $n->setUnpublished(); $n->save(); }
  if (!$existing) {
    \Drupal\redirect\Entity\Redirect::create([
      'redirect_source' => ['path' => 'node/' . $nid, 'query' => []],
      'redirect_redirect' => ['uri' => 'internal:' . $target],
      'language' => 'und',
      'status_code' => 301,
    ])->save();
  }
}

// --- 3. PUBLISH -----------------------------------------------------------
print "\n3. PUBLISH — the care guides get real URLs\n";
foreach ($PUBLISH as $nid => $alias) {
  $n = $etm->getStorage('node')->load($nid);
  if (!$n) { printf("   node/%-3d MISSING\n", $nid); continue; }
  $current = \Drupal::service('path_alias.repository')->lookupBySystemPath('/node/' . $nid, 'en');
  $clash = $aliasStorage->loadByProperties(['alias' => $alias]);
  $words = str_word_count(trim(preg_replace('/\s+/', ' ', strip_tags((string) $n->get('body')->value))));
  printf("   node/%-3d %-36s %4dw  %-50s %s\n", $nid, substr($n->label(), 0, 36), $words, $alias,
    $clash ? 'ALIAS IN USE — SKIPPED' : ($current ? 'has an alias already' : ''));
  if (!$apply || $clash || $current) { continue; }
  $aliasStorage->create(['path' => '/node/' . $nid, 'alias' => $alias, 'langcode' => 'en'])->save();
  if (!$n->isPublished()) { $n->setPublished(); $n->save(); }
}

print "\ndone" . ($apply ? '' : ' (dry run — nothing written)') . "\n";
