<?php

/**
 * @file
 * Drupal 11 upgrade — step 0 hygiene. Idempotent, DRY-RUN BY DEFAULT.
 *
 * Three cleanups that must happen BEFORE core moves, so they can never be
 * mistaken for upgrade fallout:
 *
 *   1. Pending entity definitions (DEFINITION_CREATED) — an entity type that
 *      exists in code but was never installed into storage. On BOS this is
 *      `ai_agent_override` (provider ai_agents). A pending definition turns a
 *      major-version updb into a puzzle.
 *   2. Orphaned `system.schema` entries — a module row left behind after the
 *      module was removed from disk. On BOS this was `migrate_devel` at v8000.
 *   3. Core modules that are ENABLED here but DELETED from core in Drupal 11.
 *      Measured against real 11.4.12 source, core drops: action, book, forum,
 *      help_topics, statistics, tracker, tour. BOS has action, forum and tour
 *      enabled. Left enabled, they become step-6 blockers.
 *
 * `action` is the Actions UI module ONLY — the action plugin system and the
 * `system.action.*` config entities belong to system/user and survive. VBO was
 * verified intact afterwards (57 displays / 119 configured actions / 0
 * unresolvable). Uninstalling it removes /admin/config/system/actions and
 * nothing else.
 *
 * SAFETY: forum is uninstalled only when it holds NO content. The script
 * refuses if any forum node or forum_index row exists, and it will only delete
 * taxonomy terms in the `forums` vocabulary when the forum is otherwise empty
 * (on BOS that was the single stock "General discussion" term created at
 * install). BOS rule 4 — no deletion of operational history — is the reason
 * these guards are hard refusals rather than warnings.
 *
 * Usage:
 *   ddev drush scr web/scripts/d11_step0_cleanup.php              # report only
 *   ddev drush scr web/scripts/d11_step0_cleanup.php -- --apply   # act
 *
 * Exit codes: 0 = nothing to do, or applied cleanly. 1 = a guard refused.
 */

$argv_all = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $extra ?? [], TRUE) || in_array('--apply', $argv_all, TRUE);

// Core modules removed in Drupal 11.4.12, measured against tagged core source
// (github.com/drupal/core, 10.6.20 vs 11.4.12 — 79 modules vs 75).
$REMOVED_IN_D11 = ['action', 'book', 'forum', 'help_topics', 'statistics', 'tracker', 'tour'];

$edum = \Drupal::entityDefinitionUpdateManager();
$etm = \Drupal::entityTypeManager();
$mh = \Drupal::moduleHandler();
$db = \Drupal::database();

$todo = 0;
$refused = 0;
$mode = $apply ? 'APPLY' : 'DRY RUN (pass --apply to act)';
print "=== D11 step 0 cleanup — {$mode} ===\n\n";

// ---------------------------------------------------------------------------
// 1. Pending entity definitions.
// ---------------------------------------------------------------------------
print "1. Pending entity definition updates\n";
$changes = $edum->getChangeList();
if (!$changes) {
  print "   clean — nothing pending\n";
}
foreach ($changes as $etid => $change) {
  $def = $etm->getDefinition($etid, FALSE);
  if (!$def) {
    printf("   SKIP %s — change recorded but no definition in code\n", $etid);
    continue;
  }
  $todo++;
  printf("   %s (%s, provider %s, group %s)\n", $etid, $def->getClass(), $def->getProvider(), $def->getGroup());
  if ($apply) {
    $edum->installEntityType($def);
    print "     -> installed\n";
  }
}

// ---------------------------------------------------------------------------
// 2. Orphaned system.schema entries.
// ---------------------------------------------------------------------------
print "\n2. Orphaned system.schema entries (module absent from disk)\n";
$kv = \Drupal::keyValue('system.schema');
$schema = $kv->getAll();
$available = \Drupal::service('extension.list.module')->getAllAvailableInfo();
$orphans = array_diff_key($schema, $available);
if (!$orphans) {
  printf("   clean — 0 orphans of %d entries\n", count($schema));
}
foreach ($orphans as $name => $ver) {
  $todo++;
  printf("   %s (v%s)\n", $name, $ver);
  if ($apply) {
    $kv->delete($name);
    print "     -> removed\n";
  }
}

// ---------------------------------------------------------------------------
// 3. Core modules deleted in Drupal 11.
// ---------------------------------------------------------------------------
print "\n3. Core modules enabled here but DELETED from core in Drupal 11\n";
$to_uninstall = array_values(array_filter($REMOVED_IN_D11, static fn($m) => $mh->moduleExists($m)));
if (!$to_uninstall) {
  print "   clean — none of the removed core modules are enabled\n";
}

foreach ($to_uninstall as $m) {
  // Report the real blast radius: DIRECT config dependencies only.
  // (ConfigManager::findConfigEntityDependencies returns a transitive closure
  // and wildly overstates this — it reported 356 for forum. Read the stored
  // dependencies instead.)
  $direct = [];
  foreach (\Drupal::configFactory()->listAll() as $cname) {
    $mods = \Drupal::config($cname)->get('dependencies.module');
    if (is_array($mods) && in_array($m, $mods, TRUE)) {
      $direct[] = $cname;
    }
  }
  printf("   %-8s enabled — %d config object(s) depend on it directly\n", $m, count($direct));
  foreach (array_slice($direct, 0, 6) as $d) {
    print "       - $d\n";
  }
  if (count($direct) > 6) {
    printf("       … and %d more\n", count($direct) - 6);
  }
}

// Forum content guard — a hard refusal, not a warning.
if (in_array('forum', $to_uninstall, TRUE)) {
  $nodes = 0;
  $index = 0;
  try {
    $nodes = (int) $db->select('node_field_data', 'n')->condition('n.type', 'forum')
      ->countQuery()->execute()->fetchField();
  }
  catch (\Throwable $e) {
    // No node table / node type — treat as empty.
  }
  try {
    $index = (int) $db->select('forum_index', 'f')->countQuery()->execute()->fetchField();
  }
  catch (\Throwable $e) {
  }
  $terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'forums']);

  printf("   forum content: %d node(s), %d forum_index row(s), %d term(s)\n", $nodes, $index, count($terms));

  if ($nodes > 0 || $index > 0) {
    print "   *** REFUSING to uninstall forum — it holds content.\n";
    print "       BOS rule 4: no deletion of operational history. Review it by hand.\n";
    $to_uninstall = array_values(array_diff($to_uninstall, ['forum']));
    $refused++;
  }
  elseif ($terms) {
    foreach ($terms as $t) {
      printf("   forum is empty; term %s \"%s\" blocks uninstall and will be deleted\n", $t->id(), $t->label());
      if ($apply) {
        $t->delete();
        print "     -> deleted\n";
      }
    }
  }
}

foreach ($to_uninstall as $m) {
  $todo++;
}
if ($to_uninstall && $apply) {
  printf("   uninstalling: %s\n", implode(', ', $to_uninstall));
  \Drupal::service('module_installer')->uninstall($to_uninstall);
  print "     -> uninstalled (stale role permissions referencing removed action plugins are\n";
  print "        dropped automatically; that is cleanup, not an error)\n";
}

// ---------------------------------------------------------------------------
// 4. Block placements left behind by the forum uninstall.
//    Found 2026-11-07: five block.block.* placements of the forum comment
//    field block (`entity_field:node:comment_forum`) across five themes, two
//    of them themes that no longer exist. Core logs "The block plugin was not
//    found" on every admin page for each one. Guarded to that exact plugin id.
// ---------------------------------------------------------------------------
print "\n4. Block placements whose plugin died with the forum module\n";
$dead_plugin = 'entity_field:node:comment_forum';
$dead = [];
foreach (\Drupal::entityTypeManager()->getStorage('block')->loadMultiple() as $block) {
  if ($block->getPluginId() === $dead_plugin) {
    $dead[] = $block;
  }
}
if (!$dead) {
  print "   clean — no placements of $dead_plugin\n";
}
foreach ($dead as $block) {
  $todo++;
  printf("   %s (theme %s, region %s)\n", $block->id(), $block->getTheme(), $block->getRegion());
  if ($apply) {
    $block->delete();
    print "     -> deleted\n";
  }
}

// ---------------------------------------------------------------------------
print "\n=== RESULT ===\n";
printf("  items %s: %d\n", $apply ? 'actioned' : 'outstanding', $todo);
if ($refused) {
  printf("  *** %d guard refusal(s) — see above ***\n", $refused);
  exit(1);
}
if (!$apply && $todo) {
  print "  re-run with -- --apply to act\n";
}
if ($apply) {
  print "  follow with: drush cr, then verify VBO actions still resolve and maps still render\n";
}
// NOTE: deliberately no exit(0) — `drush scr` treats an explicit exit(0) as
// abnormal termination and returns a non-zero status, which would make a clean
// run look like a failure in any pipeline that checks it. Only the guard
// refusal above exits non-zero, which is the signal worth having.
