<?php

declare(strict_types=1);

/**
 * Work the Credentials and Reviews pages into the About Us copy itself.
 *
 * Menu and footer links get someone there who is already looking. These are the
 * links a reader trips over at the moment the question occurs to them — the
 * "licensed and insured" claim points at the proof, the licensing caveat points
 * at what we actually hold, and the closing section invites the review.
 *
 * Body content, not config: run per environment. Idempotent — every edit is
 * skipped once its link is present, so a re-run is a no-op and an edit made by
 * hand in the meantime is never clobbered.
 *
 *   drush php:script web/scripts/link_about_trust_pages.php
 */

const ABOUT_NID = 109;
const CRED = '/about-us/credentials';
const REVIEWS = '/about-us/reviews';

$node = \Drupal::entityTypeManager()->getStorage('node')->load(ABOUT_NID);
if (!$node) {
  print "ABORT: node " . ABOUT_NID . " not found\n";
  return;
}
$body = $node->get('body')->value;
$format = $node->get('body')->format;
print "node " . ABOUT_NID . ": " . $node->label() . " (format: $format)\n";
$before = $body;

// Every edit: [description, exact needle, replacement, how many to replace].
$edits = [
  [
    'hero + closing trust lines -> credentials',
    'Licensed and insured',
    '<a href="' . CRED . '">Licensed and insured</a>',
    -1,
  ],
  [
    'licensing caveat -> credentials',
    'outside our licensing',
    'outside <a href="' . CRED . '">our licensing</a>',
    1,
  ],
];

foreach ($edits as [$what, $needle, $replacement, $limit]) {
  if (strpos($body, $replacement) !== FALSE) {
    print "  skip   $what (already linked)\n";
    continue;
  }
  if (strpos($body, $needle) === FALSE) {
    print "  MISS   $what — needle not found, copy has changed\n";
    continue;
  }
  $count = 0;
  $body = $limit < 0
    ? str_replace($needle, $replacement, $body, $count)
    : preg_replace('/' . preg_quote($needle, '/') . '/', str_replace('$', '\\$', $replacement), $body, $limit, $count);
  print "  link   $what ($count)\n";
}

// Closing invitation in the "Practical Advice" section.
$anchor = '<p>Answer the phone. Do the work right. Be here next season.</p>';
$invite = $anchor . "\n  "
  . '<p>If we have worked on your property, we would rather you judge us on that than on anything written here — '
  . '<a href="' . REVIEWS . '">see what customers have said, or leave a review</a>.</p>';
if (strpos($body, REVIEWS) !== FALSE) {
  print "  skip   reviews invitation (already linked)\n";
}
elseif (strpos($body, $anchor) === FALSE) {
  print "  MISS   reviews invitation — anchor paragraph not found\n";
}
else {
  $body = str_replace($anchor, $invite, $body);
  print "  add    reviews invitation\n";
}

if ($body === $before) {
  print "no change\n";
  return;
}
$node->set('body', ['value' => $body, 'format' => $format, 'summary' => $node->get('body')->summary]);
$node->setNewRevision(TRUE);
$node->setRevisionLogMessage('Link Credentials + Customer Reviews from the About Us copy.');
$node->setRevisionUserId(1);
$node->save();
printf("saved (%d -> %d bytes)\n", strlen($before), strlen($body));
