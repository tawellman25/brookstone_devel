<?php

/**
 * "Our {Season} Services" cards, as an EVA on the season taxonomy term page.
 *
 * The season page listed field_services_performed as a bare column of links —
 * eighteen of them on Fall, with nothing to say what any of them is. This is the
 * same card component the services pages already use (`service_children`), so a
 * service looks the same wherever it is listed: iconic image strip, linked name,
 * short blurb.
 *
 * ⚠ It REUSES `service_children`'s row class and CSS rather than defining a
 * parallel set — the house rule is genuine consistency, not a lookalike. The two
 * bos_services hooks that own that component (library attach + "blurb is the
 * SUMMARY only, never trimmed body HTML") were widened to cover this view id
 * rather than copied here.
 *
 * The relationship is what differs from service_children. There the link is the
 * taxonomy PARENT; here it is a reference field on the season pointing AT the
 * services. So rows stay services — keeping the vid/status filters, the fields
 * and the sorts identical to the component being reused — and only the
 * contextual filter arrives through the reverse relationship
 * `reverse__taxonomy_term__field_services_performed`, which resolves to the
 * season that references each service. The EVA feeds it the current season's id.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_season_services_view.php
 */

use Drupal\views\Entity\View;

if (View::load('season_services')) {
  View::load('season_services')->delete();
}

$rel = 'reverse__taxonomy_term__field_services_performed';

$default = [
  'title' => '',
  // The shared card component's class. The CSS is scoped to this, NOT to a
  // view-id class, because two views use the component.
  'css_class' => 'bo-service-cards',
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query', 'options' => ['distinct' => TRUE]],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'default', 'options' => ['row_class' => 'service-card', 'default_row_class' => TRUE]],
  'row' => ['type' => 'fields'],
  'relationships' => [
    $rel => [
      'id' => $rel,
      'table' => 'taxonomy_term_field_data',
      'field' => $rel,
      'relationship' => 'none',
      'plugin_id' => 'entity_reverse',
      'admin_label' => 'Season referencing this service',
      // Required: a service nothing references must not appear on a season page.
      'required' => TRUE,
    ],
  ],
  'fields' => [
    'field_iconic_image' => [
      'id' => 'field_iconic_image', 'table' => 'taxonomy_term__field_iconic_image',
      'field' => 'field_iconic_image', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => '', 'exclude' => FALSE, 'type' => 'image',
      'settings' => ['image_style' => 'service_card_strip', 'image_link' => 'content'],
    ],
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name',
      'relationship' => 'none', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
      'plugin_id' => 'term_name', 'label' => '', 'exclude' => FALSE,
      'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
      'convert_spaces' => FALSE,
    ],
    'field_service_public_desc' => [
      'id' => 'field_service_public_desc', 'table' => 'taxonomy_term__field_service_public_desc',
      'field' => 'field_service_public_desc', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => '', 'exclude' => FALSE, 'type' => 'text_summary_or_trimmed',
      'settings' => ['trim_length' => 140],
    ],
  ],
  'arguments' => [
    // The SEASON's id, reached through the reverse relationship.
    'tid' => [
      'id' => 'tid', 'table' => 'taxonomy_term_field_data', 'field' => 'tid',
      'relationship' => $rel, 'entity_type' => 'taxonomy_term', 'entity_field' => 'tid',
      // 'taxonomy', not 'taxonomy_index_tid'. The handler comes from views DATA,
      // never from a plugin_id in config (gotchas.md), so the wrong value here
      // was silently ignored and the query was right anyway — which is exactly
      // how a misleading config survives. Written to match what Views uses.
      'plugin_id' => 'taxonomy', 'default_action' => 'empty',
      'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
      'default_argument_type' => 'fixed',
      'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'],
      'break_phrase' => FALSE,
    ],
  ],
  'filters' => [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
      'operator' => 'in', 'value' => ['services' => 'services'], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'weight' => [
      'id' => 'weight', 'table' => 'taxonomy_term_field_data', 'field' => 'weight', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'weight', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'plugin_id' => 'text',
      // Rewritten to "Our {season} Services" by bos_services_views_pre_render().
      // empty: FALSE so a season with no services renders no heading at all.
      'empty' => FALSE,
      'content' => ['value' => '<h2 class="service-children__title">Services</h2>', 'format' => 'full_html'],
    ],
  ],
  'empty' => [], 'footer' => [], 'display_extenders' => [],
];

View::create([
  'id' => 'season_services',
  'label' => 'Season — services we do (cards)',
  'module' => 'views',
  'base_table' => 'taxonomy_term_field_data',
  'base_field' => 'tid',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default],
    'entity_view_1' => [
      'id' => 'entity_view_1', 'display_title' => 'Season services (EVA)', 'display_plugin' => 'entity_view', 'position' => 1,
      'display_options' => [
        'entity_type' => 'taxonomy_term',
        'bundles' => ['season'],
        'argument_mode' => 'id',
        'default_argument' => '',
        'display_extenders' => [],
      ],
    ],
  ],
])->save();

print "built season_services (EVA -> taxonomy_term:season)\nDONE.\n";
