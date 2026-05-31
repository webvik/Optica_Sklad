(function (global) {
  'use strict';

  var BASE64_CHUNK = 240000;

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
    if (!cap) return null;
    if (cap.Plugins && cap.Plugins[name]) return cap.Plugins[name];
    if (typeof cap.registerPlugin === 'function') return cap.registerPlugin(name);
    return null;
  }

  function pluginCall(pluginName, methodName, options) {
    var cap = global.Capacitor;
    if (cap && typeof cap.nativePromise === 'function') {
      return cap.nativePromise(pluginName, methodName, options);
    }
    var plugin = getCapacitorPlugin(pluginName);
    if (plugin && typeof plugin[methodName] === 'function') {
      return plugin[methodName](options);
    }
    return Promise.reject(new Error('Plugin ' + pluginName + '.' + methodName + ' unavailable'));
  }

  function absoluteUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    var origin = global.location && global.location.origin ? global.location.origin : '';
    return origin + (url.charAt(0) === '/' ? url : '/' + url);
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

  function shareViaUrl(downloadUrl, filename, text) {
    return pluginCall('OpticaShare', 'sharePdfFromUrl', {
      url: absoluteUrl(downloadUrl),
      filename: safeFilename(filename),
      dialogTitle: 'Sdílet',
    }).then(function () {
      return copyText(text).then(function () { return true; });
    }).catch(function () {
      return false;
    });
  }

  function shareViaOpticaChunks(blob, filename, text) {
    return blobToBase64(blob).then(function (b64) {
      var name = safeFilename(filename);
      return pluginCall('OpticaShare', 'sharePdfBegin', { filename: name }).then(function () {
        var chain = Promise.resolve();
        for (var i = 0; i < b64.length; i += BASE64_CHUNK) {
          (function (chunk) {
            chain = chain.then(function () {
              return pluginCall('OpticaShare', 'sharePdfAppend', { data: chunk });
            });
          })(b64.slice(i, i + BASE64_CHUNK));
        }
        return chain.then(function () {
          return pluginCall('OpticaShare', 'sharePdfFinish', { dialogTitle: 'Sdílet' });
        });
      }).then(function () {
        return copyText(text).then(function () { return true; });
      });
    }).catch(function () {
      return false;
    });
  }

  function shareViaCapacitorPlugins(blob, filename, text) {
    var Filesystem = getCapacitorPlugin('Filesystem');
    var Share = getCapacitorPlugin('Share');
    if (!Filesystem || !Share) return Promise.resolve(false);

    var path = safeFilename(filename);
    return blobToBase64(blob).then(function (b64) {
      return Filesystem.writeFile({ path: path, data: b64, directory: 'CACHE' });
    }).then(function (writeResult) {
      var fileUri = writeResult && writeResult.uri;
      if (fileUri && fileUri.indexOf('file:') === 0) return fileUri;
      return Filesystem.getUri({ path: path, directory: 'CACHE' }).then(function (uriResult) {
        return uriResult && uriResult.uri;
      });
    }).then(function (fileUri) {
      if (!fileUri || fileUri.indexOf('file:') !== 0) throw new Error('missing file uri');
      return Share.share({ files: [fileUri], dialogTitle: 'Sdílet' });
    }).then(function () {
      return copyText(text).then(function () { return true; });
    }).catch(function () {
      return false;
    });
  }

  global.opticaIsAndroidApp = isOpticaAndroidApp;

  global.opticaCapacitorSharePdfFromUrl = function (downloadUrl, filename, text) {
    if (!isOpticaAndroidApp() || !downloadUrl) return Promise.resolve(false);
    return shareViaUrl(downloadUrl, filename, text).catch(function (err) {
      if (isShareCanceled(err)) throw err;
      return false;
    });
  };

  global.opticaCapacitorSharePdf = function (blobOrFile, filename, text, downloadUrl) {
    if (!isOpticaAndroidApp()) return Promise.resolve(false);

    if (downloadUrl) {
      return shareViaUrl(downloadUrl, filename, text).then(function (ok) {
        if (ok) return true;
      }).then(function (ok) {
        if (ok) return true;
        var blob = blobOrFile instanceof Blob ? blobOrFile : null;
        if (!blob) return false;
        return shareViaOpticaChunks(blob, filename, text).then(function (chunkOk) {
          if (chunkOk) return true;
          return shareViaCapacitorPlugins(blob, filename, text);
        });
      });
    }

    var blob = blobOrFile instanceof Blob ? blobOrFile : null;
    if (!blob) return Promise.resolve(false);

    return shareViaOpticaChunks(blob, filename, text).then(function (ok) {
      if (ok) return true;
      return shareViaCapacitorPlugins(blob, filename, text);
    }).catch(function (err) {
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
