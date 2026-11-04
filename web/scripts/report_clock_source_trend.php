<?php

/**
 * @file
 * Are crews clocking themselves in, or is sign-off doing it for them?
 *
 * This is the number that decides whether a foreman crew-clock is worth
 * building. Every entry carries `field_source`:
 *
 *   wo_clock_button        — a person pressed the button themselves
 *   signoff_reconciliation — the system invented it at sign-off because
 *                            nobody had clocked that person in
 *   manual                 — typed in or corrected by hand
 *
 * Reconciliation is the one that costs: it has to guess a start and end, and
 * that guess is what produced 623- and 271-hour entries on multi-visit work
 * orders. Every entry that comes from the button instead is a window that was
 * observed rather than inferred.
 *
 * READ-ONLY.
 *
 * Run: drush php:script web/scripts/report_clock_source_trend.php
 */

$db = \Drupal::database();

$rows = $db->query('SELECT DATE_FORMAT(FROM_UNIXTIME(d.created), :fmt) m,
    SUM(CASE WHEN src.field_source_value = :self THEN 1 ELSE 0 END) self_n,
    SUM(CASE WHEN src.field_source_value = :recon THEN 1 ELSE 0 END) recon_n,
    COUNT(*) tot
  FROM {wo_time_clock__field_source} src
  JOIN {wo_time_clock_field_data} d ON d.id = src.entity_id
  WHERE src.deleted = 0 AND d.created > :since
  GROUP BY m ORDER BY m', [
    ':fmt' => '%Y-%m',
    ':self' => 'wo_clock_button',
    ':recon' => 'signoff_reconciliation',
    ':since' => strtotime('-12 months'),
  ])->fetchAll();

printf("%-9s %7s %7s %7s   %s\n", 'MONTH', 'SELF', 'RECON', 'TOTAL', 'SELF SHARE');
printf("%s\n", str_repeat('-', 62));
foreach ($rows as $r) {
  $pct = $r->tot ? $r->self_n / $r->tot * 100 : 0;
  printf("%-9s %7s %7s %7s   %5.1f%%  %s\n", $r->m, $r->self_n, $r->recon_n, $r->tot,
    $pct, str_repeat('#', (int) round($pct / 4)));
}

// The green-button clock (wo_clock Phase A) shipped 2026-07-03. Did it move
// the needle, or is the recent improvement noise?
$before = $db->query('SELECT
    SUM(CASE WHEN src.field_source_value = :self THEN 1 ELSE 0 END) s,
    COUNT(*) t
  FROM {wo_time_clock__field_source} src
  JOIN {wo_time_clock_field_data} d ON d.id = src.entity_id
  WHERE src.deleted = 0 AND d.created < :cut',
  [':self' => 'wo_clock_button', ':cut' => strtotime('2026-07-03')])->fetchAssoc();
$after = $db->query('SELECT
    SUM(CASE WHEN src.field_source_value = :self THEN 1 ELSE 0 END) s,
    COUNT(*) t
  FROM {wo_time_clock__field_source} src
  JOIN {wo_time_clock_field_data} d ON d.id = src.entity_id
  WHERE src.deleted = 0 AND d.created >= :cut',
  [':self' => 'wo_clock_button', ':cut' => strtotime('2026-07-03')])->fetchAssoc();

printf("\nbefore the clock button shipped (pre 07/03): %5.1f%% self  (%s of %s)\n",
  $before['t'] ? $before['s'] / $before['t'] * 100 : 0, number_format((int) $before['s']), number_format((int) $before['t']));
printf("since:                                       %5.1f%% self  (%s of %s)\n",
  $after['t'] ? $after['s'] / $after['t'] * 100 : 0, number_format((int) $after['s']), number_format((int) $after['t']));

// Who is still being reconciled? If it is a handful of people it is a
// conversation, not a feature.
print "\nteammates most often reconciled (last 90 days):\n";
$people = $db->query('SELECT u.field_teammate_target_id uid,
    SUM(CASE WHEN src.field_source_value = :recon THEN 1 ELSE 0 END) recon_n,
    SUM(CASE WHEN src.field_source_value = :self THEN 1 ELSE 0 END) self_n
  FROM {wo_time_clock__field_source} src
  JOIN {wo_time_clock_field_data} d ON d.id = src.entity_id
  JOIN {wo_time_clock__field_teammate} u ON u.entity_id = src.entity_id AND u.deleted = 0
  WHERE src.deleted = 0 AND d.created > :since
  GROUP BY uid HAVING recon_n > 0 ORDER BY recon_n DESC LIMIT 12', [
    ':recon' => 'signoff_reconciliation',
    ':self' => 'wo_clock_button',
    ':since' => time() - 90 * 86400,
  ])->fetchAll();
$store = \Drupal::entityTypeManager()->getStorage('user');
printf("  %-24s %8s %8s  %s\n", 'TEAMMATE', 'RECON', 'SELF', 'SELF SHARE');
foreach ($people as $p) {
  $u = $store->load($p->uid);
  $tot = $p->recon_n + $p->self_n;
  printf("  %-24s %8s %8s  %5.1f%%\n", $u ? mb_substr($u->getDisplayName(), 0, 24) : 'uid ' . $p->uid,
    $p->recon_n, $p->self_n, $tot ? $p->self_n / $tot * 100 : 0);
}
