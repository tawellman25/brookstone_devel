<?php
declare(strict_types=1);
/**
 * Verify the plant_characteristics copy load by RENDERING, as anonymous.
 * Read-only. Safe on live.
 */
use Drupal\Core\Session\UserSession;
use Drupal\Core\Render\RenderContext;

$etm = \Drupal::entityTypeManager();
$acct = \Drupal::service('account_switcher');
$renderer = \Drupal::service('renderer');
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) {
  print ($c ? "  ✓ " : "  ✗ FAIL ") . $m . "\n"; $c ? $pass++ : $fail++;
};

// 1. Every one of the 9 category pages: how many rows carry card text?
print "CATEGORY PAGES — rows listed vs rows with card text (as anon)\n";
$acct->switchTo(new UserSession(['uid' => 0, 'roles' => ['anonymous']]));
$views = \Drupal::entityQuery('view')->accessCheck(FALSE)->execute();
$bare_total = 0;
foreach ($views as $vid) {
  if (strpos($vid, 'char_cat_') !== 0) { continue; }
  $v = \Drupal\views\Views::getView($vid);
  if (!$v) { continue; }
  $v->setDisplay('page_1'); $v->execute();
  $with = 0; $bare = [];
  foreach ($v->result as $r) {
    $t = $r->_entity ?? NULL; if (!$t) { continue; }
    trim(strip_tags((string) ($t->get('field_short_description')->value ?? ''))) === ''
      ? $bare[] = $t->label() : $with++;
  }
  $n = count($v->result);
  printf("  %-38s %2d rows, %2d with text%s\n", str_replace('char_cat_', '', $vid), $n, $with,
    $bare ? '  BARE: ' . implode(', ', $bare) : '');
  $bare_total += count($bare);
}
$acct->switchBack();
$ok($bare_total === 8, "exactly 8 rows still bare — the Environmental Tolerance batch that was never supplied (got $bare_total)");

// 2. A term page as ANON: public body renders as HTML, crew copy absent.
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', 'plant_characteristics')->condition('name', 'Rabbit-Resistant')->execute();
$term = $etm->getStorage('taxonomy_term')->load(reset($tids));
$acct->switchTo(new UserSession(['uid' => 0, 'roles' => ['anonymous']]));
$build = $etm->getViewBuilder('taxonomy_term')->view($term, 'full');
$html = (string) $renderer->executeInRenderContext(new RenderContext(), fn() => $renderer->render($build));
$acct->switchBack();
print "\nTERM PAGE (Rabbit-Resistant, as anon)\n";
$ok(str_contains($html, 'girdle') || str_contains($html, 'strip bark'), 'public body renders');
$ok(str_contains($html, '<p>') && str_contains($html, '<strong>'), 'HTML renders as markup, not escaped');
$ok(!str_contains($html, '&lt;p&gt;'), 'no escaped tags');
$ok(!str_contains($html, 'Trunk guards on every young'), 'CREW copy absent for anon');
$ok(!str_contains($html, 'What to tell a customer'), 'no crew phrasing leaked');
$ok(!str_contains($html, '**'), 'no markdown asterisks rendered');

// 3. Office sees the crew copy.
$office = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'administration')->range(0,1)->execute();
if ($office) {
  $acct->switchTo($etm->getStorage('user')->load(reset($office)));
  $b2 = $etm->getViewBuilder('taxonomy_term')->view($term, 'full');
  $h2 = (string) $renderer->executeInRenderContext(new RenderContext(), fn() => $renderer->render($b2));
  $acct->switchBack();
  $ok(str_contains($h2, 'Trunk guards on every young'), 'office DOES see crew copy');
}

// 4. Metatags.
$raw = (string) ($term->get('field_meta_tags')->value ?? '');
$tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
print "\nMETATAGS\n";
$ok(($tags['title'] ?? '') === 'Rabbit-Resistant Plants | Brookstone Outdoors', 'authored title stored');
$ok(str_contains($tags['description'] ?? '', 'snow line'), 'authored description stored');
$ok(($tags['og_description'] ?? '') === ($tags['description'] ?? ''), 'og_description mirrors description');

// 5. Every term: description length inside Google's window.
$all = $etm->getStorage('taxonomy_term')->loadMultiple(
  \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid','plant_characteristics')->execute());
$long = [];
foreach ($all as $t) {
  $r = (string) ($t->get('field_meta_tags')->value ?? '');
  $g = $r !== '' ? (json_decode($r, TRUE) ?: []) : [];
  $d = $g['description'] ?? '';
  if ($d !== '' && mb_strlen($d) > 160) { $long[] = $t->label() . ' (' . mb_strlen($d) . ')'; }
}
print "\nALL TERMS\n";
$ok(!$long, 'no meta description over 160 chars' . ($long ? ': ' . implode(', ', $long) : ''));
$noBody = [];
foreach ($all as $t) {
  if (trim(strip_tags((string) ($t->get('field_public_description')->value ?? ''))) === '') { $noBody[] = $t->label(); }
}
$ok(!$noBody, 'every term still has a public body' . ($noBody ? ': ' . implode(', ', $noBody) : ''));

printf("\n%d passed, %d failed\n", $pass, $fail);
