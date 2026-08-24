/**
 * @file
 * Winterize win-back: record call outcomes without leaving the list.
 * "Not interested" removes the card; other outcomes annotate it. Reset clears.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var LABELS = {
    left_message: 'Left message',
    no_answer: 'No answer',
    reached: 'Reached',
    declined: 'Not interested'
  };

  // Fetch (and cache) the session CSRF token for the header-token route.
  var tokenPromise = null;
  function csrfToken() {
    if (!tokenPromise) {
      tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); });
    }
    return tokenPromise;
  }

  function post(pid, outcome) {
    var base = (drupalSettings.bosWinback && drupalSettings.bosWinback.markUrlBase) || '';
    return csrfToken().then(function (token) {
      var body = new URLSearchParams();
      body.set('outcome', outcome);
      return fetch(base + pid, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (r) { return r.json(); });
    });
  }

  Drupal.behaviors.bosWinback = {
    attach: function (context) {
      once('wb-mark', '.wb-card', context).forEach(function (card) {
        var pid = card.getAttribute('data-pid');
        var stateEl = card.querySelector('[data-role="state"]');
        var resetBtn = card.querySelector('.wb-reset');

        card.querySelectorAll('.wb-mark').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var outcome = btn.getAttribute('data-outcome');
            btn.disabled = true;
            post(pid, outcome).then(function (res) {
              btn.disabled = false;
              if (!res || res.status !== 'ok') { return; }
              if (res.suppress) {
                // Not interested → remove from the list.
                card.style.transition = 'opacity .25s';
                card.style.opacity = '0';
                setTimeout(function () { card.remove(); }, 250);
                return;
              }
              card.classList.add('is-worked');
              if (stateEl) {
                stateEl.textContent = (LABELS[res.outcome] || res.outcome) + ' · ' + res.by + ' · ' + res.time;
              }
              if (resetBtn) { resetBtn.hidden = false; }
            }).catch(function () { btn.disabled = false; });
          });
        });

        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            resetBtn.disabled = true;
            post(pid, 'clear').then(function () {
              resetBtn.disabled = false;
              card.classList.remove('is-worked');
              if (stateEl) { stateEl.textContent = ''; }
              resetBtn.hidden = true;
            }).catch(function () { resetBtn.disabled = false; });
          });
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
