<?php

/**
 * Add field_handbook_version (string) to the handbook `cover` bundle and seed the
 * ROOT cover ("Team Handbook") with an initial version. Idempotent, entity-API
 * (ECK field configs silently skip on cim). Run per env.
 *
 * The value on the ROOT cover is the "current handbook version" the whole
 * acknowledgment feature keys off, and is the same version the 2nd Brain stamps
 * on the printed copy (keeps online + print aligned — see the handbook alignment
 * invariant in content_knowledge_entities.md). Bump it to require re-acknowledgment.
 *
 *   ddev drush php:script web/scripts/add_handbook_version_field.php   (local)
 *   drush php:script web/scripts/add_handbook_version_field.php        (live)
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$ENTITY = 'handbook';
$BUNDLE = 'cover';
$FIELD = 'field_handbook_version';
$INITIAL = 'v1.0 (Effective Aug 2026)';

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'type' => 'string',
    'cardinality' => 1, 'settings' => ['max_length' => 64],
  ])->save();
  print "storage created: $ENTITY.$FIELD\n";
}
else {
  print "storage exists: $ENTITY.$FIELD\n";
}

if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
    'label' => 'Handbook version / effective date', 'required' => FALSE,
    'description' => 'The current handbook version (set on the root "Team Handbook" cover). Bumping this prompts staff to re-acknowledge. Keep in step with the printed copy.',
  ])->save();
  print "instance created: $ENTITY.$BUNDLE.$FIELD\n";
}
else {
  print "instance exists: $ENTITY.$BUNDLE.$FIELD\n";
}

$repo = \Drupal::service('entity_display.repository');
$repo->getFormDisplay($ENTITY, $BUNDLE, 'default')
  ->setComponent($FIELD, ['type' => 'string_textfield', 'weight' => -5, 'region' => 'content'])->save();
$repo->getViewDisplay($ENTITY, $BUNDLE, 'default')
  ->setComponent($FIELD, ['type' => 'string', 'weight' => -5, 'label' => 'inline', 'region' => 'content'])->save();
print "form + view displays updated\n";

// Seed the ROOT cover (no parent — the "Team Handbook") if it has no version yet.
$storage = \Drupal::entityTypeManager()->getStorage('handbook');
$root_ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'cover')->notExists('field_parent_page')->sort('id')->execute();
if ($root_ids) {
  $root = $storage->load(reset($root_ids));
  if ($root->get($FIELD)->isEmpty()) {
    $root->set($FIELD, $INITIAL)->save();
    printf("seeded root cover [%d] \"%s\" → %s\n", $root->id(), $root->label(), $INITIAL);
  }
  else {
    printf("root cover [%d] already has version: %s\n", $root->id(), $root->get($FIELD)->value);
  }
}
else {
  print "WARNING: no root cover found — set the version manually.\n";
}

print "Done.\n";
