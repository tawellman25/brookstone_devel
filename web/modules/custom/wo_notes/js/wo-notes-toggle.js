/**
 * @file
 * "Hide schedule changes" toggle for the WO Notes cards.
 *
 * Toggles .hide-system on the nearest .wo-notes-list, flipping aria-pressed and
 * the button label. Default ON (schedule changes hidden — set in the template);
 * the button reveals them. once() prevents double-bind after the AJAX re-render
 * that follows "Add Note".
 */
(function (Drupal, once) {
  'use strict';

  function openNoteModal(url) {
    if (!url) { return; }
    Drupal.ajax({
      url: url,
      dialogType: 'modal',
      dialog: { width: '90%', maxWidth: 1000 }
    }).execute();
  }

  // Whole-card click opens the note's edit modal. The card is a <div> (not an
  // <a>) so note bodies can safely contain links; here we open the modal on
  // click while letting real inner links/buttons behave normally.
  Drupal.behaviors.woNotesCardModal = {
    attach: function (context) {
      once('wo-note-card-modal', '.wo-note-card--link[data-edit-url]', context).forEach(function (card) {
        card.addEventListener('click', function (e) {
          if (e.target.closest('a, button, input, textarea, select, label')) {
            return;
          }
          openNoteModal(card.getAttribute('data-edit-url'));
        });
        card.addEventListener('keydown', function (e) {
          if (e.target === card && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            openNoteModal(card.getAttribute('data-edit-url'));
          }
        });
      });
    }
  };

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
