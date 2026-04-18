(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.estimateStageBadge = {
    attach: function (context, settings) {
      const form = context.querySelector('#estimate-stage-change-form');
      if (!form || form.dataset.stageBadgeInit) return;
      form.dataset.stageBadgeInit = 'true';

      const select = form.querySelector('#edit-stage');
      if (!select) return;

      // Build slug from stage text for CSS class
      function toSlug(text) {
        return text.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
      }

      // Create badge
      const badge = document.createElement('span');
      badge.className = 'stage-badge stage-' + toSlug(select.options[select.selectedIndex].text);
      badge.textContent = select.options[select.selectedIndex].text;

      // Create change trigger button
      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'stage-change-trigger';
      trigger.textContent = 'Change';

      // Insert badge and trigger before the select
      form.insertBefore(badge, form.firstChild);
      form.insertBefore(trigger, form.firstChild.nextSibling);

      // Toggle edit mode
      trigger.addEventListener('click', function () {
        form.classList.toggle('stage-edit-mode');
        trigger.textContent = form.classList.contains('stage-edit-mode') ? 'Cancel' : 'Change';
      });

      // Update badge when select changes
      select.addEventListener('change', function () {
        var selectedText = select.options[select.selectedIndex].text;
        badge.textContent = selectedText;
        badge.className = 'stage-badge stage-' + toSlug(selectedText);
      });

      // After form submit, collapse back
      form.addEventListener('submit', function () {
        form.classList.remove('stage-edit-mode');
        trigger.textContent = 'Change';
      });
    }
  };

})(jQuery, Drupal);
