<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

/**
 * Extrakce řádků kabelových cívek z OCR textu dodacího listu (POHODA / Ma-Fia styl).
 * Experimentální — očekává „Šarže“ + kód KO-… + množství v metrech (i při typických OCR chybách).
 */
final class DodaciListParser
{
    /**
     * @return list<array{stockCode: string, reelNumber: string, lengthM: int, lengthInferred: bool, lengthMissing: bool}>
     */
    public function parse(string $ocrText): array
    {
        $text = $this->normalize($ocrText);
        if ('' === $text) {
            return [];
        }

        $rows = [];
        // Po normalizaci: „Šarže: “ + volitelný šum (| . :) + číslo saře (jen pomlčky, ne mezera+„0 M“)
        if (!\preg_match_all('/Šarže:\s*[|.:]*\s*([0-9]+(?:[\-—–][0-9]+)*)/u', $text, $matches, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[1] as [$reelRaw, $offset]) {
            $reel = $this->normalizeReelNumber((string) $reelRaw);
            if ('' === $reel || \strlen($reel) < 4) {
                continue;
            }
            // Množství bývá na řádku NAD saře (popis kabelu), kód KO- těsně před saře.
            $start = \max(0, $offset - 220);
            $chunk = \substr($text, $start, 300);
            $reelPos = $offset - $start;
            $before = \substr($chunk, 0, \max(0, $reelPos));
            $after = \substr($chunk, \max(0, $reelPos));

            if ($this->looksLikeTransportOnly($before, $after)) {
                continue;
            }

            // Délka jen z bloku tohoto řádku (ne z předchozí / následující saře).
            $block = $this->sliceAfterPreviousSarze($before);
            $afterOwn = $this->sliceBeforeNextSarze($after);
            $stockCode = $this->extractStockCode($block !== '' ? $block : $before);
            // Za saře (méně časté) — první hit; v bloku nad saře — poslední hit.
            $lengthM = $this->extractLengthM($afterOwn, false);
            if (null === $lengthM) {
                $lengthM = $this->extractLengthM($block, true);
            }

            $rows[] = [
                'stockCode' => $stockCode,
                'reelNumber' => $reel,
                'lengthM' => $lengthM ?? 0,
                'lengthInferred' => false,
                'lengthMissing' => null === $lengthM,
            ];
        }

        return $this->uniqueByReel($this->inferMissingLengths($rows));
    }

    /**
     * Hlavička dodacího listu: číslo dokladu + datum vystavení (best-effort z OCR).
     *
     * @return array{documentNumber: ?string, documentDate: ?string} documentDate = Y-m-d
     */
    public function extractDocumentMeta(string $ocrText): array
    {
        $text = $this->normalize($ocrText);
        $number = null;
        $date = null;

        if (\preg_match('/DODAC[IÍ]\s*LIST\s*(?:[cčCČ]\.?\s*)?[:|]?\s*(\d{6,12})\b/ui', $text, $m)) {
            $number = $m[1];
        } elseif (\preg_match('/\bDL\s*[:.]?\s*(\d{6,12})\b/u', $text, $m)) {
            $number = $m[1];
        }

        if (\preg_match('/Vystaveno\s*[:|]?\s*(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})\b/ui', $text, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if (\checkdate($mo, $d, $y)) {
                $date = \sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        return [
            'documentNumber' => $number,
            'documentDate' => $date,
        ];
    }

    private function normalize(string $ocrText): string
    {
        $t = \str_replace(["\r\n", "\r"], "\n", $ocrText);
        $t = \preg_replace('/[ \t]+/u', ' ', $t) ?? $t;

        // OCR: K0- → KO- (O jako nula)
        $t = \preg_replace('/\bK0-/u', 'KO-', $t) ?? $t;
        // OCR: 209%5m → 2095m; 450=020830 → 450-020830
        $t = \preg_replace('/(\d)%(\d)/u', '$1$2', $t) ?? $t;
        $t = \preg_replace('/(\d)=(\d)/u', '$1-$2', $t) ?? $t;
        // OCR: KO-02-9.2444 → KO-02-9-2444
        $t = \preg_replace('/\b(KO-\d+-\d+)\.([0-9A-Za-z]+)\b/u', '$1-$2', $t) ?? $t;

        // Šarže / Sarze / Sarte / Sarde / Šarče … ± : | . → jednotný „Šarže: “
        $t = \preg_replace(
            '/\b[SsŠš]ar[tzžčcde]{1,3}e?\b\s*[:|.]?\s*/ui',
            'Šarže: ',
            $t
        ) ?? $t;
        // Zbytečný svislítko / tečky hned za štítkem (OCR „Šarže: | 450-…“)
        $t = \preg_replace('/Šarže:\s*[|.:]+\s*/u', 'Šarže: ', $t) ?? $t;

        return \trim($t);
    }

    private function normalizeReelNumber(string $raw): string
    {
        $reel = \trim(\str_replace(['—', '–'], '-', $raw));
        $reel = \preg_replace('/-+/', '-', $reel) ?? $reel;
        if (\preg_match('/^(\d{3})(\d{6,})$/', $reel, $rm)) {
            $reel = $rm[1].'-'.$rm[2];
        }

        return $reel;
    }

    /** Text mezi předchozí „Šarže:“ a aktuální saře (popis + KO aktuálního řádku). */
    private function sliceAfterPreviousSarze(string $before): string
    {
        if (!\preg_match_all('/Šarže:\s*[|.:]*\s*[0-9]+(?:[\-—–][0-9]+)*/u', $before, $m, \PREG_OFFSET_CAPTURE)) {
            return $before;
        }
        $last = $m[0][\array_key_last($m[0])];
        $end = (int) $last[1] + \strlen((string) $last[0]);

        return \substr($before, $end);
    }

    /** Text za aktuální saře jen do další „Šarže:“ (ať L nevezme z následujícího řádku). */
    private function sliceBeforeNextSarze(string $after): string
    {
        if (\preg_match('/Šarže:/u', $after, $m, \PREG_OFFSET_CAPTURE)) {
            return \substr($after, 0, (int) $m[0][1]);
        }

        return $after;
    }

    private function extractStockCode(string $before): string
    {
        if (!\preg_match_all('/\b(KO-[0-9A-Z]+(?:-[0-9A-Z]+)*)\b/u', $before, $cm) || [] === $cm[1]) {
            return '';
        }
        $code = \strtoupper((string) $cm[1][\array_key_last($cm[1])]);
        // Častá záměna Z→2 v suffixu (Z444 → 2444)
        if (\preg_match('/^(KO-\d+-\d+-)2(\d{3})$/', $code, $m)) {
            $code = $m[1].'Z'.$m[2];
        }

        return $code;
    }

    private function looksLikeTransportOnly(string $before, string $after): bool
    {
        $ctx = $before.' '.$after;
        if (!\preg_match('/\b\d+\s*ks\b/iu', $ctx) && !\preg_match('/\bpřeprav/iu', $ctx)) {
            return false;
        }

        return !\preg_match('/\d[\d\s]*\s*m\b/iu', $ctx) && !\preg_match('/\bM\s+\d{3,6}\b/u', $ctx);
    }

    private function extractLengthM(string $chunk, bool $preferLast): ?int
    {
        /** @var array<int, int> $byOffset */
        $byOffset = [];

        if (\preg_match_all('/(\d{1,3}(?:[\x{00A0} ]\d{3})+|\d{3,6})\s*m\b/iu', $chunk, $mm, \PREG_OFFSET_CAPTURE)) {
            foreach ($mm[1] as [$raw, $off]) {
                $n = (int) \preg_replace('/\s+/u', '', (string) $raw);
                if ($n >= 100 && $n <= 50000) {
                    $byOffset[(int) $off] = $n;
                }
            }
        }
        // Sloupec množství: „M 2095“ / „M. 2095“ / „M 2000 n“ (OCR m→n)
        if (\preg_match_all('/\bM[\s.:]+(\d{3,6})\b/u', $chunk, $mm, \PREG_OFFSET_CAPTURE)) {
            foreach ($mm[1] as [$raw, $off]) {
                $n = (int) $raw;
                if ($n >= 100 && $n <= 50000) {
                    $byOffset[(int) $off] = $n;
                }
            }
        }

        if ([] === $byOffset) {
            return null;
        }
        \ksort($byOffset);
        $vals = \array_values($byOffset);

        return $preferLast ? $vals[\array_key_last($vals)] : $vals[0];
    }

    /**
     * Chybí-li L u řádku, vezmi medián délek jiných řádků se stejným KO- (typicky ~2095 u blown).
     *
     * @param list<array{stockCode: string, reelNumber: string, lengthM: int, lengthInferred: bool, lengthMissing: bool}> $rows
     *
     * @return list<array{stockCode: string, reelNumber: string, lengthM: int, lengthInferred: bool, lengthMissing: bool}>
     */
    private function inferMissingLengths(array $rows): array
    {
        $byCode = [];
        foreach ($rows as $r) {
            if ($r['lengthM'] >= 100 && '' !== $r['stockCode']) {
                $byCode[$r['stockCode']][] = $r['lengthM'];
            }
        }
        foreach ($rows as $i => $r) {
            if ($r['lengthM'] >= 100) {
                continue;
            }
            $code = $r['stockCode'];
            if ('' === $code || empty($byCode[$code])) {
                continue;
            }
            $vals = $byCode[$code];
            \sort($vals);
            $rows[$i]['lengthM'] = $vals[(int) \floor((\count($vals) - 1) / 2)];
            $rows[$i]['lengthInferred'] = true;
            $rows[$i]['lengthMissing'] = false;
        }

        return $rows;
    }

    /**
     * @param list<array{stockCode: string, reelNumber: string, lengthM: int, lengthInferred: bool, lengthMissing: bool}> $rows
     *
     * @return list<array{stockCode: string, reelNumber: string, lengthM: int, lengthInferred: bool, lengthMissing: bool}>
     */
    private function uniqueByReel(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = \strtolower($row['reelNumber']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }
}
