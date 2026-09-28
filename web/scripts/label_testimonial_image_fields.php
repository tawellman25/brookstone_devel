<?php

declare(strict_types=1);

/**
 * Make it obvious which testimonial image is public.
 *
 * The bundle now carries two image fields that behave oppositely: one renders
 * on /about-us/reviews, the other never leaves the office. They were labelled
 * "Example Image" and "Scan of the original note", with no description on the
 * public one — so the field whose contents go on the website read as the more
 * incidental of the two.
 *
 * Office policy is to crop or blur a handwritten note before publishing it.
 * That instruction belongs on the field where the upload happens, not only in
 * an SOP nobody has open at the time.
 *
 * Labels and descriptions only — no storage, cardinality or access change.
 * Idempotent; run per environment.
 *
 *   drush php:script web/scripts/label_testimonial_image_fields.php
 */

$wanted = [
  'field_testimonial_image' => [
    'label' => 'Public image',
    'description' => 'Shown on the public reviews page. If this is a photo of a handwritten note, crop or blur the signature and any address, phone number or full name FIRST — whatever is in this image goes on the website exactly as uploaded. To keep the original card as an internal record instead, use "Scan of the original note" below.',
  ],
  'field_testimony' => [
    'label' => 'Testimony',
    'description' => 'The words of the review, as published. Type them out even when the original is handwritten — this is what the public reads, and what search engines and screen readers can read. An image of text is invisible to both.',
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('field_config');
$changed = 0;

foreach ($wanted as $name => $want) {
  $field = $storage->load('testimonial.client.' . $name);
  if (!$field) {
    print "  MISS   $name — not on testimonial.client\n";
    continue;
  }
  if ($field->getLabel() === $want['label'] && $field->getDescription() === $want['description']) {
    print "  ok     $name (already set)\n";
    continue;
  }
  printf("  set    %s: \"%s\" -> \"%s\"\n", $name, $field->getLabel(), $want['label']);
  $field->setLabel($want['label'])->setDescription($want['description'])->save();
  $changed++;
}

printf("DONE (%d changed).\n", $changed);
