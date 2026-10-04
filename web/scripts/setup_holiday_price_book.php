<?php

declare(strict_types=1);

/**
 * Holiday lighting price book → config_pages:business_setting.
 *
 * Replaces the single $8.00/ft model with the three-plan model from
 * "Holiday Lighting Field Pricing" Rev 2 (Oct 2026), confirmed by Todd
 * 2026-10-04: all three plans public, leading with the two-year rate.
 *
 * Reuse over creation, and the two reused fields are reused deliberately:
 *   field_holiday_light_per_foot  becomes the TWO-YEAR lead rate (5.75).
 *     It is reused rather than retired because the live /holiday-lights page
 *     and the [holiday-price] token on /services/christmas-decorations both
 *     read it. Repointing the value keeps the lead price correct everywhere it
 *     is already rendered; orphaning it would leave $8.00 on a live page.
 *   field_holiday_min_job         becomes the FULL-SEASON minimum (775).
 *
 * field_holiday_walkway changes MEANING — it was "per linear foot", the sheet
 * prices borders "per 25 ft section" — so it is relabelled, not silently
 * reused at a new value. It is empty today, so nothing is being reinterpreted.
 *
 * NO COST OR MARGIN FIELDS. Section 12 of the sheet lists the burdened labor
 * rate ($30/man-hour) and the material costs as unverified — backed out of
 * retail, not quoted. A stored margin computed from a guessed input reads as
 * fact on an office screen, so margins wait until those are real.
 *
 *   drush php:script web/scripts/setup_holiday_price_book.php
 *   BOS_HOLIDAY_APPLY=1 drush php:script web/scripts/setup_holiday_price_book.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$apply = getenv('BOS_HOLIDAY_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_HOLIDAY_APPLY=1 to write)\n\n";

$ENTITY = 'config_pages';
$BUNDLE = 'business_setting';

// field_name => [label, type, value, settings]
// Decimals for money and the percentage; integer for a count.
$FIELDS = [
  // --- plans -----------------------------------------------------------
  'field_holiday_light_per_foot' => ['Holiday — Full Season 2-Year (per ft)', 'decimal', '5.75'],
  'field_holiday_rate_1yr'       => ['Holiday — Full Season 1-Year (per ft)', 'decimal', '7.50'],
  'field_holiday_rate_hang'      => ['Holiday — Hang & Remove (per ft)', 'decimal', '3.00'],
  'field_holiday_min_job'        => ['Holiday — Full Season Minimum', 'decimal', '775.00'],
  'field_holiday_min_hang'       => ['Holiday — Hang & Remove Minimum', 'decimal', '450.00'],
  // --- deciduous trees, by trunk bracket -------------------------------
  'field_holiday_tree_dec_sm'    => ['Holiday — Deciduous Tree, Small', 'decimal', '225.00'],
  'field_holiday_tree_dec_md'    => ['Holiday — Deciduous Tree, Medium', 'decimal', '450.00'],
  'field_holiday_tree_dec_lg'    => ['Holiday — Deciduous Tree, Large', 'decimal', '700.00'],
  // --- evergreens, by height bracket -----------------------------------
  'field_holiday_tree_eg_6'      => ['Holiday — Evergreen, to 6 ft', 'decimal', '185.00'],
  'field_holiday_tree_eg_9'      => ['Holiday — Evergreen, 6 to 9 ft', 'decimal', '350.00'],
  'field_holiday_tree_eg_12'     => ['Holiday — Evergreen, 9 to 12 ft', 'decimal', '650.00'],
  // --- wreaths, by diameter --------------------------------------------
  'field_holiday_wreath_24'      => ['Holiday — Wreath, 24 in', 'decimal', '275.00'],
  'field_holiday_wreath_36'      => ['Holiday — Wreath, 36 in', 'decimal', '450.00'],
  'field_holiday_wreath_48'      => ['Holiday — Wreath, 48 in', 'decimal', '600.00'],
  // --- counted items ----------------------------------------------------
  'field_holiday_shrub'          => ['Holiday — Shrub or Bush (each)', 'decimal', '85.00'],
  'field_holiday_shrub_min_qty'  => ['Holiday — Shrub Minimum Quantity', 'integer', '3'],
  'field_holiday_column_wrap'    => ['Holiday — Porch Column (each, to 10 ft)', 'decimal', '225.00'],
  'field_holiday_window'         => ['Holiday — Window Outline (each)', 'decimal', '110.00'],
  'field_holiday_walkway'        => ['Holiday — Walkway/Bed Border (per 25 ft section)', 'decimal', '110.00'],
  // --- site conditions --------------------------------------------------
  'field_holiday_steep_pct'      => ['Holiday — Steep Roof Premium (%)', 'decimal', '20.00'],
  'field_holiday_tall_adder'     => ['Holiday — Work Above 25 ft (flat)', 'decimal', '100.00'],
];

$cp = \Drupal::service('config_pages.loader')->load($BUNDLE);
if (!$cp) {
  print "ERROR: no business_setting config page loaded. Aborting.\n";
  return;
}

$fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("$ENTITY.$BUNDLE.default");
$weight = 900;
$created = $relabelled = $valued = $kept = 0;

foreach ($FIELDS as $name => [$label, $type, $value]) {
  if (strlen($name) > 32) {
    printf("ERROR: %s is %d chars — the field name limit is 32. Aborting.\n", $name, strlen($name));
    return;
  }

  // storage
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    printf("+ storage   %-32s %s\n", $name, $type);
    if ($apply) {
      $settings = $type === 'decimal' ? ['precision' => 10, 'scale' => 2] : [];
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $ENTITY,
        'type' => $type,
        'cardinality' => 1,
        'settings' => $settings,
      ])->save();
    }
  }

  // instance
  $inst = FieldConfig::loadByName($ENTITY, $BUNDLE, $name);
  if (!$inst) {
    printf("+ instance  %-32s \"%s\"\n", $name, $label);
    $created++;
    if ($apply) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $ENTITY,
        'bundle' => $BUNDLE,
        'label' => $label,
        'required' => FALSE,
      ])->save();
    }
  }
  elseif ($inst->label() !== $label) {
    printf("~ relabel   %-32s \"%s\" -> \"%s\"\n", $name, $inst->label(), $label);
    $relabelled++;
    if ($apply) {
      $inst->set('label', $label)->save();
    }
  }
  else {
    $kept++;
  }

  // widget
  if ($apply && $fd && !$fd->getComponent($name)) {
    $fd->setComponent($name, [
      'type' => $type === 'integer' ? 'number' : 'number',
      'weight' => $weight++,
      'region' => 'content',
    ]);
  }
}
if ($apply && $fd) {
  $fd->save();
}

// values — only write where empty, or where the OLD per-foot/minimum values
// are the superseded $8.00 / unset model. Never clobber an office edit.
print "\nValues:\n";
if ($apply) {
  $cp = \Drupal::service('config_pages.loader')->load($BUNDLE);
}
foreach ($FIELDS as $name => [$label, $type, $value]) {
  if (!$cp->hasField($name)) {
    printf("  %-32s (field not yet created — re-run after apply)\n", $name);
    continue;
  }
  $cur = $cp->get($name)->value;
  $empty = ($cur === NULL || $cur === '');
  // The one deliberate overwrite: the retired $8.00/ft single rate.
  $supersededPerFoot = ($name === 'field_holiday_light_per_foot' && !$empty && abs((float) $cur - 8.00) < 0.001);
  if ($empty || $supersededPerFoot) {
    printf("  %-32s %s -> %s%s\n", $name, $empty ? 'EMPTY' : $cur, $value, $supersededPerFoot ? '   (retiring the single $8.00/ft model)' : '');
    $valued++;
    if ($apply) {
      $cp->set($name, $value);
    }
  }
  else {
    printf("  %-32s %s   (office value kept)\n", $name, $cur);
  }
}
if ($apply) {
  $cp->save();
}

// --- retire the superseded generic fields ---------------------------------
// field_holiday_tree_wrap ("per tree") is superseded by the six trunk/height
// brackets; field_holiday_wreath ("each") by the three diameters. Both are
// EMPTY and nothing reads them — verified against all config and all custom
// code. Leaving them on the form is how somebody ends up pricing a tree off
// the wrong field, so the instances go.
print "\nRetiring superseded fields:\n";
foreach (['field_holiday_tree_wrap', 'field_holiday_wreath'] as $old) {
  $inst = FieldConfig::loadByName($ENTITY, $BUNDLE, $old);
  if (!$inst) {
    printf("  %-32s already gone\n", $old);
    continue;
  }
  $val = $cp->hasField($old) ? $cp->get($old)->value : NULL;
  if ($val !== NULL && $val !== '') {
    printf("  %-32s HOLDS A VALUE (%s) — refusing to remove it\n", $old, $val);
    continue;
  }
  printf("- instance  %-32s (empty, superseded)\n", $old);
  if ($apply) {
    $inst->delete();
  }
}

printf("\n%d instances created, %d relabelled, %d already correct, %d values set.\n", $created, $relabelled, $kept, $valued);
if (!$apply) {
  print "(dry-run — nothing written)\n";
}
