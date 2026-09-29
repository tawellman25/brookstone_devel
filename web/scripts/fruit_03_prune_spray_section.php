<?php

declare(strict_types=1);

/**
 * Add the "We prune and spray them too" section to the Fruit Trees footer.
 *
 * The rest of that page already matches marketing's document. This section is
 * new, and it is the one that answers a question left open when the page was
 * first built: whether fruit tree pruning and spraying is a service we offer.
 * It is, and the page now says so.
 *
 * It goes immediately BEFORE "Fruit as a landscape decision…", which is the
 * order the document gives — the service offer reads after the work it refers
 * to, and before the hand-off to the characteristic page.
 *
 * Copy is marketing's, verbatim. Terms are matched by RELATIONSHIP, never by
 * name: this category has already been renamed twice (Fruit Trees → Fruit →
 * Fruit Trees) and its parent once (Deciduous → Deciduous Trees), and a
 * name-matched lookup would silently do nothing.
 *
 * Idempotent — a second run finds the heading present and stops. Dry-run by
 * default; set BOS_FRUIT_PRUNE_APPLY=1 to write.
 *
 *   drush php:script web/scripts/fruit_03_prune_spray_section.php
 */

$apply = getenv('BOS_FRUIT_PRUNE_APPLY') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

/** Locate Trees → Deciduous* → Fruit* without relying on any exact name. */
$trees = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types']) as $t) {
  if ($t->get('field_material_bundle')->value === 'trees') {
    $trees = $t;
    break;
  }
}
if (!$trees) {
  print "ABORT: no material_types term claims the 'trees' bundle.\n";
  return;
}

$deciduous = NULL;
foreach ($terms->loadTree('material_types', (int) $trees->id(), 1, TRUE) as $child) {
  if (stripos($child->label(), 'deciduous') === 0) {
    $deciduous = $child;
    break;
  }
}
if (!$deciduous) {
  print "ABORT: no Deciduous category under Trees.\n";
  return;
}

$fruit = NULL;
foreach ($terms->loadTree('material_types', (int) $deciduous->id(), 1, TRUE) as $child) {
  if (stripos($child->label(), 'fruit') === 0) {
    $fruit = $child;
    break;
  }
}
if (!$fruit) {
  print "ABORT: no Fruit category under " . $deciduous->label() . ".\n";
  return;
}

printf("target: %s (tid %s) under %s\n", $fruit->label(), $fruit->id(), $deciduous->label());

$SECTION = <<<'HTML'
<h2>We prune and spray them too</h2>

<p>We plant fruit trees, we prune them, and we run spray programs on them, for homeowners with three trees and for properties with thirty.</p>

<p>Dormant pruning runs January through March, before bud break — a narrow window, and one of the few jobs in a yard that genuinely cannot be done late. The dormant spray goes on in the same stretch. After that the calendar is codling moth timing through the summer on apples and pears, and thinning in early June.</p>

<p>If you would rather do it yourself, ask and we will write out the schedule for the varieties you have. Knowing when is most of it.</p>

HTML;

$footer = (string) $fruit->get('field_call_to_action')->value;
if ($footer === '') {
  print "ABORT: the Fruit Trees footer (field_call_to_action) is empty — run fruit_02_content.php first.\n";
  return;
}

if (str_contains($footer, 'We prune and spray them too')) {
  print "  already present — nothing to do.\n";
  return;
}

// Anchor on the heading that must follow it.
$anchor = '<h2>Fruit as a landscape decision';
$pos = strpos($footer, $anchor);
if ($pos === FALSE) {
  print "ABORT: could not find the 'Fruit as a landscape decision' heading to insert before.\n";
  print "       The footer has been edited; insert the section by hand rather than guessing.\n";
  return;
}

$new = substr($footer, 0, $pos) . $SECTION . substr($footer, $pos);

printf("  %s insert 'We prune and spray them too' before 'Fruit as a landscape decision' (%d -> %d chars)\n",
  $apply ? 'WRITE ' : 'would ', strlen($footer), strlen($new));

if (!$apply) {
  print "\nDRY RUN. Re-run with BOS_FRUIT_PRUNE_APPLY=1 to write.\n";
  return;
}

$fruit->set('field_call_to_action', [
  'value' => $new,
  'format' => $fruit->get('field_call_to_action')->format ?: 'full_html',
]);
$fruit->save();
print "  saved.\n";
