(function (global) {
  'use strict';

  function isOpticaAndroidApp() {
    var cap = global.Capacitor;
    if (cap) {
      if (cap.isNativePlatform && cap.isNativePlatform()) return true;
      if (cap.getPlatform && cap.getPlatform() === 'android') return true;
    }
    var ua = (global.navigator && global.navigator.userAgent) || '';
    return ua.indexOf('OpticaSkladApp') !== -1;
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

  function copyText(text) {
    if (!text || !global.navigator || !global.navigator.clipboard || !global.navigator.clipboard.writeText) {
      return Promise.resolve(false);
    }
    return global.navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return false; });
  }

  function shareViaOpticaPlugin(blob, filename, text) {
    var OpticaShare = getCapacitorPlugin('OpticaShare');
    if (!OpticaShare || typeof OpticaShare.sharePdf !== 'function') {
      return Promise.resolve(false);
    }
    return blobToBase64(blob).then(function (b64) {
      return OpticaShare.sharePdf({
        filename: safeFilename(filename),
        data: b64,
        dialogTitle: 'Sdílet',
      });
    }).then(function () {
      return copyText(text).then(function () { return true; });
    });
  }

  function shareViaCapacitorPlugins(blob, filename, text) {
    var Filesystem = getCapacitorPlugin('Filesystem');
    var Share = getCapacitorPlugin('Share');
    if (!Filesystem || !Share) return Promise.resolve(false);

    var path = safeFilename(filename);
    return blobToBase64(blob).then(function (b64) {
      return Filesystem.writeFile({
        path: path,
        data: b64,
        directory: 'CACHE',
      });
    }).then(function (writeResult) {
      var fileUri = writeResult && writeResult.uri;
      if (fileUri && fileUri.indexOf('file:') === 0) return fileUri;
      return Filesystem.getUri({ path: path, directory: 'CACHE' }).then(function (uriResult) {
        return uriResult && uriResult.uri;
      });
    }).then(function (fileUri) {
      if (!fileUri || fileUri.indexOf('file:') !== 0) {
        throw new Error('Capacitor Share: chybí file:// URI');
      }
      return Share.share({
        files: [fileUri],
        dialogTitle: 'Sdílet',
      });
    }).then(function () {
      return copyText(text).then(function () { return true; });
    });
  }

  global.opticaIsAndroidApp = isOpticaAndroidApp;

  /**
   * Sdílení PDF v Android WebView (nativní plugin nebo Capacitor Share).
   * @returns {Promise<boolean>} true pokud se dialog otevřel
   */
  global.opticaCapacitorSharePdf = function (blobOrFile, filename, text) {
    if (!isOpticaAndroidApp()) return Promise.resolve(false);

    var blob = blobOrFile instanceof Blob ? blobOrFile : null;
    if (!blob) return Promise.resolve(false);

    return shareViaOpticaPlugin(blob, filename, text)
      .then(function (ok) {
        if (ok) return true;
        return shareViaCapacitorPlugins(blob, filename, text);
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
