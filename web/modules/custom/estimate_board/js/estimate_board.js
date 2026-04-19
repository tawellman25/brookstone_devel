(function ($, Drupal, drupalSettings) {
  'use strict';

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

        document.querySelectorAll('.estimate-board-swimlane').forEach(function (lane) {
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

  // ── Status action buttons (← Back, Next →, ✕ Decline) ─────────
  Drupal.behaviors.estimateBoardStatusButtons = {
    attach: function (context) {
      context.querySelectorAll('.estimate-board-status-btn').forEach(function (btn) {
        if (btn.dataset.statusBtnInit) return;
        btn.dataset.statusBtnInit = 'true';

        btn.addEventListener('click', function () {
          var confirmMsg = btn.dataset.confirm;
          if (confirmMsg && !window.confirm(confirmMsg)) return;

          var requestId = btn.dataset.requestId;
          var statusTid = btn.dataset.statusTid;
          var originalText = btn.textContent;

          btn.disabled = true;
          btn.textContent = '...';

          // Get CSRF token from Drupal's session token endpoint.
          fetch('/session/token', { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (csrfToken) {
              return fetch('/admin/office/estimates/status-update', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                  estimate_request_id: requestId,
                  new_status_tid: parseInt(statusTid, 10),
                }),
                credentials: 'same-origin',
              });
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
                    var badge = swimlane ? swimlane.querySelector('.estimate-board-badge') : null;
                    if (badge) {
                      var current = parseInt(badge.textContent, 10);
                      badge.textContent = Math.max(0, current - 1);
                    }
                  }, 300);
                }
              } else {
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Status update failed: ' + (data.error || 'Unknown error'));
              }
            })
            .catch(function () {
              btn.disabled = false;
              btn.textContent = originalText;
              alert('Network error. Please try again.');
            });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
