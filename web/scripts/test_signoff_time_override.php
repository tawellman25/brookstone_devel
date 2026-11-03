<?php

/**
 * @file
 * The sign-off form must be able to confirm a genuine long day.
 *
 * Until 2026-11-02 the entry-hours cap had exactly one escape hatch —
 * `field_time_limit_override`, a checkbox on the standalone time-entry form —
 * and the sign-off reconciliation path created entries WITHOUT that field. So
 * when a cap was wrong for a bundle, the foreman had no way through and the
 * job simply would not close until somebody changed code. That is what
 * happened to fall_cleanup (WO 54225): a plain 6.01-hour entry was rejected
 * and the sign-off failed.
 *
 * Moving two bundles onto the long-job list fixed those two. This asserts the
 * mechanism, which is what stops the next bundle repeating it:
 *
 *   1. Over the cap, no override  -> still rejected (the guard is intact).
 *   2. Over the cap, override set -> accepted, and the override is recorded.
 *   3. Under the cap              -> unaffected either way.
 *
 * ⚠ WRITES AND ROLLS BACK. Uses an explicit $tx->rollBack(). Do NOT switch
 * this to unset($tx) — unsetting a Drupal Transaction COMMITS it.
 *
 * Run: drush php:script web/scripts/test_signoff_time_override.php
 */

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $good, string $detail = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : '');
};

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

// A work order on a bundle that still uses the 4-hour default, so the cap is
// genuinely in play. lawn_mowing is deliberately NOT on the long-job list.
$woId = (int) $db->query("SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE wo.type = 'lawn_mowing' AND st.field_status_target_id NOT IN (1098)
  ORDER BY wo.id DESC LIMIT 1")->fetchField();
if (!$woId) {
  print "ABORT: no usable lawn_mowing work order.\n";
  return;
}
$cap = (float) _wo_total_time_get_max_entry_hours('lawn_mowing');
$uid = (int) $db->query('SELECT uid FROM {users_field_data} WHERE status = 1 AND uid > 1 LIMIT 1')->fetchField();
printf("WO %d (lawn_mowing), cap %.2fh, test uid %d\n\n", $woId, $cap, $uid);
$ok('the test bundle really is capped low', $cap > 0 && $cap < 10, sprintf('%.2fh', $cap));

/** Try to save an entry of $hours, with or without the override. */
$attempt = function (float $hours, bool $override) use ($etm, $woId, $uid): array {
  $start = (new DateTime('-' . (int) round($hours * 60) . ' minutes'))->setTimezone(new DateTimeZone('UTC'));
  $end = (new DateTime('now'))->setTimezone(new DateTimeZone('UTC'));
  $e = $etm->getStorage('wo_time_clock')->create([
    'type' => 'entry',
    'field_source' => 'signoff_reconciliation',
    'field_work_order' => $woId,
    'field_teammate' => $uid,
    'uid' => $uid,
    'field_start_time' => $start->format('Y-m-d\TH:i:s'),
    'field_end_time' => $end->format('Y-m-d\TH:i:s'),
    'field_notes' => '[automated test]',
    'field_time_limit_override' => $override ? 1 : 0,
  ]);
  $e->_signoff_reconciliation = TRUE;
  try {
    $e->save();
    return ['saved' => TRUE, 'entity' => $e, 'msg' => ''];
  }
  catch (\Throwable $ex) {
    return ['saved' => FALSE, 'entity' => NULL, 'msg' => $ex->getMessage()];
  }
};

$tx = $db->startTransaction();
try {
  $over = $cap + 2.0;

  // 1. Over the cap without the override — must still be refused.
  $r = $attempt($over, FALSE);
  $ok('over the cap WITHOUT override is still rejected', $r['saved'] === FALSE,
    $r['saved'] ? 'it saved — guard is broken' : mb_substr($r['msg'], 0, 54));

  // 2. Over the cap WITH the override — this is the new capability.
  $r2 = $attempt($over, TRUE);
  $ok('over the cap WITH override is accepted', $r2['saved'] === TRUE,
    $r2['saved'] ? sprintf('%.2fh accepted', $over) : mb_substr($r2['msg'], 0, 60));

  if ($r2['saved']) {
    $fresh = $etm->getStorage('wo_time_clock')->loadUnchanged($r2['entity']->id());
    $ok('  the override is recorded on the entry',
      (int) ($fresh->get('field_time_limit_override')->value ?? 0) === 1);
    // ⚠ field_notes is UNLIMITED cardinality and the guard uses appendItem(),
    // so the override stamp is a SECOND value — reading ->value (item 0)
    // returns the original note and looks like the audit never happened.
    $notes = array_column($fresh->get('field_notes')->getValue(), 'value');
    $stamp = '';
    foreach ($notes as $n) {
      if (stripos((string) $n, 'Time limit override') !== FALSE) {
        $stamp = (string) $n;
      }
    }
    $ok('  an audit note marks it as an override', $stamp !== '',
      $stamp !== '' ? mb_substr($stamp, 0, 72) : count($notes) . ' note value(s), none stamped');
  }

  // 3. A normal entry is unaffected.
  $r3 = $attempt(max(0.5, $cap - 1.0), FALSE);
  $ok('under the cap still saves with no override', $r3['saved'] === TRUE,
    $r3['saved'] ? '' : mb_substr($r3['msg'], 0, 60));
}
finally {
  // Explicit. unset() would COMMIT.
  $tx->rollBack();
  print "\nrolled back.\n";
}

// Prove the rollback actually took, rather than trusting it.
$left = (int) $db->query('SELECT COUNT(*) FROM {wo_time_clock__field_notes}
  WHERE deleted = 0 AND field_notes_value LIKE :p', [':p' => '%[automated test]%'])->fetchField();
$ok('no test rows survive the rollback', $left === 0, "$left found");

printf("\n%d passed, %d failed\n", $pass, $fail);
