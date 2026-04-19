(function ($, Drupal, drupalSettings) {
  'use strict';

  // ── Pipeline help modal ─────────────────────────────────────────
  Drupal.behaviors.estimateBoardHelp = {
    attach: function (context) {
      var btn = context.querySelector
        ? context.querySelector('#estimate-board-help-btn')
        : document.getElementById('estimate-board-help-btn');
      if (!btn || btn.dataset.helpInit) return;
      btn.dataset.helpInit = 'true';

      var modal = document.getElementById('estimate-board-help-modal');
      if (!modal) return;
      var closeBtn = modal.querySelector('.estimate-board-help-modal__close');
      var backdrop = modal.querySelector('.estimate-board-help-modal__backdrop');

      function openModal() {
        modal.removeAttribute('hidden');
        document.body.classList.add('estimate-board-help--open');
        if (closeBtn) closeBtn.focus();
      }

      function closeModal() {
        modal.setAttribute('hidden', '');
        document.body.classList.remove('estimate-board-help--open');
        btn.focus();
      }

      btn.addEventListener('click', openModal);
      if (closeBtn) closeBtn.addEventListener('click', closeModal);
      if (backdrop) backdrop.addEventListener('click', closeModal);

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
          closeModal();
        }
      });
    }
  };

  // ── Swimlane collapse/expand persistence ───────────────────────
  var STORAGE_KEY = 'estimate_board_swimlane_state';

  Drupal.behaviors.estimateBoardSwimlaneState = {
    attach: function (context) {
      context.querySelectorAll('.estimate-board-swimlane details').forEach(function (details) {
        if (details.dataset.swimlaneStateInit) return;
        details.dataset.swimlaneStateInit = 'true';

        var match = details.closest('.estimate-board-swimlane').className.match(/swimlane--([a-z-]+)/);
        if (!match) return;
        var slug = match[1];

        var saved = localStorage.getItem(STORAGE_KEY + ':' + slug);
        if (saved === 'closed') details.removeAttribute('open');
        if (saved === 'open') details.setAttribute('open', '');

        details.addEventListener('toggle', function () {
          localStorage.setItem(STORAGE_KEY + ':' + slug, details.open ? 'open' : 'closed');
        });
      });
    }
  };

  // ── Row limit per swimlane ─────────────────────────────────────
  var SWIMLANE_ROW_LIMIT = 15;

  Drupal.behaviors.estimateBoardRowLimit = {
    attach: function (context) {
      context.querySelectorAll('.estimate-board-swimlane').forEach(function (swimlane) {
        if (swimlane.dataset.rowLimitInit) return;
        swimlane.dataset.rowLimitInit = 'true';

        var rows = Array.from(swimlane.querySelectorAll('.estimate-board-request-row'));
        if (rows.length <= SWIMLANE_ROW_LIMIT) return;

        rows.slice(SWIMLANE_ROW_LIMIT).forEach(function (row) {
          row.classList.add('estimate-board-row--hidden-limit');
        });

        var table = swimlane.querySelector('.estimate-board-table');
        if (!table) return;

        var toggle = document.createElement('div');
        toggle.className = 'estimate-board-row-limit-toggle';
        toggle.innerHTML =
          '<button type="button" class="estimate-board-show-more">' +
          'Show all ' + rows.length + ' \u2193</button>';
        table.after(toggle);

        var expanded = false;
        toggle.querySelector('button').addEventListener('click', function () {
          expanded = !expanded;
          rows.slice(SWIMLANE_ROW_LIMIT).forEach(function (row) {
            row.classList.toggle('estimate-board-row--hidden-limit', !expanded);
          });
          this.textContent = expanded
            ? 'Show less \u2191'
            : 'Show all ' + rows.length + ' \u2193';
        });
      });
    }
  };

  // ── Live filter bar ────────────────────────────────────────────
  Drupal.behaviors.estimateBoardFilter = {
    attach: function (context) {
      if (document.getElementById('estimate-board-filter-bar')) return;

      var firstSwimlane = document.querySelector('.estimate-board-swimlane');
      if (!firstSwimlane) return;

      var bar = document.createElement('div');
      bar.id = 'estimate-board-filter-bar';
      bar.className = 'estimate-board-filter-bar';
      bar.innerHTML =
        '<label for="estimate-board-filter-input" class="estimate-board-filter-label">' +
        'Filter:</label>' +
        '<input type="search" id="estimate-board-filter-input" ' +
        'class="estimate-board-filter-input" ' +
        'placeholder="Type a name, service, or property..." autocomplete="off">' +
        '<span class="estimate-board-filter-count"></span>' +
        '<button type="button" class="estimate-board-filter-clear" hidden>' +
        '\u2715 Clear</button>';

      firstSwimlane.before(bar);

      var input = bar.querySelector('#estimate-board-filter-input');
      var countEl = bar.querySelector('.estimate-board-filter-count');
      var clearBtn = bar.querySelector('.estimate-board-filter-clear');

      input.addEventListener('input', function () {
        var query = this.value.trim().toLowerCase();
        clearBtn.toggleAttribute('hidden', query.length === 0);
        applyFilter(query);
      });

      clearBtn.addEventListener('click', function () {
        input.value = '';
        this.setAttribute('hidden', '');
        applyFilter('');
      });

      function applyFilter(query) {
        var visibleTotal = 0;

        document.querySelectorAll('.estimate-board-swimlane, .estimate-board-on-hold').forEach(function (lane) {
          var rows = Array.from(lane.querySelectorAll('.estimate-board-request-row'));
          var visibleInLane = 0;

          rows.forEach(function (row) {
            var text = row.textContent.toLowerCase();
            var matches = !query || text.indexOf(query) !== -1;
            row.classList.toggle('estimate-board-row--filtered-out', !matches);
            if (matches) {
              visibleInLane++;
              visibleTotal++;
            }
          });

          if (query) {
            if (visibleInLane > 0) {
              var details = lane.querySelector('details');
              if (details) details.setAttribute('open', '');
            }
            lane.querySelectorAll('.estimate-board-row-limit-toggle').forEach(function (t) {
              t.setAttribute('hidden', '');
            });
            lane.querySelectorAll('.estimate-board-row--hidden-limit').forEach(function (r) {
              r.classList.add('estimate-board-row--filter-override');
            });
          } else {
            lane.querySelectorAll('.estimate-board-row-limit-toggle').forEach(function (t) {
              t.removeAttribute('hidden');
            });
            lane.querySelectorAll('.estimate-board-row--filter-override').forEach(function (r) {
              r.classList.remove('estimate-board-row--filter-override');
            });
          }
        });

        if (query) {
          countEl.textContent = visibleTotal + ' match' + (visibleTotal !== 1 ? 'es' : '');
          countEl.style.display = 'inline';
        } else {
          countEl.style.display = 'none';
        }
      }
    }
  };

  // ── Helper: update swimlane badge count ─────────────────────────
  function updateSwimlaneBadge(swimlane, delta) {
    var badge = swimlane ? swimlane.querySelector('.estimate-board-badge') : null;
    if (badge) {
      var current = parseInt(badge.textContent.trim(), 10) || 0;
      var newCount = Math.max(0, current + delta);
      badge.textContent = newCount;
      // Toggle empty class.
      if (newCount === 0) {
        badge.classList.add('estimate-board-badge--empty');
      } else {
        badge.classList.remove('estimate-board-badge--empty');
      }
    }
  }

  // ── Helper: insert row HTML into destination swimlane ───────────
  function insertRowIntoSwimlane(swimlane, rowHtml) {
    var tbody = swimlane.querySelector('tbody');

    // If swimlane was empty (no table), create the table structure.
    if (!tbody) {
      var emptyMsg = swimlane.querySelector('.estimate-board-empty');
      if (emptyMsg) emptyMsg.remove();

      var details = swimlane.querySelector('details');
      var target = details || swimlane;

      var table = document.createElement('table');
      table.className = 'estimate-board-table';
      table.innerHTML =
        '<thead><tr>' +
        '<th>Client</th><th>Property</th><th>Services</th>' +
        '<th>Coordinator</th><th>Age</th><th>Estimates</th><th>Actions</th>' +
        '</tr></thead><tbody></tbody>';
      target.appendChild(table);
      tbody = table.querySelector('tbody');
    }

    var temp = document.createElement('tbody');
    temp.innerHTML = rowHtml;
    var newRow = temp.firstElementChild;
    if (!newRow) return;

    newRow.style.opacity = '0';
    tbody.appendChild(newRow);

    // Attach Drupal behaviors to the new row so buttons work.
    if (typeof Drupal !== 'undefined' && Drupal.attachBehaviors) {
      Drupal.attachBehaviors(newRow);
    }

    // Fade in.
    requestAnimationFrame(function () {
      newRow.style.transition = 'opacity 0.3s';
      newRow.style.opacity = '1';
    });
  }

  // ── Helper: build row HTML from server data ────────────────────
  function buildRowHtml(data) {
    var escHtml = function (s) {
      var div = document.createElement('div');
      div.textContent = s || '';
      return div.innerHTML;
    };

    var backBtn = data.prev_status_tid
      ? '<button type="button" class="estimate-board-status-btn estimate-board-status-btn--back" ' +
        'data-request-id="' + data.request_id + '" ' +
        'data-status-tid="' + data.prev_status_tid + '" ' +
        'data-status-label="' + escHtml(data.prev_status_label) + '" ' +
        'title="Move back to: ' + escHtml(data.prev_status_label) + '">' +
        '\u2190 Back</button>'
      : '';

    var nextBtn = data.next_status_tid
      ? '<button type="button" class="estimate-board-status-btn estimate-board-status-btn--next estimate-board-status-btn--' + data.new_status_slug + '" ' +
        'data-request-id="' + data.request_id + '" ' +
        'data-status-tid="' + data.next_status_tid + '" ' +
        'data-status-label="' + escHtml(data.next_status_label) + '" ' +
        'title="Advance to: ' + escHtml(data.next_status_label) + '">' +
        escHtml(data.next_status_label) + ' \u2192</button>'
      : '';

    var declineBtn =
      '<button type="button" class="estimate-board-status-btn estimate-board-status-btn--decline" ' +
      'data-request-id="' + data.request_id + '" ' +
      'data-status-tid="' + data.decline_tid + '" ' +
      'data-status-label="Declined" ' +
      'data-confirm="Mark this estimate request as Declined?" ' +
      'title="Decline">\u2715</button>';

    var holdBtn =
      '<button type="button" class="estimate-board-status-btn estimate-board-status-btn--hold" ' +
      'data-request-id="' + data.request_id + '" ' +
      'data-action="hold" ' +
      'title="Put on hold">\u23F8</button>';

    var estimatesHtml = '';
    if (data.estimates && data.estimates.length) {
      data.estimates.forEach(function (est) {
        estimatesHtml +=
          '<a href="' + escHtml(est.url) + '" target="_blank" class="estimate-board-est-link">' +
          escHtml(est.label) +
          (est.total ? ' <span class="estimate-board-est-total">' + escHtml(est.total) + '</span>' : '') +
          '</a>';
      });
    }

    var ageClass = data.age_days > 7 ? ' estimate-board-age--warning' : '';

    return '<tr class="estimate-board-request-row" data-request-id="' + data.request_id + '">' +
      '<td><a href="' + escHtml(data.url) + '" target="_blank">' + escHtml(data.client_name) + '</a></td>' +
      '<td>' + escHtml(data.property) + '</td>' +
      '<td>' + escHtml(data.services) + '</td>' +
      '<td>' + escHtml(data.coordinator) + '</td>' +
      '<td class="' + ageClass + '">' + data.age_days + 'd</td>' +
      '<td class="estimate-board-estimates-cell">' + estimatesHtml + '</td>' +
      '<td class="estimate-board-actions-cell">' + backBtn + nextBtn + declineBtn + holdBtn + '</td>' +
      '</tr>';
  }

  // ── On Hold helpers ─────────────────────────────────────────────
  function insertRowIntoOnHold(data) {
    var onHoldSection = document.querySelector('.estimate-board-on-hold');
    if (!onHoldSection) return;

    onHoldSection.style.display = '';
    var tbody = onHoldSection.querySelector('tbody');
    if (!tbody) return;

    var rowHtml = buildOnHoldRowHtml(data);
    var temp = document.createElement('tbody');
    temp.innerHTML = rowHtml;
    var newRow = temp.firstElementChild;
    if (!newRow) return;

    newRow.style.opacity = '0';
    tbody.appendChild(newRow);
    if (typeof Drupal !== 'undefined' && Drupal.attachBehaviors) {
      Drupal.attachBehaviors(newRow);
    }
    requestAnimationFrame(function () {
      newRow.style.transition = 'opacity 0.3s';
      newRow.style.opacity = '1';
    });
  }

  function buildOnHoldRowHtml(data) {
    var escHtml = function (s) {
      var div = document.createElement('div');
      div.textContent = s || '';
      return div.innerHTML;
    };
    return '<tr class="estimate-board-request-row estimate-board-request-row--on-hold" data-request-id="' + data.request_id + '">' +
      '<td><a href="' + escHtml(data.url) + '" target="_blank">' + escHtml(data.client_name) + '</a></td>' +
      '<td><span class="estimate-board-hold-stage">' + escHtml(data.current_status_label) + '</span></td>' +
      '<td>' + escHtml(data.property) + '</td>' +
      '<td>' + escHtml(data.services) + '</td>' +
      '<td>' + escHtml(data.coordinator) + '</td>' +
      '<td class="estimate-board-hold-date">' + (data.hold_until || 'Indefinite') + '</td>' +
      '<td class="estimate-board-actions-cell">' +
        '<button type="button" class="estimate-board-status-btn estimate-board-status-btn--lift-hold" ' +
        'data-request-id="' + data.request_id + '" data-action="lift_hold" ' +
        'data-status-slug="' + escHtml(data.current_status_slug) + '" ' +
        'title="Lift hold — return to pipeline">\u25B6 Resume</button>' +
      '</td></tr>';
  }

  function updateOnHoldBadge(delta) {
    var badge = document.querySelector('.estimate-board-on-hold .estimate-board-badge');
    if (badge) {
      var current = parseInt(badge.textContent.trim(), 10) || 0;
      badge.textContent = Math.max(0, current + delta);
    }
  }

  // ── Status action buttons (← Back, Next →, ✕ Decline, ⏸ Hold, ▶ Resume)
  var OFF_BOARD_SLUGS = ['declined', 'converted', 'accepted'];

  Drupal.behaviors.estimateBoardStatusButtons = {
    attach: function (context) {
      context.querySelectorAll('.estimate-board-status-btn').forEach(function (btn) {
        if (btn.dataset.statusBtnInit) return;
        btn.dataset.statusBtnInit = 'true';

        btn.addEventListener('click', function () {
          var action = btn.dataset.action || 'status';
          var confirmMsg = btn.dataset.confirm;
          if (confirmMsg && !window.confirm(confirmMsg)) return;

          var requestId = btn.dataset.requestId;
          var originalText = btn.textContent;

          // Handle hold action — prompt for date.
          if (action === 'hold') {
            var holdUntil = window.prompt(
              'Hold until date (optional, format MM-DD-YYYY):\nLeave blank for indefinite hold.',
              ''
            );
            if (holdUntil === null) return;
            // Convert MM-DD-YYYY to YYYY-MM-DD for the server.
            if (holdUntil) {
              var match = holdUntil.match(/^(\d{2})-(\d{2})-(\d{4})$/);
              if (!match) {
                alert('Invalid date format. Use MM-DD-YYYY or leave blank.');
                return;
              }
              holdUntil = match[3] + '-' + match[1] + '-' + match[2];
            }

            btn.disabled = true;
            btn.textContent = '...';

            var csrfToken = drupalSettings.estimateBoard ? drupalSettings.estimateBoard.csrfToken : '';
            fetch('/admin/office/estimates/status-update', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
              body: JSON.stringify({ action: 'hold', estimate_request_id: requestId, hold_until: holdUntil || null }),
              credentials: 'same-origin',
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.success) {
                var row = btn.closest('tr');
                var swimlane = btn.closest('.estimate-board-swimlane');
                if (row) {
                  row.style.transition = 'opacity 0.3s';
                  row.style.opacity = '0';
                  setTimeout(function () {
                    row.remove();
                    updateSwimlaneBadge(swimlane, -1);
                    insertRowIntoOnHold(data);
                    updateOnHoldBadge(+1);
                  }, 300);
                }
              } else {
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Hold failed: ' + (data.error || 'Unknown error'));
              }
            })
            .catch(function () { btn.disabled = false; btn.textContent = originalText; alert('Network error.'); });
            return;
          }

          // Handle lift_hold action.
          if (action === 'lift_hold') {
            btn.disabled = true;
            btn.textContent = '...';

            var csrfToken2 = drupalSettings.estimateBoard ? drupalSettings.estimateBoard.csrfToken : '';
            fetch('/admin/office/estimates/status-update', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken2 },
              body: JSON.stringify({ action: 'lift_hold', estimate_request_id: requestId }),
              credentials: 'same-origin',
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.success) {
                var row = btn.closest('tr');
                if (row) {
                  row.style.transition = 'opacity 0.3s';
                  row.style.opacity = '0';
                  setTimeout(function () {
                    row.remove();
                    updateOnHoldBadge(-1);

                    var destSwimlane = document.querySelector('.estimate-board-swimlane--' + data.new_status_slug);
                    if (destSwimlane) {
                      var rowHtml = buildRowHtml(data);
                      insertRowIntoSwimlane(destSwimlane, rowHtml);
                      updateSwimlaneBadge(destSwimlane, +1);
                      var destDetails = destSwimlane.querySelector('details');
                      if (destDetails && !destDetails.open) destDetails.setAttribute('open', '');
                    }
                  }, 300);
                }
              } else {
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Resume failed: ' + (data.error || 'Unknown error'));
              }
            })
            .catch(function () { btn.disabled = false; btn.textContent = originalText; alert('Network error.'); });
            return;
          }

          // Default: status change.
          var statusTid = btn.dataset.statusTid;
          btn.disabled = true;
          btn.textContent = '...';

          var csrfToken3 = drupalSettings.estimateBoard ? drupalSettings.estimateBoard.csrfToken : '';
          fetch('/admin/office/estimates/status-update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken3 },
            body: JSON.stringify({ action: 'status', estimate_request_id: requestId, new_status_tid: parseInt(statusTid, 10) }),
            credentials: 'same-origin',
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.success) {
                var row = btn.closest('tr');
                var sourceSwimlane = btn.closest('.estimate-board-swimlane');

                if (row) {
                  row.style.transition = 'opacity 0.3s';
                  row.style.opacity = '0';
                  setTimeout(function () {
                    row.remove();
                    updateSwimlaneBadge(sourceSwimlane, -1);

                    if (OFF_BOARD_SLUGS.indexOf(data.new_status_slug) !== -1) return;

                    var destSwimlane = document.querySelector('.estimate-board-swimlane--' + data.new_status_slug);
                    if (destSwimlane) {
                      var rowHtml = buildRowHtml(data);
                      insertRowIntoSwimlane(destSwimlane, rowHtml);
                      updateSwimlaneBadge(destSwimlane, +1);
                      var destDetails = destSwimlane.querySelector('details');
                      if (destDetails && !destDetails.open) destDetails.setAttribute('open', '');
                    }
                  }, 300);
                }
              } else {
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Status update failed: ' + (data.error || 'Unknown error'));
              }
            })
            .catch(function () { btn.disabled = false; btn.textContent = originalText; alert('Network error.'); });
        });
      });
    }
  };

  // ── Estimate stage buttons (My Estimates board) ────────────────
  Drupal.behaviors.estimateBoardEstimateStage = {
    attach: function (context) {
      context.querySelectorAll('[data-action="estimate_stage"]').forEach(function (btn) {
        if (btn.dataset.estStageInit) return;
        btn.dataset.estStageInit = 'true';

        btn.addEventListener('click', function () {
          var confirmMsg = btn.dataset.confirm;
          if (confirmMsg && !window.confirm(confirmMsg)) return;

          var estimateId = btn.dataset.estimateId;
          var stageTid   = btn.dataset.stageTid;
          var origText   = btn.textContent;

          btn.disabled = true;
          btn.textContent = '...';

          var csrfToken = drupalSettings.estimateBoard
            ? drupalSettings.estimateBoard.csrfToken : '';

          fetch('/admin/office/estimates/estimate-stage-update', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
              estimate_id:   estimateId,
              new_stage_tid: stageTid,
            }),
            credentials: 'same-origin',
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.scope_required) {
              btn.disabled = false;
              btn.textContent = origText;
              alert(
                'Scope Summary needs to be updated before this estimate ' +
                'can advance past In Preparation.\n\n' +
                'Open the estimate to update the scope summary.'
              );
              return;
            }
            if (data.success) {
              var row = btn.closest('tr');
              var swimlane = btn.closest('.estimate-board-stage-swimlane');

              row.style.transition = 'opacity 0.3s';
              row.style.opacity = '0';

              setTimeout(function () {
                row.remove();

                // Update source swimlane badge.
                updateSwimlaneBadge(swimlane, -1);

                if (!data.off_board) {
                  // Find destination swimlane by slug.
                  var dest = document.querySelector(
                    '.estimate-board-stage-swimlane--' + data.new_stage_slug
                  );
                  if (dest) {
                    var details = dest.querySelector('details');
                    if (details && !details.open) {
                      details.setAttribute('open', '');
                    }
                    updateSwimlaneBadge(dest, +1);
                  }
                }
              }, 300);
            } else {
              btn.disabled = false;
              btn.textContent = origText;
              alert('Stage update failed: ' +
                    (data.message || 'Unknown error'));
            }
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Network error. Please try again.');
          });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
