<?php

declare(strict_types=1);

/**
 * The PUBLIC reviews page at /about-us/reviews (Todd 2026-09-28).
 *
 * Sits beside /about-us/credentials — both are "here is the evidence" pages.
 * Separate from the office surfaces, which keep their own voice:
 *
 *   /about-us/reviews                 PUBLIC  — approved only, customer voice
 *   /admin/office/reviews             office  — all statuses, the working queue
 *   /admin/office/reviews/pending     office  — awaiting a decision
 *   /admin/office/reviews/approved    office  — quote cards for pulling copy
 *
 * Filter is field_status = approved with operator **`or`**. On a PUBLIC display a
 * wrong operator is a content-safety bug: `in` emits no SQL on a list_string
 * ManyToOne filter, which would publish unreviewed text from strangers.
 *
 * Idempotent; saved through the View ENTITY so postSave registers the route.
 *   drush php:script web/scripts/build_reviews_public_page.php
 */

use Drupal\views\Entity\View;

$view = View::load('testimonials');
if (!$view) {
  print "ERROR: view 'testimonials' not found.\n";
  return;
}
$display = $view->get('display');

if (empty($display['page_public'])) {
  print "ERROR: page_public (the office reading view) is missing — nothing to clone from.\n";
  return;
}

// Clone the office reading view, then restore the public voice on the copy.
$site = $display['page_public'];
$site['id'] = 'page_site';
$site['display_title'] = 'Public reviews page';
$site['position'] = 4;
$site['display_options']['path'] = 'about-us/reviews';
$site['display_options']['title'] = 'Customer reviews';
$site['display_options']['access'] = ['type' => 'perm', 'options' => ['perm' => 'access content']];
// A public page is not a tab on an admin section.
unset($site['display_options']['menu']);

$site['display_options']['header']['area']['content']['value'] =
  '<p class="reviews__intro">What customers have said about working with us. These are sent to us directly; you can also read and leave reviews on our Google profile.</p>'
  . "\n" . '<p><a class="button" href="/review">Leave a review</a></p>';

$site['display_options']['footer']['area']['content']['value'] =
  '<p class="reviews__outro">Worked with us and willing to say so? It genuinely helps — <a href="/review">leave a review</a>. It takes a minute, and you can post to Google or send it straight to us.</p>';

$site['display_options']['empty']['area_text_custom']['content'] =
  '<p>We are just starting to collect these here. In the meantime, reviews on our Google profile are the best place to look — and if you have worked with us, <a href="/review">we would love yours</a>.</p>';

$site['display_options']['display_extenders'] = [
  'metatag_display_extender' => [
    'metatags' => [
      'title' => 'Customer Reviews | Brookstone Outdoors',
      'description' => 'What customers across Delta and Montrose counties say about our landscaping, irrigation, spraying and snow removal work.',
    ],
  ],
];

$display['page_site'] = $site;
$view->set('display', $display);
$view->save();

print "built page_site at /about-us/reviews (public, approved only)\n";
print "  office surfaces unchanged: /admin/office/reviews{,/pending,/approved}\n";
print "DONE.\n";
