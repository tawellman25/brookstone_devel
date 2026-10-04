<?php

declare(strict_types=1);

/**
 * Give the `city` entity a real card summary, and stop the county card
 * truncating the body.
 *
 * The "Towns We Serve" card on a county page renders `field_city_description`
 * trimmed to 180 characters. That is the same anti-pattern settled against for
 * taxonomy terms on 2026-10-03 and written into CLAUDE.md's UI Patterns: a card
 * never shows a truncated body, because the first 180 characters of an essay
 * were not written to be read alone and routinely stop mid-sentence.
 *
 * This adds `field_short_description` to `city` and repoints the card at it.
 *
 * FALLBACK IS DELIBERATE. Only Montrose has a written summary today; the other
 * 11 cities do not. Repointing with no fallback would blank 11 cards on the
 * county pages. So the card prefers the summary and falls back to the trimmed
 * body where none is written — the `material_children` pattern from 2026-10-02,
 * chosen for exactly this situation: "so nothing regresses while the catalogue
 * is filled in". As each city gets a summary its card improves on its own.
 *
 * The fallback is Views-native (a field's "No results behavior" rendering the
 * preceding field's token), NOT a preprocess hook, so the office can see and
 * change it in the Views UI. Field ORDER matters: the body must come before the
 * summary or its token is not available to the fallback.
 *
 * VIEWS-ONLY, per the house rule: the field goes on the FORM so the office can
 * write it, and on NO view display. (The code-level guard that enforces this,
 * bos_content_coverage_entity_view_display_presave(), is scoped to
 * taxonomy_term — it does not cover ECK entities, so this is convention here.)
 *
 *   drush php:script web/scripts/setup_city_short_description.php
 *   BOS_CITY_SD_APPLY=1 drush php:script web/scripts/setup_city_short_description.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$apply = getenv('BOS_CITY_SD_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_CITY_SD_APPLY=1 to write)\n\n";

$etm = \Drupal::entityTypeManager();
$FIELD = 'field_short_description';
$ENTITY = 'city';
$BUNDLE = 'city';
$VIEW = 'county_cities';
$BODY = 'field_city_description';

// ---------------------------------------------------------------- 1. storage
$storage = FieldStorageConfig::loadByName($ENTITY, $FIELD);
if ($storage) {
  print "  storage          already exists\n";
}
else {
  print "+ storage          text_long, cardinality 1\n";
  if ($apply) {
    FieldStorageConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $ENTITY,
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
  }
}

// --------------------------------------------------------------- 2. instance
$instance = FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD);
if ($instance) {
  print "  instance         already exists\n";
}
else {
  print "+ instance         \"Short Description\" on city.city (optional, Full HTML)\n";
  if ($apply) {
    FieldConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => 'Short Description',
      'required' => FALSE,
      // Full HTML, matching the standardised three-field content model. A field
      // left on a more restrictive format renders pasted markup as escaped text
      // and looks like a copy problem rather than a field problem.
      'settings' => ['allowed_formats' => ['full_html']],
      'description' => 'The card text on the county page. Written to be read on its own — never a truncated body. Does not render on this page.',
    ])->save();
  }
}

// ----------------------------------------------------------- 3. form display
$fd = $etm->getStorage('entity_form_display')->load("$ENTITY.$BUNDLE.default");
if ($fd && !$fd->getComponent($FIELD)) {
  print "+ form widget      textarea, 4 rows (above the body)\n";
  if ($apply) {
    $bodyWeight = $fd->getComponent($BODY)['weight'] ?? 0;
    $fd->setComponent($FIELD, [
      'type' => 'text_textarea',
      'weight' => $bodyWeight - 1,
      'settings' => ['rows' => 4],
    ])->save();
  }
}
elseif ($fd) {
  print "  form widget      already present\n";
}

// --------------------------------------------- 4. keep it OFF every view display
foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $vd) {
  if (strpos($id, "$ENTITY.$BUNDLE.") !== 0) {
    continue;
  }
  if ($vd->getComponent($FIELD)) {
    print "- removing $FIELD from view display $id (views-only field)\n";
    if ($apply) {
      $vd->removeComponent($FIELD)->save();
    }
  }
}

// ------------------------------------------------------------------- 5. view
$view = $etm->getStorage('view')->load($VIEW);
if (!$view) {
  print "\nERROR: view $VIEW not found — card not repointed.\n";
  return;
}
$display = $view->get('display');
$fields = $display['default']['display_options']['fields'] ?? [];

if (!isset($fields[$BODY])) {
  print "\nERROR: $BODY not on the $VIEW view — refusing to guess at the card source.\n";
  return;
}
if (isset($fields[$FIELD])) {
  print "\n  view             card already repointed\n";
}
else {
  $trim = $fields[$BODY]['settings']['trim_length'] ?? '?';
  print "\n+ view             card: $BODY (trimmed $trim) -> $FIELD, falling back to it\n";
  if ($apply) {
    // Body stays in the field list — excluded from render, but still produces a
    // token for the fallback below. Removing it would break the fallback.
    $fields[$BODY]['exclude'] = TRUE;
    $fields[$FIELD] = [
      'id' => $FIELD,
      'table' => 'city__' . $FIELD,
      'field' => $FIELD,
      'entity_type' => $ENTITY,
      'entity_field' => $FIELD,
      'plugin_id' => 'field',
      'type' => 'text_default',
      'label' => '',
      'exclude' => FALSE,
      'element_label_colon' => FALSE,
      // The fallback. Views renders this when the summary is empty; the token is
      // the body field above, so it arrives already trimmed to its 180 chars.
      'empty' => '{{ ' . $BODY . ' }}',
      'hide_empty' => FALSE,
      'empty_zero' => FALSE,
    ];
    $display['default']['display_options']['fields'] = $fields;
    $view->set('display', $display);
    // Saved as an ENTITY so routes and caches rebuild (the 2026-09-26 gotcha).
    $view->save();
  }
}

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}

print "\nDone. Card prefers the written summary, falls back to the trimmed body.\n";
