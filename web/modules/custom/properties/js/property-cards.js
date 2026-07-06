/**
 * @file
 * Per-card "Add Work Order" service picker on /admin/properties.
 * Opens the chosen work_order bundle's add form, prefilled with the property.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.propertyCardWorkOrder = {
    attach: function (context) {
      once('prop-card-wo', '.prop-card-wo__go', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var wrap = btn.closest('.prop-card-wo');
          var sel = wrap ? wrap.querySelector('.prop-card-wo__select') : null;
          if (!sel || !sel.value) {
            return;
          }
          var base = btn.getAttribute('data-base');
          var pid = btn.getAttribute('data-property');
          var url = base + sel.value +
            '?edit[field_property][widget][0][target_id]=' + encodeURIComponent(pid);
          window.open(url, '_blank');
        });
      });
    }
  };
})(Drupal, once);
