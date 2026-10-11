<?php
/**
 * The three audience displays for `season`.
 *
 * full          public   icon + body + the services list + the closing ask
 * teammate_view crew     icon + crew notes + the services list
 * admin_view    office   everything, so the office can see what each audience gets
 *
 * ⚠ field_short_description is on NONE of them. It is the teaser the Seasons
 * LIST reads as a Views field; rendering it on the term's own page puts the
 * one-liner above the body it summarises. bos_content_coverage strips it from
 * any taxonomy_term view display on save, so this would not stick anyway.
 *
 * Weights follow material_types: body at 0, the list at 10, the ask at 20 — the
 * ask belongs after the thing it is asking about.
 */
$etm = \Drupal::entityTypeManager();
$VID = 'season';
$repo = \Drupal::service('entity_display.repository');

$LAYOUT = [
  'full' => [
    'field_icon' => 0,
    'field_public_description' => 1,
    'field_services_performed' => 10,
    'field_call_to_action' => 20,
  ],
  'teammate_view' => [
    'field_icon' => 0,
    'field_teammate_description' => 1,
    'field_services_performed' => 10,
  ],
  'admin_view' => [
    'field_icon' => 0,
    'field_public_description' => 1,
    'field_teammate_description' => 2,
    'field_call_to_action' => 3,
    'field_services_performed' => 10,
  ],
];

$modes = array_keys($repo->getViewModes('taxonomy_term'));
printf("registered taxonomy_term view modes: %s\n\n", implode(', ', $modes));

foreach ($LAYOUT as $mode => $components) {
  if ($mode !== 'full' && !in_array($mode, $modes, TRUE)) {
    printf("  %-14s view mode NOT REGISTERED — skipped\n", $mode);
    continue;
  }
  // Through the display repository so the display is registered, not just saved.
  $display = $repo->getViewDisplay('taxonomy_term', $VID, $mode);
  foreach (array_keys($display->getComponents()) as $existing) {
    $display->removeComponent($existing);
  }
  foreach ($components as $field => $weight) {
    if (!\Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, $field)
      && !in_array($field, ['field_icon', 'field_services_performed'], TRUE)) {
      continue;
    }
    $type = ($field === 'field_icon') ? 'image'
      : (($field === 'field_services_performed') ? 'entity_reference_label' : 'text_default');
    $settings = ($field === 'field_icon') ? ['image_style' => '', 'image_link' => ''] : [];
    if ($field === 'field_services_performed') { $settings = ['link' => TRUE]; }
    $display->setComponent($field, [
      'type' => $type, 'weight' => $weight, 'label' => 'hidden',
      'settings' => $settings, 'region' => 'content',
    ]);
  }
  $display->setStatus(TRUE)->save();
  printf("  %-14s %s\n", $mode, implode(', ', array_keys($display->getComponents())));
}

// The old single display keeps working for anything that still asks for it,
// but core `description` comes off it once the migration has run.
print "\ndone\n";
