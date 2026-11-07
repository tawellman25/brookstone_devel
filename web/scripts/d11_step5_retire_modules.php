<?php

/**
 * @file
 * Drupal 11 upgrade — step 5: retire `editablefields` and `migrate_file`.
 * Idempotent, DRY-RUN BY DEFAULT.
 *
 * These were the last two packages composer named as blocking drupal/core
 * 11.4.12 (`composer why-not drupal/core 11.4.12`, 2026-11-07). Todd's call,
 * 2026-11-07: neither is needed.
 *
 *   editablefields 1.0.2 (^8 || ^9 || ^10, no tagged D11 release) — used by
 *     exactly ONE editable column, field_cell_number on the QuickBooks export
 *     list view (quickbooks_client_merge, /admin/client/qb_export_list), and a
 *     dead reference on the sop_log.standard.default display (0 sop_log
 *     entities). The office does not edit cell numbers from that list.
 *   migrate_file 2.1.3 (^9 | ^10) — NOTHING uses it: 0 migrations use its
 *     process plugins, no custom code references it.
 *
 * THE TRAP THIS SCRIPT EXISTS FOR: Drupal deletes dependent config entities
 * when a module is uninstalled. Two config objects depend on editablefields —
 * the whole quickbooks_client_merge VIEW and the sop_log display — so a bare
 * `drush pmu editablefields` silently deletes the QuickBooks export list. The
 * order here is: rewrite the two formatters to core ones, re-count the
 * dependents, and uninstall ONLY when that count is zero. If any config this
 * script does not know how to rewrite depends on the module, it REFUSES
 * (BOS rule 4: no surprise cascades).
 *
 * This is DATABASE state and does not travel through git: run it on dev and
 * on live as well (live had BOTH modules enabled on 2026-11-07, checked
 * read-only). Then, per environment, remove the packages:
 *
 *   composer remove drupal/editablefields drupal/migrate_file
 *
 * Usage:
 *   ddev drush scr web/scripts/d11_step5_retire_modules.php              # report
 *   ddev drush scr web/scripts/d11_step5_retire_modules.php -- --apply   # act
 *
 * Exit 1 on any refusal. No exit(0) on success — `drush scr` treats an
 * explicit exit(0) as abnormal termination.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\views\Entity\View;

$argv_all = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $extra ?? [], TRUE) || in_array('--apply', $argv_all, TRUE);

$cf = \Drupal::configFactory();
$mh = \Drupal::moduleHandler();
$mi = \Drupal::service('module_installer');
$refused = 0;
$todo = 0;

// Direct dependents only — ConfigManager::findConfigEntityDependencies() is a
// transitive closure and overstates wildly (see runbook §3.3a).
$dependents = function (string $module) use ($cf): array {
  $hits = [];
  foreach ($cf->listAll() as $name) {
    $mods = $cf->get($name)->get('dependencies.module');
    if (is_array($mods) && in_array($module, $mods, TRUE)) {
      $hits[] = $name;
    }
  }
  return $hits;
};

printf("=== D11 step 5 — retire editablefields + migrate_file — %s ===\n\n", $apply ? 'APPLY' : 'DRY RUN (pass --apply to act)');

// ---------------------------------------------------------------------------
// 1. editablefields — rewrite the two formatters, then uninstall.
// ---------------------------------------------------------------------------
print "1. editablefields\n";
if (!$mh->moduleExists('editablefields')) {
  print "   not enabled — nothing to do\n";
}
else {
  $known = [
    'views.view.quickbooks_client_merge',
    'core.entity_view_display.sop_log.standard.default',
  ];
  $deps = $dependents('editablefields');
  printf("   config depending on it: %d%s\n", count($deps), $deps ? ' — ' . implode(', ', $deps) : '');
  $unknown = array_values(array_diff($deps, $known));
  if ($unknown) {
    print "   *** REFUSING: config this script cannot rewrite depends on editablefields:\n";
    foreach ($unknown as $u) {
      print "       - $u\n";
    }
    print "       Uninstalling now would DELETE it. Rewrite it by hand first.\n";
    $refused++;
  }
  else {
    // (a) The view: editablefields_formatter -> basic_string (the telephone
    //     field type's default formatter; plain text is also what the CSV
    //     export display of this same view wants).
    $view = View::load('quickbooks_client_merge');
    if ($view) {
      $displays = $view->get('display');
      $changed = FALSE;
      foreach ($displays as $did => &$disp) {
        if (empty($disp['display_options']['fields'])) {
          continue;
        }
        // NOT `foreach (($x ?? []) as &$f)`: the `??` expression yields a
        // temporary copy, so a reference into it never reaches $displays. The
        // first run of this script "saved" the view unchanged that way — the
        // dry-run read fine, the apply wrote nothing, and the dependency
        // survived (which the refusal below correctly caught).
        foreach ($disp['display_options']['fields'] as $fid => &$f) {
          if (($f['type'] ?? '') === 'editablefields_formatter') {
            printf("   view quickbooks_client_merge:%s field %s — editablefields_formatter -> basic_string\n", $did, $fid);
            $todo++;
            if ($apply) {
              $f['type'] = 'basic_string';
              $f['settings'] = [];
              $changed = TRUE;
            }
          }
        }
        unset($f);
      }
      unset($disp);
      if ($apply && $changed) {
        $view->set('display', $displays);
        // save() recalculates dependencies from the handlers' providers, which
        // is what drops editablefields out of dependencies.module.
        $view->save();
        print "     -> saved\n";
      }
    }
    // (b) The display: editablefields_formatter on `changed` -> core timestamp.
    $display = EntityViewDisplay::load('sop_log.standard.default');
    if ($display && ($c = $display->getComponent('changed')) && ($c['type'] ?? '') === 'editablefields_formatter') {
      print "   display sop_log.standard.default field changed — editablefields_formatter -> timestamp\n";
      $todo++;
      if ($apply) {
        $display->setComponent('changed', [
          'type' => 'timestamp',
          'label' => $c['label'] ?? 'above',
          'weight' => $c['weight'] ?? 0,
          'region' => $c['region'] ?? 'content',
          'settings' => ['date_format' => 'medium', 'custom_date_format' => '', 'timezone' => ''],
          'third_party_settings' => [],
        ])->save();
        print "     -> saved\n";
      }
    }
    if ($apply) {
      $after = $dependents('editablefields');
      printf("   dependents after rewrite: %d\n", count($after));
      if ($after) {
        print "   *** REFUSING to uninstall — still depended on by: " . implode(', ', $after) . "\n";
        $refused++;
      }
      else {
        $mi->uninstall(['editablefields']);
        print "   -> uninstalled editablefields\n";
      }
    }
    else {
      print "   then: uninstall editablefields (only if dependents = 0 after the rewrite)\n";
      $todo++;
    }
  }
}

// ---------------------------------------------------------------------------
// 2. migrate_file — nothing may use it; refuse otherwise.
// ---------------------------------------------------------------------------
print "\n2. migrate_file\n";
if (!$mh->moduleExists('migrate_file')) {
  print "   not enabled — nothing to do\n";
}
else {
  $deps = $dependents('migrate_file');
  $used = [];
  foreach ($cf->listAll('migrate_plus.migration.') as $n) {
    if (preg_match('/"file_import"|"file_blob"|"file_remote"|migrate_file/', json_encode($cf->get($n)->getRawData()))) {
      $used[] = $n;
    }
  }
  printf("   config depending on it: %d; migrations using its process plugins: %d\n", count($deps), count($used));
  if ($deps || $used) {
    print "   *** REFUSING — it is in use: " . implode(', ', array_merge($deps, $used)) . "\n";
    $refused++;
  }
  else {
    $todo++;
    if ($apply) {
      $mi->uninstall(['migrate_file']);
      print "   -> uninstalled migrate_file\n";
    }
    else {
      print "   then: uninstall migrate_file\n";
    }
  }
}

// ---------------------------------------------------------------------------
print "\n=== RESULT ===\n";
printf("  items %s: %d\n", $apply ? 'actioned' : 'outstanding', $todo);
if ($refused) {
  printf("  *** %d refusal(s) — nothing was uninstalled where a refusal applied ***\n", $refused);
  exit(1);
}
if ($apply) {
  print "  now, in this environment: composer remove drupal/editablefields drupal/migrate_file\n";
  print "  then: drush cr, and re-check that /admin/client/qb_export_list still shows the cell-number column\n";
}
elseif ($todo) {
  print "  re-run with -- --apply to act\n";
}
