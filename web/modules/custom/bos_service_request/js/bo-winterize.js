/**
 * When the hero "Get on the list" CTA is clicked, drop the cursor into the
 * Last name field after the anchor scroll — without re-jumping the view
 * (preventScroll), so the "Request your winterization" heading stays in frame.
 * Progressive enhancement: with JS off, the anchor still scrolls to the form.
 */
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.boWinterizeFocus = {
    attach: function (context) {
      once('bo-winterize-cta', 'a[href="#request"]', context).forEach(function (link) {
        link.addEventListener('click', function () {
          var field = document.querySelector('input[name="submitted_name"]');
          if (field) {
            window.setTimeout(function () {
              try { field.focus({ preventScroll: true }); }
              catch (e) { field.focus(); }
            }, 450);
          }
        });
      });
    }
  };
})(Drupal, once);
