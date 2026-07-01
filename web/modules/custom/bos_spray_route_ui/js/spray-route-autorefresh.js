/**
 * @file
 * Idle-aware auto-refresh for the crew weed-spray route report.
 *
 * The route resets a property's "days" and re-sorts it to the bottom as soon as
 * the crew signs it off (sprayed or "no spray needed") — but only on a fresh
 * page load. Crews leave the report open all day and don't reload, so completed
 * stops appear stale. This reloads the page periodically, but ONLY during a lull
 * (no interaction for QUIET_MS) and never while a form field is focused, so it
 * won't yank someone mid-scroll or mid-search. Scoped to the crew route only
 * (see bos_spray_route_ui.module) — not the office admin views.
 */
(function (Drupal) {
  'use strict';

  var REFRESH_MS = 5 * 60 * 1000; // attempt a refresh every 5 minutes
  var QUIET_MS = 45 * 1000;       // ...but only after 45s of no interaction

  Drupal.behaviors.sprayRouteAutoRefresh = {
    attach: function () {
      // Behaviors run on every AJAX pass; only wire up the timer once.
      if (window.__sprayRouteAutoRefresh) {
        return;
      }
      window.__sprayRouteAutoRefresh = true;

      var lastActivity = Date.now();
      var bump = function () {
        lastActivity = Date.now();
      };
      ['mousedown', 'keydown', 'touchstart', 'scroll', 'wheel'].forEach(function (evt) {
        window.addEventListener(evt, bump, { passive: true });
      });

      window.setInterval(function () {
        // Wait until the tab is visible and the user has been idle a moment.
        if (document.hidden) {
          return;
        }
        if (Date.now() - lastActivity < QUIET_MS) {
          return;
        }
        var el = document.activeElement;
        if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) {
          return; // mid-entry (e.g. the search filter) — don't interrupt
        }
        window.location.reload();
      }, REFRESH_MS);
    },
  };
})(Drupal);
