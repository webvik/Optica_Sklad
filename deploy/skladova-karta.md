# Skladová karta (Excel / tisk) — záměr a mapování sloupců deníku

Etalon layoutu: `Optica_Sklad_Doc/SKLADOVÁ KARTA.xlsx` (list 1, rozsah cca A1:L48).

Implementace: `deploy/excel/skladova-karta.template.xlsx` + `SkladovaKartaExcelExporter` (jen hodnoty, layout ze šablony). Route: `GET /sklad/spool/{id}/skladova-karta.xlsx`

**UI:** na desktopu (≥42rem) odkaz „Skladová karta (Excel)“ — stažení souboru. Na mobilu „Skladová karta — WhatsApp“: Excel se na serveru převede na **PDF** (LibreOffice, `libreoffice-calc`), PDF se sdílí přes Web Share API nebo jako odkaz ke stažení (token 48 h, route `skladova-karta-sdilet/{token}.pdf`).

**Server:** `sudo apt install libreoffice-calc` (Rocky: `dnf install libreoffice-calc libreoffice-headless`). Volitelně `SKLADOVA_KARTA_LO_BIN` v `.env.local`.

**PDF vs Excel tisk:** export `.xlsx` pro desktop = scale **100 %**, okraje ze šablony (jako papír). Cesta Excel → PDF (LibreOffice) používá scale **81 %** a menší spodní okraj — jinak LO rozdělí kartu na 4 strany místo 2. Šablona `deploy/excel/skladova-karta.template.xlsx` = kopie etalonu včetně výšek řádků.

## Sloupce deníku na papíru (zavedená praxe — dodržet)

| Sloupec na kartě | Význam | Zdroj v Optickém skladu |
|------------------|--------|-------------------------|
| **DATUM** | Datum události | `SpoolEvent.occurredAt` |
| **ZAČÁTEK** | Objekt / zakázka (stavba, zakázka) | `SpoolEvent.projectLabel` |
| **KONEC** | Viditelné čtení metru na kabelu (m) | `SpoolEvent.visibleM` |
| **ZŮSTATEK** | Zůstatek v evidenci po kroku (m) | dopočítat z deníku / `current_remaining_m` po událostech |

Pozn.: název sloupce **ZAČÁTEK** na papíru **neznamená** „počáteční metráž kroku“ — vždy se tam zapisuje **zakázka / objekt**.

**KONEC** = vždy **viditelné m** (stejná hodnota jako u zafuku v poli „Běžný stav“ / `visible_m` v DB).

## Rozdíl oproti webové tabulce „Deník“ na kartě cívky

Web (`_spool_summary_and_diary.html.twig`) má jiné sloupce: m (čtení), Délka, Zakázka, Kdy, Pozn. — to je UI pro práci v programu.

Export skladové karty do Excelu musí kopírovat **papírový** layout (DATUM / ZAČÁTEK / KONEC / ZŮSTATEK), ne webovou tabulku 1:1.

## Hlavička karty (orientačně, doplnit cell map při implementaci)

- ŠARŽE → `Spool.reelNumber`
- CELKOVÁ METRÁŽ → `Spool.totalLengthM`
- POČET VLÁKEN / typ → `effectiveFiberCount`, `family`, popis kabelu
- POČÁTEČNÍ STAV → `Spool.initialVisibleM`
- DATUM NASKLADNĚNÍ → `registeredAt` / `createdAt` (buňka D2)
- POZNÁMKA → průměr kabelu (mm), buňka F5: `Spool.effectiveDiameterMm` (cívka nebo typ z katalogu)

## Technický postup

1. Šablona: `deploy/excel/skladova-karta.template.xlsx` (kopie etalonu z Doc).
2. Deník řazen podle `occurredAt`; ZŮSTATEK = běžící součet `totalLengthM − Σ usedMeters`.
3. **Jeden list**, dvě tiskové stránky: ř. 1–25 (hlavička karty + deník), zlom **po ř. 25**, ř. 26–48 (jen hlavička deníku + pokračování). Bez „Přizpůsobit stránce“. Max 39 záznamů.
4. POC: tisk z Excelu vs. referenční papír.
