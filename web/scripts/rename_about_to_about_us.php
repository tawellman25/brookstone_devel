<?php

/**
 * Rename the public About page URL /about -> /about-us.
 *   - Repoints the node's path alias.
 *   - Adds a 301 redirect /about -> /about-us (old links / SEO keep working).
 *   - Updates any menu links stored as internal:/about (entity: links follow
 *     the alias automatically and are left alone).
 *
 * Idempotent; keyed on the alias (node id / menu link ids differ per env), so
 * run per env:  drush php:script web/scripts/rename_about_to_about_us.php
 */

use Drupal\redirect\Entity\Redirect;

$OLD = '/about';
$NEW = '/about-us';
$out = [];

// 1) Path alias.
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$system_path = NULL;
$aliases = $aliasStorage->loadByProperties(['alias' => $OLD]);
if ($aliases) {
  $a = reset($aliases);
  $system_path = $a->getPath();
  $a->set('alias', $NEW)->save();
  $out[] = "alias $system_path: $OLD -> $NEW";
}
else {
  $done = $aliasStorage->loadByProperties(['alias' => $NEW]);
  if ($done) {
    $system_path = reset($done)->getPath();
    $out[] = "alias already $NEW ($system_path)";
  }
  else {
    $out[] = "WARN: no $OLD or $NEW alias found — nothing to rename";
  }
}

// 2) 301 redirect old -> new.
if (\Drupal::moduleHandler()->moduleExists('redirect')) {
  $src = ltrim($OLD, '/');
  $existing = \Drupal::entityTypeManager()->getStorage('redirect')
    ->loadByProperties(['redirect_source__path' => $src]);
  if (!$existing) {
    Redirect::create([
      'redirect_source' => ['path' => $src, 'query' => []],
      'redirect_redirect' => ['uri' => 'internal:' . $NEW],
      'language' => 'und',
      'status_code' => 301,
    ])->save();
    $out[] = "redirect created: $src -> $NEW (301)";
  }
  else {
    $out[] = "redirect for '$src' already exists";
  }
}
else {
  $out[] = 'NOTE: redirect module not enabled — no 301 created';
}

// 3) Menu links stored as internal:/about.
$mlids = \Drupal::entityQuery('menu_link_content')->accessCheck(FALSE)->execute();
foreach (\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple($mlids) as $m) {
  $uri = $m->get('link')->uri ?? '';
  if ($uri === 'internal:' . $OLD) {
    $m->set('link', ['uri' => 'internal:' . $NEW])->save();
    $out[] = "menu link {$m->id()} \"{$m->label()}\": internal:$OLD -> internal:$NEW";
  }
}

print implode("\n", $out) . "\nDONE.\n";
