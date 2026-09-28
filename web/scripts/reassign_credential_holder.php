<?php

declare(strict_types=1);

/**
 * Reassign the holder of a teammate-scoped credential.
 *
 * One-time/repeatable: BOS_CRED_CODE (credential type code) + BOS_CRED_UID.
 * The presave recomposes the title from type + holder, so the label follows.
 *
 *   BOS_CRED_CODE=CDA_QS BOS_CRED_UID=1443 drush php:script web/scripts/reassign_credential_holder.php
 *   add BOS_CRED_APPLY=1 to write.
 */

$code = getenv('BOS_CRED_CODE') ?: '';
$uid = (int) (getenv('BOS_CRED_UID') ?: 0);
$apply = getenv('BOS_CRED_APPLY') === '1';
if ($code === '' || !$uid) {
  print "ERROR: set BOS_CRED_CODE and BOS_CRED_UID.\n";
  return;
}

$etm = \Drupal::entityTypeManager();
$user = $etm->getStorage('user')->load($uid);
if (!$user) {
  print "ERROR: no user $uid.\n";
  return;
}
if (!in_array('teammates', $user->getRoles(), TRUE)) {
  printf("ERROR: %s (uid %d) does not hold the teammates role — the field's handler filters to it.\n", $user->getAccountName(), $uid);
  return;
}

$target = NULL;
foreach ($etm->getStorage('credential')->loadMultiple(\Drupal::entityQuery('credential')->accessCheck(FALSE)->execute()) as $c) {
  $t = $c->get('field_credential_type')->entity;
  if ($t && (string) $t->get('field_credential_code')->value === $code) {
    $target = $c;
    break;
  }
}
if (!$target) {
  print "ERROR: no credential of type $code.\n";
  return;
}

$was = $target->get('field_teammate')->entity;
printf("%s: credential %d \"%s\"\n", $apply ? 'APPLY' : 'DRY-RUN', $target->id(), $target->label());
printf("  holder: %s -> %s (uid %d)\n", $was ? $was->getAccountName() : 'NONE', $user->getAccountName(), $uid);

if ($apply) {
  $target->set('field_scope', 'teammate')->set('field_teammate', $uid)->save();
  $fresh = $etm->getStorage('credential')->loadUnchanged($target->id());
  printf("  title now: \"%s\"\n", $fresh->label());
}
else {
  print "  (dry-run — add BOS_CRED_APPLY=1)\n";
}
