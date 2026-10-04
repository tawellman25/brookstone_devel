/**
 * @file
 * Live total for the on-site holiday lighting estimate.
 *
 * Recalculates as the teammate types. A round-trip per keystroke on a phone in
 * a truck is unusable, so the arithmetic is mirrored here — but the SERVER
 * recomputes from HolidayEstimator on submit and that is the figure stored, so
 * this is a display convenience and never the authority.
 *
 * The rules mirrored here are the ones in HolidayEstimator::price(): the
 * per-plan minimum lifts the roofline, steep takes a percentage of the ROOFLINE
 * only (never of the add-ons), shrubs nudge up to the minimum quantity rather
 * than being rejected, and the height adder is flat.
 */
((Drupal, once) => {
  'use strict';

  const money = (n) =>
    '$' + Math.round(n).toLocaleString('en-US');

  Drupal.behaviors.bosHolidayEstimate = {
    attach(context, settings) {
      const cfg = settings.bosHolidayEstimate;
      const form = once('bos-holiday-estimate', 'form.hol-est', context)[0];
      if (!form || !cfg) {
        return;
      }

      const out = form.querySelector('#hol-est-live');
      const field = (name) => form.querySelector('[name="' + name + '"]');
      const qty = (name) => {
        const el = field(name);
        const v = el ? parseFloat(el.value) : 0;
        return Number.isFinite(v) && v > 0 ? v : 0;
      };

      const render = () => {
        const planKey = field('plan') ? field('plan').value : null;
        const plan = planKey ? cfg.plans[planKey] : null;
        if (!plan) {
          return;
        }
        const feet = qty('feet');
        const lines = [];
        let total = 0;
        let minApplied = false;

        if (feet > 0) {
          let roof = feet * plan.rate;
          if (plan.minimum > 0 && roof < plan.minimum) {
            roof = plan.minimum;
            minApplied = true;
          }
          lines.push(['Roofline — ' + feet + ' ft at $' + plan.rate.toFixed(2) + '/ft', roof]);
          total += roof;

          if (field('steep') && field('steep').checked && cfg.steepPct > 0) {
            const premium = roof * (cfg.steepPct / 100);
            lines.push(['Steep roof — ' + cfg.steepPct + '%', premium]);
            total += premium;
          }
        }

        Object.keys(cfg.addons).forEach((key) => {
          const el = form.querySelector('[data-hol-addon="' + key + '"]');
          if (!el) {
            return;
          }
          let n = parseFloat(el.value);
          n = Number.isFinite(n) && n > 0 ? n : 0;
          if (n <= 0) {
            return;
          }
          if (key === 'shrub' && cfg.shrubMin > 0 && n < cfg.shrubMin) {
            n = cfg.shrubMin;
          }
          const label = (el.closest('.js-form-item') || el.parentNode)
            .querySelector('label');
          const text = label ? label.textContent.replace(/\s+—\s+\$[\d,]+\s*$/, '').trim() : key;
          lines.push([text + (n > 1 ? ' × ' + n : ''), cfg.addons[key] * n]);
          total += cfg.addons[key] * n;
        });

        if (field('tall') && field('tall').checked && cfg.tallAdder > 0) {
          lines.push(['Work above 25 ft', cfg.tallAdder]);
          total += cfg.tallAdder;
        }

        if (!lines.length) {
          out.innerHTML = '<p class="hol-est__empty">Enter a roofline measurement to start.</p>';
          return;
        }

        let html = '';
        lines.forEach(([label, amount]) => {
          html +=
            '<div class="hol-ln"><span></span><span class="amt"></span></div>';
        });
        out.innerHTML = html;
        // Set text content rather than interpolating, so a label can never
        // inject markup into the page.
        out.querySelectorAll('.hol-ln').forEach((row, i) => {
          row.children[0].textContent = lines[i][0];
          row.children[1].textContent = money(lines[i][1]);
        });

        const totalRow = document.createElement('div');
        totalRow.className = 'hol-ln hol-ln--total';
        const tl = document.createElement('span');
        tl.textContent = 'Estimate total';
        const ta = document.createElement('span');
        ta.className = 'amt';
        ta.textContent = money(total);
        totalRow.append(tl, ta);
        out.append(totalRow);

        if (plan.full_service) {
          const dep = document.createElement('div');
          dep.className = 'hol-ln hol-ln--sub';
          const dl = document.createElement('span');
          dl.textContent = 'Deposit at signing (50%)';
          const da = document.createElement('span');
          da.className = 'amt';
          da.textContent = money(total / 2);
          dep.append(dl, da);
          out.append(dep);
        }

        if (minApplied) {
          const note = document.createElement('p');
          note.className = 'hol-est__note';
          note.textContent =
            'Roofline is under the ' + money(plan.minimum) +
            ' minimum. Add scope rather than discounting.';
          out.append(note);
        }
      };

      form.addEventListener('input', render);
      form.addEventListener('change', render);
      render();
    },
  };
})(Drupal, once);
