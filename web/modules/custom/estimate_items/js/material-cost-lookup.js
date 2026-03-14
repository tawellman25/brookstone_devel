(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.estimateItemsMaterialCost = {
    attach: function (context) {
      once('material-cost', '[data-drupal-selector="edit-field-material-0-target-id"]', context).forEach(function (input) {
        input.addEventListener('change', function () {
          var match = input.value.match(/\((\d+)\)$/);
          if (!match) {
            return;
          }
          fetch('/bos/api/material-cost/' + match[1])
            .then(function (r) { return r.json(); })
            .then(function (data) {
              var priceInput = document.querySelector(
                '[data-drupal-selector="edit-field-unit-price-0-value"]'
              );
              if (priceInput && data.cost) {
                priceInput.value = data.cost;
                priceInput.dispatchEvent(new Event('change', { bubbles: true }));
              }
            });
        });
      });
    },
  };
})(Drupal, once);
