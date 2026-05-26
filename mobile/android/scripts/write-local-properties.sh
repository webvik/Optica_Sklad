#!/usr/bin/env bash
# Zapíše android/local.properties → sdk.dir (Gradle bez toho nebuildí).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROPS="$ROOT/android/local.properties"

SDK="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-$HOME/Android/Sdk}}"

if [[ ! -d "$SDK" ]]; then
  echo "Android SDK nenalezen: $SDK"
  echo ""
  echo "1) Nainstaluj Android Studio: https://developer.android.com/studio"
  echo "2) Při prvním spuštění: SDK → obvykle ~/Android/Sdk"
  echo "3) export ANDROID_HOME=\$HOME/Android/Sdk"
  echo "4) Spusť znovu: ./scripts/write-local-properties.sh"
  exit 1
fi

mkdir -p "$(dirname "$PROPS")"
echo "sdk.dir=$SDK" > "$PROPS"
echo "OK: $PROPS"
cat "$PROPS"
