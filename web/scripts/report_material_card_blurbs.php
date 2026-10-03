<?php

/**
 * Every material category card: what it said, what it says now.
 *
 * Read-only. A term's card appears on its PARENT's page, so this covers every
 * material_types term that has a parent — exactly the cards material_children
 * renders.
 */
$etm = \Drupal::entityTypeManager();
$old = function ($t): string {
  if (!$t->hasField('field_public_description') || !($i = $t->get('field_public_description')->first())) { return ''; }
  return _material_card_text((string) $i->value, 160);
};
$new = function ($t): string {
  if ($t->hasField('field_short_description') && ($s = $t->get('field_short_description')->first())) {
    $v = _material_card_text((string) $s->value, 300);
    if ($v !== '') { return $v; }
  }
  if ($t->hasField('field_public_description') && ($i = $t->get('field_public_description')->first())) {
    return _material_card_text((string) $i->value, 160);
  }
  return '';
};

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'material_types']);
$changed = $unchanged = $empty = 0;
$rows = [];
foreach ($terms as $t) {
  if (!(int) $t->get('parent')->target_id) { continue; }   // no parent => no card
  $o = $old($t); $n = $new($t);
  if ($o === $n) { if ($n === '') { $empty++; } else { $unchanged++; } continue; }
  $changed++;
  $parent = $etm->getStorage('taxonomy_term')->load($t->get('parent')->target_id);
  $rows[] = [$parent ? (string) $parent->label() : '?', (string) $t->label(), $o, $n];
}
usort($rows, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
foreach ($rows as [$parent, $label, $o, $n]) {
  printf("\n%s  ▸  %s\n", $parent, $label);
  printf("   was: %s\n", $o === '' ? '(blank)' : $o);
  printf("   now: %s\n", $n);
}
printf("\n%d card(s) change text, %d unchanged, %d still blank (no copy either way)\n", $changed, $unchanged, $empty);
