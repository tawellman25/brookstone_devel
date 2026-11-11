<?php

/**
 * @file
 * Enter (or refresh) DRAFT SOPs in BOS from <CODE>_fields.json files.
 *
 *   drush php:script web/scripts/sop_import_drafts.php -- <path/to/CODE_fields.json> [...]
 *
 * Idempotent on field_sop_code. A SOP that does not exist is created as a
 * draft; an existing DRAFT is refreshed field by field; an existing SOP whose
 * status is approved / active / archived is never touched (GOV-SOP-001: codes
 * and content lock at approval). Parent and related SOPs are resolved by code,
 * in a second pass, so a set of new SOPs can reference each other. Owner is
 * the uid in the JSON (Todd = 1). Content format: full_html, as the existing
 * SOPs use. Code drafts; a human reviews and sets Active in BOS.
 */

use Drupal\Core\Datetime\DrupalDateTime;

$files = array_values(array_filter($extra ?? [], static fn($a) => str_ends_with($a, '.json')));
if (!$files) {
  echo "usage: drush php:script web/scripts/sop_import_drafts.php -- <CODE_fields.json> [...]\n";
  return;
}
$storage = \Drupal::entityTypeManager()->getStorage('sop');
$by_code = static function (string $code) use ($storage) {
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_sop_code', $code)->range(0, 1)->execute();
  return $ids ? $storage->load(reset($ids)) : NULL;
};
const SOP_LOCKED = ['approved', 'active', 'archived'];
$html_fields = ['field_sop_purpose', 'field_sop_scope', 'field_sop_responsibilities', 'field_sop_steps', 'field_sop_tools_and_resources', 'field_sop__kpis'];

$queued = [];
foreach ($files as $file) {
  $data = json_decode(file_get_contents($file), TRUE);
  if (!$data || empty($data['field_sop_code'])) {
    echo "!! $file: not a SOP fields JSON\n";
    continue;
  }
  $code = $data['field_sop_code'];
  $sop = $by_code($code);
  if ($sop && in_array((string) $sop->get('field_sop_status')->value, SOP_LOCKED, TRUE)) {
    printf("  %s exists with status %s — locked, not touched\n", $code, $sop->get('field_sop_status')->value);
    continue;
  }
  $is_new = !$sop;
  if ($is_new) {
    $sop = $storage->create(['type' => $data['bundle'], 'field_sop_code' => $code, 'uid' => (int) ($data['owner_uid'] ?? 1)]);
  }
  $sop->set('field_name', $data['field_name']);
  foreach ($html_fields as $f) {
    if ($sop->hasField($f) && isset($data[$f])) {
      $sop->set($f, ['value' => $data[$f], 'format' => 'full_html']);
    }
  }
  if ($sop->hasField('field_prerequisites')) {
    $sop->set('field_prerequisites', array_map(static fn($v) => ['value' => $v], $data['field_prerequisites'] ?? []));
  }
  $sop->set('field_sop_version', $data['field_sop_version'] ?? '1.0');
  $sop->set('field_sop_status', 'draft');
  $sop->set('field_sop_owner', (int) ($data['owner_uid'] ?? 1));
  $sop->set('field_sop_last_reviewed', (new DrupalDateTime('now', 'UTC'))->format('Y-m-d\TH:i:s'));
  $sop->save();
  printf("  %s %s as draft (id %d) → %s\n", $code, $is_new ? 'CREATED' : 'refreshed', $sop->id(), $sop->toUrl()->toString());
  $queued[$code] = $data;
}

// Second pass: parent + related by code.
foreach ($queued as $code => $data) {
  $sop = $by_code($code);
  if (!$sop) {
    continue;
  }
  $changed = FALSE;
  if (!empty($data['parent_code']) && $sop->hasField('field_parent_sop')) {
    $parent = $by_code($data['parent_code']);
    if ($parent && (int) ($sop->get('field_parent_sop')->target_id ?? 0) !== (int) $parent->id()) {
      $sop->set('field_parent_sop', $parent->id());
      $changed = TRUE;
    }
    elseif (!$parent) {
      echo "  !! $code: parent {$data['parent_code']} not found\n";
    }
  }
  if (!empty($data['related_codes']) && $sop->hasField('field_related_sops')) {
    $ids = [];
    foreach ($data['related_codes'] as $rc) {
      if ($rc === $code) {
        continue;
      }
      $r = $by_code($rc);
      if ($r) {
        $ids[] = (int) $r->id();
      }
      else {
        echo "  !! $code: related $rc not found\n";
      }
    }
    $cur = array_map(static fn($i) => (int) $i['target_id'], $sop->get('field_related_sops')->getValue());
    if ($ids && $ids !== $cur) {
      $sop->set('field_related_sops', $ids);
      $changed = TRUE;
    }
  }
  if ($changed) {
    $sop->save();
    printf("  %s: parent/related linked\n", $code);
  }
}
echo "done\n";
