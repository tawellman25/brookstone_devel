/**
 * @file
 * Gate 2B — client-side filter for the full service picker (the zero-candidate
 * case). No endpoint: just show/hide the already-rendered option buttons.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.woIntakeFilter = {
    attach: function (context) {
      once('wo-intake-filter', '.wo-intake-filter', context).forEach(function (input) {
        var picker = input.closest('.wo-intake-picker');
        if (!picker) {
          return;
        }
        input.addEventListener('input', function () {
          var q = input.value.trim().toLowerCase();
          picker.querySelectorAll('.wo-intake-service-option').forEach(function (btn) {
            var label = btn.getAttribute('data-label') || '';
            btn.style.display = (q === '' || label.indexOf(q) !== -1) ? '' : 'none';
          });
        });
      });
    }
  };

})(Drupal, once);
