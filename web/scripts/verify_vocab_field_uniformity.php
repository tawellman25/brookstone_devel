<?php

/**
 * Are all the public vocabularies now the same shape, and does Full HTML
 * actually render as HTML?
 *
 * The second half matters more than the first: marketing's brief asked for a
 * paragraph of HTML to be pasted into the field and confirmed to render as a
 * paragraph rather than escaped markup, BEFORE trusting it. This does that on a
 * real term and then puts the term back.
 */

$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$cov = \Drupal::service('bos_content_coverage.coverage');
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %-58s %s\n", $good ? 'PASS' : 'FAIL', $what, $got);
};

$fields = ['field_short_description', 'field_public_description', 'field_teammate_description'];
$skip = ['services' => ['field_public_description', 'field_teammate_description']];

// 1. every vocabulary has the three fields.
$missing = [];
foreach ($cov->coveredVids() as $vid) {
  $defs = $efm->getFieldDefinitions('taxonomy_term', $vid);
  foreach ($fields as $f) {
    if (in_array($f, $skip[$vid] ?? [], TRUE)) { continue; }
    if (!isset($defs[$f])) { $missing[] = "$vid/$f"; }
  }
}
$ok('all three fields present on every public vocabulary', !$missing, $missing ? implode(', ', $missing) : count($cov->coveredVids()) . ' vocabularies');

// 2. identical configuration.
$odd = [];
foreach ($cov->coveredVids() as $vid) {
  foreach ($fields as $f) {
    if (in_array($f, $skip[$vid] ?? [], TRUE)) { continue; }
    $c = $etm->getStorage('field_config')->load('taxonomy_term.' . $vid . '.' . $f);
    if (!$c) { continue; }
    if (($c->getSetting('allowed_formats') ?: []) !== ['full_html']) { $odd[] = "$vid/$f formats"; }
    if ($c->isRequired()) { $odd[] = "$vid/$f required"; }
    if ($c->getType() !== 'text_long') { $odd[] = "$vid/$f type"; }
  }
}
$ok('configuration identical (full_html, optional, text_long)', !$odd, $odd ? implode(', ', array_slice($odd, 0, 4)) : 'uniform');

// 3. all three editable on every term form.
$offForm = [];
foreach ($cov->coveredVids() as $vid) {
  $d = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $vid . '.default');
  if (!$d) { $offForm[] = "$vid (no form display)"; continue; }
  foreach ($fields as $f) {
    if (in_array($f, $skip[$vid] ?? [], TRUE)) { continue; }
    if (!$d->getComponent($f)) { $offForm[] = "$vid/$f"; }
  }
}
$ok('all three on every term edit form', !$offForm, $offForm ? implode(', ', array_slice($offForm, 0, 4)) : 'editable everywhere');

// 4. no stored value left on another format.
$wrong = 0;
foreach ($cov->coveredVids() as $vid) {
  foreach ($fields as $f) {
    foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
      if (!$t->hasField($f) || $t->get($f)->isEmpty()) { continue; }
      if (($t->get($f)->first()->getValue()['format'] ?? '') !== 'full_html') { $wrong++; }
    }
  }
}
$ok('every stored value is Full HTML', $wrong === 0, $wrong . ' on another format');

// 5. plant_characteristics: teaser visible, no CTA.
$hidden = [];
foreach (['default','full','teammate_view','admin_view','client_view'] as $vm) {
  $d = $etm->getStorage('entity_view_display')->load('taxonomy_term.plant_characteristics.' . $vm);
  if ($d && !$d->getComponent('field_short_description')) { $hidden[] = $vm; }
}
$ok('field_short_description visible on plant_characteristics', !$hidden, $hidden ? implode(',', $hidden) : 'all five view modes');
$ok('field_call_to_action NOT added to plant_characteristics',
  !$etm->getStorage('field_config')->load('taxonomy_term.plant_characteristics.field_call_to_action'));

// 6. THE REAL TEST: paste HTML, render it, confirm it is a paragraph.
$term = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'plant_characteristics']) as $t) {
  if ($t->get('field_public_description')->isEmpty()) { continue; }
  $term = $t;
  break;
}
if (!$term) {
  $ok('HTML round-trip test', FALSE, 'no term to test on');
}
else {
  $original = $term->get('field_public_description')->first()->getValue();
  $probe = '<p>First paragraph with <strong>bold</strong> and a <a href="/contact">link</a>.</p><p>Second paragraph.</p>';
  $term->set('field_public_description', ['value' => $probe, 'format' => 'full_html'])->save();
  $built = $etm->getViewBuilder('taxonomy_term')->view($etm->getStorage('taxonomy_term')->loadUnchanged($term->id()), 'full');
  $html = (string) \Drupal::service('renderer')->renderInIsolation($built);
  $ok('pasted HTML renders as real paragraphs', substr_count($html, '<p>First paragraph') === 1, 'on ' . $term->label());
  $ok('it is NOT escaped markup', !str_contains($html, '&lt;p&gt;First paragraph'));
  $ok('the bold and the link survive', str_contains($html, '<strong>bold</strong>') && str_contains($html, 'href="/contact"'));
  // Put it back.
  $term->set('field_public_description', $original)->save();
  $restored = $etm->getStorage('taxonomy_term')->loadUnchanged($term->id());
  $ok('the test term was restored', trim((string) $restored->get('field_public_description')->first()->getValue()['value']) === trim((string) $original['value']));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
