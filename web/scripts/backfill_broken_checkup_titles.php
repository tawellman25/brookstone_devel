<?php

declare(strict_types=1);

/**
 * Backfill — heal sprinkler_check_up (and any other) work_order entities
 * whose title is the auto_entitylabel placeholder
 * `%AutoEntityLabel: <uuid>%`.
 *
 * Root cause (see drupal_bos_gotchas.md): the AEL pattern for
 * sprinkler_check_up uses `[work_order:id]`, which is not assigned
 * during hook_entity_presave on insert. When the WO is created
 * programmatically with a single `->save()` (the contract_residential
 * check-up generator queue worker + action both did this), AEL writes
 * its sentinel placeholder expecting a follow-up save to heal it.
 * No follow-up save fires, so the placeholder sticks. Pathauto then
 * generates the URL alias from that placeholder string.
 *
 * Heal mechanic: AEL's `status: 2` (OPTIONAL) only fills the title when
 * it's empty. So clearing the title to '' and saving again lets AEL
 * regenerate using the now-known `[work_order:id]`. Verified end-to-end
 * on WO 50103 ("Ambulance District" property data was not the cause —
 * field_service and field_property were correctly populated all along).
 *
 * Side effect: re-saving also triggers pathauto, which regenerates the
 * URL alias from the corrected title. The previous broken alias
 * (e.g. `/.../work-orders/autoentitylabel-3af3a4a0-…`) becomes invalid;
 * this is desirable since the broken slug carried no useful semantics.
 *
 * Status guard: only operates on WOs with the placeholder string in
 * the title. Pre-existing Complete/Invoiced/Paid WOs in the affected
 * set still get the title fix; the wo_* presave hooks that recalc
 * billing on Complete are idempotent over their own inputs and won't
 * change billing totals from a no-op-data save.
 *
 * Usage:
 *   ddev drush scr web/scripts/backfill_broken_checkup_titles.php -- --dry-run
 *   ddev drush scr web/scripts/backfill_broken_checkup_titles.php -- --commit
 *
 * Without either flag the script exits without doing anything.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$dryRun = in_array('--dry-run', $extra ?? [], TRUE);
$commit = in_array('--commit', $extra ?? [], TRUE);

if (!$dryRun && !$commit) {
  echo "Usage: --dry-run | --commit\n";
  exit(2);
}

$storage = \Drupal::entityTypeManager()->getStorage('work_order');
$ids = \Drupal::entityQuery('work_order')
  ->accessCheck(FALSE)
  ->condition('title', '%AutoEntityLabel:%', 'LIKE')
  ->execute();

if (!$ids) {
  echo "No WOs with placeholder titles found. Nothing to do.\n";
  exit(0);
}

echo "Found " . count($ids) . " WOs with placeholder titles.\n";
echo ($dryRun ? "[DRY RUN — no writes]" : "[COMMIT — writing to DB]") . "\n\n";

$healed = 0;
$failed = 0;

foreach ($ids as $id) {
  $wo = $storage->load($id);
  if (!$wo) {
    echo "SKIP id=$id (entity not loadable)\n";
    continue;
  }
  $bundle = $wo->bundle();
  $status_tid = (int) ($wo->get('field_status')->target_id ?? 0);
  $before = $wo->label();

  if ($dryRun) {
    echo "DRY  id=$id bundle=$bundle status_tid=$status_tid title='$before'\n";
    continue;
  }

  $wo->set('title', '');
  $wo->save();

  // Reload to confirm AEL regenerated the title.
  $storage->resetCache([$id]);
  $fresh = $storage->load($id);
  $after = $fresh->label();

  if (strpos($after, '%AutoEntityLabel:') !== FALSE || $after === '') {
    echo "FAIL id=$id bundle=$bundle still='$after'\n";
    $failed++;
  }
  else {
    echo "OK   id=$id bundle=$bundle '$before' -> '$after'\n";
    $healed++;
  }
}

if ($commit) {
  echo "\nSummary: healed=$healed failed=$failed of " . count($ids) . "\n";
}
else {
  echo "\nDry-run only. Re-run with --commit to apply.\n";
}
