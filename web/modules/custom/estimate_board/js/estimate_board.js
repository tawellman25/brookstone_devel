(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.estimateBoardStageWidget = {
    attach: function (context) {

      // Toggle widget visibility.
      context.querySelectorAll('.estimate-stage-toggle').forEach(function (btn) {
        if (btn.dataset.stageWidgetInit) return;
        btn.dataset.stageWidgetInit = 'true';

        btn.addEventListener('click', function () {
          var li = btn.closest('li');
          var widget = li.querySelector('.estimate-inline-stage-widget');
          var isHidden = widget.hasAttribute('hidden');
          widget.toggleAttribute('hidden', !isHidden);
          btn.textContent = isHidden ? '\u25B4' : '\u25BE';
        });
      });

      // Cancel button.
      context.querySelectorAll('.estimate-inline-stage-cancel').forEach(function (btn) {
        if (btn.dataset.cancelInit) return;
        btn.dataset.cancelInit = 'true';

        btn.addEventListener('click', function () {
          var li = btn.closest('li');
          li.querySelector('.estimate-inline-stage-widget').setAttribute('hidden', '');
          li.querySelector('.estimate-stage-toggle').textContent = '\u25BE';
        });
      });

      // Save button — fetch the estimate's form tokens then POST the stage change.
      context.querySelectorAll('.estimate-inline-stage-save').forEach(function (btn) {
        if (btn.dataset.saveInit) return;
        btn.dataset.saveInit = 'true';

        btn.addEventListener('click', function () {
          var li = btn.closest('li');
          var select = li.querySelector('.estimate-inline-stage-select');
          var estimateId = select.dataset.estimateId;
          var canonicalUrl = select.dataset.canonicalUrl;
          var stageTid = select.value;
          var stageLabel = select.options[select.selectedIndex].text;

          btn.textContent = 'Saving...';
          btn.disabled = true;

          // Fetch the estimate page to get form tokens.
          fetch(canonicalUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
              var parser = new DOMParser();
              var doc = parser.parseFromString(html, 'text/html');
              var form = doc.getElementById('estimate-stage-change-form');
              if (!form) throw new Error('Stage form not found on estimate page');

              var formData = new FormData();
              // Copy hidden inputs (form_build_id, form_token, form_id).
              form.querySelectorAll('input[type="hidden"]').forEach(function (input) {
                formData.append(input.name, input.value);
              });
              formData.set('estimate_id', estimateId);
              formData.set('stage', stageTid);
              formData.set('op', 'Update Stage');

              return fetch(canonicalUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
              });
            })
            .then(function () {
              // Update the badge in place.
              var badge = li.querySelector('.estimate-stage-badge');
              badge.className = 'estimate-stage-badge';
              var slug = stageLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
              badge.classList.add('stage-' + slug);
              badge.textContent = stageLabel;

              // Hide widget.
              li.querySelector('.estimate-inline-stage-widget').setAttribute('hidden', '');
              li.querySelector('.estimate-stage-toggle').textContent = '\u25BE';
              btn.textContent = 'Save';
              btn.disabled = false;
            })
            .catch(function (err) {
              console.error('Stage save failed:', err);
              btn.textContent = 'Error';
              btn.disabled = false;
              setTimeout(function () { btn.textContent = 'Save'; }, 2000);
            });
        });
      });
    }
  };

  // Swimlane collapse/expand persistence via localStorage.
  var STORAGE_KEY = 'estimate_board_swimlane_state';

  Drupal.behaviors.estimateBoardSwimlaneState = {
    attach: function (context) {
      context.querySelectorAll('.estimate-board-swimlane details').forEach(function (details) {
        if (details.dataset.swimlaneStateInit) return;
        details.dataset.swimlaneStateInit = 'true';

        var match = details.closest('.estimate-board-swimlane').className.match(/swimlane--([a-z-]+)/);
        if (!match) return;
        var slug = match[1];

        // Restore saved state.
        var saved = localStorage.getItem(STORAGE_KEY + ':' + slug);
        if (saved === 'closed') details.removeAttribute('open');
        if (saved === 'open') details.setAttribute('open', '');

        // Save on toggle.
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

        var items = Array.from(swimlane.querySelectorAll('.pipeline-list > li'));
        if (items.length <= SWIMLANE_ROW_LIMIT) return;

        // Hide items beyond limit.
        items.slice(SWIMLANE_ROW_LIMIT).forEach(function (item) {
          item.classList.add('estimate-board-row--hidden-limit');
        });

        // Add toggle link after the list.
        var list = swimlane.querySelector('.pipeline-list');
        if (!list) return;

        var toggle = document.createElement('div');
        toggle.className = 'estimate-board-row-limit-toggle';
        toggle.innerHTML =
          '<button type="button" class="estimate-board-show-more">' +
          'Show all ' + items.length + ' \u2193</button>';
        list.after(toggle);

        var expanded = false;
        toggle.querySelector('button').addEventListener('click', function () {
          expanded = !expanded;
          items.slice(SWIMLANE_ROW_LIMIT).forEach(function (item) {
            item.classList.toggle('estimate-board-row--hidden-limit', !expanded);
          });
          this.textContent = expanded
            ? 'Show less \u2191'
            : 'Show all ' + items.length + ' \u2193';
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

      // Build filter bar.
      var bar = document.createElement('div');
      bar.id = 'estimate-board-filter-bar';
      bar.className = 'estimate-board-filter-bar';
      bar.innerHTML =
        '<label for="estimate-board-filter-input" class="estimate-board-filter-label">' +
        'Filter estimates:</label>' +
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
          var rows = Array.from(lane.querySelectorAll('.pipeline-list > li'));
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
            // Open swimlanes with matches.
            if (visibleInLane > 0) {
              var details = lane.querySelector('details');
              if (details) details.setAttribute('open', '');
            }
            // Override row limit when filtering.
            lane.querySelectorAll('.estimate-board-row-limit-toggle').forEach(function (t) {
              t.setAttribute('hidden', '');
            });
            lane.querySelectorAll('.estimate-board-row--hidden-limit').forEach(function (r) {
              r.classList.add('estimate-board-row--filter-override');
            });
          } else {
            // Restore row limit toggles.
            lane.querySelectorAll('.estimate-board-row-limit-toggle').forEach(function (t) {
              t.removeAttribute('hidden');
            });
            lane.querySelectorAll('.estimate-board-row--filter-override').forEach(function (r) {
              r.classList.remove('estimate-board-row--filter-override');
            });
          }
        });

        // Update count.
        if (query) {
          countEl.textContent = visibleTotal + ' match' + (visibleTotal !== 1 ? 'es' : '');
          countEl.style.display = 'inline';
        } else {
          countEl.style.display = 'none';
        }
      }
    }
  };

})(jQuery, Drupal);
