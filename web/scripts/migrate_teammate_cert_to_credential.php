<?php

declare(strict_types=1);

/**
 * §8 migration — teammate_profile certification fields -> credential records.
 *
 * Approved by Todd 2026-09-27. Creates ONE credential per teammate_profile that
 * carries field_certification_number, using field_certification_association as the
 * issuing authority. Idempotent on (teammate, number). The profile fields are NOT
 * removed here — the backflow snapshot still falls back to them, so retirement is
 * a separate, later step.
 *
 * field_signature is NOT touched: it feeds generated reports and stays on the
 * profile. It is not a credential.
 *
 * Expiration dates are NOT invented. Pass them explicitly:
 *   BOS_CERT_EXPIRY_<uid>=YYYY-MM-DD
 *
 * Dry-run by default.
 *   drush php:script web/scripts/migrate_teammate_cert_to_credential.php
 *   BOS_CERT_APPLY=1 BOS_CERT_EXPIRY_1=2028-12-31 drush php:script web/scripts/migrate_teammate_cert_to_credential.php
 */

$apply = getenv('BOS_CERT_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

$abpa = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  if ((string) $t->get('field_credential_code')->value === 'ABPA_TESTER') {
    $abpa = $t;
  }
}
if (!$abpa) {
  print "ERROR: credential type ABPA_TESTER not found — run seed_credential_types.php first.\n";
  return;
}

printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_CERT_APPLY=1 to write)');

$ids = \Drupal::entityQuery('profile')->accessCheck(FALSE)
  ->condition('type', 'teammate_profile')
  ->exists('field_certification_number')
  ->execute();
if (!$ids) {
  print "No teammate_profile carries field_certification_number — nothing to migrate.\n";
  return;
}

$created = 0;
$skipped = 0;
foreach ($etm->getStorage('profile')->loadMultiple($ids) as $profile) {
  $uid = (int) $profile->getOwnerId();
  $number = trim((string) $profile->get('field_certification_number')->value);
  $authority = $profile->hasField('field_certification_association')
    ? trim((string) $profile->get('field_certification_association')->value)
    : '';
  if ($number === '' || !$uid) {
    continue;
  }
  $owner = $profile->getOwner();
  $name = $owner ? $owner->getDisplayName() : "uid $uid";

  $existing = $etm->getStorage('credential')->loadByProperties([
    'field_teammate' => $uid,
    'field_credential_number' => $number,
  ]);
  if ($existing) {
    printf("  skip   %-22s %-16s — credential %d already exists\n", $name, $number, reset($existing)->id());
    $skipped++;
    continue;
  }

  $expiry = getenv('BOS_CERT_EXPIRY_' . $uid) ?: '';
  printf("  create %-22s %-16s authority=%-6s expiry=%s\n", $name, $number, $authority ?: '(none)', $expiry ?: '(NOT SUPPLIED — office must add it)');

  if ($apply) {
    $values = [
      'type' => 'credential',
      'field_credential_type' => $abpa->id(),
      'field_scope' => 'teammate',
      'field_teammate' => $uid,
      'field_credential_number' => $number,
      'field_status' => 'active',
    ];
    if ($authority !== '') {
      $values['field_issuing_authority'] = $authority;
    }
    if ($expiry !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
      $values['field_expiration_date'] = $expiry;
    }
    $credential = $etm->getStorage('credential')->create($values);
    $credential->save();
    printf("         -> credential %d \"%s\"\n", $credential->id(), $credential->label());
  }
  $created++;
}

printf("\n%d to create, %d already present.%s\n", $created, $skipped, $apply ? '' : ' (dry-run)');
print "NOTE: profile fields are left in place — the backflow snapshot still falls back to them.\n";
