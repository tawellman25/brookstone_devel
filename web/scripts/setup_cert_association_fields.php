<?php

/**
 * Certifying association alongside the certification number.
 *   - profile.teammate_profile.field_certification_association (string) — the
 *     body that issued the tester's cert (e.g. "ABPA"). Travels with the number.
 *   - wo_tasks_list.backflow_testing.field_certification_association (string) —
 *     snapshot target (mirrors the cert-number snapshot; hidden on the form,
 *     shown on the report).
 * Seeds Todd's teammate_profile association = "ABPA".
 *
 * Idempotent; entity-API (no cim). Run per env.
 *   drush php:script web/scripts/setup_cert_association_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$out = [];

$ensure = function (string $entity, string $bundle, string $name, string $label, string $desc) use (&$out) {
  if (!FieldStorageConfig::loadByName($entity, $name)) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => $entity, 'type' => 'string', 'cardinality' => 1])->save();
    $out[] = "storage $entity.$name";
  }
  if (!FieldConfig::loadByName($entity, $bundle, $name)) {
    FieldConfig::create(['field_name' => $name, 'entity_type' => $entity, 'bundle' => $bundle, 'label' => $label, 'description' => $desc])->save();
    $out[] = "instance $entity.$bundle.$name";
  }
};

// Profile field (place beside the cert number on the teammate profile form).
$ensure('profile', 'teammate_profile', 'field_certification_association',
  'Certification Association', 'Certifying body that issued the certification number (e.g. ABPA, ASSE).');
$pfd = \Drupal::service('entity_display.repository')->getFormDisplay('profile', 'teammate_profile');
if (!$pfd->getComponent('field_certification_association')) {
  $w = ($pfd->getComponent('field_certification_number')['weight'] ?? 0) + 1;
  $pfd->setComponent('field_certification_association', ['type' => 'string_textfield', 'weight' => $w, 'region' => 'content'])->save();
  $out[] = 'profile form: association placed';
}

// Backflow test snapshot target (hidden on the form — auto-snapshotted).
$ensure('wo_tasks_list', 'backflow_testing', 'field_certification_association',
  'Certification Association', 'Snapshot of the tester\'s certifying body at test time.');

// Seed Todd's teammate_profile.
$profiles = \Drupal::entityTypeManager()->getStorage('profile')->loadByProperties(['uid' => 1, 'type' => 'teammate_profile']);
if ($profiles) {
  $p = reset($profiles);
  if ($p->hasField('field_certification_association') && $p->get('field_certification_association')->isEmpty()) {
    $p->set('field_certification_association', 'ABPA')->save();
    $out[] = 'seeded Todd association = ABPA';
  }
}

print implode("\n", $out) . "\nDONE.\n";
