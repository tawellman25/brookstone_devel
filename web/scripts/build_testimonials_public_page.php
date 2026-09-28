<?php

declare(strict_types=1);

/**
 * The public reviews page: /reviews — approved testimonials only.
 *
 * Filter field_status = approved with operator **`or`**: a list_string filter is
 * ManyToOne and has NO `in` operator, so `in` emits no SQL and the page would
 * silently show PENDING submissions — i.e. unreviewed text from strangers on a
 * public page. That is the one filter on this site where getting the operator
 * wrong is a content-safety bug, not a cosmetic one.
 *
 * Every field carries the FULL Views option set; a sparse definition survives
 * execute() and crashes on render.
 *
 * Idempotent; saved through the View ENTITY so postSave registers the route.
 *   drush php:script web/scripts/build_testimonials_public_page.php
 */

use Drupal\views\Entity\View;

$FD = [
  'label' => '', 'exclude' => FALSE,
  'alter' => [
    'alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE,
    'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE,
    'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '',
    'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE,
    'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE,
    'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE,
  ],
  'element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '',
  'element_label_colon' => TRUE, 'element_wrapper_type' => '', 'element_wrapper_class' => '',
  'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => TRUE, 'empty_zero' => FALSE,
  'hide_alter_empty' => TRUE, 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
  'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE,
  'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE,
  'click_sort_column' => 'value', 'settings' => [], 'type' => 'string',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'field',
];

$f = fn(string $n, string $label, string $type, string $class, array $x = []) => [
  'id' => $n, 'table' => 'testimonial__' . $n, 'field' => $n,
  'entity_type' => 'testimonial', 'entity_field' => $n,
  'label' => $label, 'type' => $type, 'element_class' => $class,
  'element_label_colon' => FALSE,
] + $x + $FD;

$HEADER = <<<'HTML'
<p class="reviews__intro">What customers have said about working with us. These are sent to us directly; you can also read and leave reviews on our Google profile.</p>
<p><a class="button" href="/review">Leave a review</a></p>
HTML;

$FOOTER = <<<'HTML'
<p class="reviews__outro">Worked with us and willing to say so? It genuinely helps — <a href="/review">leave a review</a>. It takes a minute, and you can post to Google or send it straight to us.</p>
HTML;

$view = View::load('testimonials');
if (!$view) {
  print "ERROR: view 'testimonials' not found — run build_testimonial_admin_view.php first.\n";
  return;
}
$display = $view->get('display');

$display['page_public'] = [
  'id' => 'page_public',
  'display_title' => 'Public reviews page',
  'display_plugin' => 'page',
  'position' => 3,
  'display_options' => [
    'defaults' => [
      'title' => FALSE, 'fields' => FALSE, 'filters' => FALSE, 'sorts' => FALSE,
      'style' => FALSE, 'row' => FALSE, 'pager' => FALSE, 'access' => FALSE,
      'header' => FALSE, 'footer' => FALSE, 'empty' => FALSE, 'css_class' => FALSE,
    ],
    'title' => 'Customer reviews',
    'path' => 'reviews',
    'css_class' => 'reviews',
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'pager' => ['type' => 'some', 'options' => ['items_per_page' => 30, 'offset' => 0]],
    'style' => ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => 'review-card', 'default_row_class' => TRUE, 'uses_fields' => FALSE]],
    'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => TRUE]],
    'fields' => [
      'field_testimony' => $f('field_testimony', '', 'text_default', 'review-card__quote'),
      'field_testimonial_by' => $f('field_testimonial_by', '', 'string', 'review-card__by'),
      'field_testimony_service' => $f('field_testimony_service', '', 'entity_reference_label', 'review-card__service', ['settings' => ['link' => TRUE]]),
      'field_testimonial_image' => $f('field_testimonial_image', '', 'image', 'review-card__img', ['settings' => ['image_style' => 'medium', 'image_link' => '']]),
    ],
    'filters' => [
      'type' => [
        'id' => 'type', 'table' => 'testimonial_field_data', 'field' => 'type',
        'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'testimonial',
        'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
        'value' => ['client' => 'client'], 'group' => 1,
      ],
      'field_status_value' => [
        'id' => 'field_status_value', 'table' => 'testimonial__field_status',
        'field' => 'field_status_value', 'relationship' => 'none', 'group_type' => 'group',
        'admin_label' => '', 'entity_type' => 'testimonial', 'entity_field' => 'field_status',
        // `or` = is one of. NOT `in` — see the docblock.
        'plugin_id' => 'list_field', 'operator' => 'or',
        'value' => ['approved' => 'approved'],
        'group' => 1, 'exposed' => FALSE, 'is_grouped' => FALSE, 'reduce_duplicates' => FALSE,
      ],
    ],
    'sorts' => [
      'created' => [
        'id' => 'created', 'table' => 'testimonial_field_data', 'field' => 'created',
        'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'testimonial',
        'entity_field' => 'created', 'plugin_id' => 'date', 'order' => 'DESC',
      ],
    ],
    'header' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
        'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text', 'empty' => TRUE,
        'content' => ['value' => $HEADER, 'format' => 'full_html'], 'tokenize' => FALSE,
      ],
    ],
    'footer' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
        'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text', 'empty' => TRUE,
        'content' => ['value' => $FOOTER, 'format' => 'full_html'], 'tokenize' => FALSE,
      ],
    ],
    // Honest empty state — the page publishes before the first approval lands.
    'empty' => [
      'area_text_custom' => [
        'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
        'relationship' => 'none', 'group_type' => 'group', 'plugin_id' => 'text_custom',
        'empty' => TRUE,
        'content' => '<p>We are just starting to collect these here. In the meantime, reviews on our Google profile are the best place to look — and if you have worked with us, <a href="/review">we would love yours</a>.</p>',
      ],
    ],
    'display_extenders' => [
      'metatag_display_extender' => [
        'metagags' => [],
        'metatags' => [
          'title' => 'Customer Reviews | Brookstone Outdoors',
          'description' => 'What customers across Delta and Montrose counties say about our landscaping, irrigation, spraying and snow removal work.',
        ],
      ],
    ],
  ],
];

$view->set('display', $display);
$view->save();
print "built display page_public at /reviews (filter: field_status = approved, operator 'or')\n";
print "DONE.\n";
