<?php

declare(strict_types=1);

/**
 * Honour marketing's field_list_order for plant_characteristics.
 *
 * ⚠ The eight char_cat_* views are DISABLED and their page paths are SHADOWED
 * by the category terms' own URL aliases, so they render nowhere. The live
 * category page is the plant_character_categories TERM page and the list is
 * rendered by the plant_characteristic_children EVA. That EVA is the only view
 * this script touches - an earlier pass changed the eight dead views and the
 * live page did not move, which is how the shadowing was found.
 *
 * The list view sorts by NAME, so the Environmental
 * Tolerance page leads with Acid-Loving - the term whose own copy says to read
 * it as a warning label - instead of Alkaline-Tolerant, which marketing put
 * first because on this ground it decides the most outcomes.
 *
 * Three steps: add the field instance (storage already exists, reused from
 * backflow_uses), seed the nine supplied values, and add list_order ASC as the
 * PRIMARY sort on all eight views with name ASC kept as the secondary.
 *
 * The other 32 terms have no list order, so they tie at NULL and the name sort
 * still decides - meaning the other seven pages should not move at all. That is
 * asserted by capturing row order before and after, not assumed.
 *
 *   drush php:script web/scripts/setup_plant_characteristic_list_order.php
 *   BOS_LO_APPLY=1 drush php:script web/scripts/setup_plant_characteristic_list_order.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\views\Views;

$apply = getenv('BOS_LO_APPLY') === '1';
$VID = 'plant_characteristics';
$FIELD = 'field_list_order';

// Marketing's order, grouped by kind of condition, most consequential first.
$ORDER = [
  'Alkaline-Tolerant' => 10,
  'Acid-Loving' => 20,
  'Well-Drained Soil' => 30,
  'Drought-Tolerant' => 40,
  'Sun-Loving' => 50,
  'Shade-Tolerant' => 60,
  'Cold-Hardy' => 70,
  'Heat-Tolerant' => 80,
  'Salt-Tolerant' => 90,
];

$etm = \Drupal::entityTypeManager();
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_LO_APPLY=1 to write)\n\n";

// Row order on every category page, so the change can be proven not to move
// the seven categories that supplied no order.
// Row order per category, taken from the view that actually renders, fed the
// category term id the way the EVA is fed it.
$CATS = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', 'plant_character_categories')->execute();
$snapshot = function () use ($CATS, $etm) {
  $out = [];
  foreach ($etm->getStorage('taxonomy_term')->loadMultiple($CATS) as $cat) {
    $v = Views::getView('plant_characteristic_children');
    if (!$v) { continue; }
    $v->setDisplay('entity_view_1');
    $v->setArguments([$cat->id()]);
    $v->execute();
    $out[$cat->label()] = array_values(array_map(fn($r) => $r->_entity ? $r->_entity->label() : '?', $v->result));
  }
  return $out;
};
$before = $snapshot();

// ---- 1. Field instance.
$inst = $etm->getStorage('field_config')->load("taxonomy_term.$VID.$FIELD");
if ($inst) {
  print "✓ $FIELD instance already exists\n";
}
else {
  $storage = $etm->getStorage('field_storage_config')->load("taxonomy_term.$FIELD");
  if (!$storage) { print "ABORT — no $FIELD storage on taxonomy_term\n"; return; }
  print "+ creating $FIELD instance (storage: " . $storage->getType() . ")\n";
  if ($apply) {
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $VID,
      'label' => 'List Order',
      'description' => 'Lower numbers sort first on the parent category page. Leave empty to sort by name.',
      'required' => FALSE,
    ])->save();
    // Put it on the term form so the office can change it without a deploy.
    $fd = $etm->getStorage('entity_form_display')->load("taxonomy_term.$VID.default");
    if ($fd && !$fd->getComponent($FIELD)) {
      $fd->setComponent($FIELD, ['type' => 'number', 'weight' => 20, 'region' => 'content'])->save();
      print "  + added to the term edit form\n";
    }
  }
}

// ---- 2. Seed the nine values.
$byName = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple(\Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)->condition('vid', $VID)->execute()) as $t) {
  $byName[mb_strtolower(trim($t->label()))] = $t;
}
foreach ($ORDER as $name => $n) {
  $t = $byName[mb_strtolower($name)] ?? NULL;
  if (!$t) { print "  ✗ term not found: $name — reported, not created\n"; continue; }
  if (!$t->hasField($FIELD)) { print "  (field not on term yet — dry run)\n"; break; }
  $cur = $t->get($FIELD)->value;
  if ((string) $cur === (string) $n) { printf("  %-22s %2d unchanged\n", $name, $n); continue; }
  printf("  %-22s %2d %s\n", $name, $n, $cur === NULL ? '(was empty)' : "(was $cur)");
  if ($apply) { $t->set($FIELD, $n)->save(); }
}

// ---- 3. list_order ASC as the primary sort, name ASC kept behind it.
print "\nVIEW SORTS\n";
foreach (['plant_characteristic_children'] as $vid) {
  $view = $etm->getStorage('view')->load($vid);
  $d = $view->get('display');
  $sorts = $d['default']['display_options']['sorts'] ?? [];
  if (isset($sorts[$FIELD . '_value'])) { printf("  %-34s already sorted by list order\n", $vid); continue; }
  $new = [
    $FIELD . '_value' => [
      'id' => $FIELD . '_value',
      'table' => 'taxonomy_term__' . $FIELD,
      'field' => $FIELD . '_value',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'plugin_id' => 'standard',
      'order' => 'ASC',
      'expose' => ['label' => ''],
      'exposed' => FALSE,
      'entity_type' => 'taxonomy_term',
      'entity_field' => $FIELD,
    ],
  ] + $sorts;
  printf("  %-34s + list order ASC before %s\n", $vid, implode(', ', array_keys($sorts)) ?: '(none)');
  if ($apply) {
    $d['default']['display_options']['sorts'] = $new;
    $view->set('display', $d);
    // Saved as an ENTITY so routes and caches rebuild.
    $view->save();
  }
}

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

// ---- 4. Prove the other seven categories did not move.
drupal_flush_all_caches();
$after = $snapshot();
print "\nROW ORDER, before → after\n";
$moved = 0;
foreach ($before as $vid => $rows) {
  $now = $after[$vid] ?? [];
  $same = $rows === $now;
  printf("  %-34s %s\n", $vid, $same ? 'unchanged' : 'REORDERED');
  if (!$same) {
    $moved++;
    print "      was: " . implode(' · ', $rows) . "\n";
    print "      now: " . implode(' · ', $now) . "\n";
  }
}
printf("\n%d of %d category pages reordered. Expected: exactly 1 (Environmental Tolerance).\n", $moved, count($before));
