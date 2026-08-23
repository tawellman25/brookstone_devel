/**
 * /winterize progressive enhancements (JS off → everything still works):
 *  1. Hero "Get on the list" CTA drops the cursor into Last name after the
 *     anchor scroll, without re-jumping the view.
 *  2. After a submit/rebuild the page lands at the top (hero), but the form is
 *     far down — so scroll any validation warning OR the confirmation message
 *     into view so the visitor sees the outcome.
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

  Drupal.behaviors.boWinterizeScrollToAlert = {
    attach: function (context) {
      once('bo-winterize-alert', 'body', context).forEach(function () {
        // Confirmation (successful submit) takes priority, then any error/warning
        // message, then the first invalid field.
        var target =
          document.querySelector('.winterize-confirmation') ||
          document.querySelector('.messages--error, .messages--warning, [data-drupal-messages] .messages') ||
          document.querySelector('.form-item--error, input.error, [aria-invalid="true"]');
        if (target) {
          window.setTimeout(function () {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }, 200);
        }
      });
    }
  };
})(Drupal, once);
