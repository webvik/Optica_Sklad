#!/usr/bin/env bash
# Zkopíruje vlastní Java pluginy do android/ po cap sync.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/native-src"
DST="$ROOT/android/app/src/main/java/net/lowpartners/optica"

if [[ ! -d "$ROOT/android" ]]; then
  echo "Skip: android/ chybí — nejdřív npx cap add android"
  exit 0
fi

if [[ ! -f "$SRC/OpticaSharePlugin.java" || ! -f "$SRC/MainActivity.java" ]]; then
  echo "Chybí native-src/*.java"
  exit 1
fi

mkdir -p "$DST"
cp "$SRC/OpticaSharePlugin.java" "$SRC/MainActivity.java" "$DST/"
echo "OK: OpticaSharePlugin + MainActivity → $DST"
