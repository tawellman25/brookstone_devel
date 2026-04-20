(function ($, Drupal, drupalSettings) {
  'use strict';

  // ── Help modal ──────────────────────────────────────────────────
  Drupal.behaviors.contractBoardHelp = {
    attach: function (context) {
      var btn = document.getElementById('contract-board-help-btn');
      if (!btn || btn.dataset.helpInit) return;
      btn.dataset.helpInit = 'true';

      var modal = document.getElementById('contract-board-help-modal');
      if (!modal) return;
      var closeBtn = modal.querySelector('.contract-board-help-modal__close');
      var backdrop = modal.querySelector('.contract-board-help-modal__backdrop');

      function openModal() {
        modal.removeAttribute('hidden');
        document.body.classList.add('contract-board-help--open');
        if (closeBtn) closeBtn.focus();
      }

      function closeModal() {
        modal.setAttribute('hidden', '');
        document.body.classList.remove('contract-board-help--open');
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

  // ── Swimlane collapse/expand persistence ────────────────────────
  var STORAGE_KEY = 'contract_board_swimlane_state';

  Drupal.behaviors.contractBoardSwimlaneState = {
    attach: function (context) {
      context.querySelectorAll('.contract-board-swimlane details').forEach(function (details) {
        if (details.dataset.swimlaneStateInit) return;
        details.dataset.swimlaneStateInit = 'true';

        var match = details.closest('.contract-board-swimlane').className.match(/swimlane--([a-z0-9-]+)/);
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

  // ── Row limit per swimlane ──────────────────────────────────────
  var SWIMLANE_ROW_LIMIT = 15;

  Drupal.behaviors.contractBoardRowLimit = {
    attach: function (context) {
      context.querySelectorAll('.contract-board-swimlane').forEach(function (swimlane) {
        if (swimlane.dataset.rowLimitInit) return;
        swimlane.dataset.rowLimitInit = 'true';

        var rows = Array.from(swimlane.querySelectorAll('.contract-board-request-row'));
        if (rows.length <= SWIMLANE_ROW_LIMIT) return;

        rows.slice(SWIMLANE_ROW_LIMIT).forEach(function (row) {
          row.classList.add('contract-board-row--hidden-limit');
        });

        var table = swimlane.querySelector('.contract-board-table');
        if (!table) return;

        var toggle = document.createElement('div');
        toggle.className = 'contract-board-row-limit-toggle';
        toggle.innerHTML =
          '<button type="button" class="contract-board-show-more">' +
          'Show all ' + rows.length + ' \u2193</button>';
        table.after(toggle);

        var expanded = false;
        toggle.querySelector('button').addEventListener('click', function () {
          expanded = !expanded;
          rows.slice(SWIMLANE_ROW_LIMIT).forEach(function (row) {
            row.classList.toggle('contract-board-row--hidden-limit', !expanded);
          });
          this.textContent = expanded
            ? 'Show less \u2191'
            : 'Show all ' + rows.length + ' \u2193';
        });
      });
    }
  };

  // ── Live filter bar ─────────────────────────────────────────────
  Drupal.behaviors.contractBoardFilter = {
    attach: function (context) {
      if (document.getElementById('contract-board-filter-bar')) return;

      var firstSwimlane = document.querySelector('.contract-board-swimlane');
      if (!firstSwimlane) return;

      var bar = document.createElement('div');
      bar.id = 'contract-board-filter-bar';
      bar.className = 'contract-board-filter-bar';
      bar.innerHTML =
        '<label for="contract-board-filter-input" class="contract-board-filter-label">' +
        'Filter:</label>' +
        '<input type="search" id="contract-board-filter-input" ' +
        'class="contract-board-filter-input" ' +
        'placeholder="Type a name, service, or property..." autocomplete="off">' +
        '<span class="contract-board-filter-count"></span>' +
        '<button type="button" class="contract-board-filter-clear" hidden>' +
        '\u2715 Clear</button>';

      firstSwimlane.before(bar);

      var input = bar.querySelector('#contract-board-filter-input');
      var countEl = bar.querySelector('.contract-board-filter-count');
      var clearBtn = bar.querySelector('.contract-board-filter-clear');

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

        document.querySelectorAll('.contract-board-swimlane').forEach(function (lane) {
          var rows = Array.from(lane.querySelectorAll('.contract-board-request-row'));
          var visibleInLane = 0;

          rows.forEach(function (row) {
            var text = row.textContent.toLowerCase();
            var matches = !query || text.indexOf(query) !== -1;
            row.classList.toggle('contract-board-row--filtered-out', !matches);
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
            lane.querySelectorAll('.contract-board-row-limit-toggle').forEach(function (t) {
              t.setAttribute('hidden', '');
            });
            lane.querySelectorAll('.contract-board-row--hidden-limit').forEach(function (r) {
              r.classList.add('contract-board-row--filter-override');
            });
          } else {
            lane.querySelectorAll('.contract-board-row-limit-toggle').forEach(function (t) {
              t.removeAttribute('hidden');
            });
            lane.querySelectorAll('.contract-board-row--filter-override').forEach(function (r) {
              r.classList.remove('contract-board-row--filter-override');
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

  // ── Helper: update swimlane badge count ──────────────────────────
  function updateSwimlaneBadge(swimlane, delta) {
    var badge = swimlane ? swimlane.querySelector('.contract-board-badge') : null;
    if (badge) {
      var current = parseInt(badge.textContent.trim(), 10) || 0;
      var newCount = Math.max(0, current + delta);
      badge.textContent = newCount;
      if (newCount === 0) {
        badge.classList.add('contract-board-badge--empty');
      } else {
        badge.classList.remove('contract-board-badge--empty');
      }
    }
  }

  // ── Helper: insert row into destination swimlane ─────────────────
  function insertRowIntoSwimlane(swimlane, rowHtml) {
    var tbody = swimlane.querySelector('tbody');

    if (!tbody) {
      var emptyMsg = swimlane.querySelector('.contract-board-empty');
      if (emptyMsg) emptyMsg.remove();

      var details = swimlane.querySelector('details');
      var target = details || swimlane;

      var table = document.createElement('table');
      table.className = 'contract-board-table';
      table.innerHTML =
        '<thead><tr>' +
        '<th>Client</th><th>Property</th><th>Services</th>' +
        '<th>Year</th><th>Age</th><th>Actions</th>' +
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

    if (typeof Drupal !== 'undefined' && Drupal.attachBehaviors) {
      Drupal.attachBehaviors(newRow);
    }

    requestAnimationFrame(function () {
      newRow.style.transition = 'opacity 0.3s';
      newRow.style.opacity = '1';
    });
  }

  // ── Helper: build row HTML from server data ──────────────────────
  function buildRowHtml(data) {
    var escHtml = function (s) {
      var div = document.createElement('div');
      div.textContent = s || '';
      return div.innerHTML;
    };

    var nextBtn = data.next_action
      ? '<button type="button" class="contract-board-status-btn contract-board-status-btn--next contract-board-status-btn--' + escHtml(data.new_status_slug) + '" ' +
        'data-contract-id="' + data.contract_id + '" ' +
        'data-action-id="' + escHtml(data.next_action) + '" ' +
        'data-status-label="' + escHtml(data.next_label) + '" ' +
        'title="Advance to: ' + escHtml(data.next_label) + '">' +
        escHtml(data.next_label) + ' \u2192</button>'
      : '';

    var cancelBtn =
      '<button type="button" class="contract-board-status-btn contract-board-status-btn--cancel" ' +
      'data-contract-id="' + data.contract_id + '" ' +
      'data-action-id="contract_residential_mark_canceled" ' +
      'data-confirm="cancel" ' +
      'title="Cancel">\u2715</button>';

    var ageClass = data.age_days > 7 ? ' contract-board-age--warning' : '';

    return '<tr class="contract-board-request-row" data-contract-id="' + data.contract_id + '">' +
      '<td><a href="' + escHtml(data.url) + '" target="_blank">' + escHtml(data.client_name) + '</a></td>' +
      '<td>' + escHtml(data.property) + '</td>' +
      '<td>' + escHtml(data.services) + '</td>' +
      '<td>' + data.year + '</td>' +
      '<td class="' + ageClass + '">' + data.age_days + 'd</td>' +
      '<td class="contract-board-actions-cell">' + nextBtn + cancelBtn + '</td>' +
      '</tr>';
  }

  // ── Off-board slugs ──────────────────────────────────────────────
  var OFF_BOARD_SLUGS = ['completed-for-the-year', 'canceled'];

  // ── Status action buttons ────────────────────────────────────────
  Drupal.behaviors.contractBoardStatusButtons = {
    attach: function (context) {
      context.querySelectorAll('.contract-board-status-btn').forEach(function (btn) {
        if (btn.dataset.statusBtnInit) return;
        btn.dataset.statusBtnInit = 'true';

        btn.addEventListener('click', function () {
          var contractId = btn.dataset.contractId;
          var actionId = btn.dataset.actionId;
          var originalText = btn.textContent;
          var extraData = {};

          // Cancel action — prompt for reason.
          if (btn.dataset.confirm === 'cancel') {
            var reason = window.prompt(
              'Cancellation reason (required for non-administrators):\n' +
              'Briefly explain why this contract is being canceled.'
            );
            if (reason === null) return;
            extraData.cancellation_reason = reason;
          }

          btn.disabled = true;
          btn.textContent = '...';

          var csrfToken = drupalSettings.contractBoard ? drupalSettings.contractBoard.csrfToken : '';

          fetch('/admin/office/contracts/board/status-update', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(Object.assign({
              contract_id: contractId,
              action_id: actionId,
            }, extraData)),
            credentials: 'same-origin',
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success) {
              var row = btn.closest('tr');
              var sourceSwimlane = btn.closest('.contract-board-swimlane');

              if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(function () {
                  row.remove();
                  if (sourceSwimlane) updateSwimlaneBadge(sourceSwimlane, -1);

                  if (!data.off_board) {
                    var destSwimlane = document.querySelector('.contract-board-swimlane--' + data.new_status_slug);
                    if (destSwimlane) {
                      var rowHtml = buildRowHtml(data);
                      insertRowIntoSwimlane(destSwimlane, rowHtml);
                      updateSwimlaneBadge(destSwimlane, +1);
                      var destDetails = destSwimlane.querySelector('details');
                      if (destDetails && !destDetails.open) destDetails.setAttribute('open', '');
                    }
                  }
                }, 300);
              }
            } else {
              btn.disabled = false;
              btn.textContent = originalText;
              alert('Action failed: ' + (data.error || 'Unknown error'));
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
