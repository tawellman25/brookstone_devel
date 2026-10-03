<?php

declare(strict_types=1);

/**
 * Split the two Lighting child pages so they stop competing.
 *
 * Todd's decision, 3 October: SPLIT, do not merge. The axis is WHERE THE
 * FIXTURE SITS - exterior is mounted on the building and aims outward, landscape
 * stands out in the yard and aims back. Both can light the same wall and the
 * results look nothing alike.
 *
 * Before this, both pages opened on "beauty, safety, and functionality", both
 * said "designs, installs, and maintains", and the EXTERIOR page offered
 * "installing new landscape lighting" - it was selling the other page's service.
 *
 * ⚠ The electrical-licensing sentence is DELIBERATELY ABSENT. Building-mounted
 * fixtures are usually line voltage, which normally means a licensed electrician
 * and sometimes a permit. Marketing held that sentence out of the paste block
 * rather than write a licensing claim nobody had verified, and this script does
 * not invent one. It is Todd's answer to supply.
 *
 *   drush php:script web/scripts/seed_lighting_split_copy.php
 *   BOS_LIGHT_APPLY=1 drush php:script web/scripts/seed_lighting_split_copy.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_LIGHT_APPLY') === '1';
$FIELD = 'field_service_public_desc';
$TERMS = [];

$TERMS['Exterior Lighting'] = <<<'HTML'
<p>Exterior lighting is the lighting mounted on the building — soffit and eave fixtures, entry and garage lights, wall sconces, floods and security lighting. It points outward and down, away from the house, and its job is practical: getting to the door, finding the lock, seeing the step, backing out of the garage, being able to tell who is standing on the porch.</p>

<p><strong>The single most common mistake is one bright fixture.</strong> A floodlight on a corner feels like the obvious answer and it makes the property harder to see, not easier. Your eye adjusts to the brightest thing in view, so everything beyond the lit circle goes black — and the step you were worried about is now in the part your eye has given up on. A house with one flood on it is often less navigable after dark than a house with four modest fixtures spread along it.</p>

<p>So the approach is more fixtures at lower output, shielded so the light goes down rather than sideways, warm rather than blue. That reads as a lit house instead of a lit parking lot, and it is also what keeps light off the neighbor's bedroom window and out of the sky — which on this side of the divide people notice, because the stars here are one of the reasons to live here.</p>

<p>A few things worth deciding together rather than one at a time. Which lights come on with a switch inside, which run on a photocell from dusk, and which come on only when something moves — the mix is what decides whether the system actually gets used. Where the address numbers are and whether they can be read from the road, which matters on a night somebody is looking for your house in a hurry. And whether the garage, the back door and the side gate are covered, because those are the three that get left out and then get added badly later.</p>

<p>Building-mounted lighting is wired into the house, so it is a different kind of job from low-voltage work in the yard, and it is worth getting right the first time — fixtures on a building are not something anybody wants to relocate.</p>

<p><strong>What this will not do is make the house look like anything.</strong> Light coming off a wall flattens that wall — there is no shadow, no depth, nothing picked out. If what you want is the property to look like something after dark, that comes from fixtures standing out in the landscape aiming back at it, which is <a href="/services/lighting/landscape-lighting">landscape lighting</a>. Most properties want some of each, and they are not substitutes for one another.</p>
HTML;

$TERMS['Landscape Lighting'] = <<<'HTML'
<p>Landscape lighting is the lighting that stands out in the yard and aims back — fixtures in the beds, in the ground, up in the trees, lighting the house, the planting, the stone and the paths from outside rather than from the building.</p>

<p><strong>That is the whole difference, and it is the reason the results look nothing alike.</strong> A light on the wall washes the wall flat. A light on the ground twelve feet out, aimed up, rakes across the same wall and gives it texture, shadow and depth — you see the stone, the brick courses, the shape of the eave. The house stops being a dark block with lit windows and becomes the thing people look at.</p>

<p>The techniques are few and they do different jobs.</p>

<ul>
<li><strong>Uplighting.</strong> A fixture at the base aiming up a trunk or a wall. On a mature tree this is the highest-value fixture on most properties — one light turns a tree you cannot see at night into the main feature of the yard.</li>
<li><strong>Grazing.</strong> The same idea but close in and steeply angled, so the beam skims a textured surface. On flagstone, a boulder wall or rough brick it brings out everything the texture is doing.</li>
<li><strong>Path lighting.</strong> Low fixtures spilling a pool of light downward along a walk. These are the ones people buy first and overbuy — spaced too close they look like a runway. Spaced properly they mark the route without being the thing you look at.</li>
<li><strong>Downlighting from a tree.</strong> A fixture up in the canopy aiming down, so the light comes through the branches onto a patio or a lawn the way moonlight does. It is the most natural-looking light in the catalog and the one almost nobody asks for by name.</li>
</ul>

<p>The rule that governs all of it is that you should see the light and not the fixture. A visible bulb is glare, and glare kills everything the rest of the system is doing — one badly aimed fixture undoes a whole design. That is also why aiming is not a one-time event. Plants grow into a beam and a tree that was lit beautifully in year three is lighting its own foliage by year six, which is a ten-minute adjustment and not a new system.</p>

<p>It is low voltage — twelve volts from a transformer, buried cable, no exposure to anything dangerous in the yard — which means it goes in without tearing the place up. A trencher slit through turf is closed over in a week. Most systems are in over a day or two on a finished landscape.</p>

<p>Where it does get decided is the parts nobody sees. <strong>Brass and copper fixtures age and keep working. Thin-wall aluminum and plastic fail at the lens seal and the socket, usually in the second or third winter</strong> — which is after anyone is still thinking about who installed them, and it is why cheap systems look like a bargain for exactly one season. Wire gauge and transformer sizing decide whether the far end of a long run is as bright as the near end. And connections buried in ground that freezes and thaws every spring are the other failure point, which is a question of how they were made rather than what they cost.</p>

<p>For the fixtures on the building itself — entries, soffit, the garage, security — see <a href="/services/lighting/exterior-lighting">exterior lighting</a>. Most properties end up with both, doing different jobs.</p>
HTML;

$etm = \Drupal::entityTypeManager();
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_LIGHT_APPLY=1 to write)\n\n";

// Guards, same as the other copy loaders.
foreach ($TERMS as $name => $html) {
  if (strpos($html, '**') !== FALSE) { print "ABORT — markdown ** inside HTML on $name\n"; return; }
}
print "✓ no markdown artifacts\n";

foreach ($TERMS as $name => $html) {
  if (preg_match_all('~href="(/[^"]+)"~', $html, $m)) {
    foreach ($m[1] as $href) {
      if (\Drupal::service('path_alias.manager')->getPathByAlias($href) === $href) {
        print "ABORT — unresolved link $href (in $name)\n"; return;
      }
      printf("✓ link %-44s (in %s)\n", $href, $name);
    }
  }
}

// Resolve by name; report rather than create.
$byName = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple(\Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)->condition('vid', 'services')->execute()) as $t) {
  $byName[mb_strtolower(trim($t->label()))] = $t;
}
$matched = [];
foreach ($TERMS as $name => $html) {
  $k = mb_strtolower($name);
  if (!isset($byName[$k])) { print "ABORT — term not found: $name (reported, not created)\n"; return; }
  $matched[$name] = $byName[$k];
}
printf("\n%d of %d matched a live term.\n", count($matched), count($TERMS));

$backup = []; $changed = 0;
foreach ($matched as $name => $term) {
  $old = (string) ($term->get($FIELD)->value ?? '');
  $backup[$term->id()] = ['name' => $name, 'body' => $old];
  if (trim($old) === trim($TERMS[$name])) { printf("  %-20s unchanged\n", $name); continue; }
  printf("  %-20s %d → %d ch\n", $name, mb_strlen($old), mb_strlen($TERMS[$name]));
  $changed++;
  if ($apply) {
    $term->set($FIELD, ['value' => $TERMS[$name], 'format' => 'full_html'])->save();
    Cache::invalidateTags(['taxonomy_term:' . $term->id()]);
  }
}

if ($apply) {
  $f = '/tmp/lighting_copy_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "\nPrevious bodies backed up to $f\n";
}
printf("\n%d terms changed%s.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');

print "\n⚠ The electrical-licensing sentence was NOT added. Still Todd's to answer:\n"
  . "   in-house licensed · subbed to a licensed electrician · low-voltage only + refer out.\n"
  . "   The third answer would make Exterior Lighting a short hand-off page instead.\n";
