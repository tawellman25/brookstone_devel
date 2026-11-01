<?php

/**
 * @file
 * Stop the work-order heading printing an HOA's name twice.
 *
 * The heading (view `wo_title_and_alerts`) prints the property's NICKNAME and
 * then its TITLE on separate lines. For a `property` that is two different and
 * useful things:
 *
 *   The Shoppes                 <- field_nickname
 *   300 Stafford Lane, Delta    <- title
 *
 * For an `hoa` it is the same thing twice:
 *
 *   Bear Creek HOA
 *   Bear Creek HOA
 *
 * ⚠ THE AUTO-LABEL IS NOT THE BUG, and it would be the tempting thing to
 * "fix". The hoa bundle auto-labels from field_nickname deliberately — an
 * HOA's name IS its label, and changing that pattern would rewrite every HOA
 * label to satisfy one heading. The view is what assumes the two values always
 * differ, which is true of `property` and not of `hoa`.
 *
 * So the second line is printed only when it actually says something new.
 * Comparing values rather than testing the bundle means it also covers any
 * other record where someone has typed the nickname and title the same — the
 * duplicate is the thing worth suppressing, whatever caused it.
 *
 * ⚠ `|striptags|trim` on both sides is load-bearing: these tokens render as
 * Markup objects, and comparing them without forcing a string first compares
 * objects, which is never equal — the condition would silently always pass and
 * nothing would change.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/fix_wo_title_hoa_duplicate.php
 */

const VIEW_ID = 'wo_title_and_alerts';
const PLAIN = '{{ title_1 }}</h2>';
const GUARDED = '{% if title_1|striptags|trim != field_nickname|striptags|trim %}{{ title_1 }}{% endif %}</h2>';

$apply = getenv('BOS_APPLY') === '1';
$config = \Drupal::configFactory()->getEditable('views.view.' . VIEW_ID);
if ($config->isNew()) {
  printf("ABORT: view %s not found here.\n", VIEW_ID);
  return;
}

$changed = 0;
foreach ((array) $config->get('display') as $did => $display) {
  $path = "display.$did.display_options.fields.nothing.alter.text";
  $text = $config->get($path);
  if (!is_string($text) || $text === '') {
    continue;
  }
  if (str_contains($text, 'title_1|striptags')) {
    printf("  %-14s already guarded — no change\n", $did);
    continue;
  }
  if (!str_contains($text, PLAIN)) {
    printf("  %-14s the expected '%s' is not present — SKIPPED rather than guessed\n", $did, PLAIN);
    continue;
  }
  $new = str_replace(PLAIN, GUARDED, $text);
  printf("  %-14s will guard the second line\n", $did);
  if ($apply) {
    $config->set($path, $new);
  }
  $changed++;
}

if (!$changed) {
  print "\nNothing to change.\n";
  return;
}
if (!$apply) {
  printf("\nDRY RUN — %d display(s) would change. Re-run with BOS_APPLY=1.\n", $changed);
  return;
}
$config->save();

$after = \Drupal::config('views.view.' . VIEW_ID)->get('display.default.display_options.fields.nothing.alter.text');
printf("\nSaved. Heading template now:\n%s\n", $after);
