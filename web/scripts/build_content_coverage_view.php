<?php

/**
 * Build the content coverage report view.
 *
 * Built as a View ENTITY by script rather than hand-written YAML and a partial
 * cim, for two reasons documented in this repo: a Views page display created
 * from raw config leaves its route unregistered (the view 404s until the entity
 * is saved so postSave rebuilds routes), and a view-only cim would not carry the
 * permission grant. Idempotent — deletes and rebuilds, so re-running is safe.
 *
 * Usage: drush php:script web/scripts/build_content_coverage_view.php
 */

use Drupal\views\Entity\View;
use Drupal\bos_content_coverage\ContentCoverage;

$storage = \Drupal::entityTypeManager()->getStorage('view');
$coverage = \Drupal::service('bos_content_coverage.coverage');
$vids = $coverage->coveredVids();

if ($existing = $storage->load('bos_content_coverage')) {
  $existing->delete();
  print "removed the previous view\n";
}

$field = function (string $column, string $label, int $weight): array {
  return [
    'id' => 'bos_coverage_column' . ($weight ? '_' . $weight : ''),
    'table' => 'taxonomy_term_field_data',
    'field' => 'bos_coverage_column',
    'plugin_id' => 'bos_coverage_column',
    'label' => $label,
    'coverage_column' => $column,
    'exclude' => FALSE,
  ];
};

$fields = [
  'name' => [
    'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name',
    'plugin_id' => 'term_name', 'label' => 'Term', 'settings' => ['link_to_entity' => TRUE],
  ],
  'vid' => [
    'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid',
    'plugin_id' => 'field', 'label' => 'Vocabulary', 'type' => 'entity_reference_label',
    'settings' => ['link' => FALSE],
  ],
  'bos_coverage_column' => $field('parent', 'Parent', 0),
  'bos_coverage_column_1' => $field('alias', 'Live URL alias', 1),
  'bos_coverage_column_2' => $field('teaser', 'Teaser', 2),
  'bos_coverage_column_3' => $field('body', 'Public description', 3),
  'bos_coverage_column_4' => $field('cta', 'Call to action', 4),
  'bos_coverage_column_5' => $field('crew', 'Crew description', 5),
  'bos_coverage_column_6' => $field('flag', 'Boilerplate', 6),
  'changed' => [
    'id' => 'changed', 'table' => 'taxonomy_term_field_data', 'field' => 'changed',
    'plugin_id' => 'field', 'label' => 'Changed', 'type' => 'timestamp',
    'settings' => ['date_format' => 'custom', 'custom_date_format' => 'm/d/Y', 'timezone' => ''],
  ],
];
// The ids inside each definition must match their array key.
foreach ($fields as $key => $def) {
  $fields[$key]['id'] = $key;
}

$view = View::create([
  'id' => 'bos_content_coverage',
  'label' => 'Content coverage',
  'module' => 'views',
  'description' => 'What public copy is actually live on every public taxonomy term: populated or empty, character counts, the live URL alias, and a boilerplate flag.',
  'base_table' => 'taxonomy_term_field_data',
  'base_field' => 'tid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => [
        'title' => 'Content coverage',
        'access' => ['type' => 'perm', 'options' => ['perm' => 'view content coverage report']],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => FALSE]],
        'style' => ['type' => 'table', 'options' => [
          'sticky' => TRUE,
          'columns' => array_combine(array_keys($fields), array_keys($fields)),
          'default' => 'vid',
          'info' => array_map(fn($k) => [
            'sortable' => in_array($k, ['name', 'vid', 'changed'], TRUE),
            'default_sort_order' => 'asc',
            'align' => '',
            'separator' => '',
            'empty_column' => FALSE,
            'responsive' => '',
          ], array_combine(array_keys($fields), array_keys($fields))),
          'empty_table' => TRUE,
        ]],
        'row' => ['type' => 'fields', 'options' => []],
        'fields' => $fields,
        'filters' => [
          // TWO vid filters, and the distinction is load-bearing.
          //
          // This one is NOT exposed: it hard-scopes the report to the public
          // vocabularies. An EXPOSED filter with no input supplied does not
          // filter at all — that is what returned 995 rows instead of 355 on the
          // first build, with no WHERE clause in the generated SQL — so the
          // scope guarantee cannot live on an exposed filter. ~1,300 operational
          // term pages must never appear here. Same non-exposed-floor pattern as
          // the billing views' status floor.
          'vid' => [
            'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid',
            'plugin_id' => 'bundle', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid',
            // 'in', because views' Bundle filter extends InOperator (NOT
            // ManyToOne). The operator a filter accepts comes from its base
            // class, so the rule recorded for list_string filters — where only
            // or/and/not emit SQL — does not transfer here; 'or' on this plugin
            // silently emits nothing, which cost two rebuilds to find.
            'operator' => 'in',
            'value' => array_combine($vids, $vids),
            'group' => 1,
            'exposed' => FALSE,
          ],
          // This one IS exposed, for narrowing to a vocabulary. It starts empty,
          // which correctly means "all of the scoped set".
          'vid_1' => [
            'id' => 'vid_1', 'table' => 'taxonomy_term_field_data', 'field' => 'vid',
            'plugin_id' => 'bundle', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid',
            'operator' => 'in',
            'value' => [],
            'group' => 1,
            'exposed' => TRUE,
            'expose' => [
              'operator_id' => 'vid_1_op',
              'label' => 'Vocabulary',
              'description' => '',
              'use_operator' => FALSE,
              'operator' => 'vid_1_op',
              'operator_limit_selection' => FALSE,
              'operator_list' => [],
              'identifier' => 'vocab',
              'required' => FALSE,
              'remember' => FALSE,
              'multiple' => TRUE,
              'remember_roles' => ['authenticated' => 'authenticated'],
              'reduce' => TRUE,
            ],
            'is_grouped' => FALSE,
            'group_info' => [],
          ],
          'bos_coverage_question' => [
            'id' => 'bos_coverage_question', 'table' => 'taxonomy_term_field_data',
            'field' => 'bos_coverage_question', 'plugin_id' => 'bos_coverage_question',
            'coverage_question' => 'boilerplate', 'operator' => '=', 'value' => 'All',
            'group' => 1, 'exposed' => TRUE,
            'expose' => ['operator_id' => '', 'label' => 'Carries boilerplate', 'identifier' => 'boilerplate'],
          ],
          'bos_coverage_question_1' => [
            'id' => 'bos_coverage_question_1', 'table' => 'taxonomy_term_field_data',
            'field' => 'bos_coverage_question', 'plugin_id' => 'bos_coverage_question',
            'coverage_question' => 'missing_body', 'operator' => '=', 'value' => 'All',
            'group' => 1, 'exposed' => TRUE,
            'expose' => ['operator_id' => '', 'label' => 'Missing public description', 'identifier' => 'missing_body'],
          ],
          'bos_coverage_question_2' => [
            'id' => 'bos_coverage_question_2', 'table' => 'taxonomy_term_field_data',
            'field' => 'bos_coverage_question', 'plugin_id' => 'bos_coverage_question',
            'coverage_question' => 'missing_teaser', 'operator' => '=', 'value' => 'All',
            'group' => 1, 'exposed' => TRUE,
            'expose' => ['operator_id' => '', 'label' => 'Missing teaser', 'identifier' => 'missing_teaser'],
          ],
          'changed' => [
            'id' => 'changed', 'table' => 'taxonomy_term_field_data', 'field' => 'changed',
            'plugin_id' => 'date', 'entity_type' => 'taxonomy_term', 'entity_field' => 'changed',
            'operator' => '<', 'value' => ['type' => 'date', 'value' => '', 'min' => '', 'max' => ''],
            'group' => 1, 'exposed' => TRUE,
            'expose' => [
              'operator_id' => 'changed_op', 'label' => 'Changed before', 'operator' => 'changed_op',
              'identifier' => 'changed_before',
            ],
          ],
        ],
        'sorts' => [
          'vid' => ['id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'plugin_id' => 'standard', 'order' => 'ASC'],
          'name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard', 'order' => 'ASC'],
        ],
        'pager' => ['type' => 'full', 'options' => ['items_per_page' => 100, 'offset' => 0, 'quantity' => 9]],
        'header' => [
          'area' => [
            'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
            'content' => [
              'value' => '<p><strong>What is actually live.</strong> Live state is established here or by fetching the page — never by inference from a copy file (which says what was <em>written</em>) or a build report (which says what was <em>built</em>). <strong>n/a</strong> means the vocabulary has no such field, which is not a gap. <strong>⚠</strong> marks generic placeholder copy. The <strong>Live URL alias</strong> column is the one to write cross-links against.</p>',
              'format' => 'full_html',
            ],
            'empty' => TRUE,
          ],
        ],
        'empty' => [
          'area' => [
            'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
            'content' => ['value' => '<p>No terms match — which for a "missing" or "boilerplate" filter is the good answer.</p>', 'format' => 'basic_html'],
          ],
        ],
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_title' => 'Page',
      'display_plugin' => 'page',
      'position' => 1,
      'display_options' => [
        'path' => 'admin/office/content-coverage',
        'menu' => [
          'type' => 'normal',
          'title' => 'Content coverage',
          'description' => 'What public copy is live on every public taxonomy term.',
          'menu_name' => 'admin',
          'parent' => '',
          'weight' => 0,
        ],
        'display_extenders' => [],
      ],
    ],
  ],
]);
$view->save();
printf("built view 'bos_content_coverage' at /admin/office/content-coverage over %d vocabularies\n", count($vids));
