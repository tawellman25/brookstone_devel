/**
 * @file
 * BOS Sprinkler Bulk Scheduling Tool JS.
 *
 * Handles:
 * - Checkbox selection (individual + select all)
 * - Live selected count updates
 * - AJAX save to /admin/office/work-orders/sprinkler/scheduling/save
 * - Row state updates after successful save
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.bosSprinklerScheduling = {
    attach: function (context, settings) {
      const wrap = context.querySelector('.bos-sched-wrap');
      if (!wrap || wrap.dataset.init) return;
      wrap.dataset.init = '1';

      const saveUrl    = (settings.bosSprinklerScheduling && settings.bosSprinklerScheduling.saveUrl) || '';
      const selCount   = wrap.querySelector('#bos-sched-sel-count');
      const panelCount = wrap.querySelector('#bos-sched-panel-count');
      const selectAll  = wrap.querySelector('#bos-sched-select-all');
      const selLabel   = wrap.querySelector('#bos-sched-select-label');
      const saveBtn    = wrap.querySelector('#bos-sched-save');
      const clearBtn   = wrap.querySelector('#bos-sched-clear');
      const msgEl      = wrap.querySelector('#bos-sched-message');
      const dateInput  = wrap.querySelector('#bos-sched-date');
      const techSelect = wrap.querySelector('#bos-sched-tech');
      const orderInput = wrap.querySelector('#bos-sched-order');

      function getChecked() {
        return Array.from(wrap.querySelectorAll('.bos-sched-cb:checked'));
      }

      function updateCounts() {
        const checked = getChecked();
        const n = checked.length;
        if (selCount) selCount.textContent = n;
        if (panelCount) panelCount.textContent = n;
        if (selLabel) selLabel.textContent = n > 0 ? n + ' selected' : '';
        if (saveBtn) saveBtn.disabled = n === 0;
        // Update row highlight.
        wrap.querySelectorAll('.bos-sched-cb').forEach(function(cb) {
          const row = cb.closest('.bos-sched-row');
          if (row) row.classList.toggle('selected', cb.checked);
        });
      }

      // Individual checkboxes.
      wrap.addEventListener('change', function(e) {
        if (e.target.classList.contains('bos-sched-cb')) {
          updateCounts();
        }
      });

      // Select all.
      if (selectAll) {
        selectAll.addEventListener('change', function() {
          wrap.querySelectorAll('.bos-sched-cb').forEach(function(cb) {
            const row = cb.closest('.bos-sched-row');
            if (row && !row.classList.contains('just-scheduled')) {
              cb.checked = selectAll.checked;
            }
          });
          updateCounts();
        });
      }

      // Clear selection.
      if (clearBtn) {
        clearBtn.addEventListener('click', function() {
          wrap.querySelectorAll('.bos-sched-cb').forEach(function(cb) {
            cb.checked = false;
          });
          if (selectAll) selectAll.checked = false;
          updateCounts();
        });
      }

      // Save.
      if (saveBtn) {
        saveBtn.addEventListener('click', function() {
          const checked = getChecked();
          if (checked.length === 0) return;

          const date       = dateInput ? dateInput.value : '';
          const tech       = techSelect ? techSelect.value : '';
          const startOrder = orderInput ? parseInt(orderInput.value, 10) || 1 : 1;

          if (!date) {
            showMessage('Please select a date.', 'error');
            return;
          }
          if (!tech) {
            showMessage('Please select a technician.', 'error');
            return;
          }

          const woIds = checked.map(function(cb) { return parseInt(cb.value, 10); });

          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving...';

          // Fetch CSRF token first, then POST.
          fetch('/session/token')
            .then(function(r) { return r.text(); })
            .then(function(token) {
              return fetch(saveUrl, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-Token': token.trim(),
                },
                body: JSON.stringify({
                  wo_ids:       woIds,
                  date:         date,
                  teammate_uid: tech,
                  start_order:  startOrder,
                }),
              });
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (data.success) {
                showMessage(data.message, 'success');
                // Mark rows as scheduled.
                checked.forEach(function(cb) {
                  cb.checked = false;
                  const row = cb.closest('.bos-sched-row');
                  if (row) {
                    row.classList.remove('selected');
                    row.classList.add('just-scheduled');
                    cb.disabled = true;
                  }
                });
                if (selectAll) selectAll.checked = false;
                updateCounts();
                // Update start order for next batch.
                if (orderInput) {
                  orderInput.value = startOrder + woIds.length;
                }
              }
              else {
                showMessage(data.message || 'An error occurred.', 'error');
              }
            })
            .catch(function(err) {
              showMessage('Network error — please try again.', 'error');
              console.error(err);
            })
            .finally(function() {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Schedule selected WOs';
            });
        });
      }

      function showMessage(text, type) {
        if (!msgEl) return;
        msgEl.textContent = text;
        msgEl.className = 'bos-sched-message bos-sched-message--' + type;
        msgEl.style.display = 'block';
        setTimeout(function() { msgEl.style.display = 'none'; }, 5000);
      }

      updateCounts();
    }
  };

})(Drupal, drupalSettings);
