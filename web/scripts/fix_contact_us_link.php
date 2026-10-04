<?php

declare(strict_types=1);

/**
 * /contact-us has never existed - it is a 404. The correct path is /contact.
 *
 * One reference, in the CREW description of "New Landscapes", so it was never
 * public-facing - but it is a dead link a tech taps in the field.
 *
 * Path correction only. No copy is rewritten and the anchor text is untouched.
 *
 *   drush php:script web/scripts/fix_contact_us_link.php
 *   BOS_LINK_APPLY=1 drush php:script web/scripts/fix_contact_us_link.php
 */

$apply = getenv('BOS_LINK_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_LINK_APPLY=1 to write)\n\n";

// The destination must actually work before anything is pointed at it.
if (!\Drupal::service('path.validator')->isValid('/contact')) {
  print "ABORT — /contact does not resolve. Not repointing anything at a second dead link.\n";
  return;
}
print "✓ /contact resolves\n";

$fields = ['field_service_public_desc', 'field_service_crew_desc', 'description', 'field_call_to_action'];
$changed = 0; $backup = [];

foreach ($etm->getStorage('taxonomy_term')->loadMultiple(\Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)->condition('vid', 'services')->execute()) as $t) {
  $dirty = FALSE;
  foreach ($fields as $f) {
    if (!$t->hasField($f)) { continue; }
    $item = $t->get($f)->first();
    if (!$item) { continue; }
    $v = (string) ($item->value ?? '');
    // Exact href only. A negative lookahead keeps /contact-us-something safe and
    // makes a re-run a no-op rather than producing /contact/contact.
    $new = preg_replace('~href="/contact-us(?![\w-])~', 'href="/contact', $v, -1, $n);
    if (!$n) { continue; }
    printf("  %-22s %-26s %d link(s)\n", $t->label(), $f, $n);
    $backup[$t->id()][$f] = $v;
    $t->set($f, ['value' => $new, 'format' => $item->format]);
    $dirty = TRUE; $changed += $n;
  }
  if ($dirty && $apply) { $t->save(); \Drupal\Core\Cache\Cache::invalidateTags(['taxonomy_term:' . $t->id()]); }
}

if (!$changed) { print "  (no /contact-us links found — already fixed)\n"; }
if ($apply && $backup) {
  $f = '/tmp/contact_link_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  print "\nPrevious values backed up to $f\n";
}
printf("\n%d link(s) repointed%s.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');
