/**
 * @file
 * Winterize win-back: record call outcomes without leaving the list.
 * "Not interested" opens a reason picker (reason + optional note), then removes
 * the card. Other outcomes annotate it. Reset / Undo clear the state.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var LABELS = {
    left_message: 'Left message',
    no_answer: 'No answer',
    reached: 'Reached',
    declined: 'Not interested'
  };

  var tokenPromise = null;
  function csrfToken() {
    if (!tokenPromise) {
      tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); });
    }
    return tokenPromise;
  }

  function post(pid, params) {
    var base = (drupalSettings.bosWinback && drupalSettings.bosWinback.markUrlBase) || '';
    return csrfToken().then(function (token) {
      var body = new URLSearchParams();
      Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
      return fetch(base + pid, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (r) { return r.json(); });
    });
  }

  function removeCard(card) {
    card.style.transition = 'opacity .25s';
    card.style.opacity = '0';
    setTimeout(function () { card.remove(); }, 250);
  }

  Drupal.behaviors.bosWinback = {
    attach: function (context) {
      once('wb-card', '.wb-card', context).forEach(function (card) {
        var pid = card.getAttribute('data-pid');
        var stateEl = card.querySelector('[data-role="state"]');
        var resetBtn = card.querySelector('.wb-reset');
        var picker = card.querySelector('.wb-decline');

        // Simple outcomes (left message / no answer / reached).
        card.querySelectorAll('.wb-mark').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var outcome = btn.getAttribute('data-outcome');
            btn.disabled = true;
            post(pid, { outcome: outcome }).then(function (res) {
              btn.disabled = false;
              if (!res || res.status !== 'ok') { return; }
              card.classList.add('is-worked');
              if (stateEl) {
                stateEl.textContent = (LABELS[res.outcome] || res.outcome) + ' · ' + res.by + ' · ' + res.time;
              }
              if (resetBtn) { resetBtn.hidden = false; }
            }).catch(function () { btn.disabled = false; });
          });
        });

        // "Not interested" → reveal reason picker.
        var openBtn = card.querySelector('.wb-decline-open');
        var cancelBtn = card.querySelector('.wb-decline-cancel');
        var confirmBtn = card.querySelector('.wb-decline-confirm');
        if (openBtn && picker) {
          openBtn.addEventListener('click', function () { picker.hidden = false; });
        }
        if (cancelBtn && picker) {
          cancelBtn.addEventListener('click', function () { picker.hidden = true; });
        }
        if (confirmBtn && picker) {
          confirmBtn.addEventListener('click', function () {
            var reason = picker.querySelector('.wb-decline__reason').value;
            var note = picker.querySelector('.wb-decline__note').value;
            confirmBtn.disabled = true;
            post(pid, { outcome: 'declined', reason: reason, note: note }).then(function (res) {
              if (res && res.suppress) { removeCard(card); }
              else { confirmBtn.disabled = false; }
            }).catch(function () { confirmBtn.disabled = false; });
          });
        }

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            resetBtn.disabled = true;
            post(pid, { outcome: 'clear' }).then(function () {
              resetBtn.disabled = false;
              card.classList.remove('is-worked');
              if (stateEl) { stateEl.textContent = ''; }
              resetBtn.hidden = true;
            }).catch(function () { resetBtn.disabled = false; });
          });
        }
      });

      // Undo in the "Declined this season" section → back onto the list (on reload).
      once('wb-undo', '.wb-declined-undo', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var pid = btn.getAttribute('data-pid');
          btn.disabled = true;
          post(pid, { outcome: 'clear' }).then(function () {
            var row = btn.closest('.wb-declined__row');
            if (row) { row.remove(); }
          }).catch(function () { btn.disabled = false; });
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
