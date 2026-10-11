<?php
/**
 * Does pasted HTML render as HTML on a season term, or as escaped text?
 *
 * The failure this catches looks like a copy problem and is a field problem: a
 * field left open to a more restrictive text format renders <p> as &lt;p&gt;.
 * Run BEFORE migrating anything, per the instruction.
 *
 * Reversible — the test value is written and then put back exactly.
 */
$etm = \Drupal::entityTypeManager();
$renderer = \Drupal::service('renderer');
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? " [$x]" : '');
};

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']);
$t = reset($terms);
$ok('found a season term to test on', (bool) $t, $t ? $t->label() : '-');

// Config matches the sibling it was copied from.
$efm = \Drupal::service('entity_field.manager');
$s = $efm->getFieldDefinitions('taxonomy_term', 'season');
$m = $efm->getFieldDefinitions('taxonomy_term', 'material_types');
foreach (['field_short_description', 'field_public_description', 'field_teammate_description', 'field_call_to_action'] as $f) {
  $ok("$f exists", isset($s[$f]));
  if (!isset($s[$f]) || !isset($m[$f])) { continue; }
  $sf = (array) ($s[$f]->getSetting('allowed_formats') ?? []);
  $mf = (array) ($m[$f]->getSetting('allowed_formats') ?? []);
  sort($sf); sort($mf);
  $ok("  …type matches material_types", $s[$f]->getType() === $m[$f]->getType(), $s[$f]->getType());
  $ok("  …allowed format matches", $sf === $mf, 'season=[' . implode(',', $sf) . '] material_types=[' . implode(',', $mf) . ']');
  $ok("  …cardinality matches", $s[$f]->getFieldStorageDefinition()->getCardinality() === $m[$f]->getFieldStorageDefinition()->getCardinality());
}

// The real test: paste HTML, render it, look at what comes out.
$restore = $t->get('field_public_description')->getValue();
$html = '<p>First paragraph with <strong>bold</strong> and a <a href="/about-us">link</a>.</p><p>Second paragraph.</p>';
$t->set('field_public_description', ['value' => $html, 'format' => 'full_html']);
$t->save();
$fresh = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
$build = $fresh->get('field_public_description')->view(['type' => 'text_default', 'label' => 'hidden']);
$out = (string) $renderer->renderPlain($build);
$ok('renders as real paragraphs', substr_count($out, '<p>') >= 2, substr_count($out, '<p>') . ' <p> tags');
$ok('bold survived', str_contains($out, '<strong>'));
$ok('link survived', str_contains($out, '<a href="/about-us"'));
$ok('NOT escaped', !str_contains($out, '&lt;p&gt;'));

$fresh->set('field_public_description', $restore);
$fresh->save();
$after = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
$ok('test term restored exactly', $after->get('field_public_description')->getValue() === $restore,
  $restore ? 'had a value' : 'was empty, is empty');

printf("\n%d passed, %d failed\n", $pass, $fail);
