/**
 * @file
 * Mobile-friendly data tables.
 *
 * Many WO-page Views render as multi-column tables that run off the right edge
 * on phones (the rightmost column — often the Edit/Links action — disappears).
 * This copies each column's header onto its body cells as a data-label and tags
 * the table so the CSS can stack it into labeled blocks on narrow screens.
 * Re-runs after AJAX (Drupal.behaviors + once).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.bosResponsiveTables = {
    attach: function (context) {
      once('bos-responsive-table', 'main table, .region-content table', context).forEach(function (table) {
        var head = table.querySelector('thead');
        if (!head) {
          return;
        }
        // Use the last header row (handles grouped headers).
        var headerRows = head.querySelectorAll('tr');
        if (!headerRows.length) {
          return;
        }
        var ths = headerRows[headerRows.length - 1].querySelectorAll('th');
        if (!ths.length) {
          return;
        }
        var labels = Array.prototype.map.call(ths, function (th) {
          return th.textContent.replace(/\s+/g, ' ').trim();
        });
        // Skip label-less tables (nothing useful to stack with).
        if (!labels.some(function (l) { return l !== ''; })) {
          return;
        }
        table.querySelectorAll('tbody tr').forEach(function (row) {
          Array.prototype.forEach.call(row.children, function (cell, i) {
            if (labels[i]) {
              cell.setAttribute('data-bos-label', labels[i]);
            }
          });
        });
        table.classList.add('bos-stack-table');
      });
    }
  };
})(Drupal, once);
