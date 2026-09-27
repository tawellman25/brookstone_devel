<?php

declare(strict_types=1);

/**
 * Retire core `description` on the spraying_locations vocabulary.
 *
 * The 18 children seeded by seed_spraying_locations_content.php already carry
 * field_short_description / field_public_description / field_teammate_description
 * with core `description` cleared. Arena and Driveway were deliberately left
 * alone, so their public copy still lives in core `description` — which is why
 * `description` was still pinned to the public display.
 *
 * This moves those two terms onto the same three fields so `description` can be
 * dropped from every display and the vocabulary has ONE source of truth:
 *
 *   - field_short_description  <- first paragraph of `description`, as plain text
 *   - field_public_description <- the remaining paragraphs, as HTML
 *   - description              <- cleared
 *
 * No copy is written or rewritten: the split is structural and uses the term's
 * own words. Word-paste artifacts are normalised (inline `style` attributes on
 * paragraph tags, trailing `<br>&nbsp;`) so these two bodies match the other 18.
 * field_teammate_description is already correct on both and is NOT touched.
 *
 * Idempotent (a term that already has field_public_description is skipped).
 * Dry-run by default; writes a backup JSON of every changed field before saving.
 *
 *   drush php:script web/scripts/migrate_spraying_locations_descriptions.php
 *   BOS_LOC_MIGRATE_APPLY=1 drush php:script web/scripts/migrate_spraying_locations_descriptions.php
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileSystemInterface;

$apply = getenv('BOS_LOC_MIGRATE_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';

// Only these two: every other term in the vocab is already migrated.
$SLUGS = ['arena', 'driveway'];

/** Split a flat <p>-sequence body into its top-level paragraph blocks. */
$paragraphs = function (string $html): array {
  if (!preg_match_all('#<p\b[^>]*>.*?</p>#is', $html, $m)) {
    return [];
  }
  return $m[0];
};

/** Paragraph HTML -> clean single-line plain text. */
$toPlain = function (string $html): string {
  $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  // Collapse all whitespace (incl. non-breaking space) to single spaces.
  $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
  return trim($text);
};

/** Strip Word-paste artifacts without touching the prose. */
$normalise = function (string $html): string {
  // Inline style attributes on any tag (margin-bottom:4.0pt; etc.).
  $html = preg_replace('/\s+style="[^"]*"/i', '', $html);
  // Trailing <br> + nbsp padding at the end of a paragraph.
  $html = preg_replace('#(?:<br\s*/?>|&nbsp;|\x{00A0}|\s)+</p>#iu', '</p>', $html);
  // Blank paragraphs left behind by the above.
  $html = preg_replace('#<p>\s*</p>#i', '', $html);
  return trim($html);
};

$am = \Drupal::service('path_alias.manager');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$bySlug = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $t) {
  $alias = $am->getAliasByPath('/taxonomy/term/' . $t->id());
  $bySlug[substr(strrchr($alias, '/'), 1)] = $t;
}

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (set BOS_LOC_MIGRATE_APPLY=1 to write)\n\n";

$backup = [];
$changed = 0;
$skipped = 0;
foreach ($SLUGS as $slug) {
  if (!isset($bySlug[$slug])) {
    print "  WARNING no term for slug '$slug' — skipped\n";
    continue;
  }
  $t = $bySlug[$slug];
  $name = $t->label();

  $existingPublic = trim((string) ($t->get('field_public_description')->value ?? ''));
  if ($existingPublic !== '') {
    printf("  skip %-10s (tid %s) — field_public_description already set\n", $slug, $t->id());
    $skipped++;
    continue;
  }

  $desc = (string) ($t->get('description')->value ?? '');
  if (trim($desc) === '') {
    printf("  skip %-10s (tid %s) — core description empty, nothing to move\n", $slug, $t->id());
    $skipped++;
    continue;
  }

  $blocks = $paragraphs($desc);
  if (count($blocks) < 2) {
    printf(
      "  SKIP %-10s (tid %s) — found %d top-level <p> block(s); refusing to guess a lead/body split\n",
      $slug,
      $t->id(),
      count($blocks)
    );
    $skipped++;
    continue;
  }

  $short = $toPlain(array_shift($blocks));
  $public = $normalise(implode('', $blocks));

  printf("  %-10s (tid %s) %s\n", $slug, $t->id(), $name);
  printf("      short  (%4d ch): %s\n", mb_strlen($short), mb_substr($short, 0, 96) . (mb_strlen($short) > 96 ? '…' : ''));
  printf("      public (%4d ch): %d paragraph(s)\n", mb_strlen($public), count($blocks));
  printf("      description    : %d ch -> cleared\n", mb_strlen(trim($desc)));

  $backup[] = [
    'slug' => $slug,
    'tid' => $t->id(),
    'name' => $name,
    'old_description' => $desc,
    'old_description_format' => $t->get('description')->format,
    'old_short' => $t->get('field_short_description')->value,
    'old_public' => $t->get('field_public_description')->value,
    'new_short' => $short,
    'new_public' => $public,
  ];

  if ($apply) {
    $t->set('field_short_description', ['value' => $short, 'format' => 'basic_html']);
    $t->set('field_public_description', ['value' => $public, 'format' => 'full_html']);
    $t->set('description', ['value' => '', 'format' => $t->get('description')->format ?: 'basic_html']);
    $t->save();
    Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
  $changed++;
}

if ($apply && $backup) {
  $file = 'public://spraying_locations_desc_migration_backup_' . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(
    json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $file,
    FileSystemInterface::EXISTS_REPLACE
  );
  print "\n  backup: " . \Drupal::service('file_system')->realpath($file) . "\n";
}

printf("\nDONE. %d migrated, %d skipped.%s\n", $changed, $skipped, $apply ? '' : ' (dry-run — nothing written)');
