<?php

/**
 * @file
 * Show the hookup TYPE and LOCATION text beside the hookup photos.
 *
 * The two hookup photos were added to this EVA on 2026-10-24, but the typed
 * detail that goes with them — "blow out port", "Next to shut off in same
 * Valvebox" — still rendered nowhere, so a crew saw pictures of a fitting with
 * nothing saying what kind it is or where to look.
 *
 * This view composes its visible text through ONE "Global: Custom text" field
 * (`nothing`) that interpolates other fields as tokens; the data fields
 * themselves are excluded. So the fields are added EXCLUDED and the line is
 * added to that text, which is how the shut-off block already works — rather
 * than bolting two labelled fields on and having the row read in two styles.
 *
 * ⚠ ORDER IS LOAD-BEARING. A Views field token only resolves for fields listed
 * BEFORE the field that uses it, so both are inserted ahead of `nothing`.
 * Appending them after it would leave the tokens rendering empty, silently.
 *
 * ⚠ Each display has its OWN wording (the dirty-water one says "Source is …",
 * the domestic one does not), so the line is inserted relative to the Notes
 * block in whatever text that display actually has, never by rewriting it from
 * a template.
 *
 * The line is wrapped in a Twig conditional: an empty hookup would otherwise
 * print a bare "Hookup:" label with nothing after it. The surrounding lines are
 * bare tokens and need no such guard; this one carries a literal label.
 *
 * Clone sources are chosen BY FIELD TYPE (list_string from list_string, string
 * from string) and the script aborts if a source is missing or mismatched.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/add_hookup_text_to_water_sources_view.php
 */

const VIEW_ID = 'sprinkler_system_water_sources';
const ANCHOR = 'nothing';
// new field => [clone this existing field's config, expected field type]
// new field => the field TYPE it must be cloned from. The clone source is
// resolved PER DISPLAY, because the three displays do not share a field list:
// the dirty-water one uses field_location / field_dirty_water_source_type where
// the domestic one uses field_shut_off_location_descript. A fixed clone source
// is absent on one of them, and the first run of this script proved it by
// skipping the fields there while still adding the text line — which would have
// rendered tokens that resolve to nothing.
const NEW_FIELDS = [
  'field_ss_hookup_type' => 'list_string',
  'field_ss_hookup_location' => 'string',
];
const NOTES_MARKER = '<b>Notes:</b>';
/**
 * Strip a cloned field back to a plain value render.
 *
 * ⚠ Cloning carries the SOURCE field's rewriting with it. On the default
 * display the only other `string` field is field_ss_source_name, which has
 * alter_text on with `<h4>{{ field_ss_source_name }} Well Water Source</h4>` —
 * so the clone printed that heading instead of the hookup location, and the
 * token in the custom text silently rendered a stray header. Cloning is still
 * right for structure (table, plugin, formatter type); the presentation layer
 * has to be reset or the new field inherits someone else's label.
 */
function _bos_hookup_plain(array $field): array {
  $field['label'] = '';
  $field['exclude'] = TRUE;
  $field['alter']['alter_text'] = FALSE;
  $field['alter']['text'] = '';
  $field['alter']['make_link'] = FALSE;
  $field['alter']['path'] = '';
  $field['alter']['trim'] = FALSE;
  $field['alter']['max_length'] = 0;
  $field['alter']['strip_tags'] = FALSE;
  return $field;
}

const HOOKUP_LINE = '{% if field_ss_hookup_type or field_ss_hookup_location %}<b>Hookup:</b> {{ field_ss_hookup_type }} {{ field_ss_hookup_location }}</br>' . "\n" . '{% endif %}';

$apply = getenv('BOS_APPLY') === '1';
$viewsData = \Drupal::service('views.views_data');
$fieldManager = \Drupal::service('entity_field.manager');
$defs = $fieldManager->getFieldDefinitions('property_ss_sources', 'domestic_source');

// --- guards -----------------------------------------------------------------
// Field types across ALL THREE source bundles, so a clone source that only
// exists on dirty_water or well_water is still resolvable.
$typeOf = [];
foreach (['domestic_source', 'dirty_water_source', 'well_water_source'] as $b) {
  foreach ($fieldManager->getFieldDefinitions('property_ss_sources', $b) as $n => $d) {
    $typeOf[$n] = $typeOf[$n] ?? $d->getType();
  }
}
foreach (NEW_FIELDS as $field => $expectedType) {
  if (!isset($typeOf[$field])) {
    printf("ABORT: %s does not exist on property_ss_sources.\n", $field);
    return;
  }
  if ($typeOf[$field] !== $expectedType) {
    printf("ABORT: %s is %s, expected %s.\n", $field, $typeOf[$field], $expectedType);
    return;
  }
  $table = 'property_ss_sources__' . $field;
  $d = $viewsData->get($table);
  if (!$d || !isset($d[$field])) {
    printf("ABORT: no views data for %s.%s — Views would ignore the field.\n", $table, $field);
    return;
  }
  printf("ok: %-30s %s\n", $field, $typeOf[$field]);
}

/**
 * First field already on THIS display whose type matches — the clone source.
 */
$cloneSource = function (array $fields, string $wantType) use ($typeOf): ?string {
  foreach (array_keys($fields) as $key) {
    if (($typeOf[$key] ?? NULL) === $wantType) {
      return $key;
    }
  }
  return NULL;
};

$config = \Drupal::configFactory()->getEditable('views.view.' . VIEW_ID);
if ($config->isNew()) {
  printf("ABORT: view %s not found here.\n", VIEW_ID);
  return;
}

$changed = 0;
foreach ((array) $config->get('display') as $did => $display) {
  $fields = $display['display_options']['fields'] ?? NULL;
  if ($fields === NULL) {
    printf("  %-14s inherits default — skipped\n", $did);
    continue;
  }
  if (!isset($fields[ANCHOR])) {
    printf("  %-14s no '%s' custom-text field — skipped\n", $did, ANCHOR);
    continue;
  }

  // --- 1. insert the two excluded fields BEFORE the custom text -------------
  $rebuilt = [];
  $added = [];
  $repaired = [];
  foreach ($fields as $key => $def) {
    if ($key === ANCHOR) {
      foreach (NEW_FIELDS as $field => $wantType) {
        if (isset($fields[$field])) {
          continue;
        }
        $cloneFrom = $cloneSource($fields, $wantType);
        if ($cloneFrom === NULL) {
          printf("  %-14s no %s field on this display to clone — %s skipped\n", $did, $wantType, $field);
          continue;
        }
        $clone = $fields[$cloneFrom];
        $clone['id'] = $field;
        $clone['table'] = 'property_ss_sources__' . $field;
        $clone['field'] = $field;
        $clone['label'] = '';
        // Excluded: it exists only so the custom text can interpolate it.
        $clone['exclude'] = TRUE;
        if (array_key_exists('entity_field', $clone)) {
          $clone['entity_field'] = $field;
        }
        $rebuilt[$field] = _bos_hookup_plain($clone);
        $added[] = $field;
      }
    }
    // Repair a hookup field added by an earlier run that inherited the clone
    // source's text rewrite (see _bos_hookup_plain).
    if (isset(NEW_FIELDS[$key]) && !empty($def['alter']['alter_text'])) {
      $def = _bos_hookup_plain($def);
      $repaired[] = $key;
    }
    $rebuilt[$key] = $def;
  }

  // --- 2. add the line to this display's own text --------------------------
  // ⚠ Only touch the text if BOTH tokens will actually resolve. Adding the
  // line while a field is missing renders an empty "Hookup:" forever.
  $bothPresent = TRUE;
  foreach (array_keys(NEW_FIELDS) as $field) {
    $bothPresent = $bothPresent && isset($rebuilt[$field]);
  }
  $text = (string) ($rebuilt[ANCHOR]['alter']['text'] ?? '');
  $textChanged = FALSE;
  if ($bothPresent && $text !== '' && !str_contains($text, 'field_ss_hookup_type')) {
    if (str_contains($text, NOTES_MARKER)) {
      // Before the Notes block: hookup is a physical detail, notes come last.
      $text = str_replace(NOTES_MARKER, HOOKUP_LINE . NOTES_MARKER, $text);
    }
    else {
      $text = rtrim($text) . "\n" . HOOKUP_LINE;
    }
    $rebuilt[ANCHOR]['alter']['text'] = $text;
    $textChanged = TRUE;
  }

  if (!$added && !$textChanged && !$repaired) {
    printf("  %-14s already done — no change\n", $did);
    continue;
  }
  $config->set("display.$did.display_options.fields", $rebuilt);
  $changed++;
  printf("  %-14s + %s%s%s\n", $did, implode(', ', $added) ?: '(fields already present)',
    $textChanged ? '  + hookup line' : '',
    $repaired ? '  REPAIRED rewrite on: ' . implode(', ', $repaired) : '');
}

if (!$changed) {
  print "\nNothing to change.\n";
  return;
}
if (!$apply) {
  printf("\nDRY RUN — %d display(s) would change. Re-run with BOS_APPLY=1.\n", $changed);
  return;
}
$config->save();
printf("\nSaved. %d display(s) updated.\n", $changed);

$after = \Drupal::config('views.view.' . VIEW_ID);
foreach (array_keys((array) $config->get('display')) as $did) {
  $f = $after->get("display.$did.display_options.fields");
  if ($f === NULL) {
    continue;
  }
  $order = array_keys($f);
  $typeAt = array_search('field_ss_hookup_type', $order, TRUE);
  $nothingAt = array_search(ANCHOR, $order, TRUE);
  printf("  %-14s hookup fields before the text: %s\n", $did,
    ($typeAt !== FALSE && $nothingAt !== FALSE && $typeAt < $nothingAt) ? 'YES' : 'NO/absent');
}
