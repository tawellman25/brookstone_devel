(function ($, Drupal) {
    'use strict';
  
    Drupal.AjaxCommands.prototype.materialUpdatePriceConfirm = function (ajax, response, status) {
      $(ajax.element).closest('.ui-dialog-content').dialog('close');
      window.location = ajax.element_settings.url || window.location.href;
    };
  
  })(jQuery, Drupal);