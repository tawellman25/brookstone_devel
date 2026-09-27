<?php

declare(strict_types=1);

/**
 * Step 2 — replace the nine wind_direction leaf descriptions.
 *
 * Source of truth (verbatim): "Spray Work Order Vocabulary - Three Layer Plan.md".
 *   - Eight compass values (North, North East, North West, East, South,
 *     South East, South West, West): ONE field_short_description pattern with the
 *     direction substituted, and the SAME field_teammate_description on all eight.
 *   - None: its own distinct short + teammate text (the inversion warning).
 *
 * The legacy `description` travelogue is left in place (dormant, not displayed —
 * see build_spray_audience_displays.php). Terms are matched by name within
 * vid=wind_direction, so the script is environment-independent. Idempotent.
 *
 *   drush php:script web/scripts/seed_wind_direction_content.php
 */

$etm = \Drupal::entityTypeManager();

// One instruction on all eight compass values (verbatim).
$COMPASS_TEAMMATE = <<<'HTML'
<p><strong>Look downwind before you start, not after.</strong> Direction is only half the fact — pair it with the speed and decide what is at risk on that side of the property today.</p>

<h3>What to check on the downwind side</h3>

<ul>
  <li>Vegetable gardens, fruit trees, anything the customer eats off.</li>
  <li>Neighbouring property — especially ornamental beds and anything obviously tended.</li>
  <li>Open water: ponds, ditches, irrigation heads running.</li>
  <li>Livestock and anything they graze.</li>
  <li>Beehives, on the property or the next one over.</li>
  <li><strong>Any organic operation.</strong> One drift event can cost a grower their certification. Treat an organic neighbour as a hard stop, not a judgment call.</li>
</ul>

<h3>If something sensitive is downwind</h3>

<p>Work the other side of the property today and come back. Switch to a granular product where the label allows it. Or move the job. <strong>Any of those is cheaper than the phone call.</strong></p>

<p>Record the direction accurately even when nothing is downwind and nothing went wrong. The record is worth nothing if it is only accurate on the days somebody complains.</p>
HTML;

// None — distinct text (verbatim).
$NONE_SHORT = 'No discernible wind direction at the time of application. Usually means still air, which is not automatically the best condition for spraying.';
$NONE_TEAMMATE = <<<'HTML'
<p><strong>No direction usually means no wind, and no wind is the inversion warning.</strong> Read the Calm entry under wind speed before you spray in this.</p>

<p>If you record None for direction and Calm for speed, you are describing the exact conditions where an application can travel furthest off target while appearing perfectly still. Check the time of day, look for smoke or dust hanging flat, and give the air an hour if you are unsure.</p>

<p>Selecting None because you did not check is a different thing from selecting None because there genuinely was no wind. Only one of those is a record.</p>
HTML;

// short_description pattern (verbatim), [DIRECTION] substituted per term.
$SHORT_PATTERN = 'Wind blowing from the [DIRECTION] at the time of application. Recorded with the wind speed so the conditions at the time, and what was downwind, are on the record.';

// Term-name -> lowercase, hyphenated compass word for the sentence.
$DIRECTION_WORD = [
  'North' => 'north',
  'North East' => 'north-east',
  'North West' => 'north-west',
  'East' => 'east',
  'South' => 'south',
  'South East' => 'south-east',
  'South West' => 'south-west',
  'West' => 'west',
];

$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'wind_direction')->execute();
$changed = 0;
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $t) {
  $name = $t->label();
  if (strcasecmp($name, 'None') === 0) {
    $short = $NONE_SHORT;
    $teammate = $NONE_TEAMMATE;
  }
  elseif (isset($DIRECTION_WORD[$name])) {
    $short = str_replace('[DIRECTION]', $DIRECTION_WORD[$name], $SHORT_PATTERN);
    $teammate = $COMPASS_TEAMMATE;
  }
  else {
    printf("  SKIP unmapped term '%s' (tid=%s)\n", $name, $t->id());
    continue;
  }

  $t->set('field_short_description', ['value' => $short, 'format' => 'basic_html']);
  $t->set('field_teammate_description', ['value' => $teammate, 'format' => 'full_html']);
  // Overwrite the legacy travelogue in `description` with the same public one-
  // liner. `description` is not displayed on any tier, but Metatag's taxonomy
  // default uses [term:description] for the meta/og description, so this removes
  // the travelogue from the page source (SEO) too — "replace entirely".
  $t->set('description', ['value' => $short, 'format' => 'basic_html']);
  $t->save();
  $changed++;
  printf("  set %-12s short + teammate + meta\n", $name);
}

printf("DONE. %d wind_direction terms updated.\n", $changed);
