(function (Drupal) {
  'use strict';

  Drupal.behaviors.sprayRouteSort = {
    attach: function (context) {
      const table = context.querySelector
        ? context.querySelector('table.views-table')
        : document.querySelector('table.views-table');
      if (!table || table.dataset.spraySorted) return;
      table.dataset.spraySorted = '1';

      const tbody = table.querySelector('tbody');
      if (!tbody) return;

      const rows = Array.from(tbody.querySelectorAll('tr'));

      // Extract sort value from spray-status span
      function getSortValue(row) {
        const span = row.querySelector('.spray-status');
        if (!span) return -1;

        const cls = span.className;
        const text = span.textContent.trim();

        // Overdue — extract days, sort highest first
        if (cls.includes('spray-status--overdue')) {
          const match = text.match(/^(\d+)/);
          return match ? (10000 + parseInt(match[1])) : 10000;
        }
        // Due soon
        if (cls.includes('spray-status--due')) {
          const match = text.match(/^(\d+)/);
          return match ? (5000 + parseInt(match[1])) : 5000;
        }
        // Never applied
        if (cls.includes('spray-status--never')) {
          return 4999;
        }
        // OK — sort by days descending (closest to threshold first)
        if (cls.includes('spray-status--ok')) {
          const match = text.match(/^(\d+)/);
          return match ? parseInt(match[1]) : 0;
        }
        // On Call — sort last
        if (cls.includes('spray-status--on-call')) {
          return -1;
        }
        return -1;
      }

      // Sort: highest value first (most overdue at top)
      rows.sort((a, b) => getSortValue(b) - getSortValue(a));

      // Re-append in sorted order
      rows.forEach(row => tbody.appendChild(row));
    },
  };
})(Drupal);
