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
        tooltip.querySelector('.bos-tooltip-teammate').textContent  = p.completedLayer
          ? (p.crewNames ? 'Crew: ' + p.crewNames : '')
          : (p.teammateName ? 'Assigned: ' + p.teammateName : '');
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

      // Build static business event legend on init.
      function buildBusinessLegend() {
        const legendEl = document.getElementById('bos-calendar-legend');
        if (!legendEl) return;
        const types = [
          { color: '#ffd5d5', label: 'Holiday' },
          { color: '#d5e8ff', label: 'Closure' },
          { color: '#d5ffd5', label: 'Payday' },
          { color: '#fff3d5', label: 'Company Event' },
        ];
        types.forEach(function(t) {
          const item = document.createElement('span');
          item.className = 'bos-legend-item bos-legend-business';
          item.innerHTML = '<span class="bos-legend-swatch" style="background:' + t.color + ';border:1px solid #ccc"></span>' + t.label;
          legendEl.appendChild(item);
        });
      }

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

      // ── Completed WOs event source ────────────────────────────────
      function buildCompletedUrl(fetchInfo) {
        const params = new URLSearchParams({
          start: fetchInfo.startStr,
          end:   fetchInfo.endStr,
        });
        const dept     = document.getElementById('bos-filter-department')?.value || '';
        const teammate = document.getElementById('bos-filter-teammate')?.value || '';
        if (dept)     params.set('department', dept);
        if (teammate) params.set('teammate', teammate);
        return '/teammates/calendar/completed?' + params.toString();
      }

      // Single-WO focus (via property search). focusActive toggles the filter
      // on/off while keeping the chosen WO so the bar can flip back and forth.
      let focusWoId = null, focusNick = '', focusDate = null, focusActive = false;

      const completedSource = {
        events: function (fetchInfo, successCallback, failureCallback) {
          fetch(buildCompletedUrl(fetchInfo))
            .then(function (r) {
              if (!r.ok) throw new Error('Completed events fetch failed');
              return r.json();
            })
            .then(function (data) {
              if (focusActive && focusWoId) { data = data.filter(function (e) { return e.extendedProps && e.extendedProps.woEntityId === focusWoId; }); }
              successCallback(data);
            })
            .catch(function (err) {
              console.error('BOS Calendar completed:', err);
              failureCallback(err);
            });
        },
        id: 'completed',
      };

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

      // ── Business calendar background events ───────────────────────
      const businessEventsSource = {
        url: '/teammates/calendar/business-events',
        method: 'GET',
        extraParams: function() {
          return {};
        },
        failure: function() {
          console.error('BOS Calendar: failed to load business events');
        },
        id: 'business_events',
      };

      // Property-nickname search highlight term (see search wiring below).
      let searchHighlight = '';
      function bosCalEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
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
        dayMaxEvents:  true,
        firstDay:      0,
        timeZone:      (settings.adminCalendar && settings.adminCalendar.timeZone) ? settings.adminCalendar.timeZone : 'local',
        editable:      (settings.adminCalendar && settings.adminCalendar.canReschedule) ? true : false,
        droppable:     false,
        eventSources: [businessEventsSource],

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
              if (focusActive && focusWoId) { data = data.filter(function (e) { return e.extendedProps && e.extendedProps.woEntityId === focusWoId; }); }
              successCallback(data);
            })
            .catch(function (err) {
              console.error('BOS Calendar:', err);
              failureCallback(err);
            });
        },

        eventClick: function (info) {
          if (info.event.url) {
            info.jsEvent.preventDefault();
            var a = document.createElement('a');
            a.href = info.event.url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
          }
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
          // Property-search highlight.
          if (searchHighlight && (info.event.extendedProps.propertyNickname || '').toLowerCase().indexOf(searchHighlight) !== -1) {
            info.el.style.outline = '3px solid #CB6015';
            info.el.style.outlineOffset = '1px';
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
      buildBusinessLegend();

      // ── Property-nickname search ───────────────────────────────────
      const searchInput   = document.getElementById('bos-cal-search');
      const searchResults = document.getElementById('bos-cal-search-results');
      let searchTimer = null;
      function runPropertySearch() {
        const q = (searchInput.value || '').trim();
        if (q.length < 2) { searchResults.hidden = true; searchResults.innerHTML = ''; return; }
        fetch('/teammates/calendar/search?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (rows) {
            if (!rows.length) {
              searchResults.innerHTML = '<div class="bos-cal-search-empty">No scheduled work orders match.</div>';
            }
            else {
              searchResults.innerHTML = rows.map(function (row) {
                return '<button type="button" class="bos-cal-search-item" data-date="' + row.date + '" data-status="' + (row.status_tid || 0) + '" data-wo="' + (row.wo_id || 0) + '" data-nick="' + bosCalEsc(row.nickname) + '">' +
                  '<span class="nk">' + bosCalEsc(row.nickname) + '</span> ' +
                  '<span class="sv">' + bosCalEsc(row.service) + '</span> ' +
                  '<span class="dt">' + bosCalEsc(row.date_label) + '</span>' +
                  (row.status_label ? ' <span class="st">' + bosCalEsc(row.status_label) + '</span>' : '') +
                  '</button>';
              }).join('');
            }
            searchResults.hidden = false;
          })
          .catch(function (e) { console.error('BOS Calendar search:', e); });
      }
      searchInput?.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runPropertySearch, 250);
      });
      function renderFocusNote() {
        const note = document.getElementById('bos-cal-focus-note');
        if (!note) { return; }
        if (!focusWoId) { note.hidden = true; note.innerHTML = ''; return; }
        note.hidden = false;
        note.innerHTML = focusActive
          ? 'Showing only: <strong>' + bosCalEsc(focusNick) + '</strong> <button type="button" id="bos-cal-focus-toggle">Show all</button>'
          : 'Showing all — <button type="button" id="bos-cal-focus-toggle">Show only <strong>' + bosCalEsc(focusNick) + '</strong></button>';
      }
      searchResults?.addEventListener('click', function (e) {
        const btn = e.target.closest('.bos-cal-search-item');
        if (!btn) { return; }
        searchHighlight = (btn.getAttribute('data-nick') || '').toLowerCase();
        // Filter the calendar to ONLY this work order.
        focusWoId = parseInt(btn.getAttribute('data-wo'), 10) || null;
        focusNick = btn.getAttribute('data-nick') || '';
        focusDate = btn.getAttribute('data-date');
        focusActive = true;
        renderFocusNote();
        // If the target WO is completed/invoiced/historical, make sure the
        // calendar is showing completed work so the event actually appears.
        const histStatuses = [1097, 1283, 1281, 1504];
        const st = parseInt(btn.getAttribute('data-status'), 10);
        if (histStatuses.indexOf(st) !== -1) {
          // Completed/historical WO — turn on + actually add the completed
          // event source so the event renders on its day.
          const sc = document.getElementById('bos-filter-show-completed');
          if (sc) { sc.checked = true; }
          if (!calendar.getEventSourceById('completed')) {
            calendar.addEventSource(completedSource);
          }
        }
        const targetDate = btn.getAttribute('data-date');
        calendar.gotoDate(targetDate);
        calendar.refetchEvents();
        searchResults.hidden = true;
        // Scroll the specific day cell into view + briefly highlight it so the
        // office doesn't have to hunt for it.
        setTimeout(function () {
          const cell = el.querySelector('.fc-daygrid-day[data-date="' + targetDate + '"], .fc-day[data-date="' + targetDate + '"]');
          if (cell) {
            // Scroll the target week near the top (below the sticky admin
            // toolbar + calendar header) so the week-of is prominent, not the
            // week above it. Offset ≈ toolbar + month/day headers.
            const rect = cell.getBoundingClientRect();
            const y = window.pageYOffset + rect.top - 150;
            window.scrollTo({ top: y > 0 ? y : 0, behavior: 'smooth' });
            cell.classList.add('bos-cal-day-focus');
            setTimeout(function () { cell.classList.remove('bos-cal-day-focus'); }, 4000);
          }
          else {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }, 300);
      });
      // Hide results when clicking away.
      document.addEventListener('click', function (e) {
        if (searchResults && !searchResults.hidden && !e.target.closest('.bos-cal-search-group')) {
          searchResults.hidden = true;
        }
      });
      // Toggle between showing only the chosen WO and showing all.
      document.getElementById('bos-cal-focus-note')?.addEventListener('click', function (e) {
        if (e.target.closest('#bos-cal-focus-toggle')) {
          focusActive = !focusActive;
          searchHighlight = focusActive ? (focusNick || '').toLowerCase() : '';
          if (focusActive && focusDate) { calendar.gotoDate(focusDate); }
          renderFocusNote();
          calendar.refetchEvents();
        }
      });

      // ── Filter controls ───────────────────────────────────────────
      // Mobile filter toggle.
      document.getElementById('bos-filters-toggle')?.addEventListener('click', function () {
        const inner = document.getElementById('bos-filters-inner');
        const icon  = document.getElementById('bos-filters-toggle-icon');
        if (inner) inner.classList.toggle('open');
        if (icon)  icon.textContent = inner?.classList.contains('open') ? '▲' : '▼';
      });

      // Toggle completed overlay.
      document.getElementById('bos-filter-show-completed')?.addEventListener('change', function () {
        if (this.checked) {
          calendar.addEventSource(completedSource);
        } else {
          calendar.getEventSourceById('completed')?.remove();
        }
      });

      document.getElementById('bos-calendar-apply')?.addEventListener('click', function () {
        clearLegend();
        calendar.refetchEvents();
        // Refetch completed source if active.
        if (document.getElementById('bos-filter-show-completed')?.checked) {
          calendar.getEventSourceById('completed')?.refetch();
        }
      });

      document.getElementById('bos-calendar-reset')?.addEventListener('click', function () {
        document.getElementById('bos-filter-department').value = '';
        document.getElementById('bos-filter-teammate').value   = '';
        document.getElementById('bos-filter-status').value     = '';
        document.getElementById('bos-filter-firm-only').checked = false;
        document.getElementById('bos-filter-show-completed').checked = false;
        calendar.getEventSourceById('completed')?.remove();
        clearLegend();
        calendar.refetchEvents();
      });
    }
  };

})(Drupal, drupalSettings);
