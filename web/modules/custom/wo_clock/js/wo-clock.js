/**
 * @file
 * WO Clock — clock-in/out interactions with silent GPS capture.
 *
 * Phone-first. GPS is requested with a 5-second timeout; grant, deny, or
 * timeout all proceed to the AJAX call (location omitted if unavailable). No
 * user-facing location complaints, ever. CSRF via the session token header
 * (_csrf_request_header_token on the routes).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var GPS_TIMEOUT_MS = 5000;
  var tokenPromise = null;

  function sessionToken() {
    if (!tokenPromise) {
      tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .catch(function () { return ''; });
    }
    return tokenPromise;
  }

  // Resolve to {lat, lon} or null — never rejects, never blocks.
  function getGps() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        resolve(null);
        return;
      }
      var done = false;
      var finish = function (v) { if (!done) { done = true; resolve(v); } };
      var timer = setTimeout(function () { finish(null); }, GPS_TIMEOUT_MS);
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          clearTimeout(timer);
          finish({ lat: pos.coords.latitude, lon: pos.coords.longitude });
        },
        function () { clearTimeout(timer); finish(null); },
        { enableHighAccuracy: true, timeout: GPS_TIMEOUT_MS, maximumAge: 0 }
      );
    });
  }

  function post(url, body) {
    return sessionToken().then(function (token) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token
        },
        body: JSON.stringify(body || {})
      }).then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Bad response' }; }); });
    });
  }

  function reloadWo() {
    // Refresh the WO page so the new time entry + updated total appear in the
    // Hours breakdown. Brief delay lets the button's state flip show first.
    setTimeout(function () { window.location.reload(); }, 500);
  }

  function setBusy(root, busy) {
    root.querySelectorAll('button').forEach(function (b) { b.disabled = busy; });
    var w = root.querySelector('.wo-clock__working');
    if (w) { w.hidden = !busy; }
  }

  function showError(root, msg) {
    var e = root.querySelector('.wo-clock__error');
    if (e) { e.textContent = msg || 'Something went wrong.'; e.hidden = false; }
  }

  // -- state transitions (no page reload) -----------------------------------

  function toStateClockedOut(root) {
    var status = root.querySelector('.wo-clock__status');
    if (status) { status.remove(); }
    var btn = root.querySelector('.wo-clock__btn');
    btn.textContent = 'Clock In';
    btn.className = 'wo-clock__btn wo-clock__btn--in';
    btn.setAttribute('data-wo-clock-action', 'in');
    btn.setAttribute('data-wo-id', root.getAttribute('data-wo-id'));
    btn.removeAttribute('data-entry-id');
  }

  function toStateClockedIn(root, entry) {
    var btn = root.querySelector('.wo-clock__btn');
    var status = document.createElement('p');
    status.className = 'wo-clock__status';
    status.textContent = 'Clocked in just now';
    root.insertBefore(status, btn);
    btn.textContent = 'Clock Out';
    btn.className = 'wo-clock__btn wo-clock__btn--out';
    btn.setAttribute('data-wo-clock-action', 'out');
    btn.setAttribute('data-entry-id', entry.id);
    btn.removeAttribute('data-wo-id');
    var alert = root.querySelector('.wo-clock__alert');
    if (alert) { alert.remove(); }
  }

  // -- actions --------------------------------------------------------------

  function doClockIn(root, woId, extra) {
    setBusy(root, true);
    getGps().then(function (gps) {
      var body = Object.assign({}, extra || {}, gps || {});
      post(Drupal.url('clock/in/' + woId), body).then(function (res) {
        setBusy(root, false);
        if (res.status === 'intervention_required') {
          openModal(root, woId, res.open_entries || []);
        } else if (res.status === 'clocked_in') {
          toStateClockedIn(root, res.entry);
          reloadWo();
        } else {
          showError(root, res.message);
        }
      });
    });
  }

  function doClockOut(root, entryId, override) {
    setBusy(root, true);
    getGps().then(function (gps) {
      var body = Object.assign({}, gps || {});
      if (override) { body.override = 1; }
      post(Drupal.url('clock/out/' + entryId), body).then(function (res) {
        setBusy(root, false);
        if (res.status === 'clocked_out') {
          toStateClockedOut(root);
          reloadWo();
        } else if (res.status === 'confirm_long') {
          // Over the single-entry cap — confirm the long entry, then re-submit
          // with the override so the guard accepts it.
          if (window.confirm(res.message)) {
            doClockOut(root, entryId, true);
          }
        } else {
          showError(root, res.message);
        }
      });
    });
  }

  function doClose(root, entryId, endTime, onDone) {
    setBusy(root, true);
    var url = endTime ? Drupal.url('clock/close/' + entryId + '/time') : Drupal.url('clock/close/' + entryId);
    post(url, endTime ? { end_time: endTime } : {}).then(function (res) {
      setBusy(root, false);
      if (res.status === 'closed') {
        // Remove the row from whichever surface it's on.
        document.querySelectorAll('.wo-clock-entry[data-entry-id="' + entryId + '"]').forEach(function (n) { n.remove(); });
        if (onDone) { onDone(res.remaining_open || []); }
        // If the on-page alert region is now empty, drop it.
        var alert = root.querySelector('.wo-clock__alert');
        if (alert && !alert.querySelector('.wo-clock-entry')) { alert.remove(); }
      } else {
        showError(root, res.message);
      }
    });
  }

  // -- modal (intervention) -------------------------------------------------

  function entryRowHtml(e) {
    var propHtml = e.wo_url
      ? '<a class="wo-clock-entry__property wo-clock-entry__property--link" href="' + Drupal.checkPlain(e.wo_url) + '" target="_blank" rel="noopener">' + Drupal.checkPlain(e.property) + '</a>'
      : '<span class="wo-clock-entry__property">' + Drupal.checkPlain(e.property) + '</span>';
    return '<div class="wo-clock-entry" data-entry-id="' + e.entry_id + '">' +
      '<div class="wo-clock-entry__info">' + propHtml +
      '<span class="wo-clock-entry__meta">' + Drupal.checkPlain(e.ago) + ' · started ' + Drupal.checkPlain(e.start_us) + '</span></div>' +
      '<div class="wo-clock-entry__actions">' +
      '<button type="button" class="wo-clock-entry__btn wo-clock-entry__btn--close-now" data-wo-clock-action="close" data-entry-id="' + e.entry_id + '">Close now</button>' +
      '<button type="button" class="wo-clock-entry__btn wo-clock-entry__btn--close-time" data-wo-clock-action="close-time" data-entry-id="' + e.entry_id + '">Close at specific time</button>' +
      '<span class="wo-clock-entry__time-picker" hidden><input type="datetime-local" class="wo-clock-entry__time-input"' +
      (e.start_local ? ' value="' + Drupal.checkPlain(e.start_local) + '"' : '') +
      (e.now_local ? ' max="' + Drupal.checkPlain(e.now_local) + '"' : '') +
      ' aria-label="Clock-out date and time">' +
      '<button type="button" class="wo-clock-entry__btn wo-clock-entry__btn--apply-time" data-wo-clock-action="apply-time" data-entry-id="' + e.entry_id + '">Set</button></span>' +
      '</div></div>';
  }

  function openModal(root, woId, openEntries) {
    var wrap = document.createElement('div');
    wrap.className = 'wo-clock-modal';
    wrap.innerHTML = '<p>Close these open clock-ins, then you\'ll be clocked in here:</p>' +
      openEntries.map(entryRowHtml).join('');

    var dialog = Drupal.dialog(wrap, {
      title: 'Open clock-ins',
      width: '90%',
      buttons: [{
        text: 'Cancel',
        click: function () { dialog.close(); }
      }]
    });
    dialog.showModal();

    wrap.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-wo-clock-action]');
      if (!btn) { return; }
      handleEntryButton(root, btn, function () {
        // After each close, if none remain, dismiss + clock in on target WO.
        if (!wrap.querySelector('.wo-clock-entry')) {
          dialog.close();
          doClockIn(root, woId, { resolved_entries: true, resolved_count: openEntries.length, tap_time: nowHms() });
        }
      });
    });
  }

  function nowHms() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
  }

  // Shared handler for the close / close-at-time buttons on any surface.
  function handleEntryButton(root, btn, onClosed) {
    var action = btn.getAttribute('data-wo-clock-action');
    var entryId = btn.getAttribute('data-entry-id');
    var row = btn.closest('.wo-clock-entry');
    if (action === 'close') {
      doClose(root, entryId, null, onClosed);
    } else if (action === 'close-time') {
      var picker = row.querySelector('.wo-clock-entry__time-picker');
      if (picker) { picker.hidden = !picker.hidden; }
    } else if (action === 'apply-time') {
      var input = row.querySelector('.wo-clock-entry__time-input');
      if (input && input.value) { doClose(root, entryId, input.value, onClosed); }
    }
  }

  Drupal.behaviors.woClock = {
    attach: function (context) {
      once('wo-clock', '.wo-clock', context).forEach(function (root) {
        root.addEventListener('click', function (ev) {
          var btn = ev.target.closest('[data-wo-clock-action]');
          if (!btn || btn.disabled) { return; }
          var action = btn.getAttribute('data-wo-clock-action');
          if (action === 'in') {
            doClockIn(root, btn.getAttribute('data-wo-id') || root.getAttribute('data-wo-id'));
          } else if (action === 'out') {
            doClockOut(root, btn.getAttribute('data-entry-id'));
          } else {
            handleEntryButton(root, btn, null);
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
