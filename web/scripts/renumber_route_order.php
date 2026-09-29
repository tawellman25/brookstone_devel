<?php

declare(strict_types=1);

/**
 * Make each day's route order a dense 1..N again.
 *
 * Thirty (date, technician) groups in the 2026 winterizing season carry
 * duplicate, missing or gapped route orders — triplicate 1s, jumps to 10,
 * nulls. The likely source is the 2026-08-30 re-dating, which moved 443 stops
 * onto new days without renumbering them into the groups they landed in.
 *
 * The stops are on the calendar and assigned; it is the DRIVING ORDER within a
 * day that is ambiguous wherever numbers tie.
 *
 * ⚠ SKIPS ANY ROUTE THE OFFICE HAS ARRANGED. field_route_order_set is stamped
 * by the Route Editor whenever someone drags or optimises a route, and next
 * season's carry-forward treats such a route's order as authoritative. Renumber
 * one of those and you throw away deliberate work AND corrupt the signal the
 * next carry-forward learns from. If any record in a group carries the flag, the
 * whole group is left alone and reported.
 *
 * RELATIVE ORDER IS PRESERVED. Records are sorted by their current order, then
 * by id as a stable tiebreak, and renumbered 1..N — so a route that is merely
 * gapped keeps its sequence, and ties break consistently rather than randomly.
 *
 * Operates on the WHOLE group, including non-winterizing stops, because route
 * order is per day per technician regardless of service type — renumbering only
 * some of a day's stops would leave it just as ambiguous.
 *
 * Idempotent. Dry-run by default; BOS_RENUMBER_APPLY=1 to write.
 *   drush php:script web/scripts/renumber_route_order.php
 */

use Drupal\Core\Datetime\DrupalDateTime;

$apply = getenv('BOS_RENUMBER_APPLY') === '1';
$targetYear = (int) (getenv('BOS_RENUMBER_YEAR') ?: 2026);

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$tz = new \DateTimeZone(date_default_timezone_get());
$storage = $etm->getStorage('scheduling');

// FROM TODAY FORWARD ONLY. A route that has already been driven does not
// benefit from renumbering, and next season's carry-forward reads historical
// order as one of its signals — rewriting the past would feed it invented data.
// 1,082 of this season's records are already behind us; 639 are not.
$floor = (new DrupalDateTime('today', $tz))->getTimestamp();
$seasonStart = max($floor, (new DrupalDateTime("$targetYear-08-01 00:00:00", $tz))->getTimestamp());
printf("renumbering from %s forward\n\n", DrupalDateTime::createFromTimestamp($seasonStart, $tz)->format('Y-m-d'));
$seasonEnd = (new DrupalDateTime("$targetYear-12-31 23:59:59", $tz))->getTimestamp();

// Every (date, tech) group holding at least one stop in the season window.
$rows = $db->select('scheduling__field_date', 'd')
  ->fields('d', ['entity_id', 'field_date_value'])
  ->condition('d.field_date_value', [$seasonStart, $seasonEnd], 'BETWEEN')
  ->execute()->fetchAll();

$groups = [];
foreach ($rows as $r) {
  $day = DrupalDateTime::createFromTimestamp((int) $r->field_date_value, $tz)->format('Y-m-d');
  $groups[$day][] = (int) $r->entity_id;
}

$touched = $skippedArranged = $alreadyFine = 0;
$examples = [];

foreach ($groups as $day => $ids) {
  // Split the day by technician; unassigned is its own bucket.
  $byTech = [];
  foreach ($storage->loadMultiple($ids) as $s) {
    $tech = $s->get('field_assigned_to')->isEmpty() ? '0' : (string) $s->get('field_assigned_to')->target_id;
    $byTech[$tech][] = $s;
  }

  foreach ($byTech as $tech => $recs) {
    $orders = [];
    $arranged = FALSE;
    foreach ($recs as $s) {
      if ($s->hasField('field_route_order_set') && !$s->get('field_route_order_set')->isEmpty()
        && (bool) $s->get('field_route_order_set')->value) {
        $arranged = TRUE;
      }
      $orders[] = $s->get('field_scheduled_oder')->isEmpty() ? NULL : (int) $s->get('field_scheduled_oder')->value;
    }

    $vals = array_values(array_filter($orders, fn($v) => $v !== NULL));
    sort($vals);
    $needs = count($vals) !== count($recs)
      || count($vals) !== count(array_unique($vals))
      || $vals !== range(1, count($vals));

    if (!$needs) {
      $alreadyFine++;
      continue;
    }
    if ($arranged) {
      $skippedArranged++;
      printf("  KEEP    %s tech=%-7s arranged in the Route Editor — left alone\n", $day, $tech === '0' ? 'none' : $tech);
      continue;
    }

    // Preserve the existing sequence; nulls sort last; id breaks ties.
    usort($recs, function ($a, $b) {
      $ao = $a->get('field_scheduled_oder')->isEmpty() ? PHP_INT_MAX : (int) $a->get('field_scheduled_oder')->value;
      $bo = $b->get('field_scheduled_oder')->isEmpty() ? PHP_INT_MAX : (int) $b->get('field_scheduled_oder')->value;
      return [$ao, (int) $a->id()] <=> [$bo, (int) $b->id()];
    });

    $before = array_map(fn($s) => $s->get('field_scheduled_oder')->isEmpty() ? 'null' : $s->get('field_scheduled_oder')->value, $recs);
    $rank = 1;
    foreach ($recs as $s) {
      if ($apply) {
        $s->set('field_scheduled_oder', $rank);
        $s->save();
      }
      $rank++;
    }
    $touched++;
    if (count($examples) < 6) {
      $examples[] = sprintf('%s tech=%s [%s] -> [1..%d]', $day, $tech === '0' ? 'none' : $tech, implode(',', $before), count($recs));
    }
  }
}

printf("\n  %s: %d groups\n  already dense: %d\n  left alone (office-arranged): %d\n",
  $apply ? 'renumbered' : 'would renumber', $touched, $alreadyFine, $skippedArranged);
foreach ($examples as $e) {
  print '    ' . $e . "\n";
}
if (!$apply) {
  print "\nDRY RUN. BOS_RENUMBER_APPLY=1 to write.\n";
}
