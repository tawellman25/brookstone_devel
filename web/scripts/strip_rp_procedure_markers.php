<?php

/**
 * Remove the appended RP test-procedure blocks (marked by the HTML comment
 * `<!-- rp-test-procedure -->`) from the 9 RP model descriptions and service
 * term 1649, now that the canonical procedure lives on the type hub page (the
 * backflow_device_types term). The block is a single opening marker with the
 * appended content running to end-of-field, so the strip truncates AT the marker.
 *
 * Idempotent (no marker => no change). Scans ALL material/backflow descriptions
 * and service term 1649 so it catches every marked field regardless of ids.
 * Dry-run by default; BOS_APPLY=1 to write.
 *
 *   drush php:script web/scripts/strip_rp_procedure_markers.php               (dry-run)
 *   BOS_APPLY=1 drush php:script web/scripts/strip_rp_procedure_markers.php   (apply)
 */

$APPLY = getenv('BOS_APPLY') === '1';
$MARKER = '<!-- rp-test-procedure -->';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (BOS_APPLY=1 to write) ===\n";

$strip = function (string $text) use ($MARKER): ?string {
  $pos = strpos($text, $MARKER);
  if ($pos === FALSE) {
    return NULL;
  }
  // Truncate at the marker; trim trailing whitespace left on the original body.
  return rtrim(substr($text, 0, $pos));
};

$etm = \Drupal::entityTypeManager();
$changed = 0;

// 1) material/backflow -> field_description
$ids = $etm->getStorage('material')->getQuery()
  ->accessCheck(FALSE)->condition('type', 'backflow')->execute();
foreach ($etm->getStorage('material')->loadMultiple($ids) as $m) {
  if (!$m->hasField('field_description') || $m->get('field_description')->isEmpty()) {
    continue;
  }
  $val = (string) $m->get('field_description')->value;
  $new = $strip($val);
  if ($new === NULL) {
    continue;
  }
  print "  material {$m->id()} ({$m->label()}): " . strlen($val) . " -> " . strlen($new) . " chars\n";
  $changed++;
  if ($APPLY) {
    $item = $m->get('field_description')->first()->getValue();
    $item['value'] = $new;
    $m->set('field_description', $item)->save();
  }
}

// 2) service term 1649 -> field_service_public_desc
$term = \Drupal\taxonomy\Entity\Term::load(1649);
if ($term && $term->hasField('field_service_public_desc') && !$term->get('field_service_public_desc')->isEmpty()) {
  $val = (string) $term->get('field_service_public_desc')->value;
  $new = $strip($val);
  if ($new !== NULL) {
    print "  term 1649 ({$term->label()}): " . strlen($val) . " -> " . strlen($new) . " chars\n";
    $changed++;
    if ($APPLY) {
      $item = $term->get('field_service_public_desc')->first()->getValue();
      $item['value'] = $new;
      $term->set('field_service_public_desc', $item)->save();
    }
  }
}

print "\n" . ($APPLY ? "stripped" : "would strip") . ": $changed field(s)\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN.\n";
