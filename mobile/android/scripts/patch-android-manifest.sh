#!/usr/bin/env bash
# Po každém „cap sync“: CAMERA + oprávnění pro stahování/instalaci APK z WebView.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="$ROOT/android/app/src/main/AndroidManifest.xml"

if [[ ! -f "$MANIFEST" ]]; then
  echo "Chybí $MANIFEST — nejdřív: npx cap add android && npx cap sync android"
  exit 1
fi

add_perm() {
  local name="$1"
  local line="$2"
  if grep -qF "$name" "$MANIFEST"; then
    echo "OK: $name už v manifestu"
    return 0
  fi
  if ! grep -q 'android.permission.INTERNET' "$MANIFEST"; then
    echo "Chybí INTERNET v manifestu — doplň ručně: $line"
    return 1
  fi
  sed -i "/android.permission.INTERNET/a\\${line}" "$MANIFEST"
  echo "OK: doplněno $name"
}

add_perm 'android.permission.CAMERA' '    <uses-permission android:name="android.permission.CAMERA" />'
# DownloadManager → Downloads na API ≤28
add_perm 'android.permission.WRITE_EXTERNAL_STORAGE' \
  '    <uses-permission android:name="android.permission.WRITE_EXTERNAL_STORAGE" android:maxSdkVersion="28" />'
# Instalace APK staženého z WebView (Android 8+)
add_perm 'android.permission.REQUEST_INSTALL_PACKAGES' \
  '    <uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />'
