/**
 * @file
 * Keyboard shortcuts + autofocus for per-row resolution forms
 * (Phase 3.7.5 UX — save-and-load-next companion).
 *
 * Behavior:
 *   - Autofocus the first reasonable input on page load. Order of
 *     preference: entity_autocomplete, text input, select, textarea —
 *     skipping the "Back to queue" link container and any hidden inputs.
 *   - Ctrl+Enter (or Cmd+Enter on Mac) submits the primary submit button.
 *   - Esc returns to the workflow's queue. If any input has been modified
 *     from its initial value, prompts for confirmation before navigating
 *     away so unsaved work isn't silently lost.
 *
 * Attached via the supplier_price_ingest/row_resolution_form library to
 * every per-row resolution form via IngestRowFormTrait::attachRowFormLibrary().
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Find the URL of the "Back to queue" link the trait rendered at the
   * top of every resolution form. Used as the Esc-key destination so
   * the JS doesn't have to repeat the discovery-vs-fuzzy-review logic
   * the PHP trait already encoded.
   */
  function findBackToQueueUrl(formEl) {
    var container = formEl.querySelector('.bos-ingest-back-to-queue');
    if (!container) {
      return null;
    }
    var link = container.querySelector('a[href]');
    return link ? link.getAttribute('href') : null;
  }

  /**
   * Pick the first reasonable input to focus. Order of preference:
   *   1. entity_autocomplete (input.form-autocomplete)
   *   2. textfield (input[type="text"]:not(.hidden))
   *   3. select
   *   4. textarea
   *
   * Skips inputs inside the "Back to queue" link container, and skips
   * inputs that are hidden, disabled, or readonly.
   */
  function findFirstInput(formEl) {
    var selectors = [
      'input.form-autocomplete',
      'input[type="text"]',
      'select',
      'textarea',
    ];
    for (var i = 0; i < selectors.length; i++) {
      var candidates = formEl.querySelectorAll(selectors[i]);
      for (var j = 0; j < candidates.length; j++) {
        var el = candidates[j];
        if (el.closest('.bos-ingest-back-to-queue')) {
          continue;
        }
        if (el.disabled || el.readOnly || el.type === 'hidden') {
          continue;
        }
        // offsetParent === null catches visually hidden ancestors.
        if (el.offsetParent === null) {
          continue;
        }
        return el;
      }
    }
    return null;
  }

  /**
   * Find the primary submit button — first input[type=submit] inside
   * the form that's NOT a danger-button (we don't want Ctrl+Enter to
   * trigger Reject by accident on the reject form, which is fine
   * because Reject IS the primary action there).
   */
  function findPrimarySubmit(formEl) {
    return formEl.querySelector('input[type="submit"], button[type="submit"]');
  }

  /**
   * Snapshot all input values at attach time so the Esc handler can
   * detect whether the form has been modified.
   */
  function snapshotInputs(formEl) {
    var snapshot = {};
    var inputs = formEl.querySelectorAll('input, select, textarea');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      if (!el.name) {
        continue;
      }
      if (el.type === 'submit' || el.type === 'button') {
        continue;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        snapshot[el.name + '|' + (el.value || '')] = el.checked;
      }
      else {
        snapshot[el.name] = el.value;
      }
    }
    return snapshot;
  }

  function isFormModified(formEl, snapshot) {
    var inputs = formEl.querySelectorAll('input, select, textarea');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      if (!el.name || el.type === 'submit' || el.type === 'button') {
        continue;
      }
      if (el.type === 'checkbox' || el.type === 'radio') {
        var key = el.name + '|' + (el.value || '');
        if (snapshot[key] !== el.checked) {
          return true;
        }
      }
      else if (snapshot.hasOwnProperty(el.name)
               && snapshot[el.name] !== el.value) {
        return true;
      }
    }
    return false;
  }

  Drupal.behaviors.bosIngestRowResolutionForm = {
    attach: function (context) {
      // Only act on forms the trait attached us to — identified by the
      // presence of the bos-ingest-back-to-queue marker container. Avoids
      // accidentally attaching to unrelated forms that happen to live
      // under the same admin path.
      var forms = once('bos-ingest-row-form', '.bos-ingest-back-to-queue', context);
      for (var i = 0; i < forms.length; i++) {
        // forms[i] is the marker container; walk up to its enclosing
        // <form>.
        var formEl = forms[i].closest('form');
        if (!formEl) {
          continue;
        }
        var backUrl = findBackToQueueUrl(formEl);
        var snapshot = snapshotInputs(formEl);

        // Autofocus first reasonable input.
        var firstInput = findFirstInput(formEl);
        if (firstInput) {
          // setTimeout 0 — defers until after other behaviors finish
          // wiring up so our focus doesn't get clobbered by a later
          // attach call (e.g., autocomplete widget initialization).
          window.setTimeout(function (el) {
            return function () { try { el.focus(); } catch (e) {} };
          }(firstInput), 0);
        }

        // Keyboard handler.
        formEl.addEventListener('keydown', function (formEl, backUrl, snapshot) {
          return function (event) {
            // Ctrl+Enter / Cmd+Enter — submit primary button.
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
              var submit = findPrimarySubmit(formEl);
              if (submit) {
                event.preventDefault();
                submit.click();
              }
              return;
            }
            // Esc — navigate back to queue, with modified-form guard.
            if (event.key === 'Escape' && backUrl) {
              event.preventDefault();
              if (isFormModified(formEl, snapshot)) {
                var ok = window.confirm(
                  'Are you sure? Unsaved changes will be lost.'
                );
                if (!ok) {
                  return;
                }
              }
              window.location.href = backUrl;
            }
          };
        }(formEl, backUrl, snapshot));
      }
    }
  };

}(Drupal, once));
