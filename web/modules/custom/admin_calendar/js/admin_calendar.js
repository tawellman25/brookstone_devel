/**
 * @file
 * BOS Scheduling Calendar — FullCalendar 6 initialization.
 *
 * Features:
 * - Month and week views
 * - Color by department
 * - Teammate initials + order code in event title
 * - Status filter (active by default, expandable to historical)
 * - Drag-and-drop date rescheduling
 * - Tooltip on hover
 */
(function (Drupal, drupalSettings) {
  'use strict';

  // All active status TIDs (default view).
  const ACTIVE_TIDS = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];
  const ALL_TIDS    = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096, 1097, 1283, 1281, 1504, 1098];

  Drupal.behaviors.adminCalendar = {
    attach: function (context, settings) {
      const el = context.querySelector('#bos-scheduling-calendar');
      if (!el || el.dataset.calendarInit) return;
      el.dataset.calendarInit = '1';

      const config   = settings.adminCalendar || {};
      const eventsUrl = config.eventsUrl || '/admin/scheduling/calendar/events';

      // ── Tooltip ──────────────────────────────────────────────────
      const tooltip = document.getElementById('bos-calendar-tooltip');

      function showTooltip(event, jsEvent) {
        const p = event.extendedProps;
        tooltip.querySelector('.bos-tooltip-property').textContent  = p.propertyNickname || '';
        tooltip.querySelector('.bos-tooltip-service').textContent   = p.serviceName || '';
        tooltip.querySelector('.bos-tooltip-order').textContent     = p.orderCode ? 'Order: ' + p.orderCode : '';
        tooltip.querySelector('.bos-tooltip-department').textContent = p.departmentName || '';
        tooltip.querySelector('.bos-tooltip-teammate').textContent  = p.teammateName ? 'Assigned: ' + p.teammateName : '';
        tooltip.querySelector('.bos-tooltip-status').textContent    = p.statusLabel || '';
        tooltip.querySelector('.bos-tooltip-firm').textContent      = p.isFirm ? '✓ Firm' : '~ Tentative';
        tooltip.querySelector('.bos-tooltip-note').textContent      = p.note || '';
        tooltip.style.display = 'block';
        positionTooltip(jsEvent);
      }

      function hideTooltip() {
        tooltip.style.display = 'none';
      }

      function positionTooltip(jsEvent) {
        const margin = 12;
        const tw = tooltip.offsetWidth || 240;
        const th = tooltip.offsetHeight || 140;
        let x = jsEvent.clientX + margin;
        let y = jsEvent.clientY + margin;
        if (x + tw > window.innerWidth - margin) x = jsEvent.clientX - tw - margin;
        if (y + th > window.innerHeight - margin) y = jsEvent.clientY - th - margin;
        if (y < margin) y = margin;
        if (x < margin) x = margin;
        tooltip.style.left = x + 'px';
        tooltip.style.top  = y + 'px';
      }

      document.addEventListener('mousemove', function (e) {
        if (tooltip.style.display === 'block') positionTooltip(e);
      });

      // ── Legend ───────────────────────────────────────────────────
      const legendSeen = {};

      function updateLegend(color, label) {
        if (legendSeen[color]) return;
        legendSeen[color] = true;
        const legendEl = document.getElementById('bos-calendar-legend');
        if (!legendEl) return;
        const item = document.createElement('span');
        item.className = 'bos-legend-item';
        item.innerHTML = '<span class="bos-legend-swatch" style="background:' + color + '"></span>' + label;
        legendEl.appendChild(item);
      }

      function clearLegend() {
        Object.keys(legendSeen).forEach(function (k) { delete legendSeen[k]; });
        const legendEl = document.getElementById('bos-calendar-legend');
        if (legendEl) legendEl.innerHTML = '';
      }

      // ── Filter helpers ────────────────────────────────────────────
      function getStatusParam() {
        const val = document.getElementById('bos-filter-status')?.value || '';
        if (!val) return ACTIVE_TIDS.join(',');
        if (val === 'all') return ALL_TIDS.join(',');
        return val;
      }

      function buildEventsUrl(fetchInfo) {
        const params = new URLSearchParams({
          start: fetchInfo.startStr,
          end:   fetchInfo.endStr,
        });
        const dept     = document.getElementById('bos-filter-department')?.value || '';
        const teammate = document.getElementById('bos-filter-teammate')?.value || '';
        const firmOnly = document.getElementById('bos-filter-firm-only')?.checked;
        const statuses = getStatusParam();

        if (dept)     params.set('department', dept);
        if (teammate) params.set('teammate', teammate);
        if (firmOnly) params.set('firm_only', '1');
        if (statuses) params.set('statuses', statuses);

        return eventsUrl + '?' + params.toString();
      }

      // ── Drag-drop save ────────────────────────────────────────────
      function saveEventDrop(eventId, newStart, allDay) {
        const saveUrl = eventsUrl.replace('/events', '/event/' + eventId + '/reschedule');
        const dateStr = allDay
          ? newStart.toISOString().substring(0, 10)
          : newStart.toISOString().substring(0, 16);

        fetch(saveUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ date: dateStr, all_day: allDay }),
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success) {
              console.error('BOS Calendar reschedule failed:', data.message);
              calendar.refetchEvents();
            }
          })
          .catch(function (err) {
            console.error('BOS Calendar reschedule error:', err);
            calendar.refetchEvents();
          });
      }

      // ── FullCalendar init ─────────────────────────────────────────
      const calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: {
          left:   'prev,next today',
          center: 'title',
          right:  'dayGridMonth,timeGridWeek',
        },
        height:        'auto',
        navLinks:      true,
        eventDisplay:  'block',
        dayMaxEvents:  6,
        firstDay:      1,
        timeZone:      'America/Denver',
        editable:      true,
        droppable:     false,

        events: function (fetchInfo, successCallback, failureCallback) {
          fetch(buildEventsUrl(fetchInfo))
            .then(function (r) {
              if (!r.ok) throw new Error('Calendar events fetch failed: ' + r.status);
              return r.json();
            })
            .then(function (data) {
              data.forEach(function (evt) {
                if (evt.color && evt.extendedProps && evt.extendedProps.departmentName) {
                  updateLegend(evt.color, evt.extendedProps.departmentName);
                }
              });
              successCallback(data);
            })
            .catch(function (err) {
              console.error('BOS Calendar:', err);
              failureCallback(err);
            });
        },

        eventClick: function (info) {
          info.jsEvent.preventDefault();
          if (info.event.url) window.location.href = info.event.url;
        },

        eventMouseEnter: function (info) { showTooltip(info.event, info.jsEvent); },
        eventMouseLeave: function ()      { hideTooltip(); },

        eventDidMount: function (info) {
          // Tentative = reduced opacity + dashed border.
          if (!info.event.extendedProps.isFirm) {
            info.el.style.opacity     = '0.7';
            info.el.style.borderStyle = 'dashed';
          }
          // Completed/historical = italic.
          const historicalStatuses = [1097, 1283, 1281, 1504, 1098];
          if (historicalStatuses.includes(info.event.extendedProps.statusTid)) {
            info.el.style.fontStyle = 'italic';
          }
        },

        // Drag-drop: update the scheduling entity date.
        eventDrop: function (info) {
          const confirmed = confirm(
            'Reschedule "' + (info.event.extendedProps.propertyNickname || info.event.title) +
            '" to ' + info.event.startStr + '?'
          );
          if (!confirmed) {
            info.revert();
            return;
          }
          saveEventDrop(info.event.id, info.event.start, info.event.allDay);
        },
      });

      calendar.render();

      // ── Filter controls ───────────────────────────────────────────
      document.getElementById('bos-calendar-apply')?.addEventListener('click', function () {
        clearLegend();
        calendar.refetchEvents();
      });

      document.getElementById('bos-calendar-reset')?.addEventListener('click', function () {
        document.getElementById('bos-filter-department').value = '';
        document.getElementById('bos-filter-teammate').value   = '';
        document.getElementById('bos-filter-status').value     = '';
        document.getElementById('bos-filter-firm-only').checked = false;
        clearLegend();
        calendar.refetchEvents();
      });
    }
  };

})(Drupal, drupalSettings);
