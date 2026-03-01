(function (Drupal, once) {
  'use strict';

  // Only refresh after OUR dialog closes (not every dialog on the site).
  let csuiShouldRefresh = false;

  Drupal.behaviors.contractSectionsUiRefresh = {
    attach(context) {
      // When staff clicks one of our modal edit links, arm the refresh.
      once('csui-modal-links', 'a.use-ajax[data-dialog-type="modal"]', context).forEach((link) => {
        const href = link.getAttribute('href') || '';
        // Only arm refresh for our dialog route.
        if (href.includes('/edit-dialog')) {
          link.addEventListener('click', () => {
            csuiShouldRefresh = true;
          });
        }
      });

      // After the dialog closes, refresh the View listing (if armed).
      once('csui-dialog-close-listener', 'body', context).forEach(() => {
        document.addEventListener('dialog:afterclose', function () {
          if (!csuiShouldRefresh) {
            return;
          }
          csuiShouldRefresh = false;

          // Target the View wrapper by CSS class you add in Views UI.
          const wrapper = document.querySelector('.contract-sections-view');
          if (!wrapper) {
            return;
          }

          // Views wrapper id is the dom_id (e.g. "views_dom_id:xxxx").
          const domId = wrapper.getAttribute('id');
          if (!domId || !Drupal.views || !Drupal.views.instances || !Drupal.views.instances[domId]) {
            return;
          }

          const view = Drupal.views.instances[domId];
          if (view && typeof view.refresh === 'function') {
            view.refresh();
          }
        });
      });
    }
  };

})(Drupal, once);
