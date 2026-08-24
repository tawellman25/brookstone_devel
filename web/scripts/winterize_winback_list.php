<?php

/**
 * @file
 * READ-ONLY win-back list: properties winterized in the source year that have
 * NO work order in the target year.
 *
 * Creates nothing, saves nothing, modifies nothing. Safe on live.
 *
 * Writes a CSV to /tmp and prints the path.
 *
 * Run:
 *   Local:  ddev drush php:script web/scripts/winterize_winback_list.php
 *   Live:   drush php:script web/scripts/winterize_winback_list.php
 *
 * (Not `drush eval` with a heredoc — that breaks in WSL → DDEV.
 *  Not `drush sqlq` — broken on live.)
 */

use Drupal\Core\Datetime\DrupalDateTime;

const BUNDLE          = 'sprinkler_winterizing';
const SOURCE_START    = '2025-08-15';
const SOURCE_END      = '2025-12-31';
const TARGET_BOUNDARY = '2026-01-01';

// Terminal statuses that mean the WO was never actually performed.
const STATUS_CANCELED = 1098;

$etm  = \Drupal::entityTypeManager();
$tz   = new \DateTimeZone(date_default_timezone_get());
$wo_s = $etm->getStorage('work_order');

$ts = function (string $ymd) use ($tz) {
  return (new DrupalDateTime($ymd . ' 00:00:00', $tz))->getTimestamp();
};

// ---------------------------------------------------------------------------
// 0. Schema discovery — do not guess at contact field names.
// ---------------------------------------------------------------------------

$efm = \Drupal::service('entity_field.manager');

print "=== SCHEMA DISCOVERY ===\n\n";

$wo_fields = $efm->getFieldDefinitions('work_order', BUNDLE);
print "work_order:" . BUNDLE . " contact-bearing fields:\n";
foreach ($wo_fields as $name => $def) {
  if ($def->getType() === 'entity_reference') {
    $target = $def->getSettings()['target_type'] ?? '';
    if (in_array($target, ['contacts', 'user', 'profile'], TRUE)) {
      printf("  %-28s → %s\n", $name, $target);
    }
  }
}

$prop_fields = $efm->getFieldDefinitions('properties', 'property');
print "\nproperties contact-bearing fields:\n";
foreach ($prop_fields as $name => $def) {
  if ($def->getType() === 'entity_reference') {
    $target = $def->getSettings()['target_type'] ?? '';
    if (in_array($target, ['contacts', 'user', 'profile'], TRUE)) {
      printf("  %-28s → %s\n", $name, $target);
    }
  }
}

// Contacts entity: find the phone / email / name fields by type, not by guess.
$contact_bundles = array_keys(
  \Drupal::service('entity_type.bundle.info')->getBundleInfo('contacts')
);
$contact_bundle = in_array('contact', $contact_bundles, TRUE)
  ? 'contact'
  : reset($contact_bundles);

$contact_fields = $efm->getFieldDefinitions('contacts', $contact_bundle);
$phone_fields = $email_fields = [];
foreach ($contact_fields as $name => $def) {
  $t = $def->getType();
  if ($t === 'telephone' || preg_match('/phone|cell|mobile/i', $name)) {
    $phone_fields[$name] = $def->getLabel();
  }
  if ($t === 'email' || preg_match('/email/i', $name)) {
    $email_fields[$name] = $def->getLabel();
  }
}

printf("\ncontacts:%s — phone fields: %s\n", $contact_bundle,
  $phone_fields ? implode(', ', array_keys($phone_fields)) : '(none found)');
printf("contacts:%s — email fields: %s\n", $contact_bundle,
  $email_fields ? implode(', ', array_keys($email_fields)) : '(none found)');

// ---------------------------------------------------------------------------
// 1. Source-year winterizing WOs → property map.
// ---------------------------------------------------------------------------

$source_ids = $wo_s->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', BUNDLE)
  ->condition('created', [$ts(SOURCE_START), $ts(SOURCE_END)], 'BETWEEN')
  ->sort('id')
  ->execute();

printf("\n=== SOURCE YEAR ===\n\n  %d %s WOs created %s → %s\n",
  count($source_ids), BUNDLE, SOURCE_START, SOURCE_END);

// ---------------------------------------------------------------------------
// 2. Target-year winterizing WOs → the properties already covered.
// ---------------------------------------------------------------------------

$target_ids = $wo_s->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', BUNDLE)
  ->condition('created', $ts(TARGET_BOUNDARY), '>=')
  ->sort('id')
  ->execute();

$covered = [];
foreach ($wo_s->loadMultiple($target_ids) as $wo) {
  if (!$wo->get('field_property')->isEmpty()) {
    $covered[(int) $wo->get('field_property')->first()->getValue()['target_id']] = TRUE;
  }
}

printf("  %d %s WOs created on/after %s, covering %d properties\n",
  count($target_ids), BUNDLE, TARGET_BOUNDARY, count($covered));

// ---------------------------------------------------------------------------
// 3. Set difference — the win-back list.
// ---------------------------------------------------------------------------

$val = function ($entity, string $field) {
  if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
    return '';
  }
  $v = $entity->get($field)->first()->getValue();
  return (string) ($v['value'] ?? $v['target_id'] ?? '');
};

$ref = function ($entity, string $field) {
  if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
    return NULL;
  }
  return $entity->get($field)->first()->get('entity')->getTarget()?->getValue();
};

// Latest ownership record → client user uid. In BOS the CUSTOMER of a property
// is the owner on the most recent ownership_record — NOT property.uid (that is
// the office author). Mirrors estimate_intake's EstimateRequestIntakeLookup.
$find_owner = function (int $pid) use ($etm): int {
  $ids = $etm->getStorage('ownership_record')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'record')
    ->condition('field_property_reference.target_id', $pid)
    ->sort('id', 'DESC')
    ->range(0, 1)
    ->execute();
  if (!$ids) {
    return 0;
  }
  $rec = $etm->getStorage('ownership_record')->load(reset($ids));
  return ($rec && $rec->hasField('field_property_owner'))
    ? (int) ($rec->get('field_property_owner')->target_id ?? 0) : 0;
};

// A user's customer_profile primary contact entity, if any.
$profile_contact = function (int $uid) use ($etm) {
  if ($uid <= 1) {
    return NULL;
  }
  $ids = $etm->getStorage('profile')->getQuery()->accessCheck(FALSE)
    ->condition('uid', $uid)->condition('type', 'customer_profile')->range(0, 1)->execute();
  if (!$ids) {
    return NULL;
  }
  $p = $etm->getStorage('profile')->load(reset($ids));
  return ($p && $p->hasField('field_primary_contact_ref') && !$p->get('field_primary_contact_ref')->isEmpty())
    ? $p->get('field_primary_contact_ref')->entity : NULL;
};

// Phone on a CONTACT is a reference: contact.field_phone_number → phone_number
// entity → its own field_phone_number value (per CLAUDE.md traversal note).
$contact_phone = function ($contact): string {
  if (!$contact || !$contact->hasField('field_phone_number') || $contact->get('field_phone_number')->isEmpty()) {
    return '';
  }
  $pe = $contact->get('field_phone_number')->entity;
  return ($pe && $pe->hasField('field_phone_number') && !$pe->get('field_phone_number')->isEmpty())
    ? (string) $pe->get('field_phone_number')->value : '';
};

// A client user's own phone via phone_number:profile_phone_numbers (field_user).
$owner_phone = function (int $uid) use ($etm): string {
  if ($uid <= 1) {
    return '';
  }
  $ids = $etm->getStorage('phone_number')->getQuery()->accessCheck(FALSE)
    ->condition('type', 'profile_phone_numbers')->condition('field_user', $uid)->range(0, 3)->execute();
  foreach ($etm->getStorage('phone_number')->loadMultiple($ids) as $pe) {
    if ($pe->hasField('field_phone_number') && !$pe->get('field_phone_number')->isEmpty()) {
      return (string) $pe->get('field_phone_number')->value;
    }
  }
  return '';
};

$rows = [];
$seen_props = [];

foreach ($wo_s->loadMultiple($source_ids) as $wo) {
  if ($wo->get('field_property')->isEmpty()) {
    continue;
  }
  $pid = (int) $wo->get('field_property')->first()->getValue()['target_id'];

  if (isset($covered[$pid])) {
    continue;
  }
  // Keep the most recent source WO per property.
  if (isset($seen_props[$pid]) && $seen_props[$pid] >= (int) $wo->id()) {
    continue;
  }
  $seen_props[$pid] = (int) $wo->id();

  $property = $ref($wo, 'field_property');
  $status   = $ref($wo, 'field_status');
  $status_l = $status ? $status->label() : '';
  $status_t = $status ? (int) $status->id() : 0;

  // Zone count: prefer what was actually billed on the WO.
  $zones = $val($wo, 'field_zone_total');

  // Customer resolution (BOS model):
  //   1. WO's own field_contact
  //   2. property.field_primary_contact_ref → field_contacts
  //   3. LATEST OWNERSHIP RECORD → client user → customer_profile primary contact
  // The ownership record is the authoritative property↔client link; property.uid
  // is the office author, not the customer.
  $owner_uid = $find_owner($pid);

  $contact = $ref($wo, 'field_contact');
  if (!$contact && $property) {
    foreach (['field_primary_contact_ref', 'field_contacts'] as $f) {
      if ($contact = $ref($property, $f)) {
        break;
      }
    }
  }
  if (!$contact && $owner_uid) {
    $contact = $profile_contact($owner_uid);
  }

  // Owner (client user) name — the fallback identity when there is no contact.
  $owner_user = $owner_uid > 1 ? $etm->getStorage('user')->load($owner_uid) : NULL;
  $owner_name = $owner_user ? $owner_user->getDisplayName() : '';

  // Phone: contact reference chain first, then the client user's own phone.
  $phone = $contact_phone($contact);
  if ($phone === '') {
    $phone = $owner_phone($owner_uid);
  }

  // Email: contact's direct email, then the client user account email.
  $email = ($contact && $contact->hasField('field_email') && !$contact->get('field_email')->isEmpty())
    ? (string) $contact->get('field_email')->value : '';
  if ($email === '' && $owner_user) {
    $email = (string) $owner_user->getEmail();
  }

  // City via the zipcode reference, per BOS convention. field_city on the
  // zipcode is a reference to a city entity → resolve to its name, not the id.
  $city = $zip = '';
  if ($property) {
    $zipref = $ref($property, 'field_zipcode_reference');
    if ($zipref) {
      $city_entity = $ref($zipref, 'field_city');
      $city = $city_entity ? $city_entity->label() : $val($zipref, 'field_city');
      $zip  = $zipref->label();
    }
    if (!$city) {
      $city = $val($property, 'field_city_name');
    }
  }

  $rows[] = [
    'property_id'    => $pid,
    'nickname'       => $property ? $property->label() : '(property missing)',
    'street'         => $property ? $val($property, 'field_street_address') : '',
    'city'           => $city,
    'zip'            => $zip,
    'zones'          => $zones,
    'contact_name'   => $contact ? $contact->label() : '',
    'owner_name'     => $owner_name,
    'phone'          => $phone,
    'email'          => $email,
    'last_wo_id'     => (int) $wo->id(),
    'last_wo_number' => $val($wo, 'field_work_order_id'),
    'last_date'      => $wo->get('created')->isEmpty() ? '' :
      (new \DateTime('@' . $wo->get('created')->value))->setTimezone($tz)->format('Y-m-d'),
    'last_total'     => $val($wo, 'field_wo_total'),
    'last_status'    => $status_l,
    'was_canceled'   => $status_t === STATUS_CANCELED ? 'YES' : '',
  ];
}

// Route order: city, then street — so the office works it as a route.
usort($rows, function ($a, $b) {
  return [$a['city'], $a['street']] <=> [$b['city'], $b['street']];
});

// ---------------------------------------------------------------------------
// 4. Output.
// ---------------------------------------------------------------------------

$path = '/tmp/winterize_winback_' . date('Ymd_His') . '.csv';
$fh = fopen($path, 'w');
fputcsv($fh, array_keys($rows[0] ?? ['property_id' => '']));
foreach ($rows as $r) {
  fputcsv($fh, $r);
}
fclose($fh);

$no_phone   = count(array_filter($rows, fn($r) => $r['phone'] === ''));
$no_name    = count(array_filter($rows, fn($r) => $r['contact_name'] === '' && $r['owner_name'] === ''));
$no_contact = count(array_filter($rows, fn($r) => $r['contact_name'] === ''));
$canceled   = count(array_filter($rows, fn($r) => $r['was_canceled'] === 'YES'));
$revenue    = array_sum(array_map(fn($r) => (float) $r['last_total'], $rows));

print "\n=== WIN-BACK LIST ===\n\n";
printf("  %d properties winterized in the source year with no target-year WO\n\n", count($rows));
printf("  %d have no phone number on file\n", $no_phone);
printf("  %d have no contact-entity record (owner name may still be present)\n", $no_contact);
printf("  %d have no name at all (no contact AND no client user)\n", $no_name);
printf("  %d had a CANCELED work order last year (different conversation)\n", $canceled);
printf("  $%s billed across these properties last year\n\n", number_format($revenue, 2));

print "  By city:\n";
$by_city = [];
foreach ($rows as $r) {
  $by_city[$r['city'] ?: '(no city)'] = ($by_city[$r['city'] ?: '(no city)'] ?? 0) + 1;
}
arsort($by_city);
foreach ($by_city as $c => $n) {
  printf("    %-28s %3d\n", $c, $n);
}

printf("\n  CSV: %s\n", $path);
print "\n=== END — nothing was modified ===\n";
