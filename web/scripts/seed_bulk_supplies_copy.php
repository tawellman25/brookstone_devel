<?php

declare(strict_types=1);

/**
 * Copy for the two categories made public on 3 October.
 *
 * Bodies from marketing's held file, card lines newly written (they were never
 * written, because these two terms did not exist when the other 23 were done).
 *
 * The Rock cross-link paragraph on Bulk Material is NEW and marketing flagged it
 * as droppable. Kept: the page lists sand and gravel while a different category
 * is called Rock, which is a confusion a visitor will actually have. The link is
 * verified to resolve before anything is written.
 *
 * CTA on Bulk Material only. Supplies deliberately has none - it is crew
 * consumables, and a page ending by asking somebody to call about solvent and
 * blades reads like nobody read it.
 *
 *   drush php:script web/scripts/seed_bulk_supplies_copy.php
 *   BOS_BS_APPLY=1 drush php:script web/scripts/seed_bulk_supplies_copy.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_BS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

$COPY = [
  'bulk_material' => [
    'short' => 'Topsoil, compost, sand, gravel and soil amendments by the yard. The non-decorative bulk goods, and where most soil problems here actually get solved.',
    'body' => <<<'HTML'
<p>Topsoil, fill dirt, compost, lime, gypsum, sulfur, sand, gravel and soil amendments — the non-decorative bulk goods.</p>

<p>This is where most soil problems actually get solved. Our ground runs alkaline and often heavy, and the amendment that fixes one property is the wrong one for the next. Sulfur and gypsum are not interchangeable, and neither is a substitute for compost in ground that has no organic matter left in it.</p>

<p>Material chosen to be looked at rather than dug into — decorative rock, cobble, boulders — is in <a href="/material/decorative_rock">Rock</a>. What is here is the material that goes under things and into things.</p>
HTML,
    'cta' => <<<'HTML'
<p>Bulk material is sold by the yard and the right amendment depends on what the ground is already doing. Tell us the job and we will tell you what it needs and how much of it. <a href="/request-estimate">Get in touch</a>.</p>
HTML,
  ],
  'supplies' => [
    'short' => 'Solvent, primer, sealant, fasteners, marking paint, blades and the consumables a crew goes through. Unglamorous, and the reason jobs finish on schedule.',
    'body' => <<<'HTML'
<p>Consumables and the shop side of the job — solvent and primer, sealant, tape, fasteners, marking paint, blades and the things a crew goes through.</p>

<p>Unglamorous and the reason jobs finish on schedule. A crew that runs out of primer on a Friday afternoon in Crawford is done for the day, and that afternoon costs more than a case of it.</p>
HTML,
    // Deliberately none. See the docblock.
    'cta' => NULL,
  ],
];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_BS_APPLY=1 to write)\n\n";

// Guards.
foreach ($COPY as $b => $d) {
  foreach (['body', 'cta'] as $k) {
    if ($d[$k] === NULL) { continue; }
    if (strpos($d[$k], '**') !== FALSE) { print "ABORT — markdown ** in $b/$k\n"; return; }
    if (preg_match_all('~href="(/[^"]+)"~', $d[$k], $m)) {
      foreach ($m[1] as $href) {
        if (!\Drupal::service('path.validator')->isValid($href)) { print "ABORT — $href does not resolve (in $b/$k)\n"; return; }
        printf("✓ link %-30s (in %s/%s)\n", $href, $b, $k);
      }
    }
  }
  printf("  %-16s card line %d ch\n", $b, mb_strlen($d['short']));
}

// Snapshot EVERY root category's card line, so "no other teaser was touched"
// is proven rather than asserted.
$before = [];
foreach ($db->query("SELECT entity_id, field_material_bundle_value AS b FROM {taxonomy_term__field_material_bundle}") as $r) {
  $t = $etm->getStorage('taxonomy_term')->load($r->entity_id);
  if (!$t) { continue; }
  $before[$r->b] = (string) ($t->get('field_short_description')->value ?? '');
}

$changed = 0;
foreach ($COPY as $bundle => $d) {
  $tid = $db->query("SELECT entity_id FROM {taxonomy_term__field_material_bundle} WHERE field_material_bundle_value=:b",
    [':b' => $bundle])->fetchField();
  if (!$tid) { print "ABORT — no category term for $bundle.\n"; return; }
  $t = $etm->getStorage('taxonomy_term')->load($tid);
  $deltas = [];
  $set = function (string $f, ?string $v) use ($t, &$deltas) {
    if ($v === NULL || !$t->hasField($f)) { return; }
    $old = (string) ($t->get($f)->value ?? '');
    if (trim($old) === trim($v)) { return; }
    $t->set($f, ['value' => $v, 'format' => 'full_html']);
    $deltas[] = $f . ($old === '' ? ' (was empty)' : sprintf(' (%d→%d)', mb_strlen($old), mb_strlen($v)));
  };
  $set('field_short_description', $d['short']);
  $set('field_public_description', $d['body']);
  $set('field_call_to_action', $d['cta']);
  if (!$deltas) { printf("\n  %-16s unchanged\n", $bundle); continue; }
  printf("\n  %-16s %s\n", $bundle, implode(', ', $deltas));
  $changed++;
  if ($apply) { $t->save(); Cache::invalidateTags(['taxonomy_term:' . $tid]); }
}

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }
drupal_flush_all_caches();

// Prove nothing else moved.
$moved = [];
foreach ($db->query("SELECT entity_id, field_material_bundle_value AS b FROM {taxonomy_term__field_material_bundle}") as $r) {
  $t = $etm->getStorage('taxonomy_term')->load($r->entity_id);
  if (!$t) { continue; }
  $now = (string) ($t->get('field_short_description')->value ?? '');
  if (isset($COPY[$r->b])) { continue; }
  if (($before[$r->b] ?? '') !== $now) { $moved[] = $r->b; }
}
printf("\n%d changed. Other categories' card lines moved: %s\n", $changed, $moved ? implode(', ', $moved) : 'NONE');
// And the rule: the card line must not be on any term view display.
$on = [];
foreach (['default', 'full', 'teammate_view', 'admin_view', 'client_view'] as $m) {
  $disp = $etm->getStorage('entity_view_display')->load("taxonomy_term.material_types.$m");
  if ($disp && $disp->getComponent('field_short_description')) { $on[] = $m; }
}
printf("Card line on a material_types view display: %s\n", $on ? 'YES — ' . implode(', ', $on) : 'no (correct)');
