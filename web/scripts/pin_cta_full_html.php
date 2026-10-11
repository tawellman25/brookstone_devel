<?php
/**
 * Pin field_call_to_action to Full HTML everywhere it exists.
 *
 * It was the odd one out: the three description fields were pinned to full_html
 * across every public vocabulary on 2026-10-03, and the CTA was left
 * unrestricted. That was flagged when `season` was built on 2026-10-11 —
 * matching the siblings exactly was the instruction, so the asymmetry was
 * reported rather than silently fixed. Todd's call: it should be Full HTML.
 *
 * Safe to the point of being dull: all 37 stored values across the four
 * vocabularies are ALREADY full_html, so this makes the setting agree with the
 * content rather than changing any content. A value in another format would be
 * converted rather than left to render as escaped markup — converting only ever
 * loosens filtering, so no markup is lost — and the script says so if it finds one.
 *
 * Dry run by default. BOS_CTA_APPLY=1 to write.
 */
$apply = getenv('BOS_CTA_APPLY') === '1';
$efm = \Drupal::service('entity_field.manager');
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_CTA_APPLY=1 to apply)');

$bundles = $efm->getFieldMap()['taxonomy_term']['field_call_to_action']['bundles'] ?? [];
sort($bundles);
$converted = 0;
foreach ($bundles as $vid) {
  $field = \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $vid, 'field_call_to_action');
  if (!$field) { continue; }
  $current = (array) ($field->getSetting('allowed_formats') ?? []);
  $needs = $current !== ['full_html'];
  printf("  %-26s %s\n", $vid, $needs ? 'pin to full_html' : 'already pinned');
  if ($apply && $needs) {
    $field->setSetting('allowed_formats', ['full_html'])->save();
  }

  // Any stored value in another format would render escaped once the widget is
  // pinned; convert it rather than leave it to be discovered by a reader.
  $odd = $db->query("SELECT f.entity_id, f.field_call_to_action_format fmt
    FROM {taxonomy_term__field_call_to_action} f
    JOIN {taxonomy_term_field_data} t ON t.tid = f.entity_id
    WHERE t.vid = :v AND (f.field_call_to_action_format <> 'full_html' OR f.field_call_to_action_format IS NULL)",
    [':v' => $vid])->fetchAll();
  foreach ($odd as $row) {
    printf("      term %s is %s — converting\n", $row->entity_id, $row->fmt ?: 'NULL');
    $converted++;
    if (!$apply) { continue; }
    $t = $etm->getStorage('taxonomy_term')->load($row->entity_id);
    $v = $t->get('field_call_to_action')->getValue();
    foreach ($v as $i => $item) { $v[$i]['format'] = 'full_html'; }
    $t->set('field_call_to_action', $v);
    $t->save();
  }
}
printf("\n%d value(s) needed converting\n", $converted);
print "done" . ($apply ? '' : ' (dry run)') . "\n";
