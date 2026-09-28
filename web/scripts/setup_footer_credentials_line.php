<?php

declare(strict_types=1);

/**
 * Add the "Licensed, Bonded & Insured" trust line to the footer company block.
 *
 * Stored as a FIELD, not hardcoded in the template, because the whole footer is
 * fielded so the office can edit it without a deploy — including changing this
 * wording later (e.g. to "Licensed & Insured") with no code change.
 *
 * The template links it to /about-us/credentials, so the claim is one click from
 * the records that substantiate it, which is the point of that page.
 *
 * Idempotent; never overwrites an existing value.
 *   drush php:script web/scripts/setup_footer_credentials_line.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'block_content';
$BUNDLE = 'footer_company';
$FIELD = 'field_fc_credentials';
$TEXT = 'Licensed, Bonded & Insured';

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'type' => 'string', 'cardinality' => 1,
  ])->save();
  print "created storage $ENTITY.$FIELD\n";
}
else {
  print "storage exists\n";
}

if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
    'label' => 'Credentials trust line',
    'description' => 'Short trust line under the tagline, e.g. "Licensed, Bonded & Insured". Rendered as a link to /about-us/credentials. Leave empty to hide it.',
    'required' => FALSE,
  ])->save();
  print "created instance\n";
}
else {
  print "instance exists\n";
}

$form = \Drupal::service('entity_display.repository')->getFormDisplay($ENTITY, $BUNDLE, 'default');
if (!$form->getComponent($FIELD)) {
  $form->setComponent($FIELD, ['type' => 'string_textfield', 'weight' => 3, 'region' => 'content'])->save();
  print "added to the block form\n";
}

/* Set the value on the live footer block, without clobbering an edit. */
$blocks = \Drupal::entityTypeManager()->getStorage('block_content')
  ->loadByProperties(['type' => $BUNDLE]);
foreach ($blocks as $block) {
  $current = trim((string) ($block->get($FIELD)->value ?? ''));
  if ($current === '') {
    $block->set($FIELD, $TEXT);
    $block->save();
    printf("  set on block %s: \"%s\"\n", $block->id(), $TEXT);
  }
  else {
    printf("  block %s already reads \"%s\" — left alone\n", $block->id(), $current);
  }
}
print "DONE.\n";
