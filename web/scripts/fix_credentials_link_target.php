<?php

declare(strict_types=1);

/**
 * Repoint public links from /credentials to /about-us/credentials.
 *
 * Todd 2026-09-27: /about-us/credentials is the wanted URL. The only public link
 * is in the Spray Chemicals page footer (node 113, applied by
 * build_spray_parent_headers_footers.php, whose source copy is updated too so a
 * re-run cannot regress it).
 *
 * Idempotent: the match uses a negative lookbehind, so an already-correct
 * /about-us/credentials is never rewritten into /about-us/about-us/credentials.
 * Deliberately does NOT touch views.view.credentials — that is the credential
 * entity's own admin view, whose paths (admin/office/credentials,
 * teammates/credentials) merely contain the same substring.
 *
 * Dry-run by default; writes a backup JSON of every changed body first.
 *
 *   drush php:script web/scripts/fix_credentials_link_target.php
 *   BOS_LINK_APPLY=1 drush php:script web/scripts/fix_credentials_link_target.php
 */

use Drupal\Core\File\FileSystemInterface;

$apply = getenv('BOS_LINK_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$pattern = '#(?<!about-us)/credentials#';

printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_LINK_APPLY=1 to write)');

/* Find every node whose body carries a bare /credentials. */
$db = \Drupal::database();
$ids = $db->query("SELECT DISTINCT entity_id FROM {node__body} WHERE body_value LIKE '%/credentials%'")->fetchCol();

$backup = [];
$changed = 0;
foreach ($etm->getStorage('node')->loadMultiple($ids) as $node) {
  $body = (string) $node->get('body')->value;
  $hits = preg_match_all($pattern, $body);
  if (!$hits) {
    printf("  skip   node %-5s %-34s already correct\n", $node->id(), mb_substr($node->label(), 0, 34));
    continue;
  }
  $new = preg_replace($pattern, '/about-us/credentials', $body);
  printf("  fix    node %-5s %-34s %d link(s)\n", $node->id(), mb_substr($node->label(), 0, 34), $hits);
  $backup[] = ['nid' => $node->id(), 'title' => $node->label(), 'old_body' => $body];
  if ($apply) {
    $node->set('body', ['value' => $new, 'format' => $node->get('body')->format, 'summary' => $node->get('body')->summary]);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Repoint /credentials link to /about-us/credentials.');
    $node->save();
  }
  $changed++;
}

if ($apply && $backup) {
  $file = 'public://credentials_link_fix_backup_' . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(
    json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $file,
    FileSystemInterface::EXISTS_REPLACE
  );
  print "\n  backup: $file\n";
}

printf("\n%d node(s) %s.\n", $changed, $apply ? 'updated' : 'would change (dry-run)');
