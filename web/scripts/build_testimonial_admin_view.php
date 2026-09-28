<?php

declare(strict_types=1);

/**
 * Office moderation list for testimonials.
 *
 *   /admin/operations/system_content/testimonials            all, newest first
 *   /admin/operations/system_content/testimonials/pending    tab: awaiting review
 *
 * Same wiring as its siblings under that path (menu `admin`, type `normal`), and
 * the pending filter uses operator **`or`** — a list_string Views filter is
 * ManyToOne and has NO `in` operator, so `in` would emit no SQL at all and the tab
 * would silently show everything (see Governance/drupal_bos_gotchas.md).
 *
 * Every field carries the FULL Views option set: a sparse definition survives
 * execute() and then crashes on render.
 *
 * Idempotent; saved through the View ENTITY so postSave registers the routes.
 *   drush php:script web/scripts/build_testimonial_admin_view.php
 */

use Drupal\user\Entity\Role;
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
  'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE,
  'hide_alter_empty' => TRUE, 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
  'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE,
  'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE,
  'click_sort_column' => 'value', 'settings' => [], 'type' => 'string',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'field',
];

$f = fn(string $n, string $label, string $type = 'string', array $x = []) => [
  'id' => $n, 'table' => 'testimonial__' . $n, 'field' => $n,
  'entity_type' => 'testimonial', 'entity_field' => $n, 'label' => $label, 'type' => $type,
] + $x + $FD;

$fields = [
  'title' => [
    'id' => 'title', 'table' => 'testimonial_field_data', 'field' => 'title',
    'entity_type' => 'testimonial', 'entity_field' => 'title', 'label' => 'From',
    'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
  ] + $FD,
  'field_status' => $f('field_status', 'Status', 'list_default'),
  'field_testimony' => $f('field_testimony', 'Review', 'text_default', [
    // Trimmed so the admin table stays scannable (Views-pattern convention).
    'alter' => ['trim' => TRUE, 'max_length' => 200, 'word_boundary' => TRUE, 'ellipsis' => TRUE,
      'alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE,
      'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE,
      'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '',
      'nl2br' => FALSE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '',
      'strip_tags' => TRUE, 'preserve_tags' => '', 'html' => FALSE],
  ]),
  'field_testimony_service' => $f('field_testimony_service', 'Service', 'entity_reference_label', ['settings' => ['link' => FALSE]]),
  'field_work_order' => $f('field_work_order', 'Work order', 'entity_reference_label', ['settings' => ['link' => TRUE]]),
  'field_submitter_email' => $f('field_submitter_email', 'Email', 'basic_string'),
  'created' => [
    'id' => 'created', 'table' => 'testimonial_field_data', 'field' => 'created',
    'entity_type' => 'testimonial', 'entity_field' => 'created', 'label' => 'Received',
    'plugin_id' => 'field', 'type' => 'timestamp',
    'settings' => ['date_format' => 'custom', 'custom_date_format' => 'm/d/Y g:i A', 'timezone' => ''],
  ] + $FD,
];

$bundleFilter = [
  'id' => 'type', 'table' => 'testimonial_field_data', 'field' => 'type',
  'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'testimonial',
  'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
  'value' => ['client' => 'client'], 'group' => 1,
];
$sortNewest = [
  'id' => 'created', 'table' => 'testimonial_field_data', 'field' => 'created',
  'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'testimonial',
  'entity_field' => 'created', 'plugin_id' => 'date', 'order' => 'DESC',
];

$displays = [
  'default' => [
    'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
    'display_options' => [
      'title' => 'Testimonials',
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access testimonial entity listing']],
      'cache' => ['type' => 'tag', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => []],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
      'style' => ['type' => 'table', 'options' => []],
      'row' => ['type' => 'fields', 'options' => []],
      'fields' => $fields,
      'filters' => ['type' => $bundleFilter],
      'sorts' => ['created' => $sortNewest],
      'empty' => [
        'area_text_custom' => [
          'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
          'relationship' => 'none', 'group_type' => 'group', 'plugin_id' => 'text_custom',
          'empty' => TRUE,
          'content' => 'No testimonials yet. The review page is at <a href="/review">/review</a>; print its QR from the tab above.',
        ],
      ],
    ],
  ],
  'page_all' => [
    'id' => 'page_all', 'display_title' => 'All', 'display_plugin' => 'page', 'position' => 1,
    'display_options' => [
      'defaults' => ['title' => FALSE],
      'title' => 'Testimonials',
      'path' => 'admin/operations/system_content/testimonials',
      'menu' => [
        'type' => 'normal', 'title' => 'Testimonials',
        'description' => 'Customer reviews submitted through /review — approve before they appear anywhere public.',
        'weight' => 35, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
      ],
    ],
  ],
  'page_pending' => [
    'id' => 'page_pending', 'display_title' => 'Pending', 'display_plugin' => 'page', 'position' => 2,
    'display_options' => [
      'defaults' => ['title' => FALSE, 'filters' => FALSE],
      'title' => 'Testimonials awaiting review',
      'path' => 'admin/operations/system_content/testimonials/pending',
      'filters' => [
        'type' => $bundleFilter,
        'field_status_value' => [
          'id' => 'field_status_value', 'table' => 'testimonial__field_status',
          'field' => 'field_status_value', 'relationship' => 'none', 'group_type' => 'group',
          'admin_label' => '', 'entity_type' => 'testimonial', 'entity_field' => 'field_status',
          // `or` = "is one of". There is NO `in` operator on a ManyToOne filter.
          'plugin_id' => 'list_field', 'operator' => 'or',
          'value' => ['pending' => 'pending'],
          'group' => 1, 'exposed' => FALSE, 'is_grouped' => FALSE, 'reduce_duplicates' => FALSE,
        ],
      ],
      'menu' => [
        'type' => 'tab', 'title' => 'Pending', 'description' => 'Submissions not yet approved or rejected.',
        'weight' => 10, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
      ],
    ],
  ],
];

$view = View::load('testimonials');
if (!$view) {
  $view = View::create([
    'id' => 'testimonials', 'label' => 'Testimonials', 'module' => 'views',
    'description' => 'Office moderation of customer reviews submitted through /review.',
    'base_table' => 'testimonial_field_data', 'base_field' => 'id',
    'display' => $displays,
  ]);
}
else {
  $view->set('display', $displays);
}
$view->save();
print "saved view 'testimonials' (page_all + page_pending)\n";

/* Office roles need the listing + edit permissions to moderate. */
foreach (['administration', 'supervisor', 'site_assistant', 'site_admin'] as $rid) {
  $role = Role::load($rid);
  if (!$role) { continue; }
  $added = [];
  foreach (['access testimonial entity listing', 'view any testimonial entities', 'edit any testimonial entities'] as $p) {
    if (!$role->hasPermission($p)) { $role->grantPermission($p); $added[] = $p; }
  }
  if ($added) { $role->save(); printf("  %-16s + %s\n", $rid, implode(', ', $added)); }
}
print "DONE.\n";
