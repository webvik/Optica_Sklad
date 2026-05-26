#!/usr/bin/env bash
# Po každém „cap sync“: doplní CAMERA pro sken na Práce s optikou (WebView).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="$ROOT/android/app/src/main/AndroidManifest.xml"

if [[ ! -f "$MANIFEST" ]]; then
  echo "Chybí $MANIFEST — nejdřív: npx cap add android && npx cap sync android"
  exit 1
fi

if grep -q 'android.permission.CAMERA' "$MANIFEST"; then
  echo "OK: CAMERA už v manifestu"
  exit 0
fi

sed -i '/android.permission.INTERNET/a \    <uses-permission android:name="android.permission.CAMERA" />' "$MANIFEST"
echo "OK: doplněno android.permission.CAMERA"
