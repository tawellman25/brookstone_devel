(function ($, Drupal, drupalSettings) {

    'use strict';
  
    Drupal.behaviors.customVboModal = {
      attach: function (context, settings) {
        $('.views-bulk-operations-form input[type="submit"]', context).once('custom-vbo-modal').on('click', function (e) {
          e.preventDefault();
          
          $.ajax({
            url: Drupal.url('clone-material-items-form'),
            success: function (data) {
              console.log('Received data:', data);
              if (data && data.length > 0) { // Check if data is not empty
                Drupal.dialog(data, {
                  title: 'Clone Material Item',
                  width: '800px',
                  buttons: [{
                    text: 'Close',
                    click: function () {
                      $(this).dialog('close');
                    }
                  }]
                }).showModal();
              } else {
                console.log('Received data was empty or not valid HTML');
              }
            },
            error: function (jqXHR, textStatus, errorThrown) {
              console.error('Failed to load form:', textStatus, errorThrown);
              console.log('Response text:', jqXHR.responseText);
            }
          });
        });
      }
    };
  
  })(jQuery, Drupal, drupalSettings);