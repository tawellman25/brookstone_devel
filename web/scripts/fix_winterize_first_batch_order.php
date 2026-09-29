<?php

declare(strict_types=1);

/**
 * Move the first ten applied stops to the end of their route, not the front.
 *
 * Those ten were applied before the planner learned to append: the rank started
 * at 1 unconditionally, so each landed on top of the existing first stop for
 * that date and technician. Every one of the ten duplicated an order.
 *
 * Only those ten records are touched. Existing stops are left exactly as they
 * are — some routes have been deliberately arranged in the Route Editor
 * (field_route_order_set), and renumbering a whole group would throw that away
 * to fix someone else's row.
 *
 * Day boundaries in PHP, never FROM_UNIXTIME — see existingRouteOrderMax().
 *
 * Idempotent: a record already past the group max is left alone.
 * Dry-run by default; BOS_ORDERFIX_APPLY=1 to write.
 */

$apply = getenv('BOS_ORDERFIX_APPLY') === '1';
$WOS = [53337, 53439, 53445, 53521, 53532, 53572, 53577, 53579, 53650, 53793];

$db = \Drupal::database();
$schedStorage = \Drupal::entityTypeManager()->getStorage('scheduling');
$tz = new \DateTimeZone(date_default_timezone_get());

foreach ($WOS as $woId) {
  $ids = $schedStorage->getQuery()->accessCheck(FALSE)->condition('field_work_order', $woId)->execute();
  if (!$ids) {
    printf("  skip    WO %-7s no scheduling record\n", $woId);
    continue;
  }
  foreach ($schedStorage->loadMultiple($ids) as $sched) {
    $ts = (int) $sched->get('field_date')->value;
    $tech = $sched->get('field_assigned_to')->isEmpty() ? NULL : (int) $sched->get('field_assigned_to')->target_id;
    $mine = (int) ($sched->get('field_scheduled_oder')->value ?? 0);

    $day = (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d');
    $start = (new \DateTime($day . ' 00:00:00', $tz))->getTimestamp();
    $end = (new \DateTime($day . ' 23:59:59', $tz))->getTimestamp();

    // Highest order on that day for that tech, EXCLUDING this record.
    $q = $db->select('scheduling__field_date', 'd');
    $q->leftJoin('scheduling__field_assigned_to', 'a', 'a.entity_id = d.entity_id');
    $q->leftJoin('scheduling__field_scheduled_oder', 'o', 'o.entity_id = d.entity_id');
    $q->addExpression('MAX(o.field_scheduled_oder_value)', 'mx');
    $q->condition('d.field_date_value', [$start, $end], 'BETWEEN');
    $q->condition('d.entity_id', $sched->id(), '<>');
    if ($tech !== NULL) {
      $q->condition('a.field_assigned_to_target_id', $tech);
    }
    else {
      $q->isNull('a.field_assigned_to_target_id');
    }
    $max = (int) $q->execute()->fetchField();
    $want = $max + 1;

    if ($mine > $max) {
      printf("  ok      WO %-7s %s tech=%-7s order %d already past the group max %d\n", $woId, $day, $tech ?: 'none', $mine, $max);
      continue;
    }
    printf("  %s WO %-7s %s tech=%-7s order %d -> %d\n", $apply ? 'WRITE  ' : 'would  ', $woId, $day, $tech ?: 'none', $mine, $want);
    if ($apply) {
      $sched->set('field_scheduled_oder', $want);
      $sched->save();
    }
  }
}
if (!$apply) { print "\nDRY RUN. BOS_ORDERFIX_APPLY=1 to write.\n"; }
