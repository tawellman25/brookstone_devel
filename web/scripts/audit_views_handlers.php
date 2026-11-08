<?php

/**
 * @file
 * Read-only audit: which Views handlers would render as BROKEN?
 *
 * Written during the Drupal 11 upgrade (step 5) as the before/after check for
 * contrib updates that change what a module exposes to Views. Run it before a
 * batch and after, and diff the two outputs: a NEW line is a regression.
 *
 *   ddev drush scr web/scripts/audit_views_handlers.php > before.txt
 *   ... update ...
 *   ddev drush scr web/scripts/audit_views_handlers.php > after.txt
 *   diff before.txt after.txt
 *
 * HOW VIEWS ACTUALLY RESOLVES A HANDLER — and why the first version of this
 * audit was wrong. It checked the `plugin_id` stored in the view config against
 * the plugin managers and reported ~16 "unresolvable" field handlers whose
 * pages rendered perfectly. Views does not use that stored id for
 * field/filter/sort/argument/relationship handlers at all:
 * ViewsHandlerManager::getHandler() looks up views DATA for
 * [table][field][type] and instantiates the id found THERE, falling back to the
 * "broken" handler only when that views-data entry is missing. The stored
 * plugin_id is an informational artefact (102 of them disagree with views data
 * on BOS; all harmless). So the real question for a handler is "does views data
 * still define this table/field/type", while style, row, pager, exposed_form,
 * access, cache, query and display plugins ARE resolved by their stored type.
 *
 * PRE-EXISTING RESULT, 2026-11-07: 29 broken handlers, byte-identical on the
 * untouched main dev environment and on the D11 sandbox after every step-5
 * update — so none of them came from the upgrade. They are logged in
 * Governance/deferred_work.md. The two recurring shapes: a `status` filter on
 * an ECK entity type that has no `status` base field (no-op filter), and a
 * SORT on a date field configured as `field: field_x` instead of
 * `field: field_x_value` — Views has no sort for the bare field name, so the
 * ORDER BY silently never happens (proven: the view's built SQL has no ORDER BY
 * on that column). One view, estimate_current_customer_info, points at a
 * misspelled table `estmate_field_data`.
 *
 * Read-only. Exit 0 always — it reports; the diff is the verdict.
 */

$cf = \Drupal::configFactory();
$vd = \Drupal::service('views.views_data');
$P = [];
foreach (['style', 'row', 'pager', 'exposed_form', 'access', 'cache', 'query', 'display'] as $k) {
  $P[$k] = \Drupal::service("plugin.manager.views.$k");
}

$n = 0;
$broken = [];
$stale = 0;
foreach ($cf->listAll('views.view.') as $vn) {
  $raw = $cf->get($vn)->getRawData();
  $v = str_replace('views.view.', '', $vn);
  foreach (($raw['display'] ?? []) as $did => $d) {
    $o = $d['display_options'] ?? [];
    if (($p = $d['display_plugin'] ?? '') !== '') {
      $n++;
      if (!$P['display']->hasDefinition($p)) {
        $broken[] = "$v:$did display=$p";
      }
    }
    foreach (['style', 'row', 'pager', 'exposed_form', 'access', 'cache', 'query'] as $k) {
      if (($p = $o[$k]['type'] ?? '') !== '') {
        $n++;
        if (!$P[$k]->hasDefinition($p)) {
          $broken[] = "$v:$did $k=$p";
        }
      }
    }
    foreach (['fields' => 'field', 'filters' => 'filter', 'sorts' => 'sort', 'arguments' => 'argument', 'relationships' => 'relationship'] as $sec => $type) {
      foreach (($o[$sec] ?? []) as $hid => $h) {
        $table = $h['table'] ?? '';
        $field = $h['field'] ?? '';
        if ($table === '' || $field === '') {
          continue;
        }
        $n++;
        $def = $vd->get($table)[$field][$type] ?? NULL;
        if (!$def) {
          $broken[] = "$v:$did $type $hid ($table.$field)";
          continue;
        }
        if (($h['plugin_id'] ?? '') !== '' && ($h['plugin_id'] ?? '') !== ($def['id'] ?? '')) {
          $stale++;
        }
      }
    }
  }
}
sort($broken);
printf("checked %d refs — BROKEN (no views data / no plugin): %d — stale plugin_id (harmless, ignored by Views): %d\n", $n, count($broken), $stale);
foreach ($broken as $b) {
  print "  $b\n";
}
