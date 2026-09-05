<?php

/**
 * @file
 * Stage 3 of the Contact opt-in model. Ensures every customer_profile has a
 * primary contact (field_primary_contact_ref) so opt-ins have a home.
 *
 * Per unlinked customer_profile:
 *   1. LINK   — if the owning User's email matches exactly one existing Contact.
 *   2. CREATE — else, if the User has a usable "First Last" name, create a
 *               minimal Contact (first/last name + email) and link it.
 *   3. SKIP   — no usable name, no valid user, or an ambiguous email match:
 *               left for manual review (reported, never guessed).
 *
 * Created Contacts get NO opt-in values — creating the person record is not a
 * consent event; the flags stay NULL ("unknown"). Only sets field_primary_
 * contact_ref when it is empty; re-runs are a no-op (idempotent).
 *
 * READ-ONLY by default (dry run). To apply: BOS_BACKFILL_APPLY=1.
 * Optional: BOS_BACKFILL_LIMIT=N (process at most N profiles).
 * No PII in output — counts only.
 *
 * Run (dry):   ddev drush php:script web/scripts/backfill_contact_primary_links.php
 * Run (apply): BOS_BACKFILL_APPLY=1 ddev drush php:script web/scripts/backfill_contact_primary_links.php
 */

use Drupal\Core\Database\Database;

$APPLY = getenv('BOS_BACKFILL_APPLY') === '1';
$LIMIT = (int) (getenv('BOS_BACKFILL_LIMIT') ?: 0);
$db = Database::getConnection();
$etm = \Drupal::entityTypeManager();
$cStore = $etm->getStorage('contacts');
$pStore = $etm->getStorage('profile');

print ($APPLY ? "*** APPLY MODE — writes enabled ***\n" : "--- DRY RUN (no writes) ---\n");

// Unlinked customer_profiles.
$unlinked = $db->query("
  SELECT p.profile_id pid, p.uid
  FROM {profile} p
  WHERE p.type='customer_profile'
    AND NOT EXISTS (SELECT 1 FROM {profile__field_primary_contact_ref} r WHERE r.entity_id=p.profile_id)
")->fetchAllKeyed();

// Existing contact emails -> [cid,...].
$byEmail = [];
foreach ($db->query("SELECT entity_id cid, LOWER(TRIM(field_email_value)) e FROM {contacts__field_email} WHERE bundle='contact' AND field_email_value IS NOT NULL AND TRIM(field_email_value)<>''")->fetchAll() as $r) {
  $byEmail[$r->e][] = (int) $r->cid;
}

// Users for these profiles.
$uids = array_values(array_filter(array_map('intval', $unlinked)));
$userMail = $userName = [];
foreach (array_chunk($uids, 1000) as $chunk) {
  foreach ($db->query("SELECT uid, LOWER(TRIM(mail)) e, name FROM {users_field_data} WHERE uid IN (:u[])", [':u[]' => $chunk])->fetchAll() as $r) {
    $userMail[(int) $r->uid] = $r->e;
    $userName[(int) $r->uid] = trim((string) $r->name);
  }
}

$linked = $created = 0;
$skip = ['no_user' => 0, 'ambiguous' => 0, 'no_email' => 0, 'unusable_name' => 0];
$n = 0;

foreach ($unlinked as $pid => $uid) {
  if ($LIMIT && $n >= $LIMIT) { break; }
  $n++;
  $uid = (int) $uid;
  if (!$uid || !isset($userMail[$uid])) { $skip['no_user']++; continue; }
  $email = $userMail[$uid];
  if ($email === '') { $skip['no_email']++; continue; }

  $matches = $byEmail[$email] ?? [];
  $contactId = NULL;

  if (count($matches) === 1) {
    $contactId = $matches[0];
    if ($APPLY) { $linked++; } else { $linked++; }
  }
  elseif (count($matches) > 1) {
    $skip['ambiguous']++; continue;
  }
  else {
    // Need to create. Require a usable person name.
    $name = $userName[$uid] ?? '';
    if ($name === '' || str_contains($name, '@') || !str_contains($name, ' ')) {
      $skip['unusable_name']++; continue;
    }
    [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
    if ($APPLY) {
      $c = $cStore->create([
        'type' => 'contact',
        'title' => $name,
        'field_first_name' => trim($first),
        'field_last_name' => trim($last),
        'field_email' => $email,
      ]);
      $c->save();
      $contactId = (int) $c->id();
    }
    $created++;
  }

  // Link it onto the customer_profile (only when empty — guaranteed by query).
  if ($APPLY && $contactId) {
    $profile = $pStore->load($pid);
    if ($profile && $profile->hasField('field_primary_contact_ref') && $profile->get('field_primary_contact_ref')->isEmpty()) {
      $profile->set('field_primary_contact_ref', $contactId);
      $profile->save();
    }
  }
}

print "processed:        $n\n";
print "linked (existing): $linked\n";
print "created + linked:  $created\n";
print "skipped no_user:        {$skip['no_user']}\n";
print "skipped no_email:       {$skip['no_email']}\n";
print "skipped ambiguous:      {$skip['ambiguous']}\n";
print "skipped unusable_name:  {$skip['unusable_name']}\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN complete — set BOS_BACKFILL_APPLY=1 to write.\n";
