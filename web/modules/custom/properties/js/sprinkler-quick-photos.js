/**
 * Photo handling for the sprinkler quick-entry form, built for a phone on a
 * job site with one bar of signal.
 *
 * Two problems, reported from the field on 2026-10-09 with a screenshot showing
 * three queued files at 7 MB, 4.83 MB and 4.83 MB:
 *
 *   1. It takes forever. A modern phone camera writes 4-8 MB per frame and the
 *      form was sending that untouched over cell data. Nothing on the receiving
 *      end wants those pixels -- these are "where is the shut-off" and "what
 *      does the wiring look like" photos.
 *   2. There is no preview. Drupal's managed_file element only ever renders a
 *      filename and a link, so the crew cannot tell whether the shot they just
 *      took is the one that got attached.
 *
 * So: downscale in the browser before the upload starts, and show the picture.
 *
 * The long edge is capped at 2048px rather than something smaller because one
 * of these three shots is deliberately of the CLOCK WIRING, taken so the zone
 * count can be settled later by counting wires. 2048 keeps that countable while
 * still turning 7 MB into a few hundred KB.
 *
 * Everything here fails OPEN. If the browser cannot decode the image (HEIC is
 * the usual one -- most browsers will not draw it to a canvas), or the canvas
 * is tainted, or anything else goes wrong, the original file is sent exactly as
 * it was. A crew member never loses a photo because an optimisation failed.
 */
(function (Drupal, once) {
  'use strict';

  var MAX_EDGE = 2048;
  var QUALITY = 0.85;
  // Below this there is nothing worth doing; re-encoding could even grow it.
  var SKIP_UNDER_BYTES = 1200000;

  /**
   * Decode a file to something drawable, upright.
   *
   * createImageBitmap with imageOrientation 'from-image' applies the EXIF
   * rotation to the PIXELS, which matters because re-encoding throws the EXIF
   * away -- without this a portrait photo would come out sideways and stay
   * sideways, which is the bug the exif_orientation module was installed to fix
   * on 2026-09-22. The <img> fallback is for browsers without that option;
   * modern ones orient <img> from EXIF by default.
   */
  function decode(file) {
    if (typeof createImageBitmap === 'function') {
      try {
        return createImageBitmap(file, { imageOrientation: 'from-image' });
      }
      catch (e) {
        // Older signature: no options argument.
      }
    }
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode failed')); };
      img.src = url;
    });
  }

  function shrink(file) {
    if (file.type.indexOf('image/') !== 0 || file.size < SKIP_UNDER_BYTES) {
      return Promise.resolve(file);
    }
    return decode(file).then(function (src) {
      var w = src.width;
      var h = src.height;
      if (!w || !h) { return file; }
      var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
      if (scale === 1) { return file; }

      var canvas = document.createElement('canvas');
      canvas.width = Math.round(w * scale);
      canvas.height = Math.round(h * scale);
      canvas.getContext('2d').drawImage(src, 0, 0, canvas.width, canvas.height);
      if (src.close) { src.close(); }

      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          // If the re-encode is not actually smaller, keep the original.
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          // Always JPEG out, so the name has to match or the upload validator
          // rejects it (a HEIC re-encoded to JPEG is no longer a .heic).
          var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
          resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', QUALITY);
      });
    }).catch(function () {
      return file;
    });
  }

  function setBusy(input, on, text) {
    var wrapper = input.closest('.form-managed-file') || input.parentNode;
    var note = wrapper.querySelector('.sq-photo-busy');
    if (on) {
      if (!note) {
        note = document.createElement('div');
        note.className = 'sq-photo-busy';
        wrapper.insertBefore(note, wrapper.firstChild);
      }
      note.textContent = text;
    }
    else if (note) {
      note.remove();
    }
  }

  /**
   * Show the pictures the crew just picked, immediately, before the upload has
   * even finished -- that is the moment they want to know they got the shot.
   */
  function localPreviews(input, files) {
    var wrapper = input.closest('.form-managed-file') || input.parentNode;
    var strip = wrapper.querySelector('.sq-photo-strip--local');
    if (strip) { strip.remove(); }
    if (!files.length) { return; }
    strip = document.createElement('div');
    strip.className = 'sq-photo-strip sq-photo-strip--local';
    Array.prototype.forEach.call(files, function (f) {
      if (f.type.indexOf('image/') !== 0) { return; }
      var img = document.createElement('img');
      img.className = 'sq-photo-thumb';
      img.alt = '';
      img.src = URL.createObjectURL(f);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      strip.appendChild(img);
    });
    wrapper.insertBefore(strip, wrapper.firstChild);
  }

  Drupal.behaviors.sprinklerQuickPhotos = {
    attach: function (context) {
      // Capture on the FORM, not the input. At the target element capture and
      // bubble listeners run in registration order, so stopping the event there
      // would not reliably beat Drupal's own ajax handler -- on an ancestor in
      // the capture phase it always runs first.
      once('sq-photo-capture', '.sprinkler-quick', context).forEach(function (form) {
        form.addEventListener('change', function (event) {
          var input = event.target;
          if (!input || input.type !== 'file') { return; }
          if (input.dataset.sqReady === '1') {
            // Our own re-dispatch: let it through to Drupal untouched.
            delete input.dataset.sqReady;
            return;
          }
          var files = input.files;
          if (!files || !files.length) { return; }

          // Hold the upload until the files are small.
          event.preventDefault();
          event.stopImmediatePropagation();

          localPreviews(input, files);
          setBusy(input, true, Drupal.t('Preparing @count photo(s)…', { '@count': files.length }));

          Promise.all(Array.prototype.map.call(files, shrink)).then(function (out) {
            try {
              var dt = new DataTransfer();
              out.forEach(function (f) { dt.items.add(f); });
              input.files = dt.files;
            }
            catch (e) {
              // No DataTransfer support: send exactly what was picked.
            }
            setBusy(input, false);
            input.dataset.sqReady = '1';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
        }, true);
      });

      // Thumbnails for files that are already uploaded. managed_file renders a
      // link per file and nothing else; the href is the image, so this turns
      // the list into something you can actually look at. Lives in attach() so
      // it re-runs after each ajax rebuild, which is when the list changes.
      once('sq-photo-thumb', '.sprinkler-quick .form-managed-file a[href]', context)
        .forEach(function (link) {
          if (!/\.(jpe?g|png|webp|gif)(\?|$)/i.test(link.getAttribute('href'))) { return; }
          if (link.querySelector('img')) { return; }
          var img = document.createElement('img');
          img.className = 'sq-photo-thumb';
          img.alt = '';
          img.loading = 'lazy';
          img.src = link.getAttribute('href');
          link.parentNode.insertBefore(img, link);
          link.classList.add('sq-photo-name');
        });
    }
  };
}(Drupal, once));
