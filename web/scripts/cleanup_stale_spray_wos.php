<?php

/**
 * @file
 * One-time (and repeatable) cleanup of abandoned weed_spraying work orders.
 *
 * Uses the same logic as the daily hook_cron sweep
 * (_wo_weed_spraying_sweep_abandoned), scoped to the current year: stale-empty
 * WOs (>45 days old, zero time, zero chemicals, not invoiced) are Canceled;
 * resurrected WOs (reached Complete, then reopened by a stray clock-in) are
 * FLAGGED for office review, never auto-modified (billing/history sensitive).
 * Genuinely-active WOs are left untouched. Idempotent.
 *
 * Dry-run by default. To apply, set SPRAY_CLEANUP_APPLY=1:
 *   Preview (local): ddev drush scr web/scripts/cleanup_stale_spray_wos.php
 *   Apply   (local): ddev exec env SPRAY_CLEANUP_APPLY=1 drush scr web/scripts/cleanup_stale_spray_wos.php
 *   Apply   (live) : SPRAY_CLEANUP_APPLY=1 drush scr web/scripts/cleanup_stale_spray_wos.php
 */

$apply = getenv('SPRAY_CLEANUP_APPLY') === '1';
$res = _wo_weed_spraying_sweep_abandoned(!$apply);

$mode = $apply ? 'APPLIED' : 'DRY RUN (set SPRAY_CLEANUP_APPLY=1 to apply)';
echo "Weed-spray WO cleanup — {$mode}\n";
echo '  stale-empty -> Canceled        : ' . count($res['canceled'])
  . ($res['canceled'] ? ' (' . implode(', ', $res['canceled']) . ')' : '') . "\n";
echo '  resurrected -> FLAG for review : ' . count($res['flagged'])
  . ($res['flagged'] ? ' (' . implode(', ', $res['flagged']) . ')' : '') . "\n";
echo '  left active (untouched)        : ' . $res['active'] . "\n";
