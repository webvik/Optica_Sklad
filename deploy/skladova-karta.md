# Skladová karta (Excel / tisk) — záměr a mapování sloupců deníku

Etalon layoutu: `Optica_Sklad_Doc/SKLADOVÁ KARTA.xlsx` (list 1, rozsah cca A1:L48).

Implementace: `deploy/excel/skladova-karta.template.xlsx` + `SkladovaKartaExcelExporter` (jen hodnoty, layout ze šablony).

- **Náhled v prohlížeči** (mobil, bez stahování): `GET /sklad/spool/{id}/skladova-karta` — HTML stejná data jako Excel.
- **Stažení Excelu**: `GET /sklad/spool/{id}/skladova-karta.xlsx`

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
- DATUM NASKLADNĚNÍ → doplnit při mapování buněk (datum příjmu / první evidence)

## Technický postup

1. Šablona: `deploy/excel/skladova-karta.template.xlsx` (kopie etalonu z Doc).
2. Deník řazen podle `occurredAt`; ZŮSTATEK = běžící součet `totalLengthM − Σ usedMeters`.
3. Deník: list 1 řádky 9–48 (max 40 záznamů), list 2 stejný layout — zadní strana duplexu (dalších 40). Celkem max 80 záznamů; delší deník se ořízne.
4. POC: tisk z Excelu vs. referenční papír.
