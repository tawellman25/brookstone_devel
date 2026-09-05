<?php

/**
 * @file
 * Stage 2 of the Contact opt-in model. Moves the (near-empty) legacy consent
 * values off User onto the User's linked Contact, then Stage-cleanup can retire
 * the User fields.
 *
 * Mapping (CONSERVATIVE — marketing consent is never inferred):
 *   user.field_ok_to_email = TRUE  -> contact.field_opt_in_service_email = TRUE
 *   user.field_sms_consent = TRUE  -> contact.field_opt_in_service_sms   = TRUE
 * Marketing flags are left FALSE: the legacy "OK to email" flag did not
 * distinguish marketing from service, so marketing requires a fresh explicit
 * opt-in. Sets field_consent_updated=now, field_consent_source='import' on any
 * contact that receives a value. Idempotent (won't re-flip an already-TRUE
 * flag); dry-run by default (BOS_CONSENT_APPLY=1 to write). Counts only.
 *
 * Run (dry):   ddev drush php:script web/scripts/migrate_user_consent_to_contact.php
 * Run (apply): ddev exec 'BOS_CONSENT_APPLY=1 drush php:script web/scripts/migrate_user_consent_to_contact.php'
 */

use Drupal\Core\Database\Database;

$APPLY = getenv('BOS_CONSENT_APPLY') === '1';
$db = Database::getConnection();
$etm = \Drupal::entityTypeManager();
$cStore = $etm->getStorage('contacts');
print ($APPLY ? "*** APPLY ***\n" : "--- DRY RUN ---\n");

// Users (uid>1) with a legacy consent value set.
$rows = [];
if ($db->schema()->tableExists('user__field_ok_to_email')) {
  foreach ($db->query("SELECT entity_id uid FROM {user__field_ok_to_email} WHERE entity_id>1 AND field_ok_to_email_value=1")->fetchCol() as $uid) {
    $rows[(int) $uid]['email'] = TRUE;
  }
}
if ($db->schema()->tableExists('user__field_sms_consent')) {
  foreach ($db->query("SELECT entity_id uid FROM {user__field_sms_consent} WHERE entity_id>1 AND field_sms_consent_value=1")->fetchCol() as $uid) {
    $rows[(int) $uid]['sms'] = TRUE;
  }
}
print "users with a legacy consent value: " . count($rows) . "\n";

// Map user -> linked contact via customer_profile.field_primary_contact_ref.
$migrated = 0; $noContact = 0;
$now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
foreach ($rows as $uid => $flags) {
  $cid = $db->query("
    SELECT r.field_primary_contact_ref_target_id
    FROM {profile} p
    JOIN {profile__field_primary_contact_ref} r ON r.entity_id=p.profile_id
    WHERE p.type='customer_profile' AND p.uid=:uid
    LIMIT 1", [':uid' => $uid])->fetchField();
  if (!$cid) { $noContact++; continue; }

  if ($APPLY) {
    $c = $cStore->load($cid);
    if (!$c) { $noContact++; continue; }
    $changed = FALSE;
    if (!empty($flags['email']) && !(bool) $c->get('field_opt_in_service_email')->value) {
      $c->set('field_opt_in_service_email', TRUE); $changed = TRUE;
    }
    if (!empty($flags['sms']) && !(bool) $c->get('field_opt_in_service_sms')->value) {
      $c->set('field_opt_in_service_sms', TRUE); $changed = TRUE;
    }
    if ($changed) {
      if ($c->get('field_consent_updated')->isEmpty()) { $c->set('field_consent_updated', $now); }
      if ($c->get('field_consent_source')->isEmpty()) { $c->set('field_consent_source', 'import'); }
      $c->save();
      $migrated++;
    }
  }
  else {
    $migrated++;
  }
}
print "would migrate / migrated: $migrated\n";
print "user has no linked contact (skipped): $noContact\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN — set BOS_CONSENT_APPLY=1 to write.\n";
