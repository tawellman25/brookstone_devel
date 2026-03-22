/**
 * @file
 * BOS Supervisor Dispatch Board JS.
 * Auto-refreshes the page every 5 minutes with countdown timer.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.bosDispatch = {
    attach: function (context, settings) {
      const countdownEl = context.querySelector('#bos-dispatch-countdown');
      if (!countdownEl || countdownEl.dataset.init) return;
      countdownEl.dataset.init = '1';

      const interval = (settings.bosDispatch && settings.bosDispatch.refreshInterval) || 300000;
      let remaining = Math.floor(interval / 1000);

      function formatTime(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
      }

      countdownEl.textContent = formatTime(remaining);

      const timer = setInterval(function () {
        remaining--;
        if (remaining <= 0) {
          clearInterval(timer);
          window.location.reload();
        }
        else {
          countdownEl.textContent = formatTime(remaining);
        }
      }, 1000);
    }
  };

})(Drupal, drupalSettings);
