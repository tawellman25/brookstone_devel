<?php
/**
 * Make /services render its top-level services as the card component.
 *
 * The landing page listed them as a linked name over a 300-character slab of
 * trimmed body — the same services that appear as cards on every other surface.
 * This points it at the shared component: `bo-service-cards` on the wrapper,
 * `service-card` on each row, and the iconic image strip added as the first
 * field.
 *
 * Three things deliberately NOT changed:
 *
 *  - The header area. The intro copy and the "Free estimates" CTA stay exactly
 *    as they are; the component CSS spans the header across the grid.
 *  - `name`, which already uses term_name + link_to_entity, matching the
 *    component.
 *  - The blurb is `text_summary_or_trimmed`, NOT the summary-only rule the
 *    other card views use. Landscaping and Lighting have no summary written, so
 *    summary-only would blank their card text on a live page. Summary where one
 *    exists, a short trim where it does not.
 *
 * Idempotent; dry run unless BOS_SVCCARDS_APPLY=1.
 */
$apply = getenv('BOS_SVCCARDS_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_SVCCARDS_APPLY=1 to apply)');

$view = \Drupal::entityTypeManager()->getStorage('view')->load('services');
if (!$view) {
  print "  ABORT: the services view is missing.\n";
  return;
}
$display = $view->get('display');
$o = &$display['default']['display_options'];

// --- wrapper + row classes ---------------------------------------------
$classes = preg_split('/\s+/', trim($o['css_class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
if (!in_array('bo-service-cards', $classes, TRUE)) {
  $classes[] = 'bo-service-cards';
}
printf("  css_class:  %s\n", implode(' ', $classes));
$o['css_class'] = implode(' ', $classes);

$o['style']['options']['row_class'] = 'service-card';
$o['style']['options']['default_row_class'] = TRUE;
printf("  row_class:  service-card (style: %s)\n", $o['style']['type']);

// --- the iconic image strip, first ------------------------------------
if (isset($o['fields']['field_iconic_image'])) {
  print "  iconic image: already a field\n";
}
else {
  print "  iconic image: ADDING as the first field (service_card_strip)\n";
}
$icon = [
  'id' => 'field_iconic_image', 'table' => 'taxonomy_term__field_iconic_image',
  'field' => 'field_iconic_image', 'relationship' => 'none', 'plugin_id' => 'field',
  'label' => '', 'exclude' => FALSE, 'type' => 'image',
  'settings' => ['image_style' => 'service_card_strip', 'image_link' => 'content'],
];
// Field ORDER is the render order, so rebuild the array with the image first.
$fields = ['field_iconic_image' => $icon];
foreach ($o['fields'] as $id => $f) {
  if ($id !== 'field_iconic_image') {
    $fields[$id] = $f;
  }
}
$o['fields'] = $fields;

// --- card-length blurb, with a fallback so nothing blanks --------------
if (isset($o['fields']['field_service_public_desc'])) {
  $was = $o['fields']['field_service_public_desc']['type'] ?? '?';
  $wasLen = $o['fields']['field_service_public_desc']['settings']['trim_length'] ?? '?';
  $o['fields']['field_service_public_desc']['type'] = 'text_summary_or_trimmed';
  $o['fields']['field_service_public_desc']['settings'] = ['trim_length' => 140];
  printf("  blurb:      %s(%s) -> text_summary_or_trimmed(140)\n", $was, $wasLen);
}

printf("  fields now: %s\n", implode(', ', array_keys($o['fields'])));
unset($o);

if ($apply) {
  $view->set('display', $display)->save();
}
print $apply ? "\ndone\n" : "\nnothing written\n";
