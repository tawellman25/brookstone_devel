<?php

declare(strict_types=1);

/**
 * field_service_office_desc — office-only copy on a services term.
 *
 * The services vocabulary already tiers public (field_service_public_desc) and
 * crew (field_service_crew_desc). There is no third tier, so office-only
 * material — margins, cost basis, anything a customer or a crew member looking
 * over a shoulder should not read — has nowhere to live except a field that is
 * on the admin_view display and NOTHING else.
 *
 * Built for the holiday lighting margin tables, which the field sheet marks
 * "do not hand this sheet to a customer", but the field is general: any service
 * can carry office-only notes.
 *
 * ⚠ This is DISPLAY tiering, not access control. It keeps the text off the
 * public and crew renders of the term page. It is not a guarantee against
 * JSON:API or a View, which render fields by their own rules — the lesson from
 * 2026-10-04. Nothing truly sensitive (a policy number, a credential) belongs
 * here; that needs hook_entity_field_access, as material.module does.
 *
 *   drush php:script web/scripts/setup_service_office_desc_field.php
 *   BOS_OFFICE_DESC_APPLY=1 drush php:script web/scripts/setup_service_office_desc_field.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$apply = getenv('BOS_OFFICE_DESC_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_OFFICE_DESC_APPLY=1 to write)\n\n";

$FIELD = 'field_service_office_desc';
$ENTITY = 'taxonomy_term';
$BUNDLE = 'services';
$ONLY_ON = 'taxonomy_term.services.admin_view';

$etm = \Drupal::entityTypeManager();

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  print "+ storage   $FIELD (text_long, cardinality 1)\n";
  if ($apply) {
    FieldStorageConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $ENTITY,
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
  }
}
else {
  print "  storage   already exists\n";
}

if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  print "+ instance  \"Office Only Notes\" on services\n";
  if ($apply) {
    FieldConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => 'Office Only Notes',
      'required' => FALSE,
      'description' => 'Shown ONLY on the office/admin view of this service page. Never rendered to the public or to crews. Margins, cost basis, pricing rationale.',
      // Full HTML to match the other two copy fields, so pasted markup renders
      // rather than escaping (the 2026-10-03 allowed_formats lesson).
      'settings' => ['allowed_formats' => ['full_html']],
    ])->save();
  }
}
else {
  print "  instance  already exists\n";
}

// Form: beneath the crew description.
$fd = $etm->getStorage('entity_form_display')->load("$ENTITY.$BUNDLE.default");
if ($fd && !$fd->getComponent($FIELD)) {
  print "+ form      textarea, after the crew description\n";
  if ($apply) {
    $after = $fd->getComponent('field_service_crew_desc')['weight'] ?? 50;
    $fd->setComponent($FIELD, ['type' => 'text_textarea', 'weight' => $after + 1, 'settings' => ['rows' => 10]])->save();
  }
}
elseif ($fd) {
  print "  form      already present\n";
}

// admin_view ONLY, and actively removed from every other display.
foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $vd) {
  if (strpos($id, "$ENTITY.$BUNDLE.") !== 0) {
    continue;
  }
  $has = (bool) $vd->getComponent($FIELD);
  if ($id === $ONLY_ON && !$has) {
    print "+ display   added to $id\n";
    if ($apply) {
      $vd->setComponent($FIELD, ['type' => 'text_default', 'label' => 'above', 'weight' => 99, 'region' => 'content'])->save();
    }
  }
  elseif ($id !== $ONLY_ON && $has) {
    print "- display   REMOVING from $id (office-only)\n";
    if ($apply) {
      $vd->removeComponent($FIELD)->save();
    }
  }
}

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}
print "\nDone — office notes render on admin_view and nowhere else.\n";
