<?php

declare(strict_types=1);

/**
 * Sanitize bad-paste markup out of material_types term descriptions.
 *
 * 12 material_types subcategory terms (Vines, Perennials, Deciduous, Rock, …)
 * had their `field_public_description` pasted from a web app: each begins with a
 * `<style>` reset/normalize block (which, under full_html, was injected into the
 * term page AND dumped as raw CSS text into the child-category card blurb) and
 * wraps the real prose in CSS-in-JS `<span class="css-…">` soup with inline
 * `style="color:black;…"` attributes that override the theme.
 *
 * This strips the `<style>`/`<script>` blocks (tag + content) and runs the rest
 * through Xss::filter with a prose allowlist — keeping the real words + real
 * formatting (paragraphs, bold/italic, lists, links) and dropping the junk
 * classes and inline styles. It does NOT rewrite any copy.
 *
 * Idempotent. Dry run (default) prints before/after; apply with BOS_CLEAN_APPLY=1.
 * On apply it first writes the original values to a timestamped backup JSON.
 *
 *   drush php:script web/scripts/clean_material_type_descriptions.php            (dry run)
 *   BOS_CLEAN_APPLY=1 drush php:script web/scripts/clean_material_type_descriptions.php
 */

use Drupal\Component\Utility\Xss;

$APPLY = (bool) getenv('BOS_CLEAN_APPLY');
$ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'];

$clean_html = function (string $val) use ($ALLOWED): string {
  $v = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $val);
  $v = Xss::filter($v, $ALLOWED);
  // Drop paragraphs/list items left empty once their junk children are gone.
  $v = preg_replace('#<(p|li|h[2-6])>\s*(&nbsp;)?\s*</\1>#i', '', $v);
  return trim($v);
};

$FIELDS = ['field_public_description', 'field_teammate_description'];
$isDirty = fn(string $v): bool => stripos($v, '<style') !== FALSE || stripos($v, '<script') !== FALSE || (bool) preg_match('/\bcss-[0-9a-z]{5,}/i', $v);

$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
// [tid => [term, [field => dirty]]]
$targets = [];
foreach ($ts->loadByProperties(['vid' => 'material_types']) as $t) {
  $dirtyFields = [];
  foreach ($FIELDS as $f) {
    if ($t->hasField($f) && !$t->get($f)->isEmpty() && $isDirty((string) $t->get($f)->first()->value)) {
      $dirtyFields[] = $f;
    }
  }
  if ($dirtyFields) {
    $targets[$t->id()] = [$t, $dirtyFields];
  }
}

printf("%s — %d material_types terms with dirty markup\n\n", $APPLY ? 'APPLY' : 'DRY RUN', count($targets));

if ($APPLY && $targets) {
  $backup = [];
  foreach ($targets as $id => [$t, $dirtyFields]) {
    foreach ($dirtyFields as $f) {
      $backup[$id][$f] = (string) $t->get($f)->first()->value;
    }
    $backup[$id]['name'] = $t->label();
  }
  $file = 'public://material_desc_backup_' . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(json_encode($backup, JSON_PRETTY_PRINT), $file, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
  printf("backup written: %s\n\n", \Drupal::service('file_system')->realpath($file));
}

foreach ($targets as $id => [$t, $dirtyFields]) {
  printf("  %-14s tid=%-6d  fields: %s\n", $t->label(), $id, implode(', ', $dirtyFields));
  foreach ($dirtyFields as $f) {
    $item = $t->get($f)->first();
    $orig = (string) $item->value;
    $clean = $clean_html($orig);
    printf("      %-26s %d -> %d bytes\n", $f, strlen($orig), strlen($clean));
    if ($APPLY) {
      $t->set($f, ['value' => $clean, 'format' => $item->format ?: 'full_html']);
    }
  }
  if ($APPLY) {
    $t->save();
  }
}
print "\n" . ($APPLY ? "DONE (applied + re-saved).\n" : "DRY RUN — nothing saved. Re-run with BOS_CLEAN_APPLY=1.\n");
