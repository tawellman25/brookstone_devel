(function (Drupal, $) {
    'use strict';
    Drupal.behaviors.fixFieldUI = {
      attach: function (context) {
        $('table.field-ui-overview', context).once('field-ui-fix').find('tr.draggable').each(function () {
          var $row = $(this);
          var rowHandler = $row.data('rowHandler');
          // Set 'field' for active fields, 'simple' for disabled section
          var isDisabled = $row.closest('#field-display-overview .region-disabled').length > 0;
          if (!rowHandler || !Drupal.tableDrag.prototype.rowHandlers[rowHandler]) {
            $row.data('rowHandler', isDisabled ? 'simple' : 'field');
          }
        });
      }
    };
  })(Drupal, jQuery);