<?php

/**
 * Snapshot every material_types term's body + teaser to a JSON file.
 *
 * Run before and after a copy paste; diffing the two files proves exactly which
 * fields moved — in particular that field_short_description (the card teasers)
 * was not touched, and that the four reparented categories were left alone.
 *
 * Usage: BOS_SNAP=/tmp/before.json drush php:script web/scripts/snapshot_material_type_copy.php
 */

$out = getenv('BOS_SNAP') ?: (sys_get_temp_dir() . '/material_type_copy_' . date('Ymd_His') . '.json');
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'material_types']);
$snap = [];
foreach ($terms as $t) {
  $get = function (string $f) use ($t): string {
    return $t->hasField($f) && !$t->get($f)->isEmpty()
      ? (string) $t->get($f)->first()->getValue()['value'] : '';
  };
  $snap[$t->id()] = [
    'name' => (string) $t->label(),
    'body' => $get('field_public_description'),
    'teaser' => $get('field_short_description'),
    'cta' => $get('field_call_to_action'),
    'crew' => $get('field_teammate_description'),
  ];
}
ksort($snap);
file_put_contents($out, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
printf("snapshotted %d material_types terms -> %s\n", count($snap), $out);
