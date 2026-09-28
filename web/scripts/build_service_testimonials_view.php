<?php

declare(strict_types=1);

/**
 * Show a service's own approved reviews on its public service page.
 *
 * A review already records which service it is about (field_testimony_service),
 * so the one on live — "Repair" — belongs on /services/sprinkler-system/repair,
 * where someone deciding whether to call us is actually standing. That is worth
 * more than the same quote sitting only on a reviews page nobody navigates to.
 *
 * Built as an EVA on taxonomy_term:services, exactly like service_children:
 * the term id is passed as the contextual argument, so it needs no per-term
 * configuration and lights up for every service as reviews arrive.
 *
 * EXACT MATCH ONLY. A Repair review shows on Repair, not on its parent
 * Sprinkler Systems. Rolling up to a parent would mean feeding the argument a
 * term plus its descendants from a hook (the equipment_similar approach) — a
 * deliberate next step, not a silent default, since a parent page quietly
 * claiming its children's reviews is a different promise to the reader.
 *
 * Filters match the public reviews page: bundle client, status approved,
 * published. Operator on the status filter is 'or', NOT 'in' — a list_string
 * filter is ManyToOne, which has no 'in' operator, and the difference is that
 * 'in' emits no SQL at all and would publish every pending review.
 *
 * Idempotent; run per environment.
 *
 *   drush php:script web/scripts/build_service_testimonials_view.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('view');
$source = $storage->load('testimonials');
if (!$source) {
  print "ABORT: testimonials view not found to copy field definitions from\n";
  return;
}
// Reuse the public page's field definitions verbatim — they are complete, and a
// sparse field definition crashes on RENDER rather than on execute().
$fields = $source->get('display')['page_site']['display_options']['fields'];

$display_options = [
  'title' => 'Reviews',
  'fields' => $fields,
  'pager' => [
    'type' => 'some',
    'options' => ['offset' => 0, 'items_per_page' => 6],
  ],
  'style' => [
    'type' => 'default',
    'options' => [
      'grouping' => [],
      'row_class' => 'review-card',
      'default_row_class' => TRUE,
      'uses_fields' => FALSE,
    ],
  ],
  'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
  'css_class' => 'reviews service-reviews',
  'filters' => [
    'type' => [
      'id' => 'type',
      'table' => 'testimonial_field_data',
      'field' => 'type',
      'entity_type' => 'testimonial',
      'entity_field' => 'type',
      'plugin_id' => 'bundle',
      'operator' => 'in',
      'value' => ['client' => 'client'],
    ],
    'status' => [
      'id' => 'status',
      'table' => 'testimonial_field_data',
      'field' => 'status',
      'entity_type' => 'testimonial',
      'entity_field' => 'status',
      'plugin_id' => 'boolean',
      'operator' => '=',
      'value' => '1',
    ],
    'field_status_value' => [
      'id' => 'field_status_value',
      'table' => 'testimonial__field_status',
      'field' => 'field_status_value',
      'plugin_id' => 'list_field',
      // 'or', never 'in' — see the note at the top of this file.
      'operator' => 'or',
      'value' => ['approved' => 'approved'],
    ],
  ],
  'sorts' => [
    'created' => [
      'id' => 'created',
      'table' => 'testimonial_field_data',
      'field' => 'created',
      'entity_type' => 'testimonial',
      'entity_field' => 'created',
      'plugin_id' => 'date',
      'order' => 'DESC',
    ],
  ],
  'arguments' => [
    'field_testimony_service_target_id' => [
      'id' => 'field_testimony_service_target_id',
      'table' => 'testimonial__field_testimony_service',
      'field' => 'field_testimony_service_target_id',
      'plugin_id' => 'numeric',
      // No argument means no service: show nothing rather than everything.
      'default_action' => 'empty',
      'exception' => ['value' => '', 'title_enable' => FALSE],
      'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE,
    ],
  ],
  'header' => [
    'area_text_custom' => [
      'id' => 'area_text_custom',
      'table' => 'views',
      'field' => 'area_text_custom',
      'plugin_id' => 'text_custom',
      // Rewritten per-term in bos_testimonial_views_pre_render().
      'content' => 'What customers say',
      'empty' => FALSE,
    ],
  ],
  'use_more' => FALSE,
  'display_extenders' => [],
];

$view = $storage->load('service_testimonials');
if (!$view) {
  $view = $storage->create([
    'id' => 'service_testimonials',
    'label' => 'Service testimonials',
    'description' => 'Approved reviews for a service, shown on that service page.',
    'base_table' => 'testimonial_field_data',
    'base_field' => 'id',
    'display' => [],
  ]);
  print "creating view\n";
}
else {
  print "updating view\n";
}

$view->set('display', [
  'default' => [
    'display_plugin' => 'default',
    'id' => 'default',
    'display_title' => 'Default',
    'position' => 0,
    'display_options' => $display_options,
  ],
  'entity_view_1' => [
    'display_plugin' => 'entity_view',
    'id' => 'entity_view_1',
    'display_title' => 'Service page',
    'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'entity_type' => 'taxonomy_term',
      'bundles' => ['services'],
      // The term's own id becomes the contextual argument.
      'argument_mode' => 'id',
      'default_argument' => '',
    ],
  ],
]);
$view->save();

print "saved service_testimonials (EVA on taxonomy_term:services)\n";
print "DONE.\n";
