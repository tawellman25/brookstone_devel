<?php

/**
 * Relabel the core taxonomy `description` field to "Public Description" on the
 * given vocabularies (bundle-scoped base-field override — affects the edit form
 * AND every display for that bundle only; other taxonomies keep "Description").
 *
 * Part of the public-page audience-tier pattern: the Public View shows a
 * public-facing description, and this makes the field name say so.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/relabel_term_description_public.php
 */

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

$VOCABS = ['equipment_types'];
$LABEL = 'Public Description';

$efm = \Drupal::service('entity_field.manager');
$out = [];
foreach ($VOCABS as $vid) {
  $defs = $efm->getFieldDefinitions('taxonomy_term', $vid);
  if (empty($defs['description'])) {
    $out[] = "SKIP $vid — no description field";
    continue;
  }
  $override = BaseFieldOverride::loadByName('taxonomy_term', $vid, 'description')
    ?: BaseFieldOverride::createFromBaseFieldDefinition($defs['description'], $vid);
  if ($override->getLabel() !== $LABEL) {
    $override->setLabel($LABEL)->save();
    $out[] = "$vid: description relabeled -> $LABEL";
  }
  else {
    $out[] = "$vid: already \"$LABEL\"";
  }
}
print implode("\n", $out) . "\nDONE.\n";
