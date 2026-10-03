<?php

declare(strict_types=1);

/**
 * Enter each term's meta description FROM its short description.
 *
 * Vocabulary comes from BOS_VID (required). Sets `field_meta_tags.description`
 * on every term of that vocabulary to the
 * plain-text value of field_short_description — that field is already the
 * authored public one-liner, so it is the right source and nothing new is
 * written. Verbatim: tags stripped, entities decoded, whitespace collapsed, and
 * otherwise NOT edited or truncated.
 *
 * Only `description` is set. `title` keeps the global taxonomy pattern
 * ("{name} | Brookstone Outdoors | Delta & Montrose CO") and `og_description`
 * keeps the global line — neither is touched.
 *
 * Idempotent and non-destructive: a term that already has a NON-EMPTY
 * description override is left alone (so an office edit on the term form is never
 * clobbered) unless BOS_META_FORCE=1. Dry-run by default.
 *
 * Over-length values are reported, not trimmed — Google renders ~155-160 chars,
 * so anything longer is flagged for marketing to tighten rather than cut
 * mid-thought here.
 *
 *   BOS_VID=spraying_locations drush php:script web/scripts/seed_vocab_meta_descriptions_from_short.php
 *   BOS_VID=wind_direction BOS_META_APPLY=1 drush php:script web/scripts/seed_vocab_meta_descriptions_from_short.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_META_APPLY') === '1';
$force = getenv('BOS_META_FORCE') === '1';
$etm = \Drupal::entityTypeManager();
$vid = getenv('BOS_VID') ?: '';
$SOFT_LIMIT = 160;

if ($vid === '') {
  print "ERROR: set BOS_VID to the vocabulary machine name. Aborting.\n";
  return;
}
print "VOCAB: $vid\n";

/** text_long value -> single-line plain text. */
$toPlain = function (?string $html): string {
  $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
  return trim($text);
};

$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$terms = $etm->getStorage('taxonomy_term')->loadMultiple($tids);
uasort($terms, fn($a, $b) => strcmp($a->label(), $b->label()));

print $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (set BOS_META_APPLY=1 to write)\n";
print $force ? "FORCE: existing description overrides WILL be replaced\n" : "existing non-empty description overrides are preserved\n";
print "\n";

$set = 0;
$kept = 0;
$empty = 0;
$long = [];
foreach ($terms as $t) {
  if (!$t->hasField('field_meta_tags')) {
    print "  ERROR field_meta_tags missing on {$t->label()} — run setup_vocab_meta_tags.php first\n";
    return;
  }
  $short = $toPlain($t->get('field_short_description')->value ?? '');
  // Fall back to the public body when no teaser is written. A meta description
  // has to come from somewhere, and on plant_characteristics only 1 of 41 terms
  // has a teaser — seeding from the teaser alone would have left 40 term pages
  // with no meta description at all, which is the very trap this seeding exists
  // to avoid. The teaser still wins when present: it was written to be read on
  // its own, which is exactly what a search result shows.
  if ($short === '' && $t->hasField('field_public_description')) {
    $body = $toPlain($t->get('field_public_description')->value ?? '');
    // A body is not a meta description. Take whole opening SENTENCES up to ~160
    // characters — never a mid-thought cut, which is the reason the earlier pass
    // refused to auto-trim. Verbatim would have stored 970 characters for
    // Vining, and Google would clip it anyway.
    if ($body !== '') {
      $parts = preg_split('/(?<=[.!?])\s+/u', $body) ?: [$body];
      $acc = '';
      foreach ($parts as $sentence) {
        $candidate = $acc === '' ? $sentence : $acc . ' ' . $sentence;
        if ($acc !== '' && mb_strlen($candidate) > 160) {
          break;
        }
        $acc = $candidate;
      }
      // If even the first sentence runs long, keep it whole rather than cut it.
      $short = $acc !== '' ? $acc : $body;
    }
  }
  if ($short === '') {
    printf("  %-24s SKIP — no short description\n", $t->label());
    $empty++;
    continue;
  }

  $raw = (string) ($t->get('field_meta_tags')->value ?? '');
  $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
  $existing = trim((string) ($tags['description'] ?? ''));

  if ($existing !== '' && !$force) {
    if ($existing === $short) {
      printf("  %-24s ok    — already matches short (%d ch)\n", $t->label(), mb_strlen($existing));
    }
    else {
      printf("  %-24s KEEP  — has its own description (%d ch), not overwritten\n", $t->label(), mb_strlen($existing));
    }
    $kept++;
    continue;
  }

  $tags['description'] = $short;
  $len = mb_strlen($short);
  if ($len > $SOFT_LIMIT) {
    $long[] = sprintf('%s (%d ch)', $t->label(), $len);
  }
  printf("  %-24s set   %3d ch%s  %s\n", $t->label(), $len, $len > $SOFT_LIMIT ? ' ⚠' : '  ', mb_substr($short, 0, 56) . ($len > 56 ? '…' : ''));

  if ($apply) {
    $t->set('field_meta_tags', json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $t->save();
    Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
  $set++;
}

printf("\n%d set, %d preserved, %d without a short description.%s\n", $set, $kept, $empty, $apply ? '' : ' (dry-run — nothing written)');
if ($long) {
  printf("\n⚠ Longer than ~%d chars — Google will truncate; worth tightening in the copy:\n  - %s\n", $SOFT_LIMIT, implode("\n  - ", $long));
}
