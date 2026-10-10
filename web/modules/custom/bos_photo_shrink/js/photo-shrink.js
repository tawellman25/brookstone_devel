/**
 * Shrink camera photos in the browser, before the upload starts.
 *
 * A phone writes 4-13 MB a frame and crews work on one bar of signal, so the
 * upload is the slow part of every photo a crew takes. Measured on a real work
 * order photo (4000x3000, 13 MB from a Galaxy Z Fold7):
 *
 *   full size, re-encoded at q88 ... 4.3 MB   (not one pixel lost)
 *   3200px long edge, q88 ......... 2.9 MB
 *   2560px long edge, q88 ......... 2.0 MB
 *   2048px long edge, q85 ......... 1.2 MB
 *
 * Note the first line: two thirds of the file is the phone's own wasteful
 * encoder, recoverable with no loss of resolution at all. Everything beyond
 * that is a real trade, which is why the limit is set PER FORM rather than
 * globally -- a clock-wiring reference shot and a photo that might end up in an
 * advertisement do not want the same number.
 *
 * Opt a form in by giving it the class `bos-photo-shrink` and, optionally,
 * data-bos-shrink-max / -quality / -preview. Nothing happens to a form that
 * has not asked for it.
 *
 * It fails OPEN at every step. A file the browser cannot decode (HEIC is the
 * usual one, though measured across 12,324 BOS uploads not one has ever been
 * HEIC -- iOS converts on its way through a file picker), a tainted canvas, a
 * re-encode that comes out larger, or a browser without DataTransfer all send
 * the original file exactly as it was. A crew never loses a photo to this.
 */
(function (Drupal, once) {
  'use strict';

  var DEFAULT_MAX = 3200;
  var DEFAULT_QUALITY = 88;
  // Below this there is nothing worth doing; re-encoding could even grow it.
  var SKIP_UNDER_BYTES = 1200000;

  function settings(form) {
    var max = parseInt(form.getAttribute('data-bos-shrink-max'), 10);
    var q = parseInt(form.getAttribute('data-bos-shrink-quality'), 10);
    return {
      max: (max > 0) ? max : DEFAULT_MAX,
      quality: (q > 0 && q <= 100) ? q / 100 : DEFAULT_QUALITY / 100,
      preview: form.getAttribute('data-bos-shrink-preview') === '1'
    };
  }

  /**
   * Decode to something drawable, upright.
   *
   * imageOrientation 'from-image' applies the EXIF rotation to the PIXELS,
   * which matters because re-encoding discards EXIF -- without it every
   * portrait photo would come out sideways and stay sideways, re-creating the
   * bug exif_orientation was installed to fix on 2026-09-22.
   */
  function decode(file) {
    if (typeof createImageBitmap === 'function') {
      try {
        return createImageBitmap(file, { imageOrientation: 'from-image' });
      }
      catch (e) {
        // Older signature: no options argument. Fall through to <img>.
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

  function shrink(file, opts) {
    if (file.type.indexOf('image/') !== 0 || file.size < SKIP_UNDER_BYTES) {
      return Promise.resolve(file);
    }
    return decode(file).then(function (src) {
      var w = src.width;
      var h = src.height;
      if (!w || !h) { return file; }
      // Never enlarge. A photo already under the limit is still re-encoded,
      // because that alone is most of the saving.
      var scale = Math.min(1, opts.max / Math.max(w, h));

      var canvas = document.createElement('canvas');
      canvas.width = Math.round(w * scale);
      canvas.height = Math.round(h * scale);
      canvas.getContext('2d').drawImage(src, 0, 0, canvas.width, canvas.height);
      if (src.close) { src.close(); }

      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          // Always JPEG out, so the name has to match or the upload validator
          // rejects it (a PNG re-encoded to JPEG is no longer a .png).
          var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
          resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', opts.quality);
      });
    }).catch(function () {
      return file;
    });
  }

  function wrapperOf(input) {
    return input.closest('.form-managed-file') || input.closest('.form-item') || input.parentNode;
  }

  function setBusy(input, text) {
    var wrapper = wrapperOf(input);
    var note = wrapper.querySelector('.bos-shrink-busy');
    if (text === null) {
      if (note) { note.remove(); }
      return;
    }
    if (!note) {
      note = document.createElement('div');
      note.className = 'bos-shrink-busy';
      wrapper.insertBefore(note, wrapper.firstChild);
    }
    note.textContent = text;
  }

  /**
   * Show the pictures the crew just picked, before the upload finishes. Only
   * for widgets that do not preview on their own -- the image widget already
   * renders a thumbnail, so asking for this there would just double it up.
   */
  function previews(input, files) {
    var wrapper = wrapperOf(input);
    var old = wrapper.querySelector('.bos-shrink-strip');
    if (old) { old.remove(); }
    if (!files.length) { return; }
    var strip = document.createElement('div');
    strip.className = 'bos-shrink-strip';
    Array.prototype.forEach.call(files, function (f) {
      if (f.type.indexOf('image/') !== 0) { return; }
      var img = document.createElement('img');
      img.className = 'bos-shrink-thumb';
      img.alt = '';
      img.src = URL.createObjectURL(f);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      strip.appendChild(img);
    });
    wrapper.insertBefore(strip, wrapper.firstChild);
  }

  Drupal.behaviors.bosPhotoShrink = {
    attach: function (context) {
      once('bos-photo-shrink', 'form.bos-photo-shrink', context).forEach(function (form) {
        var opts = settings(form);

        // Capture on the FORM, not the input. At the target element capture and
        // bubble listeners run in registration order, so stopping the event
        // there would not reliably beat Drupal's own ajax upload handler; on an
        // ancestor in the capture phase it always runs first.
        form.addEventListener('change', function (event) {
          var input = event.target;
          if (!input || input.type !== 'file') { return; }
          if (input.dataset.bosShrinkReady === '1') {
            // Our own re-dispatch: let it through to Drupal untouched.
            delete input.dataset.bosShrinkReady;
            return;
          }
          var files = input.files;
          if (!files || !files.length) { return; }

          // Hold the upload until the files are small.
          event.preventDefault();
          event.stopImmediatePropagation();

          if (opts.preview) { previews(input, files); }
          setBusy(input, Drupal.t('Preparing @count photo(s)…', { '@count': files.length }));

          Promise.all(Array.prototype.map.call(files, function (f) {
            return shrink(f, opts);
          })).then(function (out) {
            try {
              var dt = new DataTransfer();
              out.forEach(function (f) { dt.items.add(f); });
              input.files = dt.files;
            }
            catch (e) {
              // No DataTransfer support: send exactly what was picked.
            }
            setBusy(input, null);
            input.dataset.bosShrinkReady = '1';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          });
        }, true);
      });

      // Thumbnails for already-uploaded files, for widgets that render only a
      // filename and a link (managed_file does; the image widget does not need
      // it). In attach() so it re-runs after each ajax rebuild, which is when
      // that list changes.
      once('bos-shrink-thumb', 'form.bos-photo-shrink[data-bos-shrink-preview="1"] .form-managed-file a[href]', context)
        .forEach(function (link) {
          if (!/\.(jpe?g|png|webp|gif)(\?|$)/i.test(link.getAttribute('href'))) { return; }
          if (link.querySelector('img')) { return; }
          var img = document.createElement('img');
          img.className = 'bos-shrink-thumb';
          img.alt = '';
          img.loading = 'lazy';
          img.src = link.getAttribute('href');
          link.parentNode.insertBefore(img, link);
          link.classList.add('bos-shrink-name');
        });
    }
  };
}(Drupal, once));
