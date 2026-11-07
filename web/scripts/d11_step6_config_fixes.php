<?php

/**
 * @file
 * Drupal 11 upgrade — step 6: pre-existing CONFIG faults that abort core's
 * post-updates. Idempotent, DRY-RUN BY DEFAULT, generic (scans everything).
 *
 * Why: core 11.4's `search_post_update_search_help_dependencies()` calls
 * calculateDependencies() on every config entity that depends on `help` —
 * 347 on BOS — and SAVES the ones whose dependencies changed. Any config
 * entity that throws during that recalculation aborts the whole update. Two
 * did, and a third shape only logged warnings. None of them were caused by
 * the upgrade; all three were latent in the DB and would equally crash the
 * Views UI or Layout Builder UI on save. They are DB config, so dev and live
 * carry them too — run this there BEFORE `drush updb` on core 11.
 *
 *   1. A Views FIELD handler whose formatter `type` is not a formatter plugin
 *      id. Seen: `integer` on an entity's `id` base field in
 *      equipment_fuel_transactions_eva (the integer formatter is
 *      `number_integer`). Generic: every field handler, every view, mapped
 *      by the field's data type.
 *   2. A `taxonomy_index_tid` filter whose `vid` names a vocabulary that no
 *      longer exists — TaxonomyIndexTid::calculateDependencies() dies on a
 *      NULL vocabulary. Seen: teammate_estimates (field_stage). Generic:
 *      repointed to the field's single target bundle when there is exactly
 *      one; refused otherwise.
 *   3. A Layout Builder section component whose block plugin no longer
 *      exists. Seen: contract_sections.aerating_of_lawn.admin pointing at an
 *      EVA display that was removed; it logged "block plugin was not found"
 *      on every render and every updb run. Generic: removed.
 *
 * The companion check is `search_post_update`'s own loop, replicated at the
 * end WITHOUT saving: anything that still throws is printed.
 *
 *   ddev drush scr web/scripts/d11_step6_config_fixes.php              # report
 *   ddev drush scr web/scripts/d11_step6_config_fixes.php -- --apply   # act
 *
 * Exit 1 if anything is refused or still throws. No exit(0) on success.
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;

$argv_all = $_SERVER['argv'] ?? [];
$apply = in_array('--apply', $extra ?? [], TRUE) || in_array('--apply', $argv_all, TRUE);
$cf = \Drupal::configFactory();
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$fmt = \Drupal::service('plugin.manager.field.formatter');
$ftm = \Drupal::service('plugin.manager.field.field_type');
$blocks = \Drupal::service('plugin.manager.block');
$vd = \Drupal::service('views.views_data');
$todo = 0;
$refused = 0;
printf("=== D11 step 6 config fixes — %s ===\n\n", $apply ? 'APPLY' : 'DRY RUN (pass --apply to act)');

// Resolve a views field handler's underlying field data type, for base and
// configurable fields alike.
$field_type = function (string $table, string $field) use ($etm, $efm): ?string {
  foreach ($etm->getDefinitions() as $etid => $def) {
    if (!$def->entityClassImplements(\Drupal\Core\Entity\FieldableEntityInterface::class)) {
      continue;
    }
    $prefix_base = $def->getDataTable() ?: $def->getBaseTable();
    if ($table === $prefix_base || $table === $def->getBaseTable() || str_starts_with($table, $etid . '__')) {
      $defs = $efm->getFieldStorageDefinitions($etid);
      if (isset($defs[$field])) {
        return $defs[$field]->getType();
      }
    }
  }
  return NULL;
};

// ---------------------------------------------------------------------------
print "1. Views field handlers whose formatter type is not a plugin id\n";
$n1 = 0;
foreach ($cf->listAll('views.view.') as $name) {
  $vid = str_replace('views.view.', '', $name);
  $raw = $cf->get($name)->getRawData();
  $changes = [];
  foreach (($raw['display'] ?? []) as $did => $d) {
    foreach (($d['display_options']['fields'] ?? []) as $fid => $f) {
      // Only the `field` handler (EntityField) uses `type` as a FORMATTER id.
      // Other handlers reuse the key for their own options — core's
      // user_admin_people roles column is `user_roles` with `type: ul`.
      $type = $f['type'] ?? '';
      if (($f['plugin_id'] ?? '') !== 'field' || $type === '' || $fmt->hasDefinition($type)) {
        continue;
      }
      $dt = $field_type($f['table'] ?? '', $f['field'] ?? '');
      $new = $dt ? ($ftm->getDefinition($dt)['default_formatter'] ?? NULL) : NULL;
      printf("   %s:%s field %s — formatter \"%s\" is not a plugin; field type %s -> %s\n", $vid, $did, $fid, $type, $dt ?? '?', $new ?? '(no default formatter known — REFUSED)');
      if (!$new) {
        $refused++;
        continue;
      }
      $changes[] = [$did, $fid, $new];
    }
  }
  if ($changes) {
    $n1 += count($changes);
    $todo += count($changes);
    if ($apply) {
      $view = View::load($vid);
      $displays = $view->get('display');
      foreach ($changes as [$did, $fid, $new]) {
        $displays[$did]['display_options']['fields'][$fid]['type'] = $new;
      }
      $view->set('display', $displays)->save();
      print "     -> saved $vid\n";
    }
  }
}
if (!$n1) {
  print "   clean\n";
}

// ---------------------------------------------------------------------------
print "\n2. taxonomy_index_tid filters whose vocabulary no longer exists\n";
$n2 = 0;
foreach ($cf->listAll('views.view.') as $name) {
  $vid = str_replace('views.view.', '', $name);
  $raw = $cf->get($name)->getRawData();
  $changes = [];
  foreach (($raw['display'] ?? []) as $did => $d) {
    foreach (($d['display_options']['filters'] ?? []) as $fid => $f) {
      // An EMPTY vid is the broken case too (teammate_estimates had vid: NULL
      // with a term value set): TaxonomyIndexTid::calculateDependencies()
      // loads the vocabulary unconditionally and dies on NULL.
      // Resolve the handler the way Views does — through views DATA for
      // [table][field], NOT the stored plugin_id, which Views ignores (see
      // audit_views_handlers.php). The teammate_estimates filter was missed
      // once by trusting the stored id.
      $resolved = $vd->get($f['table'] ?? '')[$f['field'] ?? '']['filter']['id'] ?? '';
      if ($resolved !== 'taxonomy_index_tid' || (!empty($f['vid']) && Vocabulary::load($f['vid']))) {
        continue;
      }
      // Find the field's target bundles.
      $table = $f['table'] ?? '';
      $etid = substr($table, 0, strpos($table, '__') ?: 0);
      $fname = preg_replace('/_target_id$/', '', $f['field'] ?? '');
      $targets = [];
      if ($etid && $fname) {
        foreach ($etm->getStorage('field_config')->loadByProperties(['entity_type' => $etid, 'field_name' => $fname]) as $fc) {
          $targets = array_unique(array_merge($targets, array_keys($fc->getSetting('handler_settings')['target_bundles'] ?? [])));
        }
      }
      $targets = array_values(array_filter($targets, fn($t) => (bool) Vocabulary::load($t)));
      printf("   %s:%s filter %s — vid %s; %s.%s targets %s\n", $vid, $did, $fid, empty($f['vid']) ? 'is EMPTY' : "\"{$f['vid']}\" does not exist", $etid, $fname, $targets ? implode(',', $targets) : '(none)');
      if (count($targets) !== 1) {
        print "     *** REFUSED — need exactly one existing target vocabulary to repoint to\n";
        $refused++;
        continue;
      }
      $changes[] = [$did, $fid, $targets[0]];
    }
  }
  if ($changes) {
    $n2 += count($changes);
    $todo += count($changes);
    if ($apply) {
      $view = View::load($vid);
      $displays = $view->get('display');
      foreach ($changes as [$did, $fid, $new]) {
        $displays[$did]['display_options']['filters'][$fid]['vid'] = $new;
      }
      $view->set('display', $displays)->save();
      print "     -> saved $vid (vid -> {$changes[0][2]})\n";
    }
  }
}
if (!$n2) {
  print "   clean\n";
}

// ---------------------------------------------------------------------------
print "\n3. Layout Builder components whose block plugin no longer exists\n";
$n3 = 0;
foreach ($cf->listAll('core.entity_view_display.') as $name) {
  $sections = $cf->get($name)->get('third_party_settings.layout_builder.sections');
  if (!$sections) {
    continue;
  }
  $id = str_replace('core.entity_view_display.', '', $name);
  $dead = [];
  foreach ($sections as $si => $s) {
    foreach (($s['components'] ?? []) as $uuid => $c) {
      $pid = $c['configuration']['id'] ?? '';
      if ($pid !== '' && !$blocks->hasDefinition($pid)) {
        $dead[] = [$si, $uuid, $pid];
      }
    }
  }
  foreach ($dead as [$si, $uuid, $pid]) {
    printf("   %s section %d: %s\n", $id, $si, $pid);
  }
  if ($dead) {
    $n3 += count($dead);
    $todo += count($dead);
    if ($apply) {
      $display = EntityViewDisplay::load($id);
      foreach ($dead as [$si, $uuid, $pid]) {
        $display->getSection($si)->removeComponent($uuid);
      }
      $display->save();
      print "     -> saved $id (" . count($dead) . " removed)\n";
    }
  }
}
if (!$n3) {
  print "   clean\n";
}

// ---------------------------------------------------------------------------
print "\n3b. Views that cannot load at all: base_table has no views data\n";
//    estimate_current_customer_info was built on `estmate_field_data` (typo)
//    AND relates through `estimate__field_property`, a field `estimate` does
//    not have (it lives on estimate_request). Views UI cannot open such a
//    view; nothing references it; but core's post-update still tries to SAVE
//    it and dies in HandlerBase::getEntityType() -> ViewsData::get(NULL).
//    Renaming the table was tried first and refused by its own guard, since
//    the relationship is wrong too. Guarded delete: base_table without views
//    data AND zero config objects referencing the view. The YAML is printed
//    before deletion so the record survives.
$n3b = 0;
$all_json = [];
foreach ($cf->listAll() as $cname) {
  $all_json[$cname] = json_encode($cf->get($cname)->getRawData());
}
foreach ($cf->listAll('views.view.') as $name) {
  $vid = str_replace('views.view.', '', $name);
  $raw = $cf->get($name)->getRawData();
  $bt = $raw['base_table'] ?? '';
  if ($bt === '' || $vd->get($bt)) {
    continue;
  }
  $refs = [];
  foreach ($all_json as $cname => $json) {
    if ($cname !== $name && str_contains($json, $vid)) {
      $refs[] = $cname;
    }
  }
  printf("   %s (\"%s\"): base_table %s has no views data; referenced by %d config object(s)%s\n", $vid, $raw['label'] ?? '', $bt, count($refs), $refs ? ' — ' . implode(', ', array_slice($refs, 0, 4)) : '');
  if ($refs) {
    print "     *** REFUSED — something references it; repair by hand\n";
    $refused++;
    continue;
  }
  $n3b++;
  $todo++;
  if ($apply) {
    print "     --- YAML before deletion ---\n" . preg_replace('/^/m', '     ', \Drupal\Component\Serialization\Yaml::encode($raw)) . "\n";
    View::load($vid)->delete();
    print "     -> deleted $vid\n";
  }
}
if (!$n3b) {
  print "   clean\n";
}

// ---------------------------------------------------------------------------
print "\n4. Replicating core's post-update loop (dry-run: preSave in memory; apply: save as core would)\n";
$cm = \Drupal::service('config.manager');
$throws = 0;
foreach ($cm->findConfigEntityDependenciesAsEntities('module', ['help']) as $e) {
  try {
    // Core's loop is: calculateDependencies(), compare, SAVE if changed — and
    // the save path (preSave -> handlers' getEntityType()) is where the
    // misspelled-table view died while a bare calculateDependencies() passed.
    // Dry-run exercises preSave() in memory without persisting; apply saves
    // exactly as core would, so core's post-update becomes a no-op.
    $before = $e->getDependencies();
    $changed = $e->calculateDependencies()->getDependencies() !== $before;
    if ($apply && $changed) {
      $e->save();
    }
    else {
      $e->preSave($etm->getStorage($e->getEntityTypeId()));
    }
  }
  catch (\Throwable $x) {
    $throws++;
    printf("   *** %s %s — %s\n", $e->getEntityTypeId(), $e->id(), substr(preg_replace('/\s+/', ' ', $x->getMessage()), 0, 120));
  }
}
printf("   %s\n", $throws ? "$throws still throw" : 'none throw');

print "\n=== RESULT ===\n";
printf("  items %s: %d\n", $apply ? 'actioned' : 'outstanding', $todo);
if ($refused || $throws) {
  printf("  *** %d refusal(s), %d still throwing ***\n", $refused, $throws);
  exit(1);
}
if (!$apply && $todo) {
  print "  re-run with -- --apply to act\n";
}
