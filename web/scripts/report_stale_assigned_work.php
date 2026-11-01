<?php

/**
 * @file
 * The ageing assigned-work backlog, oldest first — triage before FIFO goes live.
 *
 * The teammate dashboard orders each person's work by the work order's created
 * date. Measured on live 2026-11-01, only 17 of 107 open assigned work orders
 * are under two weeks old and 29 are over six months, so switching FIFO on puts
 * a 902-day-old job at the top of somebody's list on day one. This is the list
 * to clear first.
 *
 * Age alone does not say whether a job is dead, so each row carries the two
 * signals that actually decide it:
 *
 *   TIME   — minutes ever clocked against it. Zero means nobody has started it.
 *   LAST   — the most recent status update. Silence for a year is the tell.
 *
 * A work order with no time logged and no status change in a year is almost
 * certainly finished-but-unclosed, or abandoned. One with time logged is a real
 * job someone stopped halfway, which is a different conversation.
 *
 * READ-ONLY. Writes a CSV alongside the table so it can be worked through.
 *
 * BOS_DAYS=180 to change the threshold. Run:
 *   drush php:script web/scripts/report_stale_assigned_work.php
 */

const CLOSED = [1097, 1098, 1281, 1283, 1504];

$days = (int) (getenv('BOS_DAYS') ?: 180);
$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

$q = $db->select('scheduling__field_assigned_to', 'a');
$q->condition('a.deleted', 0);
$q->join('scheduling__field_work_order', 'swo', 'swo.entity_id = a.entity_id AND swo.deleted = 0');
$q->join('work_order_field_data', 'wo', 'wo.id = swo.field_work_order_target_id');
$q->join('work_order__field_status', 'st', 'st.entity_id = wo.id AND st.deleted = 0');
$q->condition('st.field_status_target_id', CLOSED, 'NOT IN');
$q->condition('wo.created', $cutoff, '<');
$q->addField('wo', 'id', 'wo_id');
$q->addField('wo', 'created', 'created');
$q->addField('wo', 'type', 'bundle');
$q->addField('st', 'field_status_target_id', 'status_tid');
$q->addField('a', 'field_assigned_to_target_id', 'uid');
$q->leftJoin('work_order__field_property', 'wp', 'wp.entity_id = wo.id AND wp.deleted = 0');
$q->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wp.field_property_target_id AND nick.deleted = 0');
$q->addField('nick', 'field_nickname_value', 'property');
$q->distinct();
$q->orderBy('wo.created', 'ASC');
$rows = $q->execute()->fetchAll();

if (!$rows) {
  printf("No open assigned work older than %d days. Nothing to triage.\n", $days);
  return;
}

/** Minutes ever clocked against a work order. */
$minutesOn = function (int $woId) use ($db): int {
  $r = $db->query('SELECT s.field_start_time_value st, e.field_end_time_value et
    FROM {wo_time_clock__field_work_order} w
    LEFT JOIN {wo_time_clock__field_start_time} s ON s.entity_id = w.entity_id AND s.deleted = 0
    LEFT JOIN {wo_time_clock__field_end_time} e ON e.entity_id = w.entity_id AND e.deleted = 0
    WHERE w.deleted = 0 AND w.field_work_order_target_id = :id', [':id' => $woId])->fetchAll();
  $mins = 0;
  foreach ($r as $one) {
    if (empty($one->st) || empty($one->et)) {
      continue;
    }
    $mins += max(0, (int) round(((new \DateTime($one->et . 'Z'))->getTimestamp()
      - (new \DateTime($one->st . 'Z'))->getTimestamp()) / 60));
  }
  return $mins;
};

/** The most recent status update on a work order. */
$lastTouch = function (int $woId) use ($db): ?int {
  return $db->query('SELECT MAX(su.created) FROM {wo_status_updates__field_status_of_wo} sw
    JOIN {wo_status_updates_field_data} su ON su.id = sw.entity_id
    WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id = :id', [':id' => $woId])->fetchField() ?: NULL;
};

$statusLabel = [];
$names = [];
$now = \Drupal::time()->getRequestTime();
$csv = ["age_days,wo_id,service,assigned_to,property,status,minutes_logged,last_status_update"];

printf("Open assigned work older than %d days: %d\n\n", $days, count($rows));
printf("%-5s %-8s %-22s %-18s %-24s %-14s %-7s %s\n",
  'AGE', 'WO', 'SERVICE', 'ASSIGNED TO', 'PROPERTY', 'STATUS', 'TIME', 'LAST UPDATE');
printf("%s\n", str_repeat('-', 125));

$neverStarted = 0;
foreach ($rows as $r) {
  $uid = (int) $r->uid;
  if (!isset($names[$uid])) {
    $u = $etm->getStorage('user')->load($uid);
    $names[$uid] = $u ? $u->getDisplayName() : "uid $uid";
  }
  $tid = (int) $r->status_tid;
  if (!isset($statusLabel[$tid])) {
    $t = $etm->getStorage('taxonomy_term')->load($tid);
    $statusLabel[$tid] = $t ? $t->label() : "tid $tid";
  }
  $age = (int) floor(($now - (int) $r->created) / 86400);
  $mins = $minutesOn((int) $r->wo_id);
  $touch = $lastTouch((int) $r->wo_id);
  $touchStr = $touch ? date('m/d/Y', $touch) : 'never';
  if ($mins === 0) {
    $neverStarted++;
  }
  printf("%-5d %-8d %-22s %-18s %-24s %-14s %-7s %s\n",
    $age, (int) $r->wo_id,
    mb_substr((string) $r->bundle, 0, 22),
    mb_substr($names[$uid], 0, 18),
    mb_substr(trim((string) ($r->property ?? '')) ?: '-', 0, 24),
    mb_substr($statusLabel[$tid], 0, 14),
    $mins > 0 ? round($mins / 60, 1) . 'h' : 'NONE',
    $touchStr);
  $csv[] = sprintf('%d,%d,"%s","%s","%s","%s",%d,"%s"',
    $age, (int) $r->wo_id, $r->bundle, $names[$uid],
    str_replace('"', "'", (string) ($r->property ?? '')), $statusLabel[$tid], $mins, $touchStr);
}

$out = ($_SERVER['HOME'] ?? '/tmp') . '/tmp/stale_assigned_work.csv';
@mkdir(dirname($out), 0755, TRUE);
@file_put_contents($out, implode("\n", $csv) . "\n");

printf("\n%d of %d have NO time ever logged — nobody has started them.\n", $neverStarted, count($rows));
printf("CSV: %s\n", $out);
