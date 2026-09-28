<?php

declare(strict_types=1);

/**
 * A place to keep the original handwritten note behind a testimonial.
 *
 * Office types the words into field_testimony — that is what publishes, what
 * Google can read and what a screen reader reads; an image of text is invisible
 * to both. The photo of the card goes here as proof of where the quote came
 * from, and stays internal: a handwritten note usually carries a signature, and
 * often a name, address or phone number that the customer never agreed to have
 * published.
 *
 * Deliberately NOT field_testimonial_image — that one renders on the public
 * /about-us/reviews page. If a particular note is worth showing off, office can
 * crop it and put that crop there on purpose.
 *
 * Multi-value: notes run onto a second page, and some arrive as a card plus an
 * envelope.
 *
 * Access is enforced in code (bos_testimonial_entity_field_access), not by
 * leaving the field off a display — display is presentation, not access.
 *
 * Idempotent. ECK/field configs silently skip on cim, so this script is the
 * deploy path: run per environment.
 *
 *   drush php:script web/scripts/setup_testimonial_scan_field.php
 */

const FIELD = 'field_testimonial_scan';

$efm = \Drupal::service('entity_field.manager');
$storage_cfg = \Drupal::entityTypeManager()->getStorage('field_storage_config');
$field_cfg = \Drupal::entityTypeManager()->getStorage('field_config');

// --- storage ---------------------------------------------------------------
if ($storage_cfg->load('testimonial.' . FIELD)) {
  print "storage exists\n";
}
else {
  $storage_cfg->create([
    'field_name' => FIELD,
    'entity_type' => 'testimonial',
    'type' => 'image',
    'cardinality' => -1,
  ])->save();
  print "storage created\n";
}

// --- instance --------------------------------------------------------------
if ($field_cfg->load('testimonial.client.' . FIELD)) {
  print "instance exists\n";
}
else {
  $field_cfg->create([
    'field_name' => FIELD,
    'entity_type' => 'testimonial',
    'bundle' => 'client',
    'label' => 'Scan of the original note',
    'description' => 'Photo of the handwritten card or letter this quote came from. Internal only — office and admin see it, the public never does. Type the words into "Testimony" above; that is what gets published.',
    'required' => FALSE,
    'settings' => [
      'file_directory' => 'testimonials/scans/[date:custom:Y]',
      'alt_field' => FALSE,
      'alt_field_required' => FALSE,
      'title_field' => FALSE,
      'max_filesize' => '',
      'file_extensions' => 'png jpg jpeg heic webp pdf',
    ],
  ])->save();
  print "instance created\n";
}

// --- form display ----------------------------------------------------------
$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')
  ->load('testimonial.client.default');
if ($form && !$form->getComponent(FIELD)) {
  $form->setComponent(FIELD, [
    'type' => 'image_image',
    'weight' => 20,
    'region' => 'content',
    'settings' => ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail'],
  ])->save();
  print "added to form display\n";
}
else {
  print "form display already has it\n";
}

// --- view display (office entity page; field access keeps it internal) ------
$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')
  ->load('testimonial.client.default');
if ($view && !$view->getComponent(FIELD)) {
  $view->setComponent(FIELD, [
    'type' => 'image',
    'weight' => 20,
    'region' => 'content',
    'label' => 'above',
    'settings' => ['image_style' => 'medium', 'image_link' => 'file'],
  ])->save();
  print "added to default view display\n";
}
else {
  print "view display already has it\n";
}

print "DONE.\n";
