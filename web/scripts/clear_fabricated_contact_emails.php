<?php

/**
 * @file
 * Clear FABRICATED (our-domain) email addresses from contacts.contact so they
 * can never be emailed. Targets only addresses at our own domains
 * (@brookstoneoutdoors.com / @sewardslandscape.com) that are NOT a real staff
 * mailbox and NOT a placeholder — i.e. the name@ourdomain accounts fabricated at
 * client-account creation. Real external emails and the ~780 deliverable ones
 * are never touched.
 *
 * Does NOT touch the client User's email (policy: client User emails change only
 * by admin-manual edit — see __BOS_AI/Governance/client_email_policy.md). Only
 * the Contact-side field_email is cleared. The customer<->Contact link is by
 * entity id, and the Contact title is name+phone, so neither is affected.
 *
 * Deletes the field rows directly (a clean value removal; no title churn, no
 * spurious hooks). A DOMAIN GUARD on the delete ensures only our-domain values
 * are ever removed, even if the id list were wrong.
 *
 * READ-ONLY by default (dry run). To clear: BOS_CLEAR_APPLY=1. Counts only.
 * Run (dry):   drush php:script web/scripts/clear_fabricated_contact_emails.php
 * Run (apply): BOS_CLEAR_APPLY=1 drush php:script web/scripts/clear_fabricated_contact_emails.php
 */

use Drupal\Core\Database\Database;

$APPLY = getenv('BOS_CLEAR_APPLY') === '1';
$db = Database::getConnection();
print ($APPLY ? "*** APPLY MODE — will delete field values ***\n" : "--- DRY RUN (no writes) ---\n");

$OURDOMAINS = ['brookstoneoutdoors.com', 'sewardslandscape.com'];
$ROLEWORDS = ['info','office','admin','estimates','estimate','billing','sales','noreply','no-reply','service','scheduling','crew','support','accounts','accounting'];
$PLACEHOLDER_LOCAL = ['noemail','none','na','n/a','test','unknown','nobody','x','xx','placeholder','donotuse','dummy','email'];
$STAFF_ROLES = ['teammates','supervisor','administration','site_assistant','site_admin','administrator','system_integration'];

// Staff mailboxes to protect (real @ourdomain accounts).
$staffEmails = [];
foreach ($db->query("SELECT DISTINCT LOWER(TRIM(u.mail)) e FROM {users_field_data} u JOIN {user__roles} r ON r.entity_id=u.uid WHERE r.roles_target_id IN (:roles[]) AND u.mail<>''", [':roles[]' => $STAFF_ROLES])->fetchCol() as $e) {
  $staffEmails[$e] = TRUE;
}

$only_alpha = fn($s) => preg_replace('/[^a-z]/', '', strtolower($s));
$name_derives = function ($local, $first, $last) use ($only_alpha) {
  $l = preg_replace('/\d+$/', '', strtolower($local));
  $f = $only_alpha($first); $ln = $only_alpha($last);
  if ($f === '' && $ln === '') return FALSE;
  $v = [];
  if ($f && $ln) { $v = ["$f.$ln","$f$ln","{$f}_{$ln}","$f-$ln",substr($f,0,1).$ln,$f.substr($ln,0,1),"$ln$f","$ln.$f"]; }
  if ($f) $v[] = $f; if ($ln) $v[] = $ln;
  foreach ($v as $cand) { if ($l === $cand || $only_alpha($l) === $only_alpha($cand)) return TRUE; }
  return FALSE;
};

$rows = $db->query("
  SELECT c.id cid, e.field_email_value email, fn.field_first_name_value first, ln.field_last_name_value last
  FROM {contacts_field_data} c
  JOIN {contacts__field_email} e ON e.entity_id=c.id AND e.bundle='contact'
  LEFT JOIN {contacts__field_first_name} fn ON fn.entity_id=c.id AND fn.bundle='contact'
  LEFT JOIN {contacts__field_last_name} ln ON ln.entity_id=c.id AND ln.bundle='contact'
  WHERE c.type='contact' AND e.field_email_value IS NOT NULL AND TRIM(e.field_email_value)<>''
")->fetchAll();

$targetIds = [];
$byDomain = ['brookstoneoutdoors.com' => 0, 'sewardslandscape.com' => 0];
$comp = ['name_derived' => 0, 'not_name_derived' => 0, 'role_word' => 0];
$skipped_staff = 0; $skipped_placeholder = 0;

foreach ($rows as $r) {
  $email = strtolower(trim((string) $r->email));
  $parts = explode('@', $email);
  if (count($parts) !== 2) continue;
  [$local, $dom] = $parts;
  if (!in_array($dom, $OURDOMAINS, true)) continue;      // only our domains
  if (isset($staffEmails[$email])) { $skipped_staff++; continue; } // protect staff mailboxes
  if (in_array($local, $PLACEHOLDER_LOCAL, true)) { $skipped_placeholder++; continue; }

  $targetIds[] = (int) $r->cid;
  $byDomain[$dom]++;
  if (in_array($local, $ROLEWORDS, true)) { $comp['role_word']++; }
  elseif ($name_derives($local, (string) $r->first, (string) $r->last)) { $comp['name_derived']++; }
  else { $comp['not_name_derived']++; }
}

$n = count($targetIds);
print "fabricated (our-domain) contact emails targeted: $n\n";
print "  by domain:  brookstoneoutdoors.com={$byDomain['brookstoneoutdoors.com']}  sewardslandscape.com={$byDomain['sewardslandscape.com']}\n";
print "  composition: name-derived={$comp['name_derived']}  not-name-derived={$comp['not_name_derived']}  role-word={$comp['role_word']}\n";
print "  protected (not touched): staff mailboxes=$skipped_staff  placeholder-local=$skipped_placeholder\n";

if (!$APPLY) {
  print "DRY RUN — no changes. Set BOS_CLEAR_APPLY=1 to clear.\n";
  return;
}

// APPLY: delete the field rows for the target ids, with a domain guard so only
// our-domain values are ever removed. Chunked. Clears data + revision tables.
$hasRev = $db->schema()->tableExists('contacts_revision__field_email');
$domainLike = "(LOWER(field_email_value) LIKE :d1 OR LOWER(field_email_value) LIKE :d2)";
$deletedData = 0; $deletedRev = 0;
foreach (array_chunk($targetIds, 500) as $chunk) {
  $q = $db->delete('contacts__field_email')
    ->condition('entity_id', $chunk, 'IN')
    ->condition('bundle', 'contact');
  $q->where($domainLike, [':d1' => '%@brookstoneoutdoors.com', ':d2' => '%@sewardslandscape.com']);
  $deletedData += $q->execute();
  if ($hasRev) {
    $qr = $db->delete('contacts_revision__field_email')->condition('entity_id', $chunk, 'IN')->condition('bundle', 'contact');
    $qr->where($domainLike, [':d1' => '%@brookstoneoutdoors.com', ':d2' => '%@sewardslandscape.com']);
    $deletedRev += $qr->execute();
  }
}
\Drupal::entityTypeManager()->getStorage('contacts')->resetCache($targetIds);
print "deleted data rows: $deletedData" . ($hasRev ? "  revision rows: $deletedRev" : "  (no revision table)") . "\n";
print "APPLIED. Run `drush cr` to flush render caches.\n";
