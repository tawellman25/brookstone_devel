<?php

declare(strict_types=1);

/**
 * Give each material listing view its own pager element.
 *
 * A material_types term page renders up to three views at once —
 * material_children (category cards), material_subcategory_items (items filed
 * by category) and material_type_items (items by bundle). All three carried
 * `pager.options.id = 0`, and Drupal keys pager state by that id, so they shared
 * one element: whichever rendered last won.
 *
 * On /material/plants/shrubs that is material_type_items with 62 items at 50 a
 * page = 2 pages, so the EMPTY subcategory view rendered a "1 2" pager under the
 * category cards. Its own query returns zero rows and reports total_items = 0 —
 * the pager it displayed was never its own.
 *
 * Distinct ids give each view its own element and its own page parameter.
 *
 * Idempotent. Dry-run by default; BOS_PAGER_APPLY=1 to write.
 */

$apply = getenv('BOS_PAGER_APPLY') === '1';

/** view id => pager element id. material_type_items keeps 0, the incumbent. */
$IDS = [
  'material_type_items' => 0,
  'material_subcategory_items' => 1,
  'material_characteristic_items' => 2,
  'material_tag_items' => 3,
];

$storage = \Drupal::entityTypeManager()->getStorage('view');
foreach ($IDS as $viewId => $pagerId) {
  $view = $storage->load($viewId);
  if (!$view) {
    printf("  skip    %-30s not present\n", $viewId);
    continue;
  }
  $display = $view->get('display');
  $current = $display['default']['display_options']['pager']['options']['id'] ?? NULL;
  if ((int) $current === $pagerId) {
    printf("  ok      %-30s already element %d\n", $viewId, $pagerId);
    continue;
  }
  printf("  %s %-30s pager element %s -> %d\n", $apply ? 'WRITE  ' : 'would  ', $viewId, var_export($current, TRUE), $pagerId);
  if ($apply) {
    $display['default']['display_options']['pager']['options']['id'] = $pagerId;
    $view->set('display', $display);
    $view->save();
  }
}
if (!$apply) {
  print "\nDRY RUN. BOS_PAGER_APPLY=1 to write.\n";
}
