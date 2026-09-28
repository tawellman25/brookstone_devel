<?php

declare(strict_types=1);

/**
 * Moderation + attribution fields on the testimonial entity.
 *
 * The entity shipped with five content fields and NO approval flag, so a public
 * form would write straight in with nothing marking a submission as unreviewed.
 * field_status fixes that and defaults to `pending` — nothing a stranger types can
 * reach a public surface until the office approves it.
 *
 *   field_status           pending / approved / rejected   (default pending)
 *   field_work_order       optional attribution — which job the review is about
 *   field_submitter_email  optional, so the office can follow up or verify
 *
 * Idempotent.
 *   drush php:script web/scripts/setup_testimonial_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'testimonial';
$BUNDLE = 'client';

$fields = [
  'field_status' => [
    'type' => 'list_string', 'label' => 'Status', 'weight' => 0, 'default' => 'pending',
    'desc' => 'Submissions arrive as Pending. Only Approved testimonials may appear on a public surface.',
    'storage' => ['allowed_values' => ['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected']],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_work_order' => [
    'type' => 'entity_reference', 'label' => 'Work order', 'weight' => 8,
    'desc' => 'Which job this review is about, when the QR carried it. Optional.',
    'storage' => ['target_type' => 'work_order'],
    'instance' => ['handler' => 'default:work_order'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_submitter_email' => [
    'type' => 'email', 'label' => 'Submitter email', 'weight' => 9,
    'desc' => 'Optional — so the office can thank them or verify the review. Never published.',
    'widget' => 'email_default', 'formatter' => 'basic_string',
  ],
];

$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$view = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

foreach ($fields as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $sv = ['field_name' => $name, 'entity_type' => $ENTITY, 'type' => $def['type'], 'cardinality' => 1];
    if (!empty($def['storage'])) {
      $sv['settings'] = $def['storage'];
    }
    FieldStorageConfig::create($sv)->save();
    printf("  storage created: %-24s (%s)\n", $name, $def['type']);
  }
  else {
    printf("  storage exists:  %s\n", $name);
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $iv = [
      'field_name' => $name, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
      'label' => $def['label'], 'description' => $def['desc'], 'required' => FALSE,
    ];
    if (!empty($def['instance'])) {
      $iv['settings'] = $def['instance'];
    }
    if (isset($def['default'])) {
      $iv['default_value'] = [['value' => $def['default']]];
    }
    FieldConfig::create($iv)->save();
    printf("    instance created: %s\n", $name);
  }
  else {
    printf("    instance exists:  %s\n", $name);
  }
  $form->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $view->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}
$form->save();
$view->save();
print "  form + view displays saved\n";
print "DONE.\n";
