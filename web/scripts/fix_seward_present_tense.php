<?php

declare(strict_types=1);

/**
 * Replace PRESENT-TENSE references to the former "S&E Ward's Landscape
 * Management" name with "Brookstone Outdoors" (legal contexts: "Brookstone
 * Outdoors LLC"), on a CONFIRMED, targeted list only — plus the Calm wind-speed
 * safety-copy fix (Step 3). Name only; grammar preserved; no copy rewritten.
 *
 * Historical references ("Continuing…", "We were…", "founded as…", the footer,
 * /about-us, Our History, licence info) are NOT in the target list and are not
 * touched. Idempotent (once swapped, nothing left to match). Dry-run default;
 * apply with BOS_FIX_APPLY=1 (writes a backup JSON first). Reports actual count.
 *
 *   drush php:script web/scripts/fix_seward_present_tense.php            (dry run)
 *   BOS_FIX_APPLY=1 drush php:script web/scripts/fix_seward_present_tense.php
 */

use Drupal\Core\Cache\Cache;

$APPLY = (bool) getenv('BOS_FIX_APPLY');

/** Ordered name-normalizer. Longest/most-specific first. */
$normalize = function (string $text, bool $legal = FALSE): string {
  $to = $legal ? 'Brookstone Outdoors LLC' : 'Brookstone Outdoors';
  $ap = "(?:'|’|&#039;|&rsquo;|&#8217;)";
  $patterns = [
    // S&E / S & E / S&amp;E / S and E  Ward('s)  Landscap(e/ing)  [Management/Mgmt.]  [, Inc.]
    "/S\\s*(?:&amp;|&|and)\\s*E\\s+Ward{$ap}?s?\\s+Landscap(?:e|ing)(?:\\s+(?:Management|Mgmt\\.?))?(?:\\s*,?\\s*Inc\\.?)?/iu",
    // SE Wards Landscape [Management] [Inc.]  (no ampersand; require Landscape to avoid false hits)
    "/\\bSE\\s+Ward{$ap}?s?\\s+Landscape(?:\\s+(?:Management|Mgmt\\.?))?(?:\\s*,?\\s*Inc\\.?)?/iu",
    // bare S&E Ward('s) / S & E Ward's / S and E Wards  (no Landscape) — bios etc.
    "/S\\s*(?:&amp;|&|and)\\s*E\\s+Ward{$ap}?s?/iu",
    // Ward's Landscape alone (\\b guards against Howards/Edwards)
    "/\\bWard{$ap}?s?\\s+Landscape(?:\\s+Management)?/iu",
  ];
  return preg_replace($patterns, $to, $text);
};

// [entity_type, id, field, legal?]
$TARGETS = [];
foreach ([1159, 1160, 1161, 1162, 1164, 1165, 1166, 1167, 1202, 1203, 1204] as $tid) {
  $TARGETS[] = ['taxonomy_term', $tid, 'description', FALSE];
}
foreach ([42, 43, 50] as $tid) {
  $TARGETS[] = ['taxonomy_term', $tid, 'field_description', FALSE];
}
foreach ([366, 376, 380, 384] as $tid) {
  $TARGETS[] = ['taxonomy_term', $tid, 'field_service_public_desc', FALSE];
}
foreach ([366, 374, 376, 380, 384] as $tid) {
  $TARGETS[] = ['taxonomy_term', $tid, 'field_service_crew_desc', FALSE];
}
foreach ([58, 59, 67] as $nid) {
  $TARGETS[] = ['node', $nid, 'body', FALSE];
}
$TARGETS[] = ['county', 3, 'field_county_description', FALSE];
$TARGETS[] = ['site_content', 59916, 'field_content_text', FALSE];
foreach ([2294, 2298, 2309, 2318] as $pid) {
  $TARGETS[] = ['profile', $pid, 'field_teammate_bio', FALSE];
}
$TARGETS[] = ['profile', 7691, 'field_company_name', FALSE];
$TARGETS[] = ['config_pages', 1, 'field_estimate_disclosure', TRUE];
$TARGETS[] = ['client_app', 9, 'field_directions', FALSE];

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$backup = [];
$changed = 0;
$scanned = 0;

print ($APPLY ? "APPLY" : "DRY RUN") . " — name swap on " . count($TARGETS) . " targeted fields + material subheaders + config + Calm\n\n";

// --- targeted entity fields ---
foreach ($TARGETS as [$type, $id, $field, $legal]) {
  $e = $etm->getStorage($type)->load($id);
  if (!$e || !$e->hasField($field) || $e->get($field)->isEmpty()) {
    continue;
  }
  $scanned++;
  $item = $e->get($field)->first();
  $old = (string) $item->value;
  $new = $normalize($old, $legal);
  if ($new === $old) {
    continue;
  }
  $changed++;
  $backup[] = ['type' => $type, 'id' => $id, 'field' => $field, 'old' => $old];
  printf("  %s/%s.%s%s\n     - %s\n     + %s\n", $type, $id, $field, $legal ? ' [LLC]' : '',
    mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($old))), 0, 90),
    mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($new))), 0, 90));
  if ($APPLY) {
    $type2 = $e->get($field)->getFieldDefinition()->getType();
    if (in_array($type2, ['text', 'text_long', 'text_with_summary'], TRUE)) {
      $e->set($field, ['value' => $new, 'format' => $item->format]);
    }
    else {
      $e->set($field, $new);
    }
    $e->save();
  }
}

// --- material subheaders (2357-ish) via direct update; material is non-revisionable ---
$rows = $db->query("SELECT entity_id, delta, field_subheader_text_value v FROM {material__field_subheader_text}
  WHERE field_subheader_text_value LIKE :a OR field_subheader_text_value LIKE :b OR field_subheader_text_value LIKE :c",
  [':a' => "%S&E Ward%", ':b' => "%Ward's Landscape%", ':c' => "%SE Ward%"])->fetchAll();
$matIds = [];
$matChanged = 0;
foreach ($rows as $r) {
  $new = $normalize((string) $r->v);
  if ($new === $r->v) {
    continue;
  }
  $matChanged++;
  $matIds[] = $r->entity_id;
  $backup[] = ['type' => 'material_subheader', 'id' => $r->entity_id, 'field' => 'field_subheader_text', 'old' => $r->v];
  if ($APPLY) {
    $db->update('material__field_subheader_text')
      ->fields(['field_subheader_text_value' => $new])
      ->condition('entity_id', $r->entity_id)->condition('delta', $r->delta)
      ->execute();
  }
}
printf("\n  material subheaders: %d to change\n", $matChanged);
$changed += $matChanged;

// --- config: equipment_status vocab description (internal admin label) ---
$vc = \Drupal::configFactory()->getEditable('taxonomy.vocabulary.equipment_status');
$od = (string) $vc->get('description');
$nd = $normalize($od);
if ($nd !== $od) {
  $changed++;
  $backup[] = ['type' => 'config', 'id' => 'taxonomy.vocabulary.equipment_status', 'field' => 'description', 'old' => $od];
  printf("  config equipment_status.description:\n     - %s\n     + %s\n", $od, $nd);
  if ($APPLY) {
    $vc->set('description', $nd)->save();
  }
}

// --- Step 3: Calm wind-speed description (verbatim; term name unchanged) ---
$calm = $etm->getStorage('taxonomy_term')->load(1180);
$calmNew = <<<'HTML'
<p>Little or no wind at the time of application — and not automatically the ideal condition it sounds like.</p>

<p>Still air often means a temperature inversion: a layer of cool air trapped beneath warmer air, with no vertical mixing. In an inversion, spray droplets do not disperse and settle. They hang as a concentrated cloud and drift sideways, slowly, a long way. A still morning can carry an application further off target than a light breeze will.</p>

<p>Inversions typically set in around dusk and break up after sunrise. It is one of the reasons a crew may arrive and wait before starting.</p>

<p>More on this: <a href="/services/landscape-lawn-care/spraying/wind-speed">wind speed and spray drift</a>.</p>
HTML;
if ($calm) {
  $item = $calm->get('description')->first();
  $curFmt = $item ? $item->format : 'basic_html';
  $curVal = $item ? (string) $item->value : '';
  if (trim($curVal) !== trim($calmNew)) {
    $changed++;
    $backup[] = ['type' => 'taxonomy_term', 'id' => 1180, 'field' => 'description(Calm/Step3)', 'old' => $curVal];
    print "\n  Calm (tid 1180) description -> new safety copy (Step 3)\n";
    if ($APPLY) {
      // Use full_html so the <p>/<a> render regardless of the term's prior format.
      $calm->set('description', ['value' => $calmNew, 'format' => 'full_html']);
      $calm->save();
    }
  }
}

// --- write backup + finish ---
if ($APPLY && $backup) {
  $file = 'public://seward_fix_backup_' . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $file, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
  print "\n  backup: " . \Drupal::service('file_system')->realpath($file) . "\n";
  if ($matIds) {
    Cache::invalidateTags(array_map(fn($id) => "material:$id", array_unique($matIds)));
  }
}

printf("\n%s. %d field(s)/rows %s.\n", $APPLY ? 'DONE' : 'DRY RUN', $changed, $APPLY ? 'changed' : 'WOULD change');
if (!$APPLY) {
  print "Re-run with BOS_FIX_APPLY=1 to apply.\n";
}
