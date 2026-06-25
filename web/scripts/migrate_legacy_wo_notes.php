<?php

/**
 * @file
 * One-time migration: parse legacy wo_schedule notes into the structured fields
 * introduced by the WO Notes card restyle (field_change_summary / field_note_kind
 * / field_is_system_note), so old schedule notes render as cards like new ones.
 *
 * Legacy notes store the whole thing in field_note_text, e.g.
 *   "Scheduled by {actor}, {stamp} — Date: {date}; Assigned: {name}; Schedule note: \"{note}\""
 *   "Schedule changed by {actor}, {stamp} — Date: {old} → {new}; Assigned: ...; Schedule note: \"old\" → \"new\""
 *
 * This splits them: drops the "by {actor}, {stamp}" prefix (the card builds
 * attribution from uid + created), relabels the date part (Scheduled: / Rescheduled:),
 * moves the scheduling note (new value only) to field_note_text, and sets the kind
 * + system flag. Idempotent: notes already carrying a kind / system flag are skipped,
 * as are genuine manual notes (no recognizable prefix).
 *
 * Run:  ddev drush scr web/scripts/migrate_legacy_wo_notes.php       (local)
 *       drush scr web/scripts/migrate_legacy_wo_notes.php            (live, at deploy)
 */

$storage = \Drupal::entityTypeManager()->getStorage('wo_notes');
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'note')->execute();
$migrated = 0;

foreach (array_chunk($ids, 300) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $n) {
    // Skip already-structured notes (idempotent re-run).
    $has_kind = $n->hasField('field_note_kind') && !$n->get('field_note_kind')->isEmpty();
    $is_sys = $n->hasField('field_is_system_note') && (bool) $n->get('field_is_system_note')->value;
    if ($has_kind || $is_sys) {
      continue;
    }

    $text = (string) ($n->get('field_note_text')->value ?? '');
    // Legacy schedule note? (em-dash U+2014 separates prefix from body.)
    if (!preg_match('/^(Scheduled by|Schedule changed by)\b.*?\x{2014}\s*(.*)$/su', $text, $m)) {
      continue;
    }
    $kind = ($m[1] === 'Scheduled by') ? 'schedule_insert' : 'schedule_change';
    $rest = $m[2];

    // Extract the scheduling note (last part); new value only on changes.
    $note_val = '';
    if (preg_match('/;?\s*Schedule note:\s*(.*)$/su', $rest, $nm)) {
      $rest = trim(preg_replace('/;?\s*Schedule note:\s*.*$/su', '', $rest));
      $raw = $nm[1];
      if (strpos($raw, ' → ') !== FALSE) {
        $parts = explode(' → ', $raw);
        $raw = end($parts);
      }
      $note_val = trim($raw, " \"");
    }

    // Relabel the date part to match the new format.
    $rest = ($kind === 'schedule_insert')
      ? preg_replace('/^Date:/', 'Scheduled:', $rest)
      : preg_replace('/^Date:/', 'Rescheduled:', $rest);

    $n->set('field_change_summary', $rest)
      ->set('field_note_text', $note_val)
      ->set('field_note_kind', $kind)
      ->set('field_is_system_note', TRUE);
    $n->save();
    $migrated++;
  }
}

echo "migrated {$migrated} legacy schedule notes\n";
