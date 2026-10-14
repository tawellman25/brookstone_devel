<?php

/**
 * @file
 * Show the two hookup photos on the Water Sources EVA, beside the shut-off one.
 *
 * The photos save correctly (verified on source 65) but render nowhere the crew
 * actually looks: `sprinkler_system_water_sources` has three EVA displays, one
 * per source bundle, hanging off property_sprinkler_system — which is what the
 * work order page shows — and all three carry ONLY the shut-off photo.
 *
 * Deliberate choices:
 *
 *  - Each new field is CLONED from `field_ss_shut_off_location_pic` on the same
 *    display, not written from a template. That display's photo uses the
 *    colorbox formatter with a gallery and a max_2600x2600 style; a hand-written
 *    field definition would have quietly used a different formatter, and the
 *    two sets of photos would not open in the same lightbox. Cloning also means
 *    this survives the dev/live view-config drift, because it copies whatever
 *    that environment actually has rather than asserting a shape.
 *  - `table` is the LOGICAL field table name (property_ss_sources__field_x),
 *    which is what the existing field uses and what Views data is keyed by —
 *    NOT the hashed physical table (property_ss_sources__1adfcc0099). Both are
 *    checked against views data before anything is written, because a wrong
 *    table is silently ignored by Views rather than erroring.
 *  - The new fields get LABELS and the shut-off photo keeps its empty one.
 *    Three unlabelled photo strips in a row is exactly the "which picture is
 *    which" problem these two fields were created to solve. The existing field
 *    is left alone — relabelling someone else's display is not this job.
 *  - Inserted immediately AFTER the shut-off photo, preserving field order,
 *    because Views renders fields in array order.
 *  - Displays WITHOUT their own `fields` array (entity_view_3 / Well Water)
 *    inherit `default`, so they are covered by writing `default` and must not
 *    be written to directly — doing so would override the inheritance.
 *
 * Idempotent, and DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/add_hookup_pics_to_water_sources_view.php
 */

const VIEW_ID = 'sprinkler_system_water_sources';
const ANCHOR = 'field_ss_shut_off_location_pic';
const NEW_FIELDS = [
  'field_ss_hookup_location_pic' => 'Hookup location',
  'field_ss_hookup_connected_pic' => 'Hooked up (air hose)',
];

$apply = getenv('BOS_APPLY') === '1';
$viewsData = \Drupal::service('views.views_data');

// --- guard: the views data must know these fields, or Views ignores them -----
foreach (array_keys(NEW_FIELDS) as $field) {
  $table = 'property_ss_sources__' . $field;
  $d = $viewsData->get($table);
  if (!$d || !isset($d[$field])) {
    printf("ABORT: views data has no %s.%s — Views would ignore the field silently.\n", $table, $field);
    return;
  }
}
print "views data OK for both fields\n";

$config = \Drupal::configFactory()->getEditable('views.view.' . VIEW_ID);
if ($config->isNew()) {
  printf("ABORT: view %s does not exist here.\n", VIEW_ID);
  return;
}

$displays = $config->get('display') ?: [];
$changed = 0;

foreach ($displays as $did => $display) {
  $fields = $display['display_options']['fields'] ?? NULL;
  // No own field list => inherits default. Writing here would BREAK that
  // inheritance, so it is skipped and picked up via default.
  if ($fields === NULL) {
    printf("  %-14s inherits default — skipped (covered by default)\n", $did);
    continue;
  }
  if (!isset($fields[ANCHOR])) {
    printf("  %-14s no %s on this display — skipped\n", $did, ANCHOR);
    continue;
  }

  $rebuilt = [];
  $added = [];
  foreach ($fields as $key => $def) {
    $rebuilt[$key] = $def;
    if ($key !== ANCHOR) {
      continue;
    }
    foreach (NEW_FIELDS as $field => $label) {
      if (isset($fields[$field])) {
        continue;
      }
      $clone = $def;
      $clone['id'] = $field;
      $clone['table'] = 'property_ss_sources__' . $field;
      $clone['field'] = $field;
      $clone['label'] = $label;
      // A label is only rendered if the formatter is asked to show one.
      $clone['element_label_colon'] = FALSE;
      if (isset($clone['entity_field'])) {
        $clone['entity_field'] = $field;
      }
      $rebuilt[$field] = $clone;
      $added[] = $field;
    }
  }

  if (!$added) {
    printf("  %-14s already has both — no change\n", $did);
    continue;
  }
  $config->set("display.$did.display_options.fields", $rebuilt);
  $changed++;
  printf("  %-14s + %s\n", $did, implode(', ', $added));
  printf("  %-14s   order: %s\n", '', implode(' > ', array_keys($rebuilt)));
}

if (!$changed) {
  print "\nNothing to do.\n";
  return;
}

if (!$apply) {
  printf("\nDRY RUN — %d display(s) would change. Re-run with BOS_APPLY=1 to write.\n", $changed);
  return;
}

$config->save();
printf("\nSaved. %d display(s) updated.\n", $changed);

// Prove it from a fresh read, not from the array we just built.
$after = \Drupal::config('views.view.' . VIEW_ID);
foreach (array_keys($displays) as $did) {
  $f = $after->get("display.$did.display_options.fields");
  if ($f === NULL) {
    continue;
  }
  $pics = array_values(array_filter(array_keys($f), fn($k) => str_contains($k, '_pic')));
  printf("  %-14s photos now: %s\n", $did, implode(', ', $pics));
}
