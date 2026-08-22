<?php

/**
 * Authoritative, idempotent configuration of the services description model,
 * using DEDICATED fields (no shared/misleading storage).
 *
 *   field_service_public_desc (text_with_summary) — PUBLIC "what we do".
 *                                                    Has the summary option.
 *   field_service_crew_desc   (text_long)          — CREW "how we do it".
 *                                                    No summary.
 *
 * Migrates content off the previously-used shared fields, repoints all services
 * displays/form/listing at the new fields, then REMOVES the shared
 * field_description + field_public_description INSTANCES from the services
 * bundle (their storages stay — other vocabularies still use them).
 *
 * Supersedes setup_services_public_description.php, build_services_view_modes.php,
 * build_services_form_layout.php.
 *
 *   drush php:script web/scripts/configure_services_descriptions.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

$out = [];
$etm = \Drupal::entityTypeManager();

$ensureField = function (string $name, string $type, string $label, string $desc, array $settings) use (&$out) {
  $storage = FieldStorageConfig::loadByName('taxonomy_term', $name);
  if (!$storage) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'taxonomy_term', 'type' => $type, 'cardinality' => 1])->save();
    $out[] = "created storage $name ($type)";
    $storage = FieldStorageConfig::loadByName('taxonomy_term', $name);
  }
  $inst = FieldConfig::loadByName('taxonomy_term', 'services', $name);
  if (!$inst) {
    FieldConfig::create(['field_storage' => $storage, 'bundle' => 'services', 'label' => $label, 'description' => $desc, 'required' => FALSE, 'settings' => $settings])->save();
    $out[] = "created instance $name on services";
  }
};

// ── 1. Dedicated fields ─────────────────────────────────────────────────────
$ensureField('field_service_public_desc', 'text_with_summary', 'Service Description (Public)',
  'Public "what we do" description shown on the /services/{name} page (full view mode) to clients and the public, and trimmed as the /services listing teaser. Has an optional summary.',
  ['display_summary' => TRUE, 'required_summary' => FALSE, 'allowed_formats' => ['full_html']]);
$ensureField('field_service_crew_desc', 'text_long', 'Crew / Training Description',
  'Teammate "how we do it" training content, shown on the same service page in teammate_view (crew/office roles only, never public).',
  ['allowed_formats' => ['full_html']]);

// ── 2. Migrate content off the old shared fields (only when target empty) ────
$tids = $etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', 'services')->execute();
$mPub = 0; $mCrew = 0;
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $term) {
  $dirty = FALSE;
  // Public content currently lives in field_public_description.
  if ($term->hasField('field_service_public_desc') && $term->get('field_service_public_desc')->isEmpty()
    && $term->hasField('field_public_description') && !$term->get('field_public_description')->isEmpty()) {
    $v = $term->get('field_public_description')->first()->getValue();
    $term->set('field_service_public_desc', ['value' => $v['value'] ?? '', 'summary' => '', 'format' => $v['format'] ?? 'full_html']);
    $dirty = TRUE; $mPub++;
  }
  // Crew content currently lives in field_description.
  if ($term->hasField('field_service_crew_desc') && $term->get('field_service_crew_desc')->isEmpty()
    && $term->hasField('field_description') && !$term->get('field_description')->isEmpty()) {
    $v = $term->get('field_description')->first()->getValue();
    $term->set('field_service_crew_desc', ['value' => $v['value'] ?? '', 'format' => $v['format'] ?? 'full_html']);
    $dirty = TRUE; $mCrew++;
  }
  if ($dirty) {
    $term->save();
  }
}
$out[] = "migrated content → public:$mPub crew:$mCrew";

// ── 3. View-mode displays (full = public, teammate_view = crew) ──────────────
$srcView = EntityViewDisplay::load('taxonomy_term.services.default');
$vc = function (string $field, array $override) use ($srcView): array {
  $base = ($srcView && $srcView->getComponent($field)) ? $srcView->getComponent($field) : [];
  return $override + $base + ['region' => 'content', 'settings' => [], 'third_party_settings' => []];
};
$buildView = function (string $mode, array $components) {
  $d = EntityViewDisplay::load("taxonomy_term.services.$mode") ?: EntityViewDisplay::create([
    'targetEntityType' => 'taxonomy_term', 'bundle' => 'services', 'mode' => $mode, 'status' => TRUE,
  ]);
  foreach (array_keys($d->getComponents()) as $n) {
    $d->removeComponent($n);
  }
  foreach ($components as $f => $spec) {
    $d->setComponent($f, $spec);
  }
  $d->save();
  return "$mode: " . implode(', ', array_keys($components));
};
$out[] = $buildView('full', [
  'field_banner_image' => ['type' => 'image', 'label' => 'hidden', 'weight' => -1, 'region' => 'content', 'settings' => ['image_style' => 'large', 'image_link' => ''], 'third_party_settings' => []],
  'field_iconic_image' => $vc('field_iconic_image', ['label' => 'hidden', 'type' => 'image', 'weight' => 0]),
  'field_subtitle' => $vc('field_subtitle', ['label' => 'hidden', 'type' => 'string', 'weight' => 1]),
  'field_service_public_desc' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 2, 'region' => 'content', 'settings' => [], 'third_party_settings' => []],
]);
$out[] = $buildView('teammate_view', [
  'field_subtitle' => $vc('field_subtitle', ['label' => 'hidden', 'type' => 'string', 'weight' => 0]),
  'field_service_crew_desc' => ['type' => 'text_default', 'label' => 'above', 'weight' => 1, 'region' => 'content', 'settings' => [], 'third_party_settings' => []],
  'field_department' => $vc('field_department', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 2]),
  'field_sop_code' => $vc('field_sop_code', ['label' => 'inline', 'type' => 'string', 'weight' => 3]),
]);
// Clean the default display: drop the two old fields, ensure the public one shows.
if ($srcView) {
  foreach (['field_description', 'field_public_description'] as $old) {
    if ($srcView->getComponent($old)) {
      $srcView->removeComponent($old);
    }
  }
  if (!$srcView->getComponent('field_service_public_desc')) {
    $srcView->setComponent('field_service_public_desc', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 2, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  $srcView->save();
  $out[] = 'default display: dropped old fields, added public field';
}

// ── 4. Edit form: Public View tab (public, summary widget) + Teammate View tab
$fd = EntityFormDisplay::load('taxonomy_term.services.default');
if ($fd) {
  foreach (['description', 'field_description', 'field_public_description'] as $old) {
    if ($fd->getComponent($old)) {
      $fd->removeComponent($old);
    }
  }
  $groups = $fd->getThirdPartySettings('field_group');
  if (isset($groups['group_public_view'])) {
    $children = array_values(array_diff($groups['group_public_view']['children'] ?? [], ['description', 'field_description', 'field_public_description']));
    if (!in_array('field_service_public_desc', $children, TRUE)) {
      $children = array_merge(['field_subtitle', 'field_service_public_desc'], array_values(array_diff($children, ['field_subtitle'])));
    }
    $groups['group_public_view']['children'] = array_values(array_unique($children));
  }
  $groups['group_teammate_view'] = [
    'children' => ['field_service_crew_desc'],
    'label' => 'Teammate View',
    'parent_name' => 'group_office_tabs',
    'region' => 'content',
    'weight' => 6,
    'format_type' => 'tab',
    'format_settings' => [
      'classes' => 'group-teammate-view field-group-fieldset',
      'show_empty_fields' => TRUE, 'id' => '', 'label_as_html' => FALSE,
      'formatter' => 'closed',
      'description' => 'How we do it — crew training content (teammate_view). Never public.',
      'required_fields' => TRUE,
    ],
  ];
  if (isset($groups['group_office_tabs']) && !in_array('group_teammate_view', $groups['group_office_tabs']['children'] ?? [], TRUE)) {
    $groups['group_office_tabs']['children'][] = 'group_teammate_view';
    $groups['group_office_tabs']['children'] = array_values($groups['group_office_tabs']['children']);
  }
  foreach (['group_public_view', 'group_teammate_view', 'group_office_tabs'] as $g) {
    if (isset($groups[$g])) {
      $fd->setThirdPartySetting('field_group', $g, $groups[$g]);
    }
  }
  $fd->setComponent('field_service_public_desc', [
    'type' => 'text_textarea_with_summary', 'weight' => 2, 'region' => 'content',
    'settings' => ['rows' => 8, 'summary_rows' => 3, 'placeholder' => '', 'show_summary' => TRUE], 'third_party_settings' => [],
  ]);
  $fd->setComponent('field_service_crew_desc', [
    'type' => 'text_textarea', 'weight' => 1, 'region' => 'content',
    'settings' => ['rows' => 8, 'placeholder' => ''], 'third_party_settings' => [],
  ]);
  $fd->save();
  $out[] = 'form: Public View→field_service_public_desc (summary), Teammate View→field_service_crew_desc';
}

// ── 5. /services listing view: public teaser (trimmed), drop old fields ──────
$view = $etm->getStorage('view')->load('services');
if ($view) {
  $disp = $view->get('display');
  $fields = $disp['default']['display_options']['fields'] ?? [];
  $template = $fields['field_description'] ?? NULL;
  unset($fields['description__value'], $fields['field_description'], $fields['field_public_description']);
  if (!isset($fields['field_service_public_desc'])) {
    // Clone the old text field entry structure, swap to the new field.
    $new = $template ?: [
      'id' => 'field_service_public_desc', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
      'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE, 'element_type' => '', 'element_class' => '',
      'element_label_type' => '', 'element_label_class' => '', 'element_label_colon' => FALSE,
      'element_wrapper_type' => '', 'element_wrapper_class' => '', 'element_default_classes' => TRUE,
      'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
      'click_sort_column' => 'value', 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
      'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE,
      'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE,
    ];
    $new['id'] = 'field_service_public_desc';
    $new['table'] = 'taxonomy_term__field_service_public_desc';
    $new['field'] = 'field_service_public_desc';
    $new['entity_type'] = 'taxonomy_term';
    $new['entity_field'] = 'field_service_public_desc';
    $new['type'] = 'text_trimmed';
    $new['settings'] = ['trim_length' => 300];
    $new['exclude'] = FALSE;
    $fields['field_service_public_desc'] = $new;
  }
  $disp['default']['display_options']['fields'] = $fields;
  $view->set('display', $disp);
  $view->save();
  $out[] = 'services listing: public field_service_public_desc (trimmed 300), old fields removed';
}

// ── 6. Remove the shared field INSTANCES from services (storages stay) ───────
foreach (['field_description', 'field_public_description'] as $old) {
  $inst = FieldConfig::loadByName('taxonomy_term', 'services', $old);
  if ($inst) {
    $inst->delete();
    $out[] = "removed shared instance $old from services";
  }
}

echo "== configure_services_descriptions ==\n";
foreach ($out as $l) {
  echo "  - $l\n";
}
