<?php

/**
 * @file
 * The hookup TYPE + LOCATION text on the Water Sources EVA.
 *
 * Two things have to be true and only one of them is obvious:
 *
 *  1. With hookup data, the row renders a "Hookup:" line.
 *  2. WITHOUT hookup data, it renders NO "Hookup:" label at all. The line is a
 *     literal label followed by tokens, so an unguarded version prints a bare
 *     "Hookup:" on every source that has not been filled in yet — which is most
 *     of them. The Twig conditional is the thing being tested here.
 *
 * Also asserts the ORDER: a Views token only resolves for fields listed BEFORE
 * the custom-text field that uses it, so both hookup fields must precede
 * `nothing`. Get that wrong and the line renders with the label and no values —
 * which looks like missing data rather than a broken view.
 *
 * Read-only. Run: drush php:script web/scripts/test_hookup_text_in_water_sources_view.php
 */

const VIEW_ID = 'sprinkler_system_water_sources';

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$config = \Drupal::config('views.view.' . VIEW_ID);

// --- config: fields present, excluded, and BEFORE the custom text -----------
foreach (['default', 'entity_view_1', 'entity_view_2'] as $did) {
  $fields = $config->get("display.$did.display_options.fields");
  if ($fields === NULL) {
    continue;
  }
  $order = array_keys($fields);
  $nothingAt = array_search('nothing', $order, TRUE);
  foreach (['field_ss_hookup_type', 'field_ss_hookup_location'] as $f) {
    $at = array_search($f, $order, TRUE);
    $ok("$did: $f is on the display", $at !== FALSE);
    if ($at !== FALSE) {
      $ok("  before the custom text (or its token will not resolve)",
        $nothingAt !== FALSE && $at < $nothingAt, "pos $at vs nothing at $nothingAt");
      $ok('  excluded (it feeds the text, it is not its own column)',
        !empty($fields[$f]['exclude']));
    }
  }
  $text = (string) ($fields['nothing']['alter']['text'] ?? '');
  $ok("$did: the text uses both tokens",
    str_contains($text, 'field_ss_hookup_type') && str_contains($text, 'field_ss_hookup_location'));
  $ok('  and guards them, so an empty hookup prints no bare label',
    str_contains($text, '{% if') && str_contains($text, '{% endif %}'));
}

/**
 * Render the right display for a source and return the HTML.
 */
$render = function ($source) use ($etm): string {
  $map = [
    'dirty_water_source' => 'entity_view_1',
    'domestic_source' => 'entity_view_2',
    'well_water_source' => 'entity_view_3',
  ];
  $display = $map[$source->bundle()] ?? 'default';
  $sid = (int) $source->get('field_property_ss_system')->target_id;
  if ($sid <= 0) {
    return '';
  }
  $view = \Drupal\views\Views::getView(VIEW_ID);
  $view->setDisplay($display);
  $view->setArguments([$sid]);
  $view->execute();
  if (!$view->result) {
    return '';
  }
  return (string) \Drupal::service('renderer')->renderRoot($view->render());
};

// --- 1. a source WITH hookup text -------------------------------------------
$withId = $db->query('SELECT entity_id FROM {property_ss_sources__field_ss_hookup_location}
  WHERE deleted = 0 AND field_ss_hookup_location_value <> :e LIMIT 1', [':e' => ''])->fetchField();
if ($withId) {
  $src = $etm->getStorage('property_ss_sources')->load($withId);
  $html = $render($src);
  $loc = (string) ($src->get('field_ss_hookup_location')->value ?? '');
  $ok('a source WITH hookup text renders a row', $html !== '',
    sprintf('source %s (%s)', $src->id(), $src->bundle()));
  if ($html !== '') {
    $ok('  the "Hookup:" label appears', str_contains($html, 'Hookup:'));
    $ok('  and the typed location is in the output',
      $loc === '' || str_contains($html, trim($loc)), mb_substr($loc, 0, 48));
  }
}
else {
  $ok('found a source with hookup text to test', FALSE, 'none on this environment');
}

// --- 2. a source WITHOUT hookup text — the case that must stay silent -------
$withoutId = $db->query('SELECT s.id FROM {property_ss_sources_field_data} s
  LEFT JOIN {property_ss_sources__field_ss_hookup_location} h
    ON h.entity_id = s.id AND h.deleted = 0
  WHERE h.entity_id IS NULL LIMIT 1')->fetchField();
if ($withoutId) {
  $src = $etm->getStorage('property_ss_sources')->load($withoutId);
  $html = $render($src);
  $ok('a source WITHOUT hookup text still renders', $html !== '',
    sprintf('source %s (%s)', $src->id(), $src->bundle()));
  if ($html !== '') {
    $ok('  and shows NO bare "Hookup:" label', !str_contains($html, 'Hookup:'),
      'this is what the Twig guard is for');
  }
}
else {
  $ok('found a source without hookup text', FALSE, 'none — guard untested');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
