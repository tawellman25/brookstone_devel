/**
 * @file
 * "Hide schedule changes" toggle for the WO Notes cards.
 *
 * Toggles .hide-system on the nearest .wo-notes-list, flipping aria-pressed and
 * the button label. Default OFF (all notes shown). once() prevents double-bind
 * after the AJAX re-render that follows "Add Note".
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.woNotesToggle = {
    attach: function (context) {
      once('wo-notes-toggle', '.wo-notes-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var toolbar = btn.closest('.wo-notes-toolbar');
          var list = (toolbar && toolbar.nextElementSibling && toolbar.nextElementSibling.classList.contains('wo-notes-list'))
            ? toolbar.nextElementSibling
            : document.querySelector('.wo-notes-list');
          if (!list) {
            return;
          }
          var hidden = list.classList.toggle('hide-system');
          btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
          btn.textContent = hidden ? 'Show schedule changes' : 'Hide schedule changes';
        });
      });
    }
  };
})(Drupal, once);
