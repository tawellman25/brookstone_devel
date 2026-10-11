<?php
/**
 * Swap the season page's plain services list for the service CARDS.
 *
 * field_services_performed rendered as a bare column of links — eighteen on
 * Fall, with nothing to say what any of them is. The season_services EVA lists
 * the same services as the card component the services pages already use, so a
 * service looks the same wherever it appears.
 *
 * This hides the raw field and puts the EVA in its place: same weight, and
 * inside the same field group where one holds it.
 *
 * ⚠ It reads the CURRENT display rather than assuming a layout. dev and live
 * disagree here — live's admin_view keeps field_services_performed inside
 * group_public_view and dev's does not — so a hardcoded group name would be
 * right on one environment and wrong on the other. Whatever group holds the
 * field is where the cards go; if none does, only the weight is matched.
 *
 * Idempotent, dry-run unless BOS_EVA_APPLY=1.
 */
$apply = getenv('BOS_EVA_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (set BOS_EVA_APPLY=1)');

$repo = \Drupal::service('entity_display.repository');
$field = 'field_services_performed';
$eva = 'season_services_entity_view_1';
$modes = array_values(array_unique(array_merge(['default'],
  array_keys($repo->getViewModeOptionsByBundle('taxonomy_term', 'season')))));

foreach ($modes as $mode) {
  $d = $repo->getViewDisplay('taxonomy_term', 'season', $mode);
  if ($d->isNew()) {
    continue;
  }
  $raw = $d->getComponent($field);
  $cur = $d->getComponent($eva);
  if (!$raw && $cur) {
    printf("  %-14s already converted\n", $mode);
    continue;
  }
  if (!$raw && !$cur) {
    printf("  %-14s neither the field nor the cards are shown — skipped\n", $mode);
    continue;
  }

  $weight = $raw['weight'] ?? 10;
  $groups = $d->getThirdPartySettings('field_group');
  $holder = NULL;
  foreach ($groups as $gid => $gc) {
    if (in_array($field, $gc['children'] ?? [], TRUE)) {
      $holder = $gid;
    }
  }
  printf("  %-14s cards at weight %d%s; plain list hidden\n", $mode, $weight,
    $holder ? ", inside $holder" : '');

  if (!$apply) {
    continue;
  }
  $d->setComponent($eva, ['weight' => $weight, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  $d->removeComponent($field);
  if ($holder !== NULL) {
    $children = $groups[$holder]['children'];
    // Replace in place so the cards keep the field's position in the group.
    $children = array_values(array_map(fn($c) => $c === $field ? $eva : $c, $children));
    $groups[$holder]['children'] = array_values(array_unique($children));
    $d->setThirdPartySetting('field_group', $holder, $groups[$holder]);
  }
  $d->save();
}
print $apply ? "\ndone\n" : "\nnothing written\n";
