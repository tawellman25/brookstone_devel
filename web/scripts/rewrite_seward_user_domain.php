<?php

/**
 * @file
 * ONE-TIME SANCTIONED EXCEPTION to client_email_policy.md part 1.
 *
 * We no longer own sewardslandscape.com, so any mail to those addresses reaches
 * a third party. This rewrites ONLY the domain on client User accounts,
 * @sewardslandscape.com -> @brookstoneoutdoors.com (which we own + will
 * catch-all), preserving the local part (the person's name/identity) so the
 * account is unchanged except that mail can no longer leave our control.
 *
 * Scope: every user (uid>1) whose mail is @sewardslandscape.com (active OR
 * blocked — purge the hostile domain entirely). Staff are @brookstoneoutdoors.com
 * already, so unaffected.
 *
 * Collision-safe: user.mail must be unique. If <local>@brookstoneoutdoors.com is
 * already taken, the new address becomes <local>.<uid>@brookstoneoutdoors.com
 * (still under the catch-all). Case of the local part is preserved; matching is
 * case-insensitive.
 *
 * Programmatic User::save() does NOT send email on a mail change (that's a
 * form-level "notify" option), so this fires no mail.
 *
 * Dry-run by default. Apply: BOS_REWRITE_APPLY=1. Optional BOS_REWRITE_LIMIT=N.
 * Counts only, no PII.
 *
 * Run (dry):   drush php:script web/scripts/rewrite_seward_user_domain.php
 * Run (apply): BOS_REWRITE_APPLY=1 drush php:script web/scripts/rewrite_seward_user_domain.php
 */

use Drupal\Core\Database\Database;
use Drupal\user\Entity\User;

$APPLY = getenv('BOS_REWRITE_APPLY') === '1';
$LIMIT = (int) (getenv('BOS_REWRITE_LIMIT') ?: 0);
$OLD = '@sewardslandscape.com';
$NEW = '@brookstoneoutdoors.com';
$db = Database::getConnection();
print ($APPLY ? "*** APPLY — rewriting User emails ***\n" : "--- DRY RUN (no writes) ---\n");

// Every current user email (lowercased) = the "taken" namespace for uniqueness.
$taken = [];
foreach ($db->query("SELECT LOWER(TRIM(mail)) e FROM {users_field_data} WHERE mail IS NOT NULL AND mail<>''")->fetchCol() as $e) {
  $taken[$e] = TRUE;
}

// Targets: uid>1 with a sewardslandscape.com address.
$targets = $db->query("SELECT uid, mail FROM {users_field_data} WHERE uid>1 AND LOWER(mail) LIKE :d", [':d' => '%' . $OLD])->fetchAllKeyed();

$rewritten = 0; $disamb = 0; $skipped = 0; $n = 0;
foreach ($targets as $uid => $mail) {
  if ($LIMIT && $n >= $LIMIT) break;
  $n++;
  $uid = (int) $uid;
  $mail = (string) $mail;
  $at = strrpos($mail, '@');
  if ($at === FALSE) { $skipped++; continue; }
  $local = substr($mail, 0, $at);
  // Double-guard: only ever act on the exact hostile domain.
  if (strtolower(substr($mail, $at)) !== $OLD) { $skipped++; continue; }

  $candidate = $local . $NEW;
  if (isset($taken[strtolower($candidate)])) {
    $candidate = $local . '.' . $uid . $NEW;   // uid guarantees uniqueness
    $disamb++;
  }

  if ($APPLY) {
    $u = User::load($uid);
    if (!$u) { $skipped++; continue; }
    $u->setEmail($candidate);
    $u->save();
  }
  // Free the old, reserve the new (keeps in-run collision math correct).
  unset($taken[strtolower($mail)]);
  $taken[strtolower($candidate)] = TRUE;
  $rewritten++;
}

print "targets (uid>1 @sewardslandscape.com): " . count($targets) . "\n";
print "rewritten -> @brookstoneoutdoors.com:  $rewritten\n";
print "  of which needed .uid disambiguation: $disamb\n";
print "skipped (bad/edge):                     $skipped\n";
print $APPLY ? "APPLIED. Run `drush cr`.\n" : "DRY RUN — set BOS_REWRITE_APPLY=1 to write.\n";
