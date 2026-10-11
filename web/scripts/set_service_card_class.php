<?php
/**
 * Give both service-card views the shared component class.
 *
 * The card CSS used to be selected by `.view-service-children`, a class Views
 * derives from a view ID. That works until a second view reuses the component —
 * `season_services` carried the `service-card` row class and nothing styled it,
 * so the Fall page rendered plain stacked text with full-size images.
 *
 * The stylesheet is now scoped to `.bo-service-cards`, which both views set
 * through the Views `css_class` option. Same precedent as the spray landing
 * (`spray-locations-landing`).
 *
 * Idempotent; dry run unless BOS_CARDCLASS_APPLY=1.
 */
$apply = getenv('BOS_CARDCLASS_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_CARDCLASS_APPLY=1 to apply)');

$class = 'bo-service-cards';
$storage = \Drupal::entityTypeManager()->getStorage('view');
foreach (['service_children', 'season_services'] as $id) {
  $view = $storage->load($id);
  if (!$view) {
    printf("  %-18s *** not found ***\n", $id);
    continue;
  }
  $display = $view->get('display');
  $cur = $display['default']['display_options']['css_class'] ?? '';
  $classes = preg_split('/\s+/', trim($cur), -1, PREG_SPLIT_NO_EMPTY);
  if (in_array($class, $classes, TRUE)) {
    printf("  %-18s already has it (css_class: %s)\n", $id, $cur);
    continue;
  }
  $classes[] = $class;
  $new = implode(' ', $classes);
  printf("  %-18s css_class '%s' -> '%s'\n", $id, $cur ?: '(none)', $new);
  if ($apply) {
    $display['default']['display_options']['css_class'] = $new;
    $view->set('display', $display)->save();
  }
}
print $apply ? "\ndone\n" : "\nnothing written\n";
