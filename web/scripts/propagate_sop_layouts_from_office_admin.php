<?php

declare(strict_types=1);

/**
 * One-off propagation: align all SOP bundles to the office_administration
 * layout (form + view display) and ensure field_sop_image is on every
 * bundle.
 *
 * What it does, per target bundle:
 *
 *   1. Ensures `field_sop_image` field instance exists, duplicating the
 *      office_administration instance (widget, formatter, settings) and
 *      re-binding to the target bundle.
 *
 *   2. Replaces the form display's content + field_group with
 *      office_administration's structure, then re-attaches any
 *      bundle-specific fields (e.g. field_materials_involved on
 *      sprinkler_maintenance, field_required_positions on training)
 *      at #weight 12 so they land after the standard fields without
 *      colliding with the office_administration weights.
 *
 *   3. Replaces the view display's content + field_group the same way.
 *
 *   4. Adjusts dependencies (eck.eck_type.sop.* and field.field.sop.*)
 *      to reference the target bundle's own configs.
 *
 *   5. Preserves the target display's existing UUID so the config
 *      identity doesn't change — only the layout body.
 *
 * Bundle-specific fields are NOT propagated to other bundles. Fields
 * present on office_administration but missing from the target bundle's
 * instance set are skipped (no dependency added, no content entry).
 *
 * Tab labels and field weights end up identical to office_administration
 * across all bundles (per user direction "unify all to match
 * office_administration").
 *
 * Usage:
 *   ddev drush scr web/scripts/propagate_sop_layouts_from_office_admin.php -- --dry-run
 *   ddev drush scr web/scripts/propagate_sop_layouts_from_office_admin.php -- --commit
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$dryRun = in_array('--dry-run', $extra ?? [], TRUE);
$commit = in_array('--commit', $extra ?? [], TRUE);

if (!$dryRun && !$commit) {
  echo "Usage: --dry-run | --commit\n";
  exit(2);
}

$src_bundle = 'office_administration';
$tgt_bundles = [
  'landscaping',
  'lighting',
  'maintenance',
  'safety',
  'snow_removal',
  'sop_governance',
  'spray',
  'sprinkler_maintenance',
  'system_procedures',
  'training',
];

$BUNDLE_SPECIFIC_WEIGHT = 12;

$src_form_id = "sop.$src_bundle.default";
$src_view_id = "sop.$src_bundle.default";

$src_form_data = \Drupal::config("core.entity_form_display.$src_form_id")->getRawData();
$src_view_data = \Drupal::config("core.entity_view_display.$src_view_id")->getRawData();

if (!$src_form_data || !$src_view_data) {
  echo "Source displays not found for office_administration.\n";
  exit(1);
}

$src_image_instance = \Drupal\field\Entity\FieldConfig::loadByName('sop', $src_bundle, 'field_sop_image');
if (!$src_image_instance) {
  echo "field_sop_image instance not found on office_administration. Aborting.\n";
  exit(1);
}

foreach ($tgt_bundles as $b) {
  echo "\n=== $b ===\n";

  // ── 1. field_sop_image field instance ──────────────────────────────
  $img = \Drupal\field\Entity\FieldConfig::loadByName('sop', $b, 'field_sop_image');
  if (!$img) {
    if ($commit) {
      $new = $src_image_instance->createDuplicate();
      $new->set('bundle', $b);
      $new->save();
      echo "  + created field instance field_sop_image\n";
    }
    else {
      echo "  + would create field instance field_sop_image\n";
    }
  }
  else {
    echo "  · field_sop_image instance already present\n";
  }

  // Which sop fields actually exist on this bundle? Needed to filter
  // content entries and dependencies so we don't reference fields the
  // target bundle doesn't have.
  $tgt_field_names = [];
  foreach (\Drupal\field\Entity\FieldConfig::loadMultiple() as $fc) {
    if ($fc->getTargetEntityTypeId() === 'sop' && $fc->getTargetBundle() === $b) {
      $tgt_field_names[$fc->getName()] = TRUE;
    }
  }
  // Account for the just-created (or about-to-be-created) image field
  // even on dry-run, so the planned content listing reflects it.
  $tgt_field_names['field_sop_image'] = TRUE;

  // ── 2. Form display ────────────────────────────────────────────────
  $tgt_form_id = "sop.$b.default";
  $tgt_form_data = \Drupal::config("core.entity_form_display.$tgt_form_id")->getRawData();

  $new_form = _bos_propagate_display($src_form_data, $tgt_form_data, $b, $tgt_field_names, $BUNDLE_SPECIFIC_WEIGHT);
  echo "  form display: " . count($new_form['content']) . " content entries (" . count(array_diff(array_keys($new_form['content']), array_keys($tgt_form_data['content'] ?? []))) . " added, "
       . count(array_diff(array_keys($tgt_form_data['content'] ?? []), array_keys($new_form['content']))) . " removed)\n";

  if ($commit) {
    \Drupal::configFactory()->getEditable("core.entity_form_display.$tgt_form_id")->setData($new_form)->save();
  }

  // ── 3. View display ────────────────────────────────────────────────
  $tgt_view_id = "sop.$b.default";
  $tgt_view_data = \Drupal::config("core.entity_view_display.$tgt_view_id")->getRawData();

  $new_view = _bos_propagate_display($src_view_data, $tgt_view_data, $b, $tgt_field_names, $BUNDLE_SPECIFIC_WEIGHT);
  echo "  view display: " . count($new_view['content']) . " content entries (" . count(array_diff(array_keys($new_view['content']), array_keys($tgt_view_data['content'] ?? []))) . " added, "
       . count(array_diff(array_keys($tgt_view_data['content'] ?? []), array_keys($new_view['content']))) . " removed)\n";

  if ($commit) {
    \Drupal::configFactory()->getEditable("core.entity_view_display.$tgt_view_id")->setData($new_view)->save();
  }
}

if ($commit) {
  echo "\nDone. Run targeted captures (active→sync) for each updated config to land it in git.\n";
}
else {
  echo "\nDry-run only. Re-run with --commit to apply.\n";
}

/**
 * Produce a new display data array for $target_bundle, modeled on
 * $src_data, preserving target-bundle UUID and bundle-specific fields.
 */
function _bos_propagate_display(array $src_data, array $tgt_data, string $target_bundle, array $tgt_field_names, int $specific_weight): array {
  $src_bundle = 'office_administration';

  $out = $src_data;
  $out['uuid'] = $tgt_data['uuid'] ?? \Drupal::service('uuid')->generate();
  $out['id'] = preg_replace('/\.' . preg_quote($src_bundle, '/') . '\./', ".$target_bundle.", $src_data['id']);
  $out['bundle'] = $target_bundle;

  // Filter content: only keep fields the target bundle has, plus the
  // non-field components (title, uid, created, changed, path, etc.).
  $out['content'] = [];
  foreach ($src_data['content'] ?? [] as $name => $cfg) {
    if (strpos($name, 'field_') === 0) {
      if (!isset($tgt_field_names[$name])) {
        continue;
      }
    }
    $out['content'][$name] = $cfg;
  }
  // Preserve any bundle-specific fields (present on target, absent on src).
  foreach ($tgt_data['content'] ?? [] as $name => $cfg) {
    if (strpos($name, 'field_') !== 0) {
      continue;
    }
    if (isset($out['content'][$name])) {
      continue;
    }
    if (!isset($tgt_field_names[$name])) {
      continue;
    }
    $cfg['weight'] = $specific_weight;
    $out['content'][$name] = $cfg;
  }
  ksort($out['content']);

  // Preserve target's hidden list (rather than copying src's) so
  // bundle-specific hidden choices don't get overwritten.
  $out['hidden'] = $tgt_data['hidden'] ?? [];

  // Adjust dependencies.config: rewrite all field.field.sop.{src}.* to
  // field.field.sop.{tgt}.*, drop any whose field instance isn't on the
  // target bundle, and rewrite eck.eck_type.sop.{src} → .{tgt}.
  $deps = $src_data['dependencies']['config'] ?? [];
  $new_deps = [];
  foreach ($deps as $dep) {
    if ($dep === "eck.eck_type.sop.$src_bundle") {
      $new_deps[] = "eck.eck_type.sop.$target_bundle";
      continue;
    }
    if (preg_match('|^field\.field\.sop\.' . preg_quote($src_bundle, '|') . '\.(.+)$|', $dep, $m)) {
      $field = $m[1];
      if (isset($tgt_field_names[$field])) {
        $new_deps[] = "field.field.sop.$target_bundle.$field";
      }
      continue;
    }
    $new_deps[] = $dep;
  }
  // Add field.field.sop.{tgt}.{field} deps for any bundle-specific
  // fields we preserved that aren't on src.
  foreach ($tgt_data['content'] ?? [] as $name => $cfg) {
    if (strpos($name, 'field_') !== 0) {
      continue;
    }
    if (isset($src_data['content'][$name])) {
      continue;
    }
    if (!isset($tgt_field_names[$name])) {
      continue;
    }
    $dep = "field.field.sop.$target_bundle.$name";
    if (!in_array($dep, $new_deps, TRUE)) {
      $new_deps[] = $dep;
    }
  }
  sort($new_deps);
  $out['dependencies']['config'] = array_values(array_unique($new_deps));

  return $out;
}
