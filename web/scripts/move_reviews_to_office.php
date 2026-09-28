<?php

declare(strict_types=1);

/**
 * Move the reviews surfaces under Office (Todd 2026-09-28).
 *
 *   /admin/office/reviews            all reviews, admin table, status visible
 *   /admin/office/reviews/pending    tab — awaiting review
 *   /admin/office/reviews/approved   tab — the quote-card reading view
 *
 * Retires BOTH the public /reviews page and my earlier
 * /admin/operations/system_content/testimonials paths, so there is ONE home rather
 * than three surfaces for one entity.
 *
 * CONSEQUENCE, deliberately: there is no longer a public testimonials page. The
 * Google profile is the public face; the internal testimonials are an office
 * library. Nothing linked to /reviews, so no broken links.
 *
 * Access moves from 'access content' to the testimonial listing permission on the
 * approved display too — it is an office surface now.
 *
 * Idempotent; saved through the View ENTITY so postSave rebuilds routes + tasks.
 *   drush php:script web/scripts/move_reviews_to_office.php
 */

use Drupal\views\Entity\View;

$BASE = 'admin/office/reviews';

$view = View::load('testimonials');
if (!$view) {
  print "ERROR: view 'testimonials' not found.\n";
  return;
}
$display = $view->get('display');

/* 1. Office home — the admin table, all statuses. */
$display['page_all']['display_options']['path'] = $BASE;
$display['page_all']['display_options']['title'] = 'Reviews';
$display['page_all']['display_options']['menu'] = [
  'type' => 'normal',
  'title' => 'Reviews',
  'description' => 'Customer reviews from /review — approve before they are used anywhere.',
  'weight' => 45,
  'menu_name' => 'admin',
  'parent' => '',
  'expanded' => FALSE,
];

/* 2. Pending tab. */
$display['page_pending']['display_options']['path'] = $BASE . '/pending';
$display['page_pending']['display_options']['menu'] = [
  'type' => 'tab', 'title' => 'Pending', 'description' => 'Not yet approved or rejected.',
  'weight' => 10, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
];

/* 3. The quote-card view becomes the "Approved" reading tab — office access now,
      and office-voice empty text (the public wording no longer fits). */
if (isset($display['page_public'])) {
  $display['page_public']['display_title'] = 'Approved (reading view)';
  $display['page_public']['display_options']['path'] = $BASE . '/approved';
  $display['page_public']['display_options']['title'] = 'Approved reviews';
  $display['page_public']['display_options']['access'] = [
    'type' => 'perm',
    'options' => ['perm' => 'access testimonial entity listing'],
  ];
  $display['page_public']['display_options']['menu'] = [
    'type' => 'tab', 'title' => 'Approved', 'description' => 'Approved reviews, as quote cards — for pulling copy.',
    'weight' => 20, 'menu_name' => 'admin', 'parent' => '', 'expanded' => FALSE,
  ];
  // Office voice, and drop the public CTAs.
  $display['page_public']['display_options']['header']['area']['content']['value'] =
    '<p>Approved reviews, newest first — use these for marketing copy. Anything still pending is on the <a href="/admin/office/reviews/pending">Pending</a> tab.</p>';
  $display['page_public']['display_options']['footer']['area']['content']['value'] =
    '<p>Customers reach the submission form at <a href="/review">/review</a>; print its QR from the work-order paperwork so a review arrives attached to the job.</p>';
  $display['page_public']['display_options']['empty']['area_text_custom']['content'] =
    '<p>No approved reviews yet. New submissions land on the Pending tab.</p>';
  // No public metatags needed on an admin surface.
  unset($display['page_public']['display_options']['display_extenders']);
}

$view->set('display', $display);
$view->save();

printf("page_all      -> /%s            (menu: admin/normal 'Reviews')\n", $BASE);
printf("page_pending  -> /%s/pending    (tab)\n", $BASE);
printf("page_public   -> /%s/approved   (tab, office access — no longer public)\n", $BASE);
print "DONE.\n";
