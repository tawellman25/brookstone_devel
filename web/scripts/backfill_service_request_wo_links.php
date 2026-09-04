<?php

/**
 * @file
 * One-time reconciliation: link service requests to the sprinkler_winterizing
 * Work Orders that were created for their matched property OUTSIDE the "Approve &
 * Create Work Order" button (so field_work_order was never set). Sets
 * field_work_order + converted-by/on and marks the request Converted.
 *
 * Only touches requests that (a) have a matched property, (b) have NO
 * field_work_order yet, and (c) have exactly one winterizing WO on that property
 * (unambiguous). Idempotent + dry-run by default.
 *
 *   dry run: drush php:script web/scripts/backfill_service_request_wo_links.php
 *   apply:   SR_BACKFILL_APPLY=1 drush php:script web/scripts/backfill_service_request_wo_links.php
 */

$apply = getenv('SR_BACKFILL_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$resolver = \Drupal::service('bos_service_request.status_resolver');
$convertedTid = $resolver->tid(\Drupal\bos_service_request\Service\ServiceRequestStatusResolver::CONVERTED);
$now = \Drupal::time()->getRequestTime();

print $apply ? "APPLYING\n" : "DRY RUN (set SR_BACKFILL_APPLY=1 to apply)\n";
print "Converted tid: {$convertedTid}\n\n";

$ids = $etm->getStorage('service_request')->getQuery()->accessCheck(FALSE)->execute();
$linked = 0;
foreach ($etm->getStorage('service_request')->loadMultiple($ids) as $sr) {
  if ($sr->get('field_property')->isEmpty()) {
    print "REQ #{$sr->id()}: no matched property — skip\n";
    continue;
  }
  if ($sr->hasField('field_work_order') && !$sr->get('field_work_order')->isEmpty()) {
    print "REQ #{$sr->id()}: already linked to WO {$sr->get('field_work_order')->target_id} — skip\n";
    continue;
  }
  $pid = $sr->get('field_property')->target_id;
  $wids = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
    ->condition('type', 'sprinkler_winterizing')
    ->condition('field_property', $pid)
    ->sort('id', 'DESC')
    ->execute();
  if (empty($wids)) {
    print "REQ #{$sr->id()} (prop {$pid}): no winterizing WO found — skip\n";
    continue;
  }
  if (count($wids) > 1) {
    print "REQ #{$sr->id()} (prop {$pid}): AMBIGUOUS — " . count($wids) . " winterizing WOs (" . implode(',', $wids) . ") — skip, link by hand\n";
    continue;
  }
  $woId = (int) reset($wids);
  $wo = $etm->getStorage('work_order')->load($woId);
  $actor = $wo ? (int) $wo->getOwnerId() : 1;
  print "REQ #{$sr->id()} (prop {$pid}) -> WO {$woId} (converted_by uid {$actor})" . ($apply ? '' : ' [dry]') . "\n";
  if ($apply) {
    $sr->set('field_work_order', $woId);
    if ($sr->hasField('field_converted_by')) {
      $sr->set('field_converted_by', $actor);
    }
    if ($sr->hasField('field_converted_on')) {
      $sr->set('field_converted_on', $now);
    }
    if ($convertedTid) {
      $sr->set('field_request_status', $convertedTid);
    }
    $sr->save();
    $linked++;
  }
}
print "\n" . ($apply ? "linked {$linked} request(s)\n" : "dry run complete\n") . "DONE\n";
