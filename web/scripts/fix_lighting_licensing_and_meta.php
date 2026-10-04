<?php

declare(strict_types=1);

/**
 * Lighting: the licensing paragraph, and the three meta descriptions.
 *
 * 1. REPLACE a paragraph I shipped that is now wrong. It divided the job by
 *    LOCATION ("building-mounted lighting is wired into the house"), and Todd's
 *    answer divides it by VOLTAGE - Brookstone does the low-voltage work
 *    including on buildings, and refers out only where code requires a licensed
 *    electrician. Dropping a sentence beside the old one would have left a
 *    contradiction on a live page.
 *
 * 2. SET per-term meta descriptions, then CLEAR the core description they were
 *    coming from. THE ORDER IS LOAD-BEARING: the taxonomy metatag default is
 *    `[term:description]` and the global default defines NO description, so
 *    clearing first would strip the meta description outright. The script
 *    verifies each override renders before it clears anything, and aborts if
 *    any one does not.
 *
 * Titles are NOT touched and marketing does not need to supply them: the
 * taxonomy title default is `[term:name] | Brookstone Outdoors | ...`, so it
 * never read the description and the three titles were never duplicated.
 *
 *   drush php:script web/scripts/fix_lighting_licensing_and_meta.php
 *   BOS_FIX_APPLY=1 drush php:script web/scripts/fix_lighting_licensing_and_meta.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_FIX_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

$OLD_PARA = '<p>Building-mounted lighting is wired into the house, so it is a different kind of job from low-voltage work in the yard, and it is worth getting right the first time — fixtures on a building are not something anybody wants to relocate.</p>';

$NEW_PARA = <<<'HTML'
<p>Where the line falls on a job like this is voltage, not position on the building. A great deal of building-mounted lighting runs at low voltage off the same kind of transformer as the fixtures in the yard — soffit washes, step lights, entry and sconce fixtures — and that work we do ourselves, start to finish. Where a fixture has to be wired into the house at line voltage and code calls for a licensed electrician, we refer you to one. We are not an electrical contractor and we do not hold an electrical license; what we do carry is listed on our <a href="/about-us/credentials">credentials page</a>.</p>
HTML;

$META = [
  'Lighting' => 'A yard with no light is finished at dusk. Landscape lighting out in the planting, exterior lighting on the building. Delta and Montrose counties.',
  'Exterior Lighting' => 'Fixtures on the building: entries, soffit, garage, security. Why one bright flood makes a property harder to see after dark, and what works instead.',
  'Landscape Lighting' => 'Low-voltage fixtures out in the yard, aiming back at the house. Uplighting, grazing, path and downlighting, and why you see the light and not the fixture.',
];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_FIX_APPLY=1 to write)\n\n";

// Guards.
foreach ($META as $n => $d) {
  if (mb_strlen($d) > 158) { printf("ABORT — %s description is %d chars.\n", $n, mb_strlen($d)); return; }
}
printf("✓ all three descriptions within 158 chars (%s)\n",
  implode(', ', array_map(fn($n, $d) => $n . ' ' . mb_strlen($d), array_keys($META), $META)));
if (count(array_unique($META)) !== count($META)) { print "ABORT — two descriptions are identical.\n"; return; }
print "✓ all three are distinct\n";
// An ALIAS lookup is not a reachability check. /about-us/credentials is a
// Views page PATH, so there is no alias row for it and getPathByAlias()
// correctly returns the input unchanged — which an alias-only guard misreads as
// unreachable. It is HTTP 200 on both environments. Ask the path validator,
// which knows about routes as well as aliases.
if (!\Drupal::service('path.validator')->isValid('/about-us/credentials')) {
  print "ABORT — /about-us/credentials does not resolve; the paragraph links to it.\n"; return;
}
print "✓ /about-us/credentials resolves (route or alias)\n";

$byName = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple(\Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)->condition('vid', 'services')->execute()) as $t) {
  $byName[$t->label()] = $t;
}
foreach (array_keys($META) as $n) {
  if (!isset($byName[$n])) { print "ABORT — term not found: $n\n"; return; }
}

// ---- 1. The paragraph.
$ext = $byName['Exterior Lighting'];
$body = (string) ($ext->get('field_service_public_desc')->value ?? '');
if (str_contains($body, $OLD_PARA)) {
  print "\n+ Exterior Lighting: replacing the location-based paragraph with the voltage-based one\n";
  if ($apply) {
    $ext->set('field_service_public_desc', ['value' => str_replace($OLD_PARA, $NEW_PARA, $body), 'format' => 'full_html'])->save();
    Cache::invalidateTags(['taxonomy_term:' . $ext->id()]);
  }
}
elseif (str_contains($body, 'we do not hold an electrical license')) {
  print "\n✓ Exterior Lighting: replacement paragraph already present\n";
}
else { print "\n⚠ Exterior Lighting: neither the old paragraph nor the new one found — wording changed; NOT guessing.\n"; }

// ---- 2. Overrides FIRST.
print "\nMETA OVERRIDES (set before anything is cleared)\n";
foreach ($META as $name => $desc) {
  $t = $byName[$name];
  $raw = (string) ($t->get('field_meta_tags')->value ?? '');
  $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
  $want = ['description' => $desc, 'og_description' => $desc];
  if (array_intersect_key($tags, $want) == $want) { printf("  %-20s already set\n", $name); continue; }
  printf("  %-20s set (%d ch)\n", $name, mb_strlen($desc));
  if ($apply) {
    $t->set('field_meta_tags', json_encode(array_merge($tags, $want), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->save();
    Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
}

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

drupal_flush_all_caches();

// ---- 3. Gate: every override must actually RENDER before the source is cleared.
print "\nGATE — each override must render before core description is cleared\n";
$ok = TRUE;
foreach ($META as $name => $desc) {
  $t = $byName[$name];
  $tags = \Drupal::service('metatag.manager')->tagsFromEntityWithDefaults($t);
  $got = (string) ($tags['description'] ?? '');
  $hit = $got === $desc;
  printf("  %-20s %s\n", $name, $hit ? '✓ renders the new description' : '✗ renders: "' . mb_substr($got, 0, 46) . '…"');
  $ok = $ok && $hit;
}
if (!$ok) {
  print "\nABORT — not clearing core description while an override does not render.\n";
  print "The overrides are saved and are an improvement on their own; nothing is lost.\n";
  return;
}

// ---- 4. Now the duplicate source can go.
print "\nCLEARING the now-redundant core description\n";
$backup = [];
foreach (array_keys($META) as $name) {
  $t = $byName[$name];
  $old = (string) ($t->get('description')->value ?? '');
  if (trim($old) === '') { printf("  %-20s already empty\n", $name); continue; }
  $backup[$t->id()] = ['name' => $name, 'description' => $old];
  printf("  %-20s cleared (%d ch)\n", $name, mb_strlen($old));
  $t->set('description', ['value' => '', 'format' => $t->get('description')->format])->save();
  Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
}
if ($backup) {
  $f = '/tmp/lighting_core_desc_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "  backed up to $f\n";
}
drupal_flush_all_caches();

// ---- 5. Re-verify after clearing.
print "\nAFTER CLEARING\n";
foreach ($META as $name => $desc) {
  $tags = \Drupal::service('metatag.manager')->tagsFromEntityWithDefaults($byName[$name]);
  printf("  %-20s %s\n", $name, ($tags['description'] ?? '') === $desc ? '✓ still renders' : '✗ LOST');
}
