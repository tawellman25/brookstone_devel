<?php

/**
 * Can these four bundles build their label on the FIRST save?
 *
 * Each one is configured to defer its label to a second save that contrib runs
 * in a PHP shutdown function — after the response is sent — even though none of
 * their patterns contains a post-save token. The risk worth measuring is that
 * three of the four reach through a REFERENCE CHAIN to another entity
 * ([media:field_work_order:entity:title], and snow routes go user -> profile ->
 * first name). Those referenced entities already exist, so the chain should
 * resolve while the entity itself is still unsaved — but that is the claim
 * under test, not an assumption.
 *
 * Behaviour 0 literally calls $decorated->setLabel() from presave on a new
 * entity, so calling it on an unsaved entity reproduces it exactly. The two
 * self-contained bundles also get a real create/read/delete.
 */

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$dec = \Drupal::service('auto_entitylabel.entity_decorator');
$pass = 0;
$fail = 0;
$check = function (string $what, bool $ok, string $got = '') use (&$pass, &$fail) {
  $ok ? $pass++ : $fail++;
  printf("  %s  %-54s %s\n", $ok ? 'PASS' : 'FAIL', $what, $got);
};

// Reference targets that already exist.
$typeTid = $db->query('SELECT tid FROM {taxonomy_term_field_data} WHERE vid = :v LIMIT 1', [':v' => 'equipment_types'])->fetchField();
$fid = $db->query('SELECT fid FROM {file_managed} ORDER BY fid LIMIT 1')->fetchField();
$woId = $db->query('SELECT id FROM {work_order_field_data} ORDER BY id DESC LIMIT 1')->fetchField();
// A teammate who actually has a profile with a first name — so the deep chain is real.
$uid = $db->query("SELECT p.uid FROM {profile} p INNER JOIN {profile__field_first_name} f ON f.entity_id = p.profile_id WHERE p.type = :t AND f.field_first_name_value <> '' LIMIT 1", [':t' => 'teammate_profile'])->fetchField();
printf("mode: %s\n", getenv('BOS_TEST_READONLY') === '1' ? 'READ-ONLY (creates nothing)' : 'full (creates and deletes test records)');
printf("reference targets: equipment_type=%s file=%s work_order=%s teammate_uid=%s\n\n", $typeTid, $fid, $woId, $uid ?: 'none');

$cases = [
  'equipment.heavy_equipment' => [
    'type' => 'equipment', 'bundle' => 'heavy_equipment', 'real' => TRUE,
    'values' => [
      'type' => 'heavy_equipment',
      'field_equipment_make' => 'TESTMAKE',
      'field_model' => 'TESTMODEL',
      'field_equipment_number' => 'TEST-99',
      'field_equipment_type' => $typeTid,
    ],
    'expect' => '/TESTMAKE .* TESTMODEL - TEST-99/',
  ],
  'taxonomy_term.snow_plow_routes' => [
    'type' => 'taxonomy_term', 'bundle' => 'snow_plow_routes', 'real' => TRUE,
    'values' => [
      'vid' => 'snow_plow_routes',
      'name' => 'placeholder',
      'field_route_name' => 'TEST ROUTE',
      'field_assigned_teammate' => $uid,
    ],
    'expect' => '/ - TEST ROUTE$/',
  ],
  'media.wo_files' => [
    'type' => 'media', 'bundle' => 'wo_files', 'real' => FALSE,
    'values' => [
      'bundle' => 'wo_files',
      'field_media_file' => ['target_id' => $fid],
      'field_work_order' => $woId,
    ],
    'expect' => '/ File: .+/',
  ],
  // Deliberately asserted as INERT, not as a working pattern. The profile
  // entity type has NO label key, so AutoEntityLabelManager::hasLabel() is
  // FALSE and auto_entitylabel_entity_presave() skips profiles entirely — the
  // stored auto_entitylabel.settings.profile.teammate_profile config (pattern
  // and all) has never had any effect and can never write a placeholder.
  // Profiles are labelled by Profile::label() ('@type #@id', overridable via
  // ProfileLabelEvent). This was counted as a latent instance of the deferred
  // label bug on 2026-10-02 by pattern-matching alone; it is not one.
  'profile.teammate_profile' => [
    'type' => 'profile', 'bundle' => 'teammate_profile', 'inert' => TRUE,
    'values' => [
      'type' => 'teammate_profile',
      'uid' => 1,
      'field_first_name' => 'TESTFIRST',
      'field_last_name' => 'TESTLAST',
      'field_cell_number' => '970-555-0100',
    ],
  ],
];

foreach ($cases as $label => $case) {
  printf("=== %s ===\n", $label);
  $storage = $etm->getStorage($case['type']);

  $draft = $storage->create($case['values']);

  // An entity type with no label key is never touched by auto_entitylabel, so
  // its stored config is dead and there is nothing to flip.
  if (!empty($case['inert'])) {
    $hasLabel = $dec->decorate($draft)->hasLabel();
    $check('auto_entitylabel cannot act on it (no label key)', $hasLabel === FALSE, 'config is inert');
    print "\n";
    continue;
  }

  // 1. The pattern resolves on a NOT-YET-SAVED entity (what behaviour 0 does).
  try {
    $computed = (string) $dec->decorate($draft)->setLabel();
  }
  catch (\Throwable $e) {
    $check('pattern resolves before the first save', FALSE, $e->getMessage());
    print "\n";
    continue;
  }
  $check('pattern resolves before the first save', $computed !== '' && !str_contains($computed, '%AutoEntityLabel'), var_export($computed, TRUE));
  $check('and reads as intended', (bool) preg_match($case['expect'], $computed), '');

  // 2. For the self-contained ones, a real single save, then removed.
  // BOS_TEST_READONLY=1 skips this so the suite can be run against live
  // without creating production records (the pre-save check above is the
  // mechanism that matters; behaviour 0 runs exactly that code in presave).
  if (!empty($case['real']) && getenv('BOS_TEST_READONLY') !== '1') {
    $e = $storage->create($case['values']);
    try {
      $e->save();
      $stored = (string) $storage->loadUnchanged($e->id())->label();
      $check('a real single save stores the same label', $stored === $computed, var_export($stored, TRUE));
    }
    catch (\Throwable $ex) {
      $check('a real single save stores the same label', FALSE, $ex->getMessage());
    }
    finally {
      if (!$e->isNew()) {
        $id = $e->id();
        $e->delete();
        $check('test record removed', $storage->loadUnchanged($id) === NULL, '');
      }
    }
  }
  print "\n";
}

printf("%d passed, %d failed\n", $pass, $fail);
