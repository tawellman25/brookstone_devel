/**
 * @file
 * Copy-down behavior for the supplier invoice number field on
 * wo_material_list_item forms.
 *
 * When the FIRST invoice field on the page changes, propagate its value
 * to subsequent invoice fields that are still empty. Already-populated
 * fields are NEVER overwritten — the crew's manual entries always win.
 *
 * Visual cue: target fields briefly highlight yellow when auto-filled.
 */
(function ($, Drupal, once) {
  'use strict';

  const SELECTOR = 'input[name*="field_supplier_invoice_number"]';
  const HIGHLIGHT_BG = '#fff3cd';
  const HIGHLIGHT_DURATION_MS = 1000;

  Drupal.behaviors.woMaterialPriceSyncInvoiceCopyDown = {
    attach: function (context) {
      // Only bind change/blur handler once per element (use core/once).
      const fields = once('wo-mps-invoice-copy-down', SELECTOR, context);
      if (fields.length === 0) {
        return;
      }

      fields.forEach(function (field) {
        $(field).on('change blur', function () {
          const $changed = $(this);
          const value = ($changed.val() || '').trim();
          if (value === '') {
            return;
          }

          // Find ALL invoice fields currently on the page (not just within
          // the partial context — we need to see siblings added by AJAX too).
          const $all = $(SELECTOR);
          if ($all.length < 2) {
            return;
          }

          // Only propagate from the FIRST invoice field on the page.
          if (!$changed.is($all.first())) {
            return;
          }

          // Walk subsequent fields, fill the empty ones.
          $all.slice(1).each(function () {
            const $target = $(this);
            const targetVal = ($target.val() || '').trim();
            if (targetVal !== '') {
              return; // Already populated — do not overwrite.
            }
            $target.val(value);

            // Brief highlight so crew sees the auto-fill happened.
            const originalBg = $target.css('background-color');
            $target.css('background-color', HIGHLIGHT_BG);
            setTimeout(function () {
              $target.css('background-color', originalBg);
            }, HIGHLIGHT_DURATION_MS);
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
