<?php

declare(strict_types=1);

/**
 * Move each pump's HP out of field_name into the structured field_pump_size, so
 * the Title composes from the coded size field's label ("3/4 HP") + the name.
 * (The auto_entitylabel token renders a list field's LABEL, so pattern
 * [field_pump_size] [field_name] yields "3/4 HP Sprinkler …".)
 *
 * Only a CLEAN leading HP that exactly matches an allowed value is moved:
 *   - "3/4 HP  Sprinkler…"            -> size 3/4 HP, name "Sprinkler…"
 *   - "3 HP Munro Pump 3 HP LP Series"-> size 3 HP,  name "Munro Pump 3 HP LP Series"
 * These are deliberately LEFT untouched (no single allowed HP, or accessories):
 *   - ranges: "3/4 HP - 3 HP Res. …", "3/4 - 2 hp PS800 …"
 *   - "1/4 in. NASON PRESSURE SENSOR", "Munro Pump Start Relay", "… Oring"
 *
 * Idempotent (a name no longer starting with an HP label is skipped) — run per
 * env. Sets the pumps pattern + ensures field_pump_size is on the form, then
 * re-saves the pumps so titles recompose.
 *
 *   drush php:script web/scripts/fix_pumps_size_title.php
 */

use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'material';

// label => key, longest label first so "1-1/2 HP"/"10 HP" beat "1 HP".
$allowed = FieldStorageConfig::loadByName($ENTITY, 'field_pump_size')->getSetting('allowed_values');
$label2key = [];
foreach ($allowed as $key => $label) {
  if (strcasecmp($label, 'Other') !== 0) {
    $label2key[$label] = $key;
  }
}
uksort($label2key, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

// pumps pattern + form widget.
\Drupal::configFactory()->getEditable('auto_entitylabel.settings.material.pumps')
  ->set('pattern', '[material:field_pump_size] [material:field_name]')->save();
$fd = \Drupal::service('entity_display.repository')->getFormDisplay($ENTITY, 'pumps', 'default');
if (!$fd->getComponent('field_pump_size')) {
  $fd->setComponent('field_pump_size', ['type' => 'options_select', 'weight' => -4, 'settings' => []])->save();
}

$st = \Drupal::entityTypeManager()->getStorage($ENTITY);
$ids = $st->getQuery()->accessCheck(FALSE)->condition('type', 'pumps')->sort('id')->execute();
$moved = 0;
$left = [];
foreach ($st->loadMultiple($ids) as $m) {
  $name = trim((string) $m->get('field_name')->value);
  $matchedKey = NULL;
  $rest = NULL;
  foreach ($label2key as $label => $key) {
    // Leading exact label followed by whitespace.
    if (preg_match('/^' . preg_quote($label, '/') . '\s+(.*)$/s', $name, $mch)) {
      $remainder = ltrim($mch[1]);
      // Skip ranges like "3/4 HP - 3 HP …" (remainder begins with a dash).
      if ($remainder === '' || $remainder[0] === '-') {
        break;
      }
      $matchedKey = $key;
      $rest = $remainder;
      break;
    }
  }
  if ($matchedKey !== NULL) {
    $m->set('field_pump_size', $matchedKey);
    $m->set('field_name', $rest);
    $m->save();
    $moved++;
    printf("  moved id=%d  size=%s  name=[%s]  ->  %s\n", $m->id(), $allowed[$matchedKey], $rest, $m->label());
  }
  else {
    // Re-save anyway so the title recomposes under the new pattern (unchanged
    // for accessories/ranges — pump_size empty => title = name).
    $before = $m->label();
    $m->save();
    $left[] = sprintf("id=%d [%s]", $m->id(), $before);
  }
}
print "\nMoved HP into field_pump_size on $moved pumps.\n";
print "Left untouched (no single allowed HP / accessory / range):\n  " . implode("\n  ", $left) . "\n";
print "DONE.\n";
