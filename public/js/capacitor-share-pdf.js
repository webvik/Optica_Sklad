(function (global) {
  'use strict';

  function isCapacitorNative() {
    return !!(global.Capacitor && global.Capacitor.isNativePlatform && global.Capacitor.isNativePlatform());
  }

  function getCapacitorPlugin(name) {
    var cap = global.Capacitor;
    if (!cap || typeof cap.registerPlugin !== 'function') return null;
    return cap.registerPlugin(name);
  }

  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onloadend = function () {
        var dataUrl = reader.result || '';
        var comma = String(dataUrl).indexOf(',');
        resolve(comma >= 0 ? String(dataUrl).slice(comma + 1) : String(dataUrl));
      };
      reader.onerror = reject;
      reader.readAsDataURL(blob);
    });
  }

  function safeFilename(name) {
    var base = (name || 'dokument.pdf').split(/[/\\]/).pop() || 'dokument.pdf';
    base = base.replace(/[^\w.\-]+/g, '_').slice(0, 100) || 'dokument.pdf';
    return 'share-' + Date.now() + '-' + base;
  }

  function isShareCanceled(err) {
    var msg = err && (err.message || err.errorMessage || String(err));
    return msg === 'Share canceled' || msg.indexOf('canceled') !== -1;
  }

  /**
   * Sdílení PDF přes nativní Capacitor Share (Android WebView).
   * @returns {Promise<boolean>} true pokud se dialog otevřel
   */
  global.opticaCapacitorSharePdf = function (blobOrFile, filename, text) {
    if (!isCapacitorNative()) return Promise.resolve(false);

    var cap = global.Capacitor;
    var Filesystem = getCapacitorPlugin('Filesystem');
    var Share = getCapacitorPlugin('Share');
    if (!Filesystem || !Share) return Promise.resolve(false);
    if (cap.isPluginAvailable && (!cap.isPluginAvailable('Filesystem') || !cap.isPluginAvailable('Share'))) {
      return Promise.resolve(false);
    }

    var blob = blobOrFile instanceof Blob ? blobOrFile : null;
    if (!blob) return Promise.resolve(false);

    var path = safeFilename(filename);

    return blobToBase64(blob)
      .then(function (b64) {
        return Filesystem.writeFile({
          path: path,
          data: b64,
          directory: 'CACHE',
        });
      })
      .then(function (writeResult) {
        var fileUri = writeResult && writeResult.uri;
        if (fileUri && fileUri.indexOf('file:') === 0) return fileUri;
        return Filesystem.getUri({ path: path, directory: 'CACHE' }).then(function (uriResult) {
          return uriResult && uriResult.uri;
        });
      })
      .then(function (fileUri) {
        if (!fileUri || fileUri.indexOf('file:') !== 0) {
          throw new Error('Capacitor Share: chybí file:// URI');
        }
        // WhatsApp na Androidu spolehlivěji bere PDF bez EXTRA_TEXT v intentu.
        return Share.share({
          files: [fileUri],
          dialogTitle: 'Sdílet',
        }).then(function () {
          if (text && global.navigator && global.navigator.clipboard && global.navigator.clipboard.writeText) {
            return global.navigator.clipboard.writeText(text).catch(function () {}).then(function () { return true; });
          }
          return true;
        });
      })
      .catch(function (err) {
        if (isShareCanceled(err)) {
          var abort = new Error('Share canceled');
          abort.name = 'AbortError';
          throw abort;
        }
        if (global.console && global.console.warn) {
          global.console.warn('opticaCapacitorSharePdf failed', err);
        }
        return false;
      });
  };
})(typeof window !== 'undefined' ? window : this);
