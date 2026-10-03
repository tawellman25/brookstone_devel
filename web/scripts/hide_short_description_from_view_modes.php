<?php

/**
 * field_short_description is NOT viewable on any view mode.
 *
 * Todd, 2026-10-03: it exists solely for VIEWS to use as the card/list text on
 * a PARENT term's page. It is never part of a term's own rendered page — a term
 * page shows its public description; the teaser is what the level above says
 * about it.
 *
 * Corrects a change I made earlier today: marketing's brief said to unhide it on
 * the plant_characteristics view modes and I did. The brief wanted the field
 * usable; it is usable through views, which is the whole point of it.
 *
 * ⚠ IT CANNOT SIMPLY BE HIDDEN EVERYWHERE, because on two vocabularies the
 * teaser is currently doing the BODY's job:
 *
 *   wind_direction   9 terms, public description EMPTY, teaser shown on full
 *                    and client_view — hiding it blanks 9 public pages.
 *   brookstone_tags  75 terms, same shape on its default display.
 *
 * So where a display shows the teaser and does NOT show a populated public
 * description, the teaser is first COPIED into the public description (copied,
 * not moved — the teaser stays for the cards) and the public description is put
 * on that display in its place. Nothing blanks, and the teaser goes back to
 * being card-only.
 *
 * The term EDIT FORM keeps it everywhere; the office has to write it. The Views
 * field instances that render it on cards and lists are untouched — those are
 * the consumers it exists for.
 *
 * Usage:
 *   drush php:script web/scripts/hide_short_description_from_view_modes.php
 *   BOS_HIDE_APPLY=1 drush php:script web/scripts/hide_short_description_from_view_modes.php
 */

$apply = getenv('BOS_HIDE_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_HIDE_APPLY=1 to write)');

$populated = function (string $vid, string $field) use ($etm): int {
  $n = 0;
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
    if ($t->hasField($field) && !$t->get($field)->isEmpty()
      && trim(strip_tags((string) $t->get($field)->first()->getValue()['value'])) !== '') {
      $n++;
    }
  }
  return $n;
};

// --- 1. protect any display where the teaser is serving as the body ---------
print "1. displays where the teaser is doing the body's job\n";
$rescued = [];
foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $display) {
  if (strpos($id, 'taxonomy_term.') !== 0 || !$display->getComponent('field_short_description')) {
    continue;
  }
  [, $vid, $mode] = explode('.', $id);
  if ($display->getComponent('field_public_description')) {
    continue;
  }
  if ($populated($vid, 'field_public_description') > 0) {
    continue;
  }
  $teasers = $populated($vid, 'field_short_description');
  printf("   %-52s %d term(s) would go blank\n", $id, $teasers);
  $rescued[$vid][] = $id;
}
if (!$rescued) {
  print "   none\n";
}

print "\n2. copying the teaser into the public description on those vocabularies\n";
foreach ($rescued as $vid => $ids) {
  $storage = $etm->getStorage('field_storage_config')->load('taxonomy_term.field_public_description');
  $instance = $etm->getStorage('field_config')->load('taxonomy_term.' . $vid . '.field_public_description');
  if (!$instance) {
    printf("   %-20s creating field_public_description\n", $vid);
    if ($apply && $storage) {
      $etm->getStorage('field_config')->create([
        'field_storage' => $storage,
        'bundle' => $vid,
        'label' => 'Public Description',
        'description' => 'The public body copy for this term page.',
        'required' => FALSE,
        'settings' => ['allowed_formats' => ['full_html']],
      ])->save();
      $form = \Drupal::service('entity_display.repository')->getFormDisplay('taxonomy_term', $vid, 'default');
      $form->setComponent('field_public_description', [
        'type' => 'text_textarea', 'weight' => 1, 'region' => 'content',
        'settings' => ['rows' => 5, 'placeholder' => ''], 'third_party_settings' => [],
      ])->save();
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    }
  }
  $copied = 0;
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
    if (!$t->hasField('field_short_description') || $t->get('field_short_description')->isEmpty()) {
      continue;
    }
    $val = (string) $t->get('field_short_description')->first()->getValue()['value'];
    if (trim(strip_tags($val)) === '') {
      continue;
    }
    if ($t->hasField('field_public_description') && !$t->get('field_public_description')->isEmpty()
      && trim((string) $t->get('field_public_description')->first()->getValue()['value']) !== '') {
      continue;
    }
    $copied++;
    if ($apply && $t->hasField('field_public_description')) {
      // Copied, not moved: the teaser stays for the cards.
      $t->set('field_public_description', ['value' => $val, 'format' => 'full_html'])->save();
    }
  }
  printf("   %-20s %d teaser(s) copied into the public description\n", $vid, $copied);

  foreach ($ids as $id) {
    $d = $etm->getStorage('entity_view_display')->load($id);
    $weight = $d->getComponent('field_short_description')['weight'] ?? 0;
    printf("   %-52s show field_public_description at weight %s\n", $id, $weight);
    if ($apply) {
      $d->setComponent('field_public_description', [
        'type' => 'text_default', 'label' => 'hidden', 'weight' => $weight,
        'region' => 'content', 'settings' => [], 'third_party_settings' => [],
      ])->save();
    }
  }
}

// --- 3. now hide the teaser everywhere -------------------------------------
print "\n3. removing field_short_description from every term view display\n";
if ($apply) {
  $etm->getStorage('entity_view_display')->resetCache();
}
$removed = 0;
foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $display) {
  if (strpos($id, 'taxonomy_term.') !== 0 || !$display->getComponent('field_short_description')) {
    continue;
  }
  printf("   %s\n", $id);
  $removed++;
  if ($apply) {
    $display->removeComponent('field_short_description')->save();
  }
}
printf("   %d display(s)%s\n", $removed, $apply ? ' cleared' : ' pending');

if (!$apply) {
  print "\nNothing written. Re-run with BOS_HIDE_APPLY=1 to apply.\n";
}
