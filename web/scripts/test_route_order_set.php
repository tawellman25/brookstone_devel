<?php

/**
 * @file
 * Reversible test: reorder() stamps field_route_order_set on a route's stops.
 * Run: ddev drush php:script web/scripts/test_route_order_set.php
 */

use Symfony\Component\HttpFoundation\Request;
use Drupal\bos_scheduling\Controller\RouteEditorController;

$c = RouteEditorController::create(\Drupal::getContainer());
$db = \Drupal::database();
$storage = \Drupal::entityTypeManager()->getStorage('scheduling');

// A route (same date + same tech) with >= 2 stops.
$route = $db->query('
  SELECT a.field_assigned_to_target_id AS uid, d.field_date_value AS ts, COUNT(*) AS n
  FROM {scheduling__field_assigned_to} a
  JOIN {scheduling__field_date} d ON d.entity_id = a.entity_id AND d.deleted = 0
  WHERE a.field_assigned_to_target_id IS NOT NULL AND a.deleted = 0
  GROUP BY a.field_assigned_to_target_id, d.field_date_value HAVING n >= 2
  ORDER BY d.field_date_value DESC LIMIT 1
')->fetchObject();
if (!$route) { print "no route found\n"; return; }

$ids = array_map('intval', $db->query('
  SELECT a.entity_id FROM {scheduling__field_assigned_to} a
  JOIN {scheduling__field_date} d ON d.entity_id = a.entity_id AND d.deleted = 0
  LEFT JOIN {scheduling__field_scheduled_oder} o ON o.entity_id = a.entity_id AND o.deleted = 0
  WHERE a.field_assigned_to_target_id = :uid AND d.field_date_value = :ts AND a.deleted = 0
  ORDER BY o.field_scheduled_oder_value ASC
', [':uid' => $route->uid, ':ts' => $route->ts])->fetchCol());

$before = [];
foreach ($ids as $id) {
  $e = $storage->loadUnchanged($id);
  $before[$id] = [
    'order' => (int) ($e->get('field_scheduled_oder')->value ?? 0),
    'set' => (bool) ($e->get('field_route_order_set')->value ?? 0),
  ];
}
print 'route uid=' . $route->uid . ' ids=' . implode(',', $ids) . "\n";
print 'before order_set: ' . json_encode(array_map(fn($b) => $b['set'], $before)) . "\n";

$resp = $c->reorder(Request::create('/x', 'POST', [], [], [], [], json_encode(['ordered_ids' => $ids])));
print 'REORDER RESP: ' . $resp->getContent() . "\n";

$allSet = TRUE;
foreach ($ids as $id) {
  if (!(bool) ($storage->loadUnchanged($id)->get('field_route_order_set')->value ?? 0)) { $allSet = FALSE; }
}
print 'all stops now route_order_set: ' . ($allSet ? "✓\n" : "✗\n");

// Revert order + order_set to original.
foreach ($ids as $id) {
  $e = $storage->loadUnchanged($id);
  $e->set('field_scheduled_oder', $before[$id]['order']);
  $e->set('field_route_order_set', $before[$id]['set']);
  if ($e->hasField('field_notify_assigned_teammate')) { $e->set('field_notify_assigned_teammate', FALSE); }
  $e->save();
}
print "reverted to original order + order_set\n";
print "DONE\n";
