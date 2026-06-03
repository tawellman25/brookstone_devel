(function (Drupal, once) {
  'use strict';

  function setFieldValue(scope, name, value) {
    var input = scope.querySelector('input[name="' + name + '"]');
    if (!input) {
      return false;
    }
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function setBySuffix(scope, suffix, value) {
    var inputs = scope.querySelectorAll('input[type="date"], input[type="time"], input[type="text"]');
    for (var i = 0; i < inputs.length; i++) {
      var n = inputs[i].getAttribute('name');
      if (n && n.indexOf(suffix, n.length - suffix.length) !== -1) {
        inputs[i].value = value;
        inputs[i].dispatchEvent(new Event('input', { bubbles: true }));
        inputs[i].dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
    }
    return false;
  }

  function flashSummary(btn) {
    var container = btn.closest('.bos-wtc-copy-from-first');
    if (!container) {
      return;
    }
    container.classList.add('bos-wtc-copied');
    setTimeout(function () {
      container.classList.remove('bos-wtc-copied');
    }, 1500);
  }

  Drupal.behaviors.bosWtcCopyFromFirst = {
    attach: function (context) {
      once('bos-wtc-copy-btn', '.bos-wtc-copy-btn', context).forEach(
        function (btn) {
          btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var startDate = btn.getAttribute('data-start-date');
            var startTime = btn.getAttribute('data-start-time');
            var endDate = btn.getAttribute('data-end-date');
            var endTime = btn.getAttribute('data-end-time');
            var scopeKind = btn.getAttribute('data-scope') || 'form';

            if (!endDate || !endTime) {
              return;
            }

            if (scopeKind === 'row') {
              // Sign-off reconciliation rows: inputs live inside the
              // nearest fieldset, with names like
              //   signoff_reconciliation[rows][<uid>][start_time][date]
              //   signoff_reconciliation[orphans][<tc_id>][end_time][time]
              // Orphan rows only carry end_time; start_time setters
              // silently no-op.
              var fs = btn.closest('fieldset');
              if (!fs) {
                return;
              }
              if (startDate && startTime) {
                setBySuffix(fs, '[start_time][date]', startDate);
                setBySuffix(fs, '[start_time][time]', startTime);
              }
              setBySuffix(fs, '[end_time][date]', endDate);
              setBySuffix(fs, '[end_time][time]', endTime);
            }
            else {
              // Standalone wo_time_clock add form: full known input names.
              var form = btn.closest('form');
              if (!form) {
                return;
              }
              if (startDate && startTime) {
                setFieldValue(form, 'field_start_time[0][value][date]', startDate);
                setFieldValue(form, 'field_start_time[0][value][time]', startTime);
              }
              setFieldValue(form, 'field_end_time[0][value][date]', endDate);
              setFieldValue(form, 'field_end_time[0][value][time]', endTime);
            }

            flashSummary(btn);
          });
        }
      );
    }
  };
})(Drupal, once);
