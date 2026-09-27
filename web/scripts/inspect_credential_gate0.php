<?php

declare(strict_types=1);

/**
 * Gate 0 — READ-ONLY inspection for the proposed `credential` ECK entity.
 * Verifies every assumption in the build spec against the running site.
 * Creates and changes NOTHING.
 *
 *   drush php:script web/scripts/inspect_credential_gate0.php
 */

$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$mh = \Drupal::moduleHandler();

$line = fn(string $s) => print($s . "\n");
$h = function (string $s) use ($line) { $line("\n=== $s ==="); };

/* 1. Does anything already exist? */
$h('1. COLLISION CHECK');
foreach (['credential'] as $t) {
  $line(sprintf('  eck type "%s": %s', $t, $etm->hasDefinition($t) ? 'EXISTS ⚠' : 'absent (free to create)'));
}
$vocab = $etm->getStorage('taxonomy_vocabulary')->load('credential_types');
$line('  vocabulary credential_types: ' . ($vocab ? 'EXISTS ⚠' : 'absent (free to create)'));

/* 2. teammate_profile certification fields — the §8 decision. */
$h('2. teammate_profile CERT FIELDS (§8 decision)');
$defs = $efm->getFieldDefinitions('profile', 'teammate_profile');
foreach (['field_certification_number', 'field_certification_association', 'field_signature'] as $f) {
  $line(sprintf('  %-34s %s', $f, isset($defs[$f]) ? 'present (' . $defs[$f]->getType() . ')' : 'ABSENT'));
}
$q = \Drupal::entityQuery('profile')->accessCheck(FALSE)->condition('type', 'teammate_profile');
$line('  teammate_profile records: ' . $q->count()->execute());
foreach (['field_certification_number', 'field_certification_association'] as $f) {
  if (!isset($defs[$f])) { continue; }
  $ids = \Drupal::entityQuery('profile')->accessCheck(FALSE)
    ->condition('type', 'teammate_profile')->exists($f)->execute();
  $line(sprintf('  %-34s populated on %d record(s)', $f, count($ids)));
  foreach ($etm->getStorage('profile')->loadMultiple($ids) as $p) {
    $owner = $p->getOwner();
    $line(sprintf('      uid %-5s %-22s %s = %s', $p->getOwnerId(), $owner ? $owner->getAccountName() : '?', $f, $p->get($f)->value));
  }
}

/* 3. contacts.contact shape — the §2b reference target. */
$h('3. contacts.contact SHAPE (§2b)');
$cdefs = $efm->getFieldDefinitions('contacts', 'contact');
foreach (['field_first_name','field_last_name','field_email','field_job_title','field_contact_status','field_phone_number','field_address','field_contact_notes'] as $f) {
  $d = $cdefs[$f] ?? NULL;
  $line(sprintf('  %-24s %s', $f, $d ? $d->getType() . ($d->isRequired() ? ' REQUIRED' : '') : 'ABSENT'));
}
$req = [];
foreach ($cdefs as $name => $d) {
  if (str_starts_with($name, 'field_') && $d->isRequired()) { $req[] = $name; }
}
$line('  required fields on contact: ' . ($req ? implode(', ', $req) : 'NONE (standalone reference is safe)'));
$line('  contact records: ' . \Drupal::entityQuery('contacts')->accessCheck(FALSE)->condition('type','contact')->count()->execute());

/* 4. bos_contact_attach delete-cleanup risk (§2b ⚠). */
$h('4. bos_contact_attach DELETE BEHAVIOUR (§2b risk)');
$line('  module enabled: ' . ($mh->moduleExists('bos_contact_attach') ? 'yes' : 'NO'));
$f = DRUPAL_ROOT . '/modules/custom/bos_contact_attach/bos_contact_attach.module';
if (is_readable($f)) {
  $src = file_get_contents($f);
  preg_match_all('/function\s+(bos_contact_attach_\w+)/', $src, $m);
  $line('  hooks: ' . implode(', ', $m[1] ?? []));
  $line('  mentions predelete/delete: ' . (preg_match('/predelete|_delete\(/', $src) ? 'YES — inspect' : 'no'));
  foreach (['properties', 'profile', 'credential'] as $target) {
    $line(sprintf('    references "%s": %s', $target, str_contains($src, $target) ? 'yes' : 'no'));
  }
}
else { $line('  module file not readable at ' . $f); }

/* 5. JSON:API exposure (§7). */
$h('5. JSON:API (§7)');
$line('  jsonapi enabled: ' . ($mh->moduleExists('jsonapi') ? 'yes' : 'no'));
if ($mh->moduleExists('jsonapi')) {
  $c = \Drupal::config('jsonapi.settings');
  $line('  read_only: ' . var_export($c->get('read_only'), TRUE));
  $line('  NOTE: jsonapi exposes ALL content entity types by default → a new ECK type is exposed automatically.');
}

/* 6. pathauto enabled_entity_types (§10 hazard). */
$h('6. PATHAUTO ENABLED ENTITY TYPES (§10 hazard)');
$pa = \Drupal::config('pathauto.settings')->get('enabled_entity_types');
$line('  enabled_entity_types: ' . (is_array($pa) ? implode(', ', $pa) : var_export($pa, TRUE)));
$line('  property_backflow_device present: ' . (is_array($pa) && in_array('property_backflow_device', $pa, TRUE) ? 'yes' : 'NO'));

/* 7. Reusable field storages (BOS convention: reuse, do not create). */
$h('7. REUSABLE FIELD STORAGES (proposed field names)');
$proposed = [
  'field_credential_type','field_scope','field_teammate','field_credential_number',
  'field_issuing_authority','field_coverage_limits','field_issue_date','field_expiration_date',
  'field_status','field_superseded_by','field_credential_images','field_credential_documents',
  'field_public_description','field_internal_notes','field_renewal_contact','field_renewal_url',
  'field_publish_publicly','field_type_code','field_default_scope','field_number_is_public',
  'field_verification_url','field_renewal_lead_days',
];
$all = $etm->getStorage('field_storage_config')->loadMultiple();
$byName = [];
foreach ($all as $s) { $byName[$s->getName()][] = $s->getTargetEntityTypeId() . ':' . $s->getType(); }
foreach ($proposed as $p) {
  $len = strlen($p);
  $warn = $len > 32 ? ' ⚠ NAME >32' : '';
  $line(sprintf('  %-26s (%2d)%s %s', $p, $len, $warn, isset($byName[$p]) ? 'exists on → ' . implode(' | ', array_unique($byName[$p])) : '— new storage'));
}

/* 8. Backflow snapshot code (§6). */
$h('8. BACKFLOW CERT SNAPSHOT (§6)');
$hits = [];
foreach (['backflow_device', 'wo_tasks_list', 'bos_backflow_types'] as $m) {
  $dir = DRUPAL_ROOT . '/modules/custom/' . $m;
  if (!is_dir($dir)) { continue; }
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
    if ($file->isFile() && preg_match('/\.(module|php|inc)$/', $file->getFilename())) {
      $src = file_get_contents($file->getPathname());
      if (str_contains($src, 'field_certification_number')) {
        $hits[] = str_replace(DRUPAL_ROOT . '/', '', $file->getPathname());
      }
    }
  }
}
$line('  files referencing field_certification_number: ' . ($hits ? implode(', ', array_unique($hits)) : 'NONE FOUND ⚠'));
$bdefs = $efm->getFieldDefinitions('wo_tasks_list', 'backflow_testing');
foreach (['field_certification_number', 'field_tester', 'field_test_date'] as $f) {
  $line(sprintf('  wo_tasks_list.backflow_testing.%-28s %s', $f, isset($bdefs[$f]) ? 'present' : 'ABSENT'));
}

/* 9. The supersede precedent (§4). */
$h('9. SUPERSEDE PRECEDENT (§4)');
$ddefs = $efm->getFieldDefinitions('property_backflow_device', 'property_backflow_device');
if (!$ddefs) {
  foreach (array_keys($etm->getDefinitions()) as $id) {
    if (str_contains($id, 'backflow_device')) { $line('  entity id found: ' . $id); }
  }
}
foreach (['field_replaced_by', 'field_status', 'field_next_due_date'] as $f) {
  $line(sprintf('  property_backflow_device.%-22s %s', $f, isset($ddefs[$f]) ? 'present (' . $ddefs[$f]->getType() . ')' : 'absent/unknown'));
}

/* 10. Field-access precedent (§7). */
$h('10. FIELD-ACCESS PRECEDENT (§7)');
$line('  material_entity_field_access exists: ' . (function_exists('material_entity_field_access') ? 'yes' : 'not loaded here'));
$mf = DRUPAL_ROOT . '/modules/custom/material/material.module';
$line('  material.module hook_entity_field_access present: ' . (is_readable($mf) && str_contains(file_get_contents($mf), 'entity_field_access') ? 'yes — the template' : 'no'));

/* 11. Date-driven status: existing cron patterns. */
$h('11. DATE-DRIVEN STATUS PATTERNS (§4)');
foreach (['backflow_device', 'contract_residential', 'bos_scheduling'] as $m) {
  $f2 = DRUPAL_ROOT . '/modules/custom/' . $m . '/' . $m . '.module';
  if (is_readable($f2)) {
    $src = file_get_contents($f2);
    $line(sprintf('  %-22s hook_cron: %s', $m, str_contains($src, "function {$m}_cron") ? 'YES' : 'no'));
  }
}
