<?php

declare(strict_types=1);

/**
 * Turn the "Plants with this characteristic" list into single-column cards.
 *
 * Was a three-column TABLE: a 100px thumbnail, the title in a narrow middle
 * column wrapping four words deep, then the paragraph. Now one card per row
 * with a tall sliver of the photo down the left at full card height and the
 * title above its paragraph.
 *
 * Three config changes and nothing else:
 *   - style  table -> unformatted list (plugin id is 'default', NOT
 *     'unformatted' - the documented BOS gotcha)
 *   - image style  thumbnail (100x100 scale) -> service_card_strip
 *     (240x640 scale-and-crop), the strip style already built for the
 *     service_children cards. Reused rather than duplicated: a second style
 *     means a second set of S3 derivatives and two things to keep in step.
 *   - css_class  material-item-cards, which the stylesheet is scoped to.
 *
 * Saved as a view ENTITY so routes and caches rebuild.
 *
 *   drush php:script web/scripts/build_material_item_cards.php
 *   BOS_CARDS_APPLY=1 drush php:script web/scripts/build_material_item_cards.php
 */

$apply = getenv('BOS_CARDS_APPLY') === '1';
$VIEW = 'material_characteristic_items';
$STRIP = 'service_card_strip';

$etm = \Drupal::entityTypeManager();
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_CARDS_APPLY=1 to write)\n\n";

// The strip style must exist before the view points at it.
if (!$etm->getStorage('image_style')->load($STRIP)) {
  print "ABORT — image style '$STRIP' not found.\n";
  return;
}
print "✓ image style $STRIP exists\n";

$view = $etm->getStorage('view')->load($VIEW);
if (!$view) { print "ABORT — view $VIEW not found.\n"; return; }

$d = $view->get('display');
$opts = &$d['default']['display_options'];

$before = [
  'style' => $opts['style']['type'] ?? '?',
  'image' => $opts['fields']['field_main_image']['settings']['image_style'] ?? '(none)',
  'class' => $opts['style']['options']['class'] ?? ($opts['css_class'] ?? ''),
];

// Field ORDER decides the DOM order, which the grid then places. Image first so
// it lands in column 1, then name, then description.
$order = ['field_main_image', 'field_name', 'field_description'];
$have = array_keys($opts['fields'] ?? []);
printf("  fields present: %s\n", implode(', ', $have));
foreach ($order as $f) {
  if (!in_array($f, $have, TRUE)) { print "ABORT — expected field $f is not on the view.\n"; return; }
}

$opts['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => '',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];
$opts['css_class'] = 'material-item-cards';
$opts['fields']['field_main_image']['settings']['image_style'] = $STRIP;

printf("\n  style      %-10s -> default (unformatted list)\n", $before['style']);
printf("  image      %-10s -> %s\n", $before['image'], $STRIP);
printf("  css_class  %-10s -> material-item-cards\n", $before['class'] ?: '(none)');

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

$view->set('display', $d);
$view->save();
print "\nSaved. Rebuilding caches so the new style and attached CSS take effect.\n";
drupal_flush_all_caches();

// Prove it by running the view rather than by re-reading the config.
$v = \Drupal\views\Views::getView($VIEW);
$v->setDisplay('entity_view_1');
$cats = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', 'plant_characteristics')->condition('name', 'Bird-Attracting')->execute();
if ($cats) {
  $v->setArguments([reset($cats)]);
  $v->execute();
  printf("Bird-Attracting lists %d plants under the new style.\n", count($v->result));
}
