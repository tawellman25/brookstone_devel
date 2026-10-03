<?php

/**
 * The sixteen root material category descriptions (marketing, written 2026-09-26).
 *
 * Writes field_public_description — the BODY of the category page — on the
 * sixteen root material_types terms. Replaces the generic "Brookstone Outdoors
 * carries a complete line of…" copy that had been live since the catalogue was
 * built.
 *
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 * -------------------------------------
 *  - field_short_description. The card teasers were pasted 2026-10-03 and are
 *    correct; this script never writes that field, and the verifier proves each
 *    one is byte-identical afterwards.
 *  - Trees, Shrubs, Plants and Annuals. The source document predates the Plants
 *    reparent and describes them as root categories, which they no longer are.
 *    They are simply absent from the map below, and the verifier confirms their
 *    bodies are untouched.
 *
 * Terms are resolved by URL ALIAS and then checked against the expected LIVE
 * name — the source document says "Block and Pavers" where the live term is
 * "Blocks and Pavers", so the expected names here are the live ones. A mismatch
 * is reported and skipped, never guessed at.
 *
 * Format is read from the stored value and preserved (all sixteen are
 * full_html), so the markup renders as paragraphs rather than escaped text.
 *
 * Usage:
 *   drush php:script web/scripts/seed_root_category_descriptions.php          # dry run
 *   BOS_DESC_APPLY=1 drush php:script web/scripts/seed_root_category_descriptions.php
 */

$apply = getenv('BOS_DESC_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$am = \Drupal::service('path_alias.manager');

$irrigation = <<<'HTML'
<p>Valves, heads, rotors, controllers, drip line, emitters, filters and everything that connects them. The largest category we stock, because irrigation is the department that runs the longest season.</p>

<p>Head and nozzle selection here is driven by water source as much as by coverage. A property on municipal supply and a property on ditch water get different equipment, and putting standard residential drip on raw ditch water is how a system fails in its first August.</p>
HTML;

$pvc = <<<'HTML'
<p>Mainline and lateral pipe, fittings, valves and solvent weld supplies in the sizes we actually run.</p>

<p>PVC is the backbone of most irrigation mainlines here. It is rigid, it takes pressure, and it does not tolerate being installed shallow in ground that freezes as hard as this does. Depth is not a detail on a PVC main — it is the whole difference between a system that lasts twenty years and one that splits the first hard winter after a dry fall.</p>
HTML;

$poly = <<<'HTML'
<p>Flexible poly pipe, insert fittings and clamps.</p>

<p>Poly goes where rigid pipe is the wrong answer — laterals that need to move, ground that shifts, trenches that curve, and repairs where cutting a straight run is not practical. It also handles a freeze cycle better than PVC does, which matters on the lines most likely to hold water.</p>
HTML;

$brass = <<<'HTML'
<p>Valves, nipples, unions, adapters and fittings in brass.</p>

<p>Brass shows up where something has to last under pressure and be serviceable later — backflow assemblies, shutoffs, and any connection that will be taken apart again someday. It is more expensive than the alternatives and it is worth it in exactly the places where replacing a failed fitting means digging.</p>
HTML;

$copper = <<<'HTML'
<p>Copper pipe and fittings.</p>

<p>Less common in landscape work than in plumbing, but it turns up on service connections and in mechanical rooms where we are working on the building side of an irrigation or backflow installation. Hard water is rough on copper over time, which is worth knowing on an older property.</p>
HTML;

$galv = <<<'HTML'
<p>Galvanized pipe, nipples and fittings.</p>

<p>Mostly encountered rather than installed — a great deal of older irrigation and stock water on this side of the valley was built in galvanized, and it is still in the ground on properties that were orchards before they were yards. We stock it to repair what is there without re-plumbing a whole run.</p>
HTML;

$pumps = <<<'HTML'
<p>Booster pumps, irrigation pumps, and the fittings and controls that go with them.</p>

<p>A pump means two things on a property: pressure where the supply does not provide it, and a backflow classification that changed the moment the pump went in. Any system drawing off a ditch, a pond or a cistern needs one, and most of the ag and acreage properties out here are on exactly that.</p>
HTML;

$backflow = <<<'HTML'
<p>Backflow prevention assemblies and the repair kits that keep them in service.</p>

<p>Colorado requires every testable assembly to be tested once a year by a certified technician. We stock the common makes and the rebuild kits for them, because an assembly that fails a test starts a sixty-day clock — and a rebuild done on the spot beats a second trip and a customer on a deadline. <a href="/services/backflow-prevention">How backflow prevention works and what the state requires</a>.</p>
HTML;

$electric = <<<'HTML'
<p>Wire, connectors, valve boxes, splices, transformers and low-voltage components.</p>

<p>Most of what fails on an irrigation controller is not the controller. It is a splice that was made with the wrong connector and has been sitting in a wet valve box for six years. We stock waterproof connectors and use them, which is a small cost at install and the difference between a diagnosable system and a scavenger hunt.</p>
HTML;

$pavers = <<<'HTML'
<p>Pavers, retaining wall block, caps, edge restraint and the base materials underneath them.</p>

<p>Hardscape here lives or dies on what is under it. Freeze and thaw will move anything set on inadequate base, and our ground gives a real freeze every year. Most of the failed patios we are asked to fix were not built with the wrong paver — they were built on four inches of whatever was handy.</p>
HTML;

$rock = <<<'HTML'
<p>Decorative rock, cobble, boulders and river rock, by the yard and by the ton.</p>

<p>Rock is the most-used ground cover on the Western Slope for good reasons — it does not blow out, it does not need replacing every spring, and it does not need water. It also holds heat, which is worth thinking about before it goes against a south wall or around something that would rather not be baked.</p>
HTML;

$mulch = <<<'HTML'
<p>Bark, wood mulch and shredded products.</p>

<p>Mulch does more work in this climate than it gets credit for. It holds soil moisture through a dry July, moderates the temperature swings that come with high desert days and cold nights, and buys a new planting a real advantage in its first two seasons. It also breaks down and needs topping up, which is a maintenance line rather than a one-time install.</p>
HTML;

$sod = <<<'HTML'
<p>Turf sod by the roll and by the pallet.</p>

<p>Sod gives a finished lawn in a day and a real irrigation obligation for the six weeks after. That trade is worth making on a short season — seeding in June here is a gamble against heat, and seeding in September is a gamble against frost. Sod removes the gamble and moves the work to the watering schedule.</p>
HTML;

$landscape = <<<'HTML'
<p>Fabric, edging, stakes, drainage components, erosion control and the structural pieces that hold a landscape together.</p>

<p>These are the parts nobody notices unless they were skipped. Edging is what keeps rock out of the lawn. Fabric is what decides whether a rock bed is still a rock bed in five years. Drainage is what decides whether the ground at the downspout is planting bed or swamp.</p>
HTML;

$xmas = <<<'HTML'
<p>Commercial-grade LED light strings, clips, timers, cords and the components behind an installed display.</p>

<p>This is not what is on a big box shelf in November. Commercial strings are built to be put up, taken down and stored for a decade, which is the only way a lighting program makes economic sense. We own the lights on our programs, we install them, we take them down and we store them, and the same set goes back up next year. <a href="/services/christmas-decorations">Holiday lighting</a>.</p>
HTML;

$misc = <<<'HTML'
<p>Everything that does not fit a category cleanly — one-off parts, specialty items and materials sourced for a specific job.</p>

<p>Some of what is here is genuinely one-of-a-kind: a part matched to equipment nobody makes anymore, or something specified by a designer for one property. If you are looking for something and cannot find its category, it may be in here.</p>
HTML;

// alias => [expected LIVE term name, body]
$map = [
  '/material/irrigation'      => ['Irrigation', $irrigation],
  '/material/pvc'             => ['PVC (Polyvinyl Chloride) - Pipes and Fittings', $pvc],
  '/material/poly'            => ['Poly (Polyethylene) Pipe and Fittings', $poly],
  '/material/brass'           => ['Brass', $brass],
  '/material/copper'          => ['Copper', $copper],
  '/material/galvanized'      => ['Galvanized Pipe and Fittings', $galv],
  '/material/pumps'           => ['Pumps', $pumps],
  '/material/backflow'        => ['Backflow', $backflow],
  '/material/electric'        => ['Electric', $electric],
  '/material/pavers'          => ['Blocks and Pavers', $pavers],
  '/material/decorative_rock' => ['Rock', $rock],
  '/material/mulch'           => ['Mulch', $mulch],
  '/material/sod'             => ['Sod', $sod],
  '/material/landscape'       => ['Landscape Materials', $landscape],
  '/material/xmas'            => ['Christmas Lights', $xmas],
  '/material/misc'            => ['Miscellaneous Materials', $misc],
];

printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN');
$set = $same = $problems = 0;
$backup = [];

foreach ($map as $alias => [$expected, $body]) {
  $internal = $am->getPathByAlias($alias);
  if (!preg_match('#^/taxonomy/term/(\d+)$#', $internal, $m)) {
    printf("  %-28s ** alias resolves to %s, not a term — SKIPPED **\n", $alias, $internal);
    $problems++;
    continue;
  }
  $term = $etm->getStorage('taxonomy_term')->load($m[1]);
  if (!$term || $term->bundle() !== 'material_types') {
    printf("  %-28s ** not a material_types term — SKIPPED **\n", $alias);
    $problems++;
    continue;
  }
  if ((string) $term->label() !== $expected) {
    printf("  %-28s ** live name is %s, copy written for %s — SKIPPED **\n", $alias, var_export((string) $term->label(), TRUE), var_export($expected, TRUE));
    $problems++;
    continue;
  }

  $item = $term->get('field_public_description');
  $cur = $item->isEmpty() ? '' : (string) $item->first()->getValue()['value'];
  // Preserve the stored format so <p> and <a href> render rather than escape.
  $format = $item->isEmpty() ? 'full_html' : ($item->first()->getValue()['format'] ?? 'full_html');
  // Record the teaser so the verifier can prove it was never touched.
  $teaser = $term->get('field_short_description')->isEmpty() ? '' : (string) $term->get('field_short_description')->first()->getValue()['value'];

  if (trim($cur) === trim($body)) {
    printf("  %-28s %-30s already current\n", $alias, (string) $term->label());
    $same++;
    continue;
  }
  printf("  %-28s %-30s %d -> %d chars [%s]\n", $alias, mb_substr((string) $term->label(), 0, 29), strlen($cur), strlen($body), $format);
  $set++;
  $backup[] = [
    'tid' => $term->id(), 'name' => (string) $term->label(),
    'field' => 'field_public_description', 'format' => $format, 'value' => $cur,
    'teaser_untouched' => $teaser,
  ];
  if ($apply) {
    // ONLY the body. field_short_description is never assigned here.
    $term->set('field_public_description', ['value' => $body, 'format' => $format]);
    $term->save();
  }
}

if ($backup) {
  $f = sys_get_temp_dir() . '/root_descriptions_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("\nprevious bodies + teaser snapshot saved to: %s\n", $f);
}
printf("\n%d written, %d already current, %d problem(s)\n", $set, $same, $problems);
if (!$apply) {
  print "\nNothing written. Re-run with BOS_DESC_APPLY=1 to apply.\n";
}
