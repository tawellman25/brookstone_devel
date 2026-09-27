<?php

declare(strict_types=1);

/**
 * Clear the stored core `description` on a vocabulary (BOS_VID) — but ONLY where
 * it is provably redundant.
 *
 * A term's `description` is cleared when it is empty, or when its plain text is
 * identical to field_short_description or field_public_description (i.e. the copy
 * already lives in a dedicated field). **Any term whose description carries text
 * found nowhere else is REFUSED and reported** — this script can never destroy the
 * only copy of something.
 *
 * Run this AFTER the vocabulary's meta description has been moved onto a
 * field_meta_tags override (seed_vocab_meta_descriptions_from_short.php), because
 * `metatag.metatag_defaults.taxonomy_term` resolves its description from
 * `[term:description]` and the global default has no `description` fallback — so
 * clearing first would strip the page's meta description. See
 * Governance/drupal_bos_gotchas.md.
 *
 * Dry-run by default; writes a backup JSON of every cleared value before saving.
 *
 *   BOS_VID=wind_direction drush php:script web/scripts/retire_vocab_core_description.php
 *   BOS_VID=wind_direction BOS_RETIRE_APPLY=1 drush php:script web/scripts/retire_vocab_core_description.php
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileSystemInterface;

$vid = getenv('BOS_VID') ?: '';
$apply = getenv('BOS_RETIRE_APPLY') === '1';
if ($vid === '') {
  print "ERROR: set BOS_VID to the vocabulary machine name. Aborting.\n";
  return;
}

$etm = \Drupal::entityTypeManager();
$plain = function (?string $h): string {
  $t = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
};

$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$terms = $etm->getStorage('taxonomy_term')->loadMultiple($tids);
if (!$terms) {
  print "No terms in vocabulary '$vid'.\n";
  return;
}
uasort($terms, fn($a, $b) => strcmp($a->label(), $b->label()));

printf("VOCAB: %s   MODE: %s\n\n", $vid, $apply ? 'APPLY' : 'DRY-RUN (set BOS_RETIRE_APPLY=1 to write)');

$backup = [];
$cleared = 0;
$alreadyEmpty = 0;
$refused = [];

foreach ($terms as $t) {
  $descRaw = (string) ($t->get('description')->value ?? '');
  $desc = $plain($descRaw);
  if ($desc === '') {
    $alreadyEmpty++;
    continue;
  }

  $dupOf = NULL;
  foreach (['field_short_description', 'field_public_description'] as $f) {
    if ($t->hasField($f) && $plain($t->get($f)->value ?? '') === $desc) {
      $dupOf = $f;
      break;
    }
  }

  if ($dupOf === NULL) {
    $refused[] = sprintf('%s (tid %s, %d ch)', $t->label(), $t->id(), mb_strlen($desc));
    printf("  %-14s REFUSED — description text is not duplicated in a dedicated field\n", $t->label());
    continue;
  }

  printf("  %-14s clear  (identical to %s, %d ch)\n", $t->label(), $dupOf, mb_strlen($desc));
  $backup[] = [
    'vid' => $vid,
    'tid' => $t->id(),
    'name' => $t->label(),
    'duplicate_of' => $dupOf,
    'old_description' => $descRaw,
    'old_description_format' => $t->get('description')->format,
  ];

  if ($apply) {
    $t->set('description', ['value' => '', 'format' => $t->get('description')->format ?: 'basic_html']);
    $t->save();
    Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
  $cleared++;
}

if ($apply && $backup) {
  $file = "public://{$vid}_core_description_retire_backup_" . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(
    json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $file,
    FileSystemInterface::EXISTS_REPLACE
  );
  // realpath() returns empty for the S3 stream wrapper on live; print the URI too.
  print "\n  backup: $file  " . (\Drupal::service('file_system')->realpath($file) ?: '(S3)') . "\n";
}

printf(
  "\n%d cleared, %d already empty, %d refused.%s\n",
  $cleared,
  $alreadyEmpty,
  count($refused),
  $apply ? '' : ' (dry-run — nothing written)'
);
if ($refused) {
  print "\nREFUSED (unique text — migrate it to a dedicated field first):\n  - " . implode("\n  - ", $refused) . "\n";
}
