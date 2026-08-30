/**
 * @file
 * Route Editor — Stage 2 (read-only). Renders a multi-day map: markers colored
 * by day, one numbered route line per (day, tech), a toggleable legend, a
 * no-location bucket, and card↔marker hover linking. No editing yet.
 *
 * Google Maps JS API is loaded dynamically with the key from drupalSettings
 * (geofield_map.settings gmap_api_key) — never hardcoded.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // One color per day index (stable across markers / lines / legend).
  var DAY_COLORS = ['#CB6015', '#007A33', '#2b6cb0', '#8e44ad', '#c0392b', '#16a085', '#d68910'];

  var map = null;
  var overlays = { markers: {}, lines: {}, listRows: {} }; // keyed by group "day|uid"
  var infoWindow = null;

  Drupal.behaviors.bosRouteEditor = {
    attach: function (context) {
      once('bos-route-editor', '#bos-re-map', context).forEach(function () {
        var cfg = drupalSettings.bosRouteEditor || {};
        if (!cfg.gmapKey) {
          setStatus('No Google Maps key configured — cannot load the map.');
          return;
        }
        loadGoogleMaps(cfg.gmapKey, function () { boot(cfg); });
      });
    }
  };

  function boot(cfg) {
    map = new google.maps.Map(document.getElementById('bos-re-map'), {
      zoom: 10,
      center: { lat: 38.79, lng: -107.98 }, // western CO default; fitBounds overrides
      mapTypeControl: false,
      streetViewControl: false,
    });
    infoWindow = new google.maps.InfoWindow();
    var ld = document.querySelector('.bos-re__map-loading');
    if (ld) { ld.remove(); }
    var url = cfg.dataUrl + '?start=' + encodeURIComponent(cfg.start) +
              '&end=' + encodeURIComponent(cfg.end) + '&range=' + encodeURIComponent(cfg.range);
    setStatus('Loading stops…');
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function (e) { setStatus('Failed to load stops: ' + e); });
  }

  function render(data) {
    var days = (data.range && data.range.days) || [];
    var colorForDay = {};
    days.forEach(function (d, i) { colorForDay[d] = DAY_COLORS[i % DAY_COLORS.length]; });

    // Group stops by "day|uid".
    var groups = {};
    (data.stops || []).forEach(function (s) {
      var key = s.date + '|' + s.assigned_uid;
      (groups[key] = groups[key] || { day: s.date, uid: s.assigned_uid, tech: s.tech, stops: [] }).stops.push(s);
    });
    Object.keys(groups).forEach(function (k) {
      groups[k].stops.sort(function (a, b) { return a.order - b.order; });
    });

    var bounds = new google.maps.LatLngBounds();

    // Origin marker (the shop).
    if (data.origin && data.origin.ok) {
      var o = new google.maps.LatLng(data.origin.lat, data.origin.lng);
      new google.maps.Marker({
        position: o, map: map, title: 'Shop — ' + (data.origin.label || ''),
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: '#111', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
        zIndex: 9999,
      });
      bounds.extend(o);
    }

    // Markers + route line per group.
    Object.keys(groups).forEach(function (key) {
      var g = groups[key];
      var color = colorForDay[g.day] || '#888';
      var path = [];
      overlays.markers[key] = [];
      g.stops.forEach(function (s, idx) {
        var pos = new google.maps.LatLng(s.lat, s.lng);
        path.push(pos);
        bounds.extend(pos);
        var marker = new google.maps.Marker({
          position: pos, map: map, label: { text: String(idx + 1), color: '#fff', fontSize: '11px', fontWeight: '700' },
          icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor: color, fillOpacity: 0.95, strokeColor: '#fff', strokeWeight: 1.5 },
          title: (idx + 1) + '. ' + s.nickname + ' (' + s.service_code + ') — ' + s.tech + ' · ' + s.date,
        });
        marker.addListener('click', function () {
          infoWindow.setContent(
            '<strong>' + esc(s.nickname) + '</strong> <span>' + esc(s.service_code) + '</span><br>' +
            'Stop ' + (idx + 1) + ' · ' + esc(s.tech) + ' · ' + esc(s.date) + '<br>' +
            esc(s.status_label) + ' · <a href="' + esc(s.wo_url) + '">WO ' + s.wo_id + '</a>'
          );
          infoWindow.open(map, marker);
        });
        marker.addListener('mouseover', function () { highlightRow(key, idx, true); });
        marker.addListener('mouseout', function () { highlightRow(key, idx, false); });
        overlays.markers[key].push(marker);
      });
      overlays.lines[key] = new google.maps.Polyline({
        path: path, map: map, strokeColor: color, strokeOpacity: 0.8, strokeWeight: 3,
      });
    });

    var stopCount = (data.stops || []).length;
    if (stopCount === 0) {
      // Empty range (e.g. a Sunday, or a week with nothing scheduled). Make it
      // obvious rather than a lonely dot zoomed to the shop's driveway.
      showEmpty(true);
      if (data.origin && data.origin.ok) { map.setCenter({ lat: data.origin.lat, lng: data.origin.lng }); }
      map.setZoom(10);
    }
    else {
      showEmpty(false);
      if (!bounds.isEmpty()) {
        map.fitBounds(bounds);
        // Don't over-zoom when the stops are tightly clustered / few.
        google.maps.event.addListenerOnce(map, 'idle', function () {
          if (map.getZoom() > 14) { map.setZoom(14); }
        });
      }
    }
    buildLegend(days, colorForDay, groups);
    buildNoLocation(data.no_location || []);
    buildStopList(days, colorForDay, groups);
    setStatus(stopCount + ' stops · ' + data.counts.no_location + ' without location' +
      (data.origin && !data.origin.ok ? ' · ⚠ origin: ' + data.origin.reason : ''));
  }

  function buildLegend(days, colorForDay, groups) {
    var el = document.querySelector('.bos-re__legend-items');
    if (!el) { return; }
    el.innerHTML = '';
    days.forEach(function (d) {
      var techs = Object.keys(groups).filter(function (k) { return groups[k].day === d; });
      if (!techs.length) { return; }
      var head = document.createElement('div');
      head.className = 'bos-re__legend-day';
      head.innerHTML = '<span class="bos-re__swatch" style="background:' + colorForDay[d] + '"></span>' + fmtDay(d);
      el.appendChild(head);
      techs.forEach(function (key) {
        var g = groups[key];
        var row = document.createElement('label');
        row.className = 'bos-re__legend-tech';
        row.innerHTML = '<input type="checkbox" checked> ' + esc(g.tech) + ' <span class="bos-re__legend-n">(' + g.stops.length + ')</span>';
        row.querySelector('input').addEventListener('change', function (e) { toggleGroup(key, e.target.checked); });
        el.appendChild(row);
      });
    });
  }

  function buildStopList(days, colorForDay, groups) {
    // A compact grouped list beside the map; rows hover-link to markers.
    var side = document.querySelector('.bos-re__side');
    var wrap = document.createElement('div');
    wrap.className = 'bos-re__stops';
    days.forEach(function (d) {
      Object.keys(groups).filter(function (k) { return groups[k].day === d; }).forEach(function (key) {
        var g = groups[key];
        var col = document.createElement('div');
        col.className = 'bos-re__stopcol';
        col.style.borderLeftColor = colorForDay[d];
        col.innerHTML = '<div class="bos-re__stopcol-h">' + fmtDay(d) + ' · ' + esc(g.tech) + '</div>';
        overlays.listRows[key] = [];
        g.stops.forEach(function (s, idx) {
          var r = document.createElement('div');
          r.className = 'bos-re__stoprow';
          r.innerHTML = '<span class="bos-re__seq" style="background:' + colorForDay[d] + '">' + (idx + 1) + '</span> ' +
            esc(s.nickname) + ' <span class="bos-re__svc">' + esc(s.service_code) + '</span>';
          r.addEventListener('mouseover', function () { bounceMarker(key, idx, true); });
          r.addEventListener('mouseout', function () { bounceMarker(key, idx, false); });
          col.appendChild(r);
          overlays.listRows[key].push(r);
        });
        wrap.appendChild(col);
      });
    });
    side.appendChild(wrap);
  }

  function buildNoLocation(list) {
    var countEl = document.querySelector('.bos-re__noloc-count');
    var listEl = document.querySelector('.bos-re__noloc-list');
    if (countEl) { countEl.textContent = '(' + list.length + ')'; }
    if (!listEl) { return; }
    listEl.innerHTML = '';
    list.forEach(function (s) {
      var li = document.createElement('li');
      li.innerHTML = esc(s.nickname) + ' <span class="bos-re__svc">' + esc(s.service_code) + '</span> — ' +
        esc(s.date) + ' · ' + esc(s.tech) + ' <span class="bos-re__noloc-reason">' + esc(s.reason) + '</span> ' +
        '<a href="' + esc(s.wo_url) + '">WO ' + s.wo_id + '</a>';
      listEl.appendChild(li);
    });
  }

  function toggleGroup(key, on) {
    (overlays.markers[key] || []).forEach(function (m) { m.setMap(on ? map : null); });
    if (overlays.lines[key]) { overlays.lines[key].setMap(on ? map : null); }
  }

  function highlightRow(key, idx, on) {
    var rows = overlays.listRows[key];
    if (rows && rows[idx]) { rows[idx].classList.toggle('is-hot', on); }
  }

  function bounceMarker(key, idx, on) {
    var m = (overlays.markers[key] || [])[idx];
    if (!m) { return; }
    m.setAnimation(on ? google.maps.Animation.BOUNCE : null);
  }

  function loadGoogleMaps(key, cb) {
    if (window.google && window.google.maps) { cb(); return; }
    window.__bosReMapInit = cb;
    var s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&callback=__bosReMapInit';
    s.async = true; s.defer = true;
    document.head.appendChild(s);
  }

  function showEmpty(on) {
    var mapEl = document.getElementById('bos-re-map');
    var banner = document.querySelector('.bos-re__empty');
    if (on) {
      if (!banner) {
        banner = document.createElement('div');
        banner.className = 'bos-re__empty';
        banner.innerHTML = 'No stops scheduled in this range.<br><small>Crews don\'t work Sundays — try the <strong>Week</strong> view or use Prev/Next to reach a working day.</small>';
        mapEl.appendChild(banner);
      }
      banner.style.display = 'block';
    }
    else if (banner) {
      banner.style.display = 'none';
    }
  }

  function setStatus(t) { var e = document.querySelector('.bos-re__status'); if (e) { e.textContent = t; } }
  function fmtDay(d) { var p = d.split('-'); return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][new Date(p[0], p[1]-1, p[2]).getDay()] + ' ' + p[1] + '/' + p[2]; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

})(Drupal, drupalSettings, once);
