/**
 * @file
 * A live "N selected" readout on every VBO bulk form.
 *
 * WHY THIS EXISTS. On 2026-10-15 a bulk action invoiced 97 work orders when far
 * fewer were intended. The header select-all is already hidden in
 * claro_menu_fix.css, so it was not that — but Drupal core's tableselect.js
 * also implements SHIFT-CLICK RANGE SELECT (tableselect.js ~line 116): tick one
 * row, shift-click another, and every row between is selected. Silently. No
 * count, no warning. Hiding the header checkbox never touched that gesture.
 *
 * ⚠ THE COUNT IS RECOMPUTED FROM SCRATCH, NEVER INCREMENTED, and this is the
 * whole point. Drupal.tableSelectRange() sets each row with
 * `.prop('checked', state)` (tableselect.js line 163), which DOES NOT fire a
 * change event. A counter that tracked change events would therefore miss every
 * row a shift-click selects — reporting "2 selected" while 97 were ticked, which
 * is worse than no counter at all, because it reassures in exactly the case
 * that caused the incident. Recounting every checkbox on any interaction is
 * immune to that: it does not care how a box came to be ticked.
 *
 * The listener is delegated on the FORM, so it fires during bubbling, after the
 * target checkbox's own core handler has already expanded the range. A
 * belt-and-braces setTimeout(0) recount follows, so the number is right even if
 * handler order ever changes.
 */
((Drupal, once) => {
  'use strict';

  // VBO names its row checkboxes views_bulk_operations_bulk_form[0], [1], ...
  const BOX = 'input[type="checkbox"][name^="views_bulk_operations_bulk_form"]';
  // Above this many, the readout stops being informational and starts warning.
  const LOUD_AT = 25;

  function readout(form) {
    let el = form.querySelector('.bos-vbo-count');
    if (!el) {
      el = document.createElement('div');
      el.className = 'bos-vbo-count';
      el.setAttribute('aria-live', 'polite');
      // Ahead of the table so it is visible with the action select, which sits
      // at the top of a VBO form.
      form.insertBefore(el, form.firstChild);
    }
    return el;
  }

  function recount(form) {
    const total = form.querySelectorAll(BOX).length;
    const n = form.querySelectorAll(`${BOX}:checked`).length;
    const el = readout(form);
    el.classList.toggle('is-loud', n >= LOUD_AT);
    el.classList.toggle('is-empty', n === 0);
    if (n === 0) {
      el.textContent = Drupal.t('Nothing selected (@total on this page)', { '@total': total });
      return;
    }
    el.textContent = Drupal.t('@n of @total selected on this page', {
      '@n': n,
      '@total': total,
    });
  }

  Drupal.behaviors.bosVboSelectionCount = {
    attach(context) {
      once('bos-vbo-count', 'form', context)
        .filter((form) => form.querySelector(BOX))
        .forEach((form) => {
          const update = () => {
            recount(form);
            // Core expands a shift-click range inside its own change handler;
            // this runs after the current task either way.
            window.setTimeout(() => recount(form), 0);
          };
          form.addEventListener('change', update);
          // A click that only toggles the row (and any future gesture that does
          // not fire change) is covered too — recounting is cheap.
          form.addEventListener('click', update);
          recount(form);
        });
    },
  };
})(Drupal, once);
