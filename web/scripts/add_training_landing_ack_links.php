<?php

/**
 * Add a "Handbook Acknowledgments" item (links to the status/gap report + the
 * audit log) to the Operations → Training section landing page, so the landing
 * lists the two acknowledgment surfaces rather than them only existing as menu
 * items. Idempotent; content — run per env (dev then live).
 *
 *   drush php:script web/scripts/add_training_landing_ack_links.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('site_landing_page');

// The Training landing (office_administration) at /admin/operations/training.
$landing = NULL;
foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('type', 'office_administration')->execute()) as $e) {
  if ($e->toUrl()->toString() === '/admin/operations/training') {
    $landing = $e;
    break;
  }
}
if (!$landing) {
  print "Training landing page not found — nothing changed.\n";
  return;
}

$html = (string) $landing->get('field_description')->value;

if (strpos($html, '/admin/operations/training/handbook/acknowledgments') !== FALSE) {
  print "Training landing already lists the acknowledgment surfaces — no change.\n";
  return;
}

$item = '<li><p><strong>Handbook Acknowledgments:</strong>&nbsp;</p><ul>'
  . '<li><p><a href="/admin/operations/training/handbook/acknowledgments"><strong>Acknowledgment status &amp; gap</strong></a> &mdash; who has and hasn&rsquo;t signed the current handbook version, with completion&nbsp;%.</p></li>'
  . '<li><p><a href="/admin/operations/handbook-acknowledgments"><strong>Acknowledgment log</strong></a> &mdash; the full audit trail of online acknowledgments (sortable; filter by version or date).</p></li>'
  . '</ul></li>';

// Insert right after the "Teammate Handbook Admin" list item.
$anchor = 'Archive outdated versions.</p></li></ul></li>';
if (strpos($html, $anchor) !== FALSE) {
  $html = str_replace($anchor, $anchor . $item, $html);
  print "inserted after the Teammate Handbook Admin item\n";
}
else {
  // Fallback: append to the end of the first <ul> list.
  $pos = strpos($html, '</ul>');
  $html = $pos !== FALSE ? substr($html, 0, $pos) . $item . substr($html, $pos) : $html . '<ul>' . $item . '</ul>';
  print "anchor not found — appended to the responsibilities list (review placement)\n";
}

$landing->set('field_description', ['value' => $html, 'format' => 'full_html'])->save();
print "Training landing updated (id " . $landing->id() . ").\n";
