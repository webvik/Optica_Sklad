(function (global) {
  'use strict';

  function isCapacitorNative() {
    return !!(global.Capacitor && global.Capacitor.isNativePlatform && global.Capacitor.isNativePlatform());
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
    return base.replace(/[^\w.\-]+/g, '_').slice(0, 120) || 'dokument.pdf';
  }

  /**
   * Sdílení PDF přes nativní Capacitor Share (Android WebView).
   * @returns {Promise<boolean>} true pokud se dialog otevřel
   */
  global.opticaCapacitorSharePdf = function (blobOrFile, filename, text) {
    if (!isCapacitorNative()) return Promise.resolve(false);

    var plugins = global.Capacitor && global.Capacitor.Plugins;
    var Filesystem = plugins && plugins.Filesystem;
    var Share = plugins && plugins.Share;
    if (!Filesystem || !Share) return Promise.resolve(false);

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
      .then(function () {
        return Filesystem.getUri({ path: path, directory: 'CACHE' });
      })
      .then(function (uriResult) {
        var opts = {
          files: [uriResult.uri],
          dialogTitle: 'Sdílet',
        };
        if (text) opts.text = text;
        return Share.share(opts);
      })
      .then(function () { return true; })
      .catch(function () { return false; });
  };
})(typeof window !== 'undefined' ? window : this);
