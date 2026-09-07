/**
 * @file
 * HOA boundary drawing widget — Google Maps polygon draw/edit ↔ WKT.
 * Reuses BOS's existing Google Maps key. Draws a single polygon; serializes it
 * into the geofield WKT textarea the point-in-polygon engine reads.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var loaderPromise = null;
  function loadGoogle(key) {
    if (window.google && window.google.maps && window.google.maps.drawing) {
      return Promise.resolve();
    }
    if (loaderPromise) { return loaderPromise; }
    loaderPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=drawing,geometry';
      s.async = true;
      s.defer = true;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
    return loaderPromise;
  }

  function wktToPaths(wkt) {
    var m = /\(\(\s*(.+?)\s*\)\)/.exec(wkt || '');
    if (!m) { return []; }
    return m[1].split(',').map(function (p) {
      var xy = p.trim().split(/\s+/);
      return { lat: parseFloat(xy[1]), lng: parseFloat(xy[0]) };
    }).filter(function (pt) { return !isNaN(pt.lat) && !isNaN(pt.lng); });
  }

  function pathToWkt(path) {
    var pts = [];
    path.forEach(function (ll) { pts.push(ll.lng().toFixed(6) + ' ' + ll.lat().toFixed(6)); });
    if (pts.length < 3) { return ''; }
    if (pts[0] !== pts[pts.length - 1]) { pts.push(pts[0]); }
    return 'POLYGON((' + pts.join(', ') + '))';
  }

  Drupal.behaviors.bosHoaBoundaryMap = {
    attach: function (context) {
      var key = (drupalSettings.bosHoa && drupalSettings.bosHoa.gmapKey) || '';
      once('hoa-map', '.hoa-boundary-map', context).forEach(function (div) {
        var mapId = div.getAttribute('data-hoa-map-id');
        var ta = document.querySelector('textarea[data-hoa-map="' + mapId + '"]');
        if (!key) {
          div.innerHTML = '<em style="padding:1rem;display:block">No Google Maps key configured — paste WKT below.</em>';
          return;
        }
        loadGoogle(key).then(function () {
          var existing = wktToPaths(ta ? ta.value : '');
          var center = existing.length ? existing[0] : { lat: 38.44, lng: -107.86 };
          var map = new google.maps.Map(div, { center: center, zoom: existing.length ? 16 : 12, mapTypeId: 'hybrid' });
          var poly = null;

          function bind(p) {
            if (poly) { poly.setMap(null); }
            poly = p;
            poly.setMap(map);
            poly.setEditable(true);
            var path = poly.getPath();
            var sync = function () { if (ta) { ta.value = pathToWkt(poly.getPath()); } };
            google.maps.event.addListener(path, 'set_at', sync);
            google.maps.event.addListener(path, 'insert_at', sync);
            google.maps.event.addListener(path, 'remove_at', sync);
            sync();
          }

          if (existing.length) {
            var b = new google.maps.LatLngBounds();
            existing.forEach(function (pt) { b.extend(pt); });
            map.fitBounds(b);
            bind(new google.maps.Polygon({ paths: existing, strokeColor: '#007A33', fillColor: '#007A33', fillOpacity: 0.15, strokeWeight: 2 }));
          }

          var dm = new google.maps.drawing.DrawingManager({
            drawingMode: existing.length ? null : google.maps.drawing.OverlayType.POLYGON,
            drawingControl: true,
            drawingControlOptions: { drawingModes: ['polygon'] },
            polygonOptions: { strokeColor: '#007A33', fillColor: '#007A33', fillOpacity: 0.15, strokeWeight: 2, editable: true }
          });
          dm.setMap(map);
          google.maps.event.addListener(dm, 'polygoncomplete', function (p) {
            dm.setDrawingMode(null);
            bind(p);
          });

          var btn = document.createElement('button');
          btn.type = 'button';
          btn.textContent = 'Clear boundary';
          btn.className = 'button button--small';
          btn.style.margin = '.25rem 0';
          btn.addEventListener('click', function () {
            if (poly) { poly.setMap(null); poly = null; }
            if (ta) { ta.value = ''; }
            dm.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
          });
          div.parentNode.insertBefore(btn, div.nextSibling);
        }).catch(function () {
          div.innerHTML = '<em style="padding:1rem;display:block">Google Maps failed to load — paste WKT below.</em>';
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
