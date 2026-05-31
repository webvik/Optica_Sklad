#!/usr/bin/env bash
# Po každém „cap sync“: cache-path pro FileProvider (Capacitor Share PDF).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PATHS="$ROOT/android/app/src/main/res/xml/file_paths.xml"

if [[ ! -f "$PATHS" ]]; then
  echo "Chybí $PATHS — nejdřív: npx cap add android && npx cap sync android"
  exit 1
fi

if grep -q 'cache-path' "$PATHS"; then
  echo "OK: cache-path už v file_paths.xml"
  exit 0
fi

sed -i 's|<paths>|<paths>\n    <cache-path name="cache" path="." />|' "$PATHS"
echo "OK: doplněno cache-path do file_paths.xml"
