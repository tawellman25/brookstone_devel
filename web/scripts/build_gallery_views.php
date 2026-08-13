<?php

/**
 * Build the two property-gallery Views (replacing the earlier bespoke
 * controller/hook, per the "list UIs must be Views" rule):
 *
 *   - property_photos        : STAFF "Gallery" tab (page display + local task at
 *                              properties/%properties/gallery). Shows ALL photo
 *                              media for the property (archive + WO, public +
 *                              held), flat grid, staff-role access. Filters are
 *                              adjustable in the Views UI.
 *   - property_photos_public : PUBLIC gallery EVA embedded on the property page.
 *                              Only field_public_ok = 1 + published. Flat grid.
 *
 * Both: base media, contextual filter on media.field_property (direct — no WO
 * relationship needed), rendered in the `gallery` view mode (Colorbox images +
 * inline video), flat list (NO grouping by work order), newest first.
 *
 * Cloned from working templates (property_work_orders for the tab,
 * property_christmas_photos for the EVA) so the tricky boilerplate
 * (contextual filter, menu tab, EVA token argument) is inherited correctly.
 *
 * Idempotent. Run: drush php:script web/scripts/build_gallery_views.php
 */

use Drupal\views\Entity\View;

$BUNDLES = [
  'property_photo' => 'property_photo',
  'property_video' => 'property_video',
  'wo_images' => 'wo_images',
  'wo_videos' => 'wo_videos',
];

// Render the media SOURCE fields directly (proven pattern, like the christmas
// EVA) rather than rendered_entity (which doesn't render reliably in a view).
// Image bundles render field_media_image_1 via Colorbox; video bundles render
// field_media_video_file_1 via the player. Each row is one or the other.
$imageField = [
  'id' => 'field_media_image_1',
  'table' => 'media__field_media_image_1',
  'field' => 'field_media_image_1',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'media',
  'entity_field' => 'field_media_image_1',
  'plugin_id' => 'field',
  'type' => 'colorbox',
  'label' => '',
  'settings' => [
    'colorbox_node_style' => 'medium',
    'colorbox_node_style_first' => '',
    'colorbox_image_style' => 'max_1300x1300',
    // Page-level gallery so lightbox prev/next pages through EVERY photo on
    // the page — not just the (now single-image) row.
    'colorbox_gallery' => 'page',
    'colorbox_gallery_custom' => '',
    'colorbox_caption' => 'alt',
    'colorbox_caption_custom' => '',
  ],
  // field_media_image_1 is multi-value (cardinality -1): wo_images/wo_videos
  // crews upload many photos into ONE media entity. group_rows => FALSE
  // explodes each image value into its OWN view row → its own grid tile, so
  // photos render all-separate instead of grouped/stacked by media entity.
  'group_rows' => FALSE,
  'group_column' => 'value',
  'group_columns' => [],
  'delta_limit' => 0,
  'delta_offset' => 0,
  'delta_reversed' => FALSE,
  'delta_first_last' => FALSE,
  'multi_type' => 'separator',
  'separator' => '',
];
$videoField = [
  'id' => 'field_media_video_file_1',
  'table' => 'media__field_media_video_file_1',
  'field' => 'field_media_video_file_1',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'media',
  'entity_field' => 'field_media_video_file_1',
  'plugin_id' => 'field',
  'type' => 'file_video',
  'label' => '',
  'settings' => ['controls' => TRUE, 'autoplay' => FALSE, 'loop' => FALSE, 'muted' => FALSE],
];
$propArg = [
  'id' => 'field_property_target_id',
  'table' => 'media__field_property',
  'field' => 'field_property_target_id',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'media',
  'entity_field' => 'field_property',
  'plugin_id' => 'entity_target_id',
  'default_action' => 'not found',
  'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
  'title_enable' => FALSE,
  'default_argument_type' => 'views_url_path',
  'default_argument_options' => [],
  'summary_options' => [],
  'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
  'specify_validation' => FALSE,
  'validate' => ['type' => 'none', 'fail' => 'not found'],
  'break_phrase' => FALSE,
];
$bundleFilter = [
  'id' => 'bundle',
  'table' => 'media_field_data',
  'field' => 'bundle',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'media',
  'operator' => 'in',
  'value' => $BUNDLES,
  'plugin_id' => 'bundle',
];
$statusFilter = [
  'id' => 'status',
  'table' => 'media_field_data',
  'field' => 'status',
  'relationship' => 'none',
  'entity_type' => 'media',
  'entity_field' => 'status',
  'plugin_id' => 'boolean',
  'operator' => '=',
  'value' => '1',
];
$dateSort = [
  'id' => 'field_date_taken_value',
  'table' => 'media__field_date_taken',
  'field' => 'field_date_taken_value',
  'relationship' => 'none',
  'entity_type' => 'media',
  'entity_field' => 'field_date_taken',
  'plugin_id' => 'datetime',
  'order' => 'DESC',
];
$createdSort = [
  'id' => 'created',
  'table' => 'media_field_data',
  'field' => 'created',
  'relationship' => 'none',
  'entity_type' => 'media',
  'entity_field' => 'created',
  'plugin_id' => 'date',
  'order' => 'DESC',
];
// Unformatted list — the bos_property_gallery CSS lays the rows out as a
// responsive grid of cards (staff row template adds the caption bar).
$gridStyle = ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE]];

// ─────────────────────────────────────────────────────────────────────────
// View 1: property_photos — STAFF tab (clone property_work_orders)
// ─────────────────────────────────────────────────────────────────────────
if (View::load('property_photos')) {
  View::load('property_photos')->delete();
  print "removed existing property_photos (rebuild)\n";
}
$a = View::load('property_work_orders')->toArray();
unset($a['uuid']);
$a['id'] = 'property_photos';
$a['label'] = 'Property Photos (Gallery — staff)';
$a['base_table'] = 'media_field_data';
$a['base_field'] = 'mid';
$a['dependencies'] = ['module' => ['media', 'user', 'views']];
foreach ($a['display'] as $dk => &$disp) {
  $do = &$disp['display_options'];
  if (isset($do['relationships'])) {
    unset($do['relationships']);
  }
  if (array_key_exists('arguments', $do)) {
    $do['arguments'] = ['field_property_target_id' => $propArg];
  }
  if (array_key_exists('fields', $do)) {
    // Only the media source fields are declared; the staff row template
    // (views-view-fields--property-photos) renders the Public/Held badge + Edit
    // link, computed from the media entity in the module's preprocess.
    $do['fields'] = [
      'field_media_image_1' => $imageField,
      'field_media_video_file_1' => $videoField,
    ];
  }
  if (array_key_exists('filters', $do)) {
    $do['filters'] = ['bundle' => $bundleFilter];
  }
  if (array_key_exists('sorts', $do)) {
    $do['sorts'] = ['field_date_taken_value' => $dateSort, 'created' => $createdSort];
  }
  if (array_key_exists('style', $do)) {
    $do['style'] = $gridStyle;
  }
  // Stable class for the gallery grid CSS (works even when the EVA wrapper
  // omits the .view-id-* class).
  $do['css_class'] = 'bos-photo-gallery';
  if (array_key_exists('row', $do)) {
    $do['row'] = ['type' => 'fields'];
  }
  if (array_key_exists('pager', $do)) {
    $do['pager'] = ['type' => 'full', 'options' => ['items_per_page' => 60, 'offset' => 0]];
  }
}
unset($disp);
// Keep only default + page_1.
$a['display'] = array_intersect_key($a['display'], ['default' => 1, 'page_1' => 1]);
$a['display']['page_1']['display_options']['path'] = 'properties/%properties/gallery';
$a['display']['page_1']['display_options']['menu']['title'] = 'Gallery';
$a['display']['page_1']['display_options']['menu']['weight'] = 9;
$a['display']['page_1']['display_options']['menu']['description'] = 'All photos & videos for this property.';
View::create($a)->save();
print "created view property_photos (staff Gallery tab)\n";

// ─────────────────────────────────────────────────────────────────────────
// View 2: property_photos_public — PUBLIC EVA (clone property_christmas_photos)
// ─────────────────────────────────────────────────────────────────────────
if (View::load('property_photos_public')) {
  View::load('property_photos_public')->delete();
  print "removed existing property_photos_public (rebuild)\n";
}
$b = View::load('property_christmas_photos')->toArray();
unset($b['uuid']);
$b['id'] = 'property_photos_public';
$b['label'] = 'Property Photos (Gallery — public)';
$b['base_table'] = 'media_field_data';
$b['base_field'] = 'mid';
$b['dependencies'] = ['module' => ['media', 'eva', 'views']];
foreach ($b['display'] as $dk => &$disp) {
  $do = &$disp['display_options'];
  if (isset($do['relationships'])) {
    unset($do['relationships']);
  }
  if (array_key_exists('arguments', $do)) {
    // EVA passes the property id; keep the property arg but on media.field_property.
    $do['arguments'] = ['field_property_target_id' => $propArg];
  }
  if (array_key_exists('fields', $do)) {
    $do['fields'] = ['field_media_image_1' => $imageField, 'field_media_video_file_1' => $videoField];
  }
  if (array_key_exists('filters', $do)) {
    $do['filters'] = [
      'status' => $statusFilter,
      'bundle' => $bundleFilter,
      'field_public_ok_value' => [
        'id' => 'field_public_ok_value', 'table' => 'media__field_public_ok', 'field' => 'field_public_ok_value',
        'relationship' => 'none', 'entity_type' => 'media', 'entity_field' => 'field_public_ok',
        'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1',
      ],
    ];
  }
  if (array_key_exists('sorts', $do)) {
    $do['sorts'] = ['field_date_taken_value' => $dateSort, 'created' => $createdSort];
  }
  if (array_key_exists('style', $do)) {
    $do['style'] = $gridStyle;
  }
  // Stable class for the gallery grid CSS (works even when the EVA wrapper
  // omits the .view-id-* class).
  $do['css_class'] = 'bos-photo-gallery';
  if (array_key_exists('row', $do)) {
    $do['row'] = ['type' => 'fields'];
  }
  if (array_key_exists('access', $do)) {
    $do['access'] = ['type' => 'none', 'options' => []];
  }
}
unset($disp);
View::create($b)->save();
print "created view property_photos_public (public EVA)\n";

print "DONE — gallery views built.\n";
