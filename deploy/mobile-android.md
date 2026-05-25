# Mobilní aplikace Android — Optický sklad

## Strategie

**Fáze 1 (teď):** Capacitor = nativní obal (WebView) na existující web  
`https://optica.lowpartners.net` (produkce). Stejná session, formuláře, skener čárového kódu, OCR jako v prohlížeči.

**Nepřepisovat na Kotlin/Flutter** — málo JSON API, zápisy jsou POST formuláře + CSRF.

**Distribuce:** APK na firemní stránce (jako u techniků), ne nutně Google Play.

## Fáze 1 — rozsah

1. Přihlášení (session + remember-me)
2. **Práce s optikou** — hledání špule, záfuk, sken
3. **Přehled skladu** — filtry, hledání špule (VIEW)
4. Oprávnění kamery v AndroidManifest
5. Debug/release APK build

**Až potom:** PWA manifest, JSON API pro záfuk, offline fronta, stránka „Stáhnout APK“, případně **.NET MAUI (C#)** místo WebView obalu.

## Roadmap

1. **Teď:** Capacitor APK → optica.lowpartners.net  
2. **Později (volitelně):** C# MAUI — buď další WebView obal pro učení C#, nebo nativní klient + REST API

## Projekty na serveru

| | URL | Poznámka |
|---|-----|----------|
| Produkce | `https://optica.lowpartners.net` | výchozí v `capacitor.config.json` |
| Beta | `https://lowpartners.net` | přepnutí `server.url` před buildem |

## Lokální vývoj obalu

Viz `mobile/android/README.md`:

```bash
cd mobile/android
npm install
npx cap add android    # jednou
npx cap sync android
npx cap open android     # Android Studio → Run / Build APK
```

## Symfony — co už funguje v WebView

- Mobilní CSS (`base.html.twig`, work index, pickery)
- `GET /sklad/spool/lookup`, `POST /sklad/spool/zaznam-prace`, `GET …/karta-embed`
- `BarcodeDetector` + Tesseract (HTTPS)

## Rizika / testy

- Starší WebView bez `BarcodeDetector` — otestovat cílové telefony
- Vypršení session — uživatel se znovu přihlásí
- Beta vs prod URL — nesmí se zaměnit v release APK omylem
