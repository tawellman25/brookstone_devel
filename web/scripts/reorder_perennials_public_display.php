<?php

/**
 * @file
 * Put the Perennials public page in the order its siblings already use.
 *
 * `material.plants` is labelled **Perennials** (the machine name is `plants`).
 * Its public display had drifted badly: the main image sat at position 18,
 * AFTER height and width, and the description at 22 — so the page opened with
 * a wall of botanical attributes and the photo turned up two thirds down.
 *
 * `shrubs` is the model and the closest sibling: image, description, then the
 * botanical detail, then stock/supplier, then pack data. This reorders
 * Perennials onto that sequence rather than inventing one.
 *
 * ⚠ It also REMOVES two components the siblings do not have — `title` and
 * `field_name`. On this bundle field_name holds the same string as the title
 * ("Dahlia"), and the page title block already prints an <h1>, so the name
 * rendered THREE times on every perennial. shrubs and trees carry neither.
 * This is the same duplicate-title defect fixed on the season pages 2026-10-11.
 *
 * Fields on Perennials that shrubs does not have keep their relative order and
 * are appended after the mirrored ones — nothing is dropped silently.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/reorder_perennials_public_display.php
 */

const TARGET = 'material.plants.public';
const MODEL = 'material.shrubs.public';
// Components the siblings do not render, and which duplicate the page title.
const REMOVE = ['title', 'field_name'];

$apply = getenv('BOS_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');

$target = $storage->load(TARGET);
$model = $storage->load(MODEL);
if (!$target) {
  printf("ABORT: %s not found.\n", TARGET);
  return;
}
if (!$model) {
  printf("ABORT: model display %s not found — nothing to mirror.\n", MODEL);
  return;
}

$current = $target->getComponents();
uasort($current, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
$modelOrder = $model->getComponents();
uasort($modelOrder, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

printf("BEFORE (%s):\n  %s\n\n", TARGET, implode(' > ', array_keys($current)));

// Mirror the model's sequence for every field Perennials actually has, then
// append anything left over in its existing relative order.
$ordered = [];
foreach (array_keys($modelOrder) as $name) {
  if (isset($current[$name]) && !in_array($name, REMOVE, TRUE)) {
    $ordered[] = $name;
  }
}
$extras = [];
foreach (array_keys($current) as $name) {
  if (!in_array($name, $ordered, TRUE) && !in_array($name, REMOVE, TRUE)) {
    $extras[] = $name;
  }
}
$final = array_merge($ordered, $extras);

$removing = array_values(array_intersect(REMOVE, array_keys($current)));
printf("AFTER:\n  %s\n\n", implode(' > ', $final));
printf("mirrored from %s: %d field(s)\n", MODEL, count($ordered));
printf("kept (not on the model), appended: %s\n", $extras ? implode(', ', $extras) : '(none)');
printf("REMOVED (duplicate the page title): %s\n", $removing ? implode(', ', $removing) : '(none present)');

if ($final === array_keys($current) && !$removing) {
  print "\nAlready in this order — nothing to do.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1 to write.\n";
  return;
}

// Re-weight in steps so a later manual insert has room between fields.
$weight = 0;
foreach ($final as $name) {
  $component = $current[$name];
  $component['weight'] = $weight;
  $target->setComponent($name, $component);
  $weight += 2;
}
foreach ($removing as $name) {
  $target->removeComponent($name);
}
$target->save();

$after = $storage->loadUnchanged(TARGET)->getComponents();
uasort($after, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
$names = array_keys($after);
printf("\nSaved. Order now:\n  %s\n", implode(' > ', $names));
printf("image first: %s   description second: %s\n",
  ($names[0] ?? '') === 'field_main_image' ? 'YES' : 'NO (' . ($names[0] ?? '-') . ')',
  ($names[1] ?? '') === 'field_description' ? 'YES' : 'NO (' . ($names[1] ?? '-') . ')');
