<?php

/**
 * Gate 2A acceptance — createFromText. Run: drush php:script <this>.
 * Creates real WOs/notes on the LOCAL synced DB and cleans them all up at the end.
 */

$svc = \Drupal::service('bos_wo_intake.intake');
$etm = \Drupal::entityTypeManager();
$woStorage = $etm->getStorage('work_order');
$noteStorage = $etm->getStorage('wo_notes');

$actors = $etm->getStorage('user')->loadByProperties(['name' => 'cowork-connect']);
$actor = reset($actors);

$createdWo = [];
$createdNotes = [];
$note = function ($r) use (&$createdWo, &$createdNotes) {
  if (($r['status'] ?? '') === 'created') {
    $createdWo[] = $r['work_order']['id'];
    foreach (($r['note_ids'] ?? []) as $n) {
      $createdNotes[] = $n;
    }
  }
};
$line = fn($s) => print($s . "\n");
$brief = function ($r) {
  $s = $r['status'] ?? ($r['success'] ?? '?');
  $out = "status=$s";
  if ($s === 'created') {
    $out .= " wo={$r['work_order']['id']} bundle={$r['work_order']['bundle']} flags=[" . implode(',', $r['flags'] ?? []) . "] notes=[" . implode(',', $r['note_ids'] ?? []) . "]";
  } elseif ($s === 'ambiguous') {
    $out .= " piece={$r['piece']} candidates=" . count($r['candidates']);
    if (!empty($r['conflict'])) {
      $out .= " CONFLICT(" . $r['conflict']['field'] . " expected=" . $r['conflict']['expected'] . " actual=" . $r['conflict']['actual'] . ")";
    }
  } elseif ($s === 'blocked') {
    $out .= " reason={$r['reason']} existing_wo={$r['existing']['id']}";
  } elseif ($s === 'error' || $s === FALSE) {
    $out .= " code=" . ($r['error']['code'] ?? '?');
  }
  return $out;
};

$L = 'Lyman'; $P = 28323; $REPAIR = 368;

$line("================ GATE 2A ACCEPTANCE ================");

// 1 — full clean command, natural-order name vs comma-stored nickname.
$cmd = "Create a repair work order for Jim Lyman on Willow Dr. They have a broken sprinkler.";
$r = $svc->createFromText($cmd, $actor); $note($r);
$woA = $r['work_order']['id'] ?? NULL;
$propOk = $woA ? ((int) $woStorage->loadUnchanged($woA)->get('field_property')->target_id === $P) : FALSE;
$noteBody = ($r['note_ids'] ?? []) ? $noteStorage->load($r['note_ids'][0])->get('field_note_text')->value : '';
$line("1  cmd: $cmd");
$line("   " . $brief($r) . " | property==$P? " . ($propOk ? 'YES' : 'NO') . " | complaint_note=\"" . trim(strip_tags($noteBody)) . "\"");

// clean slate before #2
if ($woA) { _wo2a_del($woA, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woA]); }

// 2 — sloppy thumb input, same target.
$cmd = "repair wo jim lyman willow dr";
$r = $svc->createFromText($cmd, $actor); $note($r);
$woB = $r['work_order']['id'] ?? NULL;
$propOk = $woB ? ((int) $woStorage->loadUnchanged($woB)->get('field_property')->target_id === $P) : FALSE;
$line("2  cmd: $cmd");
$line("   " . $brief($r) . " | property==$P? " . ($propOk ? 'YES' : 'NO'));
if ($woB) { _wo2a_del($woB, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woB]); }

// 3 — town filter: mclaughlin in 3 towns.
$r = $svc->createFromText("repair for mclaughlin", $actor);
$line("3a cmd: repair for mclaughlin  (no town)");
$line("   " . $brief($r) . "  towns: " . implode(', ', array_map(fn($c) => $c['town'], $r['candidates'] ?? [])));
$r2 = $svc->createFromText("repair for mclaughlin in delta", $actor); $note($r2);
$w3 = $r2['work_order']['id'] ?? NULL;
$p3 = $w3 ? (int) $woStorage->loadUnchanged($w3)->get('field_property')->target_id : NULL;
$line("3b cmd: repair for mclaughlin in delta");
$line("   " . $brief($r2) . " | resolved property=$p3");
if ($w3) { _wo2a_del($w3, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$w3]); }

// 4 — business single-token nickname. allow_duplicate: MSHA has a real active repair
// WO in the synced prod data, so bypass the dup tier to prove resolve->create cleanly.
$r = $svc->createFromText("repair for msha in delta", $actor, ['allow_duplicate' => TRUE]); $note($r);
$w4 = $r['work_order']['id'] ?? NULL;
$p4 = $w4 ? (int) $woStorage->loadUnchanged($w4)->get('field_property')->target_id : NULL;
$line("4  cmd: repair for msha in delta");
$line("   " . $brief($r) . " | resolved property=$p4 (expect 28420 MSHA)");
if ($w4) { _wo2a_del($w4, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$w4]); }

// 5 — conflict: name+street unique but wrong town.
$r = $svc->createFromText("repair for jim lyman on willow in delta", $actor);
$line("5  cmd: repair for jim lyman on willow in delta  (Lyman is in Hotchkiss)");
$line("   " . $brief($r));

// 6 — service ambiguity: "design" -> 2 terms.
$r = $svc->createFromText("design work order for jim lyman", $actor);
$line("6  cmd: design work order for jim lyman");
$line("   " . $brief($r) . "  services: " . implode(', ', array_map(fn($c) => $c['name'] . '/' . $c['bundle'], $r['candidates'] ?? [])));

// 7 — options bypass: junk text + explicit ids.
$r = $svc->createFromText("total gibberish zzz qqq", $actor, ['property_id' => $P, 'service_term_id' => $REPAIR]); $note($r);
$w7 = $r['work_order']['id'] ?? NULL;
$p7 = $w7 ? (int) $woStorage->loadUnchanged($w7)->get('field_property')->target_id : NULL;
$line("7  cmd: (junk) + property_id=$P service_term_id=$REPAIR");
$line("   " . $brief($r) . " | resolved property=$p7");
if ($w7) { _wo2a_del($w7, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$w7]); }

// 8 — active duplicate.
$r1 = $svc->createFromText("repair for jim lyman on willow dr", $actor); $note($r1);
$woDup = $r1['work_order']['id'] ?? NULL;
$r2 = $svc->createFromText("repair for jim lyman on willow dr", $actor);
$line("8  first -> " . $brief($r1));
$line("   second (dup) -> " . $brief($r2));
$r3 = $svc->createFromText("repair for jim lyman on willow dr", $actor, ['allow_duplicate' => TRUE]); $note($r3);
$line("   with allow_duplicate=TRUE -> " . $brief($r3));
$woDup2 = $r3['work_order']['id'] ?? NULL;

// 9 — terminal-recent: remove the still-active allow_dup WO, complete woDup so it
// is the only prior (terminal, recent), then re-run -> created + system flag note.
if ($woDup2) { _wo2a_del($woDup2, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woDup2]); $woDup2 = NULL; }
if ($woDup) {
  $w = $woStorage->loadUnchanged($woDup);
  $w->set('field_status', ['target_id' => 1097]);
  $w->setChangedTime(\Drupal::time()->getRequestTime());
  $w->_skip_invoiced_guard = TRUE;
  $w->save();
}
$r = $svc->createFromText("repair for jim lyman on willow dr", $actor); $note($r);
$w9 = $r['work_order']['id'] ?? NULL;
$sysNote = '';
foreach (($r['note_ids'] ?? []) as $nid) {
  $nn = $noteStorage->load($nid);
  if ($nn && (bool) $nn->get('field_is_system_note')->value) {
    $sysNote = trim(strip_tags($nn->get('field_note_text')->value));
  }
}
$line("9  complete the prior WO, re-run repair for jim lyman");
$line("   " . $brief($r) . " | system_flag_note=\"" . $sysNote . "\"");
if ($w9) { _wo2a_del($w9, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$w9]); }
if ($woDup2) { _wo2a_del($woDup2, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woDup2]); }
if ($woDup) { _wo2a_del($woDup, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woDup]); }

// 10 — guarded service (weed_spraying): create one, re-run defers to bundle guard.
$r1 = $svc->createFromText("weed control for jim lyman on willow dr", $actor); $note($r1);
$woWS = $r1['work_order']['id'] ?? NULL;
$r2 = $svc->createFromText("weed control for jim lyman on willow dr", $actor);
$line("10 weed control first -> " . $brief($r1) . (($r1['work_order']['bundle'] ?? '') === 'weed_spraying' ? ' (weed_spraying OK)' : ''));
$line("   weed control second -> " . $brief($r2) . "  (defers to wo_weed_spraying guard)");
if ($woWS) { _wo2a_del($woWS, $woStorage, $noteStorage); $createdWo = array_diff($createdWo, [$woWS]); }

// 11 — access denied: no-role actor.
$dummy = \Drupal\user\Entity\User::create(['name' => 'wo2a-noaccess-tmp', 'status' => 1, 'mail' => 'wo2a-tmp@example.com']);
$dummy->save();
$before = (int) $woStorage->getQuery()->accessCheck(FALSE)->count()->execute();
$r = $svc->createFromText("repair for jim lyman on willow dr", $dummy);
$after = (int) $woStorage->getQuery()->accessCheck(FALSE)->count()->execute();
$line("11 createFromText as no-role account");
$line("   " . $brief($r) . " | wo_count unchanged? " . ($before === $after ? 'YES' : 'NO'));
$dummy->delete();

// final cleanup sweep + assertion.
foreach ($createdWo as $id) { _wo2a_del($id, $woStorage, $noteStorage); }
$line("================ cleanup: removed " . count(array_unique($createdWo)) . " stray WOs; test WOs deleted inline ================");

function _wo2a_del($id, $woStorage, $noteStorage) {
  $wo = $woStorage->loadUnchanged($id);
  if (!$wo) { return; }
  // Delete referencing notes first.
  $nids = $noteStorage->getQuery()->accessCheck(FALSE)->condition('field_work_order', $id)->execute();
  foreach ($noteStorage->loadMultiple($nids) as $n) { $n->delete(); }
  // Satisfy the deletion guard (non-complete are deletable).
  $wo->set('field_status', ['target_id' => 1089]);
  $wo->_skip_invoiced_guard = TRUE;
  try { $wo->save(); } catch (\Throwable $e) {}
  try { $wo->delete(); } catch (\Throwable $e) { print "   [cleanup warn] WO $id: " . $e->getMessage() . "\n"; }
}
