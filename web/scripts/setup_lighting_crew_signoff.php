<?php

/**
 * Create a "Lighting Crew" wo_complete_info sign-off bundle and wire it so the
 * exterior_lighting + landscape_lighting work orders can be signed off by a
 * dedicated lighting crew (roster on field_those_on_crew).
 *
 * Modeled on landscape_crew (the closest general crew: roster + trucks, no
 * warranty / no-spray fields). Does, idempotently:
 *   1. Bundle wo_complete_info.lighting_crew (clone of landscape_crew).
 *   2. Clone landscape_crew field instances + base-field overrides onto it;
 *      retarget field_work_order to [exterior_lighting, landscape_lighting].
 *   3. Clone auto_entitylabel + form/view displays.
 *   4. Add wo_sign_off view display "entity_view_9" (clone of entity_view_7,
 *      landscape_crew) whose Sign-Off button points at add/lighting_crew and
 *      which EVA-attaches to the two lighting WO bundles.
 *   5. Place that EVA (wo_sign_off_entity_view_9) on the two lighting WO
 *      view displays so the Sign-Off button appears on those WO pages.
 *
 * The code lists in wo_sign_off (allowedBundles) and WoCrewRosterService are
 * updated separately in the module (a code deploy), not here.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/setup_lighting_crew_signoff.php
 */

use Drupal\Core\Field\FieldConfigInterface;
use Drupal\field\Entity\FieldConfig;

$SRC = 'landscape_crew';
$NEW = 'lighting_crew';
$LABEL = 'Lighting Crew';
$LIGHTING = ['exterior_lighting', 'landscape_lighting'];
$out = [];
$etm = \Drupal::entityTypeManager();
$cf = \Drupal::configFactory();

// ---------------------------------------------------------------------------
// 1) Bundle. ECK bundles are config entities of type "{entity_type}_type"
// (here wo_complete_info_type), keyed by the bare machine name.
$bundleStorage = $etm->getStorage('wo_complete_info_type');
if (!$bundleStorage->load($NEW)) {
  $data = $bundleStorage->load($SRC)->toArray();
  unset($data['uuid'], $data['_core'], $data['dependencies']);
  $data['type'] = $NEW;
  $data['name'] = $LABEL;
  $data['description'] = 'These are the Completion of Lighting Crew type Work Orders.';
  $bundleStorage->create($data)->save();
  $out[] = "bundle wo_complete_info.$NEW created";
}
else {
  $out[] = "bundle wo_complete_info.$NEW exists";
}

// ---------------------------------------------------------------------------
// 2) Field instances (clone every landscape_crew instance).
$fcStorage = $etm->getStorage('field_config');
$srcFields = $fcStorage->loadByProperties(['entity_type' => 'wo_complete_info', 'bundle' => $SRC]);
foreach ($srcFields as $fc) {
  /** @var FieldConfigInterface $fc */
  $fname = $fc->getName();
  $newId = "wo_complete_info.$NEW.$fname";
  if ($fcStorage->load($newId)) {
    continue;
  }
  $data = $fc->toArray();
  unset($data['uuid'], $data['id'], $data['_core'], $data['dependencies']);
  $data['bundle'] = $NEW;
  FieldConfig::create($data)->save();
  $out[] = "field $fname cloned";
}
// Retarget field_work_order to the lighting WO bundles.
$fwo = $fcStorage->load("wo_complete_info.$NEW.field_work_order");
if ($fwo) {
  $s = $fwo->getSettings();
  $s['handler_settings']['target_bundles'] = array_combine($LIGHTING, $LIGHTING);
  // Drop any auto_create default pointing at a non-lighting bundle.
  if (!empty($s['handler_settings']['auto_create_bundle']) && !in_array($s['handler_settings']['auto_create_bundle'], $LIGHTING, TRUE)) {
    $s['handler_settings']['auto_create_bundle'] = $LIGHTING[0];
  }
  $fwo->set('settings', $s)->save();
  $out[] = 'field_work_order target_bundles -> ' . implode(',', $LIGHTING);
}

// Base-field overrides (uid/created/changed) — cosmetic parity with source.
$bfoStorage = $etm->getStorage('base_field_override');
foreach (['uid', 'created', 'changed'] as $bf) {
  $srcId = "wo_complete_info.$SRC.$bf";
  $newId = "wo_complete_info.$NEW.$bf";
  if ($bfoStorage->load($srcId) && !$bfoStorage->load($newId)) {
    $data = $bfoStorage->load($srcId)->toArray();
    unset($data['uuid'], $data['id'], $data['_core'], $data['dependencies']);
    $data['bundle'] = $NEW;
    $etm->getStorage('base_field_override')->create($data)->save();
    $out[] = "base_field_override $bf cloned";
  }
}

// ---------------------------------------------------------------------------
// 3) auto_entitylabel.
$aelSrc = "auto_entitylabel.settings.wo_complete_info.$SRC";
$aelNew = "auto_entitylabel.settings.wo_complete_info.$NEW";
if ($cf->get($aelNew)->isNew()) {
  $data = $cf->get($aelSrc)->getRawData();
  $cf->getEditable($aelNew)->setData($data)->save();
  $out[] = 'auto_entitylabel cloned';
}

// ---------------------------------------------------------------------------
// 4) Form + view displays (clone whole config objects, retarget bundle).
foreach (['entity_form_display', 'entity_view_display'] as $dt) {
  $srcId = "core.$dt.wo_complete_info.$SRC.default";
  $newId = "core.$dt.wo_complete_info.$NEW.default";
  if ($cf->get($newId)->isNew()) {
    $data = $cf->get($srcId)->getRawData();
    unset($data['uuid'], $data['_core']);
    $data['id'] = "wo_complete_info.$NEW.default";
    $data['bundle'] = $NEW;
    // Rewrite dependency + targetEntityType references from source bundle.
    $data['dependencies']['config'] = array_values(array_map(
      fn($c) => str_replace(".$SRC", ".$NEW", $c),
      $data['dependencies']['config'] ?? []
    ));
    $cf->getEditable($newId)->setData($data)->save();
    $out[] = "$dt cloned";
  }
}

// ---------------------------------------------------------------------------
// 5) wo_sign_off view: add display entity_view_9 (clone of entity_view_7).
$view = $cf->getEditable('views.view.wo_sign_off');
$displays = $view->get('display');
if (!isset($displays['entity_view_9'])) {
  $d = $displays['entity_view_7'];
  $d['id'] = 'entity_view_9';
  $d['display_title'] = 'EVA - Lighting Crew Sign Off';
  $positions = array_map(fn($x) => $x['position'] ?? 0, $displays);
  $d['position'] = (max($positions) + 1);
  $d['display_options']['bundles'] = $LIGHTING;
  // Point the Sign-Off button at the lighting_crew add form.
  if (!empty($d['display_options']['empty'])) {
    foreach ($d['display_options']['empty'] as $k => $area) {
      if (isset($area['content']['value'])) {
        $d['display_options']['empty'][$k]['content']['value'] =
          str_replace('wo_complete_info/add/' . $SRC, 'wo_complete_info/add/' . $NEW, $area['content']['value']);
      }
    }
  }
  $displays['entity_view_9'] = $d;
  $view->set('display', $displays);
  // Add the new bundle as a config dependency.
  $deps = $view->get('dependencies.config') ?? [];
  $dep = "eck.eck_type.wo_complete_info.$NEW";
  if (!in_array($dep, $deps, TRUE)) {
    $deps[] = $dep;
    sort($deps);
    $view->set('dependencies.config', $deps);
  }
  $view->save();
  $out[] = 'wo_sign_off view: entity_view_9 added';
}
else {
  $out[] = 'wo_sign_off view: entity_view_9 exists';
}

// ---------------------------------------------------------------------------
// 6) Place the EVA on the two lighting WO view displays.
$repo = \Drupal::service('entity_display.repository');
foreach ($LIGHTING as $b) {
  $disp = $repo->getViewDisplay('work_order', $b, 'default');
  if (!$disp->getComponent('wo_sign_off_entity_view_9')) {
    $disp->setComponent('wo_sign_off_entity_view_9', [
      'settings' => [],
      'third_party_settings' => [],
      'weight' => 11,
      'region' => 'content',
    ]);
    $disp->save();
    $out[] = "work_order.$b: wo_sign_off_entity_view_9 placed";
  }
  else {
    $out[] = "work_order.$b: EVA already placed";
  }
}

print implode("\n", $out) . "\nDONE.\n";
