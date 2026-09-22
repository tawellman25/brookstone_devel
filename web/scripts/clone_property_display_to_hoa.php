<?php

/**
 * Make the HOA property page render exactly like a residential property page:
 * copy the `properties.property.default` VIEW display layout (field groups +
 * component order/regions/settings) onto `properties.hoa.default`.
 *
 * - Skips `field_hoa` (exists only on the residential `property` bundle).
 * - Keeps HOA-only fields (public desc + service dates) visible at the top.
 * - Hides the base fields property hides (title/uid/created).
 * - Leaves the `hoa.public` view mode (public showcase) untouched.
 *
 * Per-env (view displays are drifted config), idempotent, no cim:
 *   drush php:script web/scripts/clone_property_display_to_hoa.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;

$src = EntityViewDisplay::load('properties.property.default');
$tgt = EntityViewDisplay::load('properties.hoa.default');
if (!$src || !$tgt) {
  print "Missing source or target display.\n";
  return;
}

// HOA-only fields to keep visible at the top (preserve their current config).
$hoaExtras = ['field_hoa_public_desc' => -60, 'field_service_start' => -59, 'field_service_end' => -58];
$tgtContent = $tgt->get('content');

// 1) Content = property's content, minus field_hoa (not on hoa).
$newContent = [];
foreach ($src->get('content') as $key => $component) {
  if ($key === 'field_hoa') {
    continue;
  }
  $newContent[$key] = $component;
}

// 2) Re-add HOA-only fields at the top, reusing their existing formatter config.
foreach ($hoaExtras as $field => $weight) {
  if ($tgt->getComponent($field)) {
    $c = $tgtContent[$field];
    $c['weight'] = $weight;
    $c['region'] = 'content';
    $newContent[$field] = $c;
  }
}

// 3) Hidden = property's hidden, plus base fields hoa shouldn't show.
$newHidden = $src->get('hidden') ?: [];
foreach (['title', 'uid', 'created'] as $bf) {
  $newHidden[$bf] = TRUE;
  unset($newContent[$bf]);
}
// Never hide the HOA extras we just placed.
foreach (array_keys($hoaExtras) as $f) {
  unset($newHidden[$f]);
}

$tgt->set('content', $newContent);
$tgt->set('hidden', $newHidden);

// 4) Field groups = property's groups, with field_hoa stripped from children.
foreach (array_keys($tgt->getThirdPartySettings('field_group')) as $g) {
  $tgt->unsetThirdPartySetting('field_group', $g);
}
foreach ($src->getThirdPartySettings('field_group') as $name => $group) {
  if (!empty($group['children'])) {
    $group['children'] = array_values(array_diff($group['children'], ['field_hoa']));
  }
  $tgt->setThirdPartySetting('field_group', $name, $group);
}

$tgt->save();

print 'hoa.default now mirrors property.default — components: ' . count($newContent)
  . ', field groups: ' . count($tgt->getThirdPartySettings('field_group')) . "\n";
print "DONE.\n";
