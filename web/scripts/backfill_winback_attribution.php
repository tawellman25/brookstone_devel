<?php

/**
 * @file
 * One-time backfill: attribute recent win-back winterizing WOs to react{YY}.
 *
 * Before the Create-WO button stamped attribution (2026-09-06), the win-back
 * callers created winterizing WOs with no service_request and no campaign. This
 * creates a converted service_request LINKED to each such existing WO, stamped
 * field_campaign=react{YY} + field_source=reactivation, so they appear in the
 * campaign->jobs Report exactly like button-created bookings. It does NOT create
 * or modify any Work Order.
 *
 * Scope (conservative): sprinkler_winterizing WOs created in the last N days
 * (default 7) that (a) have NO service_request yet and (b) have a PRIOR
 * winterizing (reactivation signature). New customers (no prior) are skipped —
 * they are not reactivations. Idempotent (skips WOs already linked).
 *
 * Dry-run by default (BOS_BACKFILL_APPLY=1 to write). Env: BOS_BACKFILL_DAYS=N.
 * Run: ddev drush php:script web/scripts/backfill_winback_attribution.php
 */

use Drupal\Core\Database\Database;
use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;

$APPLY = getenv('BOS_BACKFILL_APPLY') === '1';
$DAYS = (int) (getenv('BOS_BACKFILL_DAYS') ?: 7);
$db = Database::getConnection();
$etm = \Drupal::entityTypeManager();
$since = strtotime("-{$DAYS} days");
$y26 = strtotime('2026-01-01 MST');
print ($APPLY ? "*** APPLY ***\n" : "--- DRY RUN ---\n");

$prefix = (string) (\Drupal::config('bos_winback.settings')->get('campaign_prefix') ?: 'react');
$prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'react';

$statusResolver = \Drupal::service('bos_service_request.status_resolver');
$convertedTid = $statusResolver->tid(ServiceRequestStatusResolver::CONVERTED);
$winbackList = \Drupal::service('bos_winback.list');

// Winterizing service term.
$tids = $etm->getStorage('taxonomy_term')->getQuery()->condition('field_service_bundle', 'sprinkler_winterizing')->accessCheck(FALSE)->range(0, 1)->execute();
$serviceTermId = $tids ? (int) reset($tids) : 0;

$wos = $db->query("SELECT id, uid, created FROM {work_order_field_data} WHERE type='sprinkler_winterizing' AND created >= :s", [':s' => $since])->fetchAll();

$made = 0; $skip_attributed = 0; $skip_newcust = 0; $skip_noprop = 0;
foreach ($wos as $w) {
  $woId = (int) $w->id;
  if ($db->query("SELECT entity_id FROM {service_request__field_work_order} WHERE field_work_order_target_id=:w LIMIT 1", [':w' => $woId])->fetchField()) {
    $skip_attributed++;
    continue;
  }
  $pid = (int) $db->query("SELECT field_property_target_id FROM {work_order__field_property} WHERE entity_id=:w", [':w' => $woId])->fetchField();
  if (!$pid) { $skip_noprop++; continue; }
  $prior = (int) $db->query("SELECT COUNT(*) FROM {work_order_field_data} w JOIN {work_order__field_property} p ON p.entity_id=w.id WHERE w.type='sprinkler_winterizing' AND p.field_property_target_id=:pid AND w.created < :y", [':pid' => $pid, ':y' => $y26])->fetchField();
  if ($prior === 0) { $skip_newcust++; continue; }

  $campaign = $prefix . substr(date('Y', (int) $w->created), -2);
  if ($APPLY) {
    $contact = $winbackList->contactForProperty($pid);
    $sr = $etm->getStorage('service_request')->create([
      'type' => 'sprinkler_winterizing',
      'uid' => (int) $w->uid,
      'created' => (int) $w->created,
      'field_property' => $pid,
      'field_service' => $serviceTermId,
      'field_service_year' => (int) date('Y', (int) $w->created),
      'field_request_status' => $convertedTid,
      'field_source' => 'reactivation',
      'field_campaign' => $campaign,
      'field_work_order' => $woId,
      'field_converted_by' => (int) $w->uid,
      'field_converted_on' => (int) $w->created,
      'field_submitted_name' => $contact ? $contact->label() : '',
      'field_office_notes' => 'Backfilled reactivation attribution for a win-back WO created before button attribution shipped.',
    ]);
    if ($contact && $sr->hasField('field_contact')) {
      $sr->set('field_contact', (int) $contact->id());
    }
    $sr->save();
  }
  $made++;
}

print "targets attributed (react{YY}):     $made\n";
print "skipped — already attributed:       $skip_attributed\n";
print "skipped — new customer (no prior):  $skip_newcust\n";
print "skipped — no property:              $skip_noprop\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN — set BOS_BACKFILL_APPLY=1 to write.\n";
