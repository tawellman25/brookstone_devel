<?php

/**
 * Move a vocabulary's public copy out of core `description` into
 * field_public_description — STEP 1 of the retirement, and only step 1.
 *
 * Why: plant_characteristics (41 terms) and growth_zone (26) never got the
 * field_public_description instance, so their public copy still sits in core
 * `description` while every other vocabulary uses the field. Core description is
 * one plain field; the BOS content model needs public / teammate / short to be
 * separate, and the queued characteristic bodies have nowhere to go until the
 * field exists.
 *
 * ORDER MATTERS AND THIS SCRIPT ONLY DOES THE COPY. Core description is the
 * source of the taxonomy metatag default ([term:description]), so clearing it
 * before the meta descriptions have been re-sourced strips them from every term
 * page — the trap hit on the earlier spraying_locations pass. Sequence:
 *   1. this script (copy)
 *   2. verify all terms populated and identical
 *   3. repoint displays + views, add field_meta_tags, seed meta descriptions
 *   4. verify rendered pages AND meta descriptions, anonymously
 *   5. only then retire_vocab_core_description.php
 *
 * Note: core `description` is a BASE field on taxonomy_term. It cannot be
 * deleted per vocabulary — it can only be emptied and hidden from the form and
 * displays, which is what "retired" means here.
 *
 * Never overwrites a non-empty field_public_description; reports and skips.
 *
 * Usage:
 *   BOS_VID=plant_characteristics drush php:script web/scripts/migrate_vocab_core_description_to_public.php
 *   BOS_VID=plant_characteristics BOS_MIGRATE_APPLY=1 drush php:script ...
 */

$vid = getenv('BOS_VID') ?: '';
$apply = getenv('BOS_MIGRATE_APPLY') === '1';
if ($vid === '') {
  print "ERROR: set BOS_VID to the vocabulary machine name. Aborting.\n";
  return;
}

$etm = \Drupal::entityTypeManager();
if (!$etm->getStorage('taxonomy_vocabulary')->load($vid)) {
  printf("ERROR: no such vocabulary '%s'. Aborting.\n", $vid);
  return;
}

printf("VOCAB: %s   MODE: %s\n\n", $vid, $apply ? 'APPLY' : 'DRY-RUN (set BOS_MIGRATE_APPLY=1 to write)');

// --- the field instance -----------------------------------------------------
$efm = \Drupal::service('entity_field.manager');
$defs = $efm->getFieldDefinitions('taxonomy_term', $vid);
if (!isset($defs['field_public_description'])) {
  $storage = $etm->getStorage('field_storage_config')->load('taxonomy_term.field_public_description');
  if (!$storage) {
    print "ERROR: field_public_description storage does not exist on taxonomy_term. Aborting.\n";
    return;
  }
  printf("field instance: MISSING on %s — will be created (text_long)\n", $vid);
  if ($apply) {
    $etm->getStorage('field_config')->create([
      'field_storage' => $storage,
      'bundle' => $vid,
      'label' => 'Public Description',
      'description' => 'The public body copy for this term page.',
      'required' => FALSE,
    ])->save();
    // Put it on the term form so the office can edit it.
    $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $vid . '.default');
    if ($form) {
      $form->setComponent('field_public_description', [
        'type' => 'text_textarea',
        'weight' => 1,
        'settings' => ['rows' => 9],
      ])->save();
      print "  added to the term edit form\n";
    }
    $efm->clearCachedFieldDefinitions();
  }
}
else {
  print "field instance: already present\n";
}
print "\n";

// --- the copy ---------------------------------------------------------------
$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]);
$copied = $already = $skipped = $emptySource = 0;
$backup = [];

foreach ($terms as $t) {
  $core = $t->get('description');
  $coreVal = $core->isEmpty() ? '' : (string) ($core->first()->getValue()['value'] ?? '');
  $coreFmt = $core->isEmpty() ? 'full_html' : ($core->first()->getValue()['format'] ?? 'full_html');

  if (trim(strip_tags($coreVal)) === '') {
    $emptySource++;
    continue;
  }
  if (!$t->hasField('field_public_description')) {
    // Only possible on a dry run, before the instance exists.
    printf("  %-30s would copy %d chars (field not yet created)\n", mb_substr((string) $t->label(), 0, 29), mb_strlen($coreVal));
    $copied++;
    continue;
  }
  $pub = $t->get('field_public_description');
  if (!$pub->isEmpty() && trim((string) $pub->first()->getValue()['value']) !== '') {
    $existing = (string) $pub->first()->getValue()['value'];
    if (trim($existing) === trim($coreVal)) {
      $already++;
      continue;
    }
    // Never clobber copy somebody wrote into the new field.
    printf("  %-30s SKIPPED — field_public_description already holds different copy (%d vs %d chars)\n",
      mb_substr((string) $t->label(), 0, 29), mb_strlen($existing), mb_strlen($coreVal));
    $skipped++;
    continue;
  }

  printf("  %-30s copy %d chars [%s]\n", mb_substr((string) $t->label(), 0, 29), mb_strlen($coreVal), $coreFmt);
  $copied++;
  $backup[] = ['tid' => $t->id(), 'name' => (string) $t->label(), 'core_description' => $coreVal, 'format' => $coreFmt];
  if ($apply) {
    // Verbatim, format preserved. Core description is NOT cleared here.
    $t->set('field_public_description', ['value' => $coreVal, 'format' => $coreFmt])->save();
  }
}

if ($backup) {
  $f = sys_get_temp_dir() . '/core_desc_migration_' . $vid . '_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("\nsource values recorded -> %s\n", $f);
}

printf("\n%d copied, %d already identical, %d skipped (new field held other copy), %d had no core description\n",
  $copied, $already, $skipped, $emptySource);

// --- verification: identical, nothing truncated ----------------------------
if ($apply) {
  print "\nverifying...\n";
  $etm->getStorage('taxonomy_term')->resetCache();
  $bad = 0; $ok = 0;
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
    $core = $t->get('description');
    $coreVal = $core->isEmpty() ? '' : (string) ($core->first()->getValue()['value'] ?? '');
    if (trim(strip_tags($coreVal)) === '') { continue; }
    $pub = $t->get('field_public_description');
    $pubVal = $pub->isEmpty() ? '' : (string) $pub->first()->getValue()['value'];
    if (trim($pubVal) !== trim($coreVal)) {
      printf("  MISMATCH %-28s core %d chars vs public %d chars\n", (string) $t->label(), mb_strlen($coreVal), mb_strlen($pubVal));
      $bad++;
    }
    else { $ok++; }
  }
  printf("  %d identical, %d mismatched\n", $ok, $bad);
  if ($bad) {
    print "  ** DO NOT PROCEED to the retire step until this is zero **\n";
  }
}
else {
  print "\nNothing written. Re-run with BOS_MIGRATE_APPLY=1 to apply.\n";
}
