<?php

/**
 * @file
 * Give the backflow device the four audience view modes the rest of BOS uses.
 *
 * The device had ONE view mode (`full`) and two displays. The page a QR tag
 * points at is public, so what it shows matters: today it renders the PROPERTY
 * link and the physical location to anonymous visitors, and HIDES the serial
 * number — close to the opposite of what a water-authority verify page wants.
 *
 * Naming follows the dominant BOS convention for ECK entities — admin /
 * teammate / client / public — matching `material`, which has all four. (The
 * `*_view` suffix variants exist only on taxonomy_term.)
 *
 * ⚠ The display MUST be created for a mode that is actually registered. A
 * view mode that does not exist makes Drupal fall back to `default` — the
 * everything display — which is how the material catalog once served its
 * internal panel to the public. Modes are created first, displays second.
 *
 * Idempotent. Per environment: these are config entities created through the
 * entity API, not a cim.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;

const ENTITY = 'property_backflow_device';
const BUNDLE = 'device';

// --- 1. The view modes --------------------------------------------------------
$modes = [
  'admin' => 'Admin',
  'teammate' => 'Teammate',
  'client' => 'Client',
  'public' => 'Public',
];
foreach ($modes as $id => $label) {
  $full = ENTITY . '.' . $id;
  if (EntityViewMode::load($full)) {
    printf("view mode ok      %s\n", $full);
    continue;
  }
  EntityViewMode::create([
    'id' => $full,
    'label' => $label,
    'targetEntityType' => ENTITY,
  ])->save();
  printf("view mode CREATED %s\n", $full);
}

// --- 2. What each audience sees ----------------------------------------------
// Ordered; weights follow the array order.
$internalHistory = 'backflow_test_history_eva_entity_view_1';
$publicHistory = 'backflow_test_history_public_eva_entity_view_1';
$clientHistory = 'backflow_test_history_client_eva_entity_view_1';

$curation = [
  // Everything. The office needs the lot, including what the device replaced,
  // how often it is due, the QR and the internal test history.
  'admin' => [
    'field_current_status', 'field_property', 'field_physical_location',
    'field_material_backflow', 'field_device_type', 'field_used_for',
    'field_serial_number', 'field_test_frequency_months',
    'field_initial_test_date', 'field_last_test_date', 'field_last_pass_date',
    'field_next_due_date', 'field_replaced_by', 'field_device_photos',
    'field_qr_code', $internalHistory,
  ],
  // What a tech needs standing in front of it. Internal history included —
  // repairs recommended is operational, not billing.
  'teammate' => [
    'field_current_status', 'field_property', 'field_physical_location',
    'field_material_backflow', 'field_device_type', 'field_used_for',
    'field_serial_number', 'field_last_test_date', 'field_next_due_date',
    'field_device_photos', $internalHistory,
  ],
  // The customer's own device. Their history with the report PDF, no internal
  // work-order or repair notes.
  'client' => [
    'field_current_status', 'field_physical_location',
    'field_material_backflow', 'field_device_type', 'field_used_for',
    'field_serial_number', 'field_last_test_date', 'field_next_due_date',
    $clientHistory,
  ],
  // The water-authority / QR verify view. Identity and compliance only:
  // what the device is, its serial, whether it passed and when, when it is next
  // due, and who certified it. Deliberately NOT here: the property it belongs
  // to, where on the premises it sits, photos, the QR itself, and anything
  // about repairs or work orders.
  'public' => [
    'field_current_status', 'field_material_backflow', 'field_device_type',
    'field_used_for', 'field_serial_number',
    'field_last_test_date', 'field_next_due_date', $publicHistory,
  ],
];

// Reuse the formatter the existing displays already chose for each field, so the
// new displays render the same way rather than to a guessed default.
$reference = EntityViewDisplay::load(ENTITY . '.' . BUNDLE . '.default');
$refContent = $reference ? $reference->get('content') : [];
$fullRef = EntityViewDisplay::load(ENTITY . '.' . BUNDLE . '.full');
$refContent += $fullRef ? $fullRef->get('content') : [];

$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions(ENTITY, BUNDLE);

foreach ($curation as $mode => $fields) {
  $id = ENTITY . '.' . BUNDLE . '.' . $mode;
  $display = EntityViewDisplay::load($id);
  if (!$display) {
    $display = EntityViewDisplay::create([
      'targetEntityType' => ENTITY,
      'bundle' => BUNDLE,
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }
  // Start from nothing so a re-run converges rather than accumulating.
  foreach (array_keys($display->get('content')) as $existing) {
    $display->removeComponent($existing);
  }
  $weight = 0;
  $placed = [];
  foreach ($fields as $field) {
    $isEva = str_contains($field, '_entity_view_');
    if (!$isEva && !isset($defs[$field])) {
      printf("  !! %s.%s: no such field, skipped\n", $mode, $field);
      continue;
    }
    $component = $refContent[$field] ?? NULL;
    if (!$component) {
      // An EVA or a field the old displays never showed: minimal sane default.
      $component = $isEva
        ? ['region' => 'content', 'settings' => [], 'third_party_settings' => []]
        : ['label' => 'inline', 'settings' => [], 'third_party_settings' => [], 'region' => 'content'];
      if (!$isEva) {
        $component['type'] = _bfd_default_formatter($defs[$field]->getType());
      }
    }
    // Date fields render date-only. These are dates, not appointments: a
    // "Next Due Date" reading "Sat, 09/18/2027 - 12:00 PM" puts a meaningless
    // time on a compliance date, on a page a water authority reads. BOS's date
    // standard is m/d/Y for date-only values.
    if (!$isEva && ($defs[$field]->getType() === 'datetime')) {
      $component['type'] = 'datetime_default';
      $component['settings'] = ['timezone_override' => '', 'format_type' => 'date_only'];
    }
    $component['weight'] = $weight++;
    $component['region'] = 'content';
    $display->setComponent($field, $component);
    $placed[] = $field;
  }
  // Everything else is explicitly hidden, so a field added later does not appear
  // on the public page by accident.
  foreach (array_keys($defs) as $name) {
    if (!in_array($name, $placed, TRUE)) {
      $display->removeComponent($name);
    }
  }
  $display->save();
  printf("display %-9s %d field(s): %s\n", $mode, count($placed), implode(', ', array_map(
    fn($f) => str_replace(['field_', '_entity_view_1'], ['', ''], $f), $placed)));
}

function _bfd_default_formatter(string $type): string {
  return match ($type) {
    'image' => 'image',
    'datetime' => 'datetime_default',
    'entity_reference' => 'entity_reference_label',
    'list_string' => 'list_default',
    'integer' => 'number_integer',
    'string_long' => 'basic_string',
    default => 'string',
  };
}
