<?php

/**
 * @file
 * Static checks on the VBO live-count work. Run: drush php:script …
 *
 * The counter itself is browser behaviour, so what is asserted here is the
 * configuration and the properties of the code that the incident turned on:
 *
 *  - every bulk action on every view that can invoice now has a confirmation
 *    step (the hole was ONE view with all SEVEN of its actions unconfirmed);
 *  - the readout is wired into the globally-attached admin library, so it
 *    appears on every VBO form rather than one screen somebody remembered;
 *  - the counter RECOUNTS rather than increments — the property that makes it
 *    survive shift-click, which sets .checked without firing change.
 */

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

// --- 1. confirmation on every invoicing-capable view ------------------------
$unconfirmed = [];
$checked = 0;
foreach (\Drupal::configFactory()->listAll('views.view.') as $name) {
  $c = \Drupal::config($name);
  foreach ((array) $c->get('display') as $did => $d) {
    foreach ((array) ($d['display_options']['fields'] ?? []) as $f) {
      if (($f['plugin_id'] ?? '') !== 'views_bulk_operations_bulk_form') {
        continue;
      }
      $actions = (array) ($f['selected_actions'] ?? []);
      $canInvoice = FALSE;
      foreach ($actions as $a) {
        if (str_contains((string) ($a['action_id'] ?? ''), 'invoiced')) {
          $canInvoice = TRUE;
        }
      }
      if (!$canInvoice) {
        continue;
      }
      $checked++;
      foreach ($actions as $a) {
        if (($a['preconfiguration']['add_confirmation'] ?? NULL) !== TRUE) {
          $unconfirmed[] = substr($name, 11) . ':' . $did . ':' . ($a['action_id'] ?? '?');
        }
      }
    }
  }
}
$ok('found the invoicing-capable bulk forms', $checked > 0, "$checked form(s)");
$ok('EVERY action on them has a confirmation step', $unconfirmed === [],
  $unconfirmed ? implode(' | ', array_slice($unconfirmed, 0, 4)) : 'none unconfirmed');

// --- 2. the readout is attached where every VBO form will get it ------------
$themePath = \Drupal::service('extension.list.theme')->getPath('brookstone_admin');
$libs = file_get_contents($themePath . '/brookstone_admin.libraries.yml');
$info = file_get_contents($themePath . '/brookstone_admin.info.yml');
$ok('the counter JS is in the claro_menu_fix library',
  (bool) preg_match('/claro_menu_fix:.*?js\/vbo-selection-count\.js/s', $libs));
$ok('  that library is attached globally by the theme',
  str_contains($info, 'brookstone_admin/claro_menu_fix'));
$ok('  and it declares core/once, which the behaviour uses',
  (bool) preg_match('/claro_menu_fix:.*?core\/once/s', $libs));

$js = file_get_contents($themePath . '/js/vbo-selection-count.js');
$css = file_get_contents($themePath . '/css/claro_menu_fix.css');

// --- 3. the properties that make it correct ---------------------------------
// Recounting, not incrementing, is what survives a shift-click range: core sets
// .checked via prop() without firing change, so an event-counted tally misses
// every row in the range.
$ok('counts with :checked over the whole form (recount, not increment)',
  str_contains($js, ':checked') && !preg_match('/\b(count\s*\+\+|\+=\s*1)/', $js));
$ok('listens on the FORM so it fires after core expands a shift range',
  str_contains($js, "addEventListener('change'") && str_contains($js, "addEventListener('click'"));
$ok('matches VBO row checkboxes by name', str_contains($js, 'views_bulk_operations_bulk_form'));
$ok('shouts above a threshold rather than reading the same at any size',
  str_contains($js, 'LOUD_AT') && str_contains($css, 'is-loud'));
$ok('the readout is sticky, so it stays visible down a long list',
  (bool) preg_match('/\.bos-vbo-count\s*\{[^}]*position:\s*sticky/s', $css));
$ok('the select-all hide is still in place (this ADDS to it, not replaces it)',
  (bool) preg_match('/th\.select-all input\[type="checkbox"\]\s*\{\s*display:\s*none/s', $css));

printf("\n%d passed, %d failed\n", $pass, $fail);
