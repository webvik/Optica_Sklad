# Android — Optický sklad (Capacitor)

Nativní obal otevírá produkční web v WebView. Logika zůstává na Symfony serveru.

## Požadavky (PC — např. vik-pc)

- **Node.js 18+** a `npm` (nvm)
- **JDK 17** — `sudo apt install openjdk-17-jdk`
- **Android SDK** — nejjednodušší přes **Android Studio** (bez SDK Gradle hlásí `SDK location not found`)

### Instalace Android SDK (jednou)

1. Stáhni a nainstaluj [Android Studio](https://developer.android.com/studio).
2. První spuštění → průvodce stáhne SDK do `~/Android/Sdk`.
3. V `~/.bashrc` (volitelně):

```bash
export ANDROID_HOME=$HOME/Android/Sdk
export PATH=$PATH:$ANDROID_HOME/platform-tools
```

4. V projektu:

```bash
chmod +x scripts/write-local-properties.sh
./scripts/write-local-properties.sh
```

Vytvoří `android/local.properties` se řádkem `sdk.dir=...` (Gradle to vyžaduje).

Ověření:

```bash
node -v && npm -v
java -version
```

## První nastavení

```bash
cd mobile/android
npm install
npx cap add android
npx cap sync android
```

## Vývoj / emulátor

Capacitor hledá Studio v `/usr/local/android-studio/` — pokud máš jinde, nastav cestu:

```bash
export CAPACITOR_ANDROID_STUDIO_PATH="$HOME/Downloads/android-studio-panda4/android-studio/bin/studio"
# nebo po přesunu: export CAPACITOR_ANDROID_STUDIO_PATH="$HOME/android-studio/bin/studio"
npx cap open android
```

Trvale do `~/.bashrc` (uprav cestu podle skutečného umístění).

**Bez `cap open`:** ve Studiu **Open** → `.../Optica_Sklad/mobile/android/android`

V Android Studio: **Run** na zařízení nebo emulátoru.

## Beta místo produkce

V `capacitor.config.json` změň `server.url` na `https://lowpartners.net`

Pak:

```bash
npx cap sync android
```

## Ikona aplikace (stejná jako favicon webu)

Zdroj: `assets/icon-full.svg` (= `public/favicon.svg`), žlutá `#FFCC00` + cívka.

```bash
npm install
npm run icons:generate
npm run android:assembleDebug
```

Po `cap sync` ikony nepřepisuje — při změně faviconu znovu `npm run icons:generate`.

## Po každém `cap sync` (kamera pro sken)

```bash
chmod +x scripts/patch-android-manifest.sh
./scripts/patch-android-manifest.sh
```

## Release APK (debug pro interní distribuci)

```bash
npx cap sync android
./scripts/patch-android-manifest.sh
cd android
./gradlew assembleDebug
```

APK:

```text
android/app/build/outputs/apk/debug/app-debug.apk
```

Pro signed release: v Android Studio **Build → Generate Signed Bundle/APK** (keystore drž mimo git).

## Distribuce na firemní web

1. Nahraj `app-release.apk` (nebo debug pro test) na server, např. do  
   `/home/httpd/html/optica.lowpartners.net/public/download/`  
   nebo samostatnou stránku s odkazem ke stažení.
2. Uživatel povolí „Instalaci z neznámých zdrojů“ pro prohlížeč / soubory.
3. Při aktualizaci zvyš `versionCode` / `versionName` v `android/app/build.gradle`.

## Oprávnění kamery

Po `cap add android` zkontroluj v `android/app/src/main/AndroidManifest.xml`:

```xml
<uses-permission android:name="android.permission.CAMERA" />
```

Capacitor často doplní sám; bez toho nefunguje sken na **Práce s optikou**.

## Poznámky

- Cookies/session: stejné jako Chrome — remember-me 30 dní.
- Offline: v této verzi ne — vyžaduje síť.
- **Systémové tlačítko zpět:** pokud má WebView historii (např. filtr → karta cívky), vrátí předchozí stránku; jinak aplikaci odloží na pozadí (jako dřív). Změna je v `native-src/MainActivity.java` — po úpravě `npm run cap:sync` a nový APK.
- Podrobnosti architektury: `deploy/mobile-android.md`
