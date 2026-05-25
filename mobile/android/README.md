# Android — Optický sklad (Capacitor)

Nativní obal otevírá produkční web v WebView. Logika zůstává na Symfony serveru.

## Požadavky (PC — např. vik-pc)

- **Node.js 18+** a `npm` (`sudo apt install npm` nebo nvm)
- **Android Studio** + Android SDK (Platform Tools)
- **JAVA_HOME** = JDK 17 (Android Studio → Settings → Build Tools)

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

```bash
npx cap open android
```

V Android Studio: **Run** na zařízení nebo emulátoru.

## Beta místo produkce

V `capacitor.config.ts` změň:

```ts
server: { url: 'https://lowpartners.net', ... }
```

Pak:

```bash
npx cap sync android
```

## Release APK (debug pro interní distribuci)

```bash
npx cap sync android
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
- Podrobnosti architektury: `deploy/mobile-android.md`
