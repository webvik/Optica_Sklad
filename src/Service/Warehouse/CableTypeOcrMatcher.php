<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\CableType;
use App\Repository\CableTypeRepository;

/**
 * Porovnání OCR textu z výrobního štítku s katalogovými typy (kód, název, popis…).
 */
final class CableTypeOcrMatcher
{
    private const ACTIVE_ONLY = true;

    /** Nejlepší skóre musí překročit tento práh (autom. výběr) */
    private const MIN_ACCEPT_SCORE = 62.0;

    /** Rozdíl oproti druhému kandidátovi */
    private const MIN_MARGIN = 3.5;

    /** Nejhorší score v seznamu kandidátů vráceného do UI — filtr nesmyslů */
    private const MIN_SHOW_CANDIDATE_SCORE = 18.0;

    private const OCR_MAX_PREVIEW = 12;

    public function __construct(
        private readonly CableTypeRepository $cableTypes,
    ) {
    }

    /**
     * @return array{
     *   matched: bool,
     *   score: float,
     *   margin: float,
     *   cableType: ?CableType,
     *   normalizedQuery: string,
     *   hints: array<string, int|string|null>,
     *   candidates: list<array{cableType: CableType, score: float}>
     * }
     */
    public function matchWithCandidates(string $raw, ?int $maxCandidates = null): array
    {
        $limit = min(20, max(1, $maxCandidates ?? self::OCR_MAX_PREVIEW));
        $raw = trim($raw);
        if ('' === $raw || \strlen($raw) > 65535) {
            return [
                'matched' => false,
                'score' => 0.0,
                'margin' => 0.0,
                'cableType' => null,
                'normalizedQuery' => '',
                'hints' => [],
                'candidates' => [],
            ];
        }

        $qNorm = $this->normalize($raw);
        if ('' === $qNorm || \strlen($qNorm) < 2) {
            return [
                'matched' => false,
                'score' => 0.0,
                'margin' => 0.0,
                'cableType' => null,
                'normalizedQuery' => $qNorm,
                'hints' => [],
                'candidates' => [],
            ];
        }

        $qCompact = $this->alnumLower($raw);
        $hints = $this->extractStructuralHintsFromLabel($raw);
        /** @var list<CableType> $list */
        $list = $this->cableTypes->findAllOrderedForCableTypePicker(self::ACTIVE_ONLY);

        $ranked = [];
        foreach ($list as $c) {
            $base = $this->scoreCableType($c, $qNorm, $qCompact);
            $bonus = $this->heuristicStructuralBonus($c, $hints);
            $score = min(99.98, max(0.0, $base + $bonus));
            $ranked[] = ['cableType' => $c, 'score' => $score];
        }

        \usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
        );

        $bestScore = -1.0;
        $bestType = null;
        $secondBest = -1.0;
        if ([] !== $ranked) {
            $bestScore = $ranked[0]['score'];
            $bestType = $ranked[0]['cableType'];
            $secondBest = \count($ranked) >= 2 ? $ranked[1]['score'] : -1.0;
        }
        $margin = $bestScore - ((-1.0 === $secondBest) ? 0.0 : $secondBest);

        $candidates = [];
        foreach ($ranked as $row) {
            if ($row['score'] < self::MIN_SHOW_CANDIDATE_SCORE) {
                continue;
            }
            $candidates[] = ['cableType' => $row['cableType'], 'score' => $row['score']];
            if (\count($candidates) >= $limit) {
                break;
            }
        }

        return [
            'matched' => $bestType instanceof CableType
                && $bestScore >= self::MIN_ACCEPT_SCORE
                && $margin >= self::MIN_MARGIN,
            'score' => max(0.0, $bestScore),
            'margin' => $margin,
            'cableType' => ($bestScore >= self::MIN_ACCEPT_SCORE && $margin >= self::MIN_MARGIN) ? $bestType : null,
            'normalizedQuery' => $qNorm,
            'hints' => $this->hintsToApiPayload($hints),
            'candidates' => $candidates,
        ];
    }

    /**
     * Zpětná kompatibilita; deleguje na matchWithCandidates (bez použití kandidátů navíc).
     *
     * @return array<string, mixed>
     */
    public function matchBest(string $raw): array
    {
        $r = $this->matchWithCandidates($raw, 5);

        return [
            'matched' => $r['matched'],
            'score' => $r['score'],
            'margin' => $r['margin'],
            'cableType' => $r['cableType'],
            'normalizedQuery' => $r['normalizedQuery'],
        ];
    }

    private function normalize(string $s): string
    {
        $s = \mb_strtolower($s, 'UTF-8');
        /** @var string $s */
        $s = (string) \preg_replace('/[^\p{L}\p{N}+÷°øØµ.]+/u', ' ', $s);
        $s = \trim((string) \preg_replace('/\s+/u', ' ', $s));

        return $s;
    }

    private function alnumLower(string $s): string
    {
        $s = \mb_strtolower($s, 'UTF-8');
        $s = \preg_replace('/[^a-z0-9]/', '', $s);

        return (string) $s;
    }

    private function scoreCableType(CableType $c, string $qNorm, string $qCompact): float
    {
        $code = \trim($c->getCode());
        $name = \trim($c->getName());
        $fam = \trim($c->getFamily());
        $full = \trim((string) ($c->getFullDescription() ?? ''));
        $brief = InventuraBriefGroupLabel::forCableType($c);
        $constr = \trim((string) ($c->getConstructionCode() ?? ''));

        $codeCompact = $this->alnumLower($code);

        $max = 0.0;

        if (\strlen($codeCompact) >= 3 && '' !== $qCompact) {
            if (\str_contains($qCompact, $codeCompact)) {
                $max = \max($max, 95.0);
            }
        }

        $needles = [];
        foreach ([$code, $name, $brief, $constr] as $p) {
            if ('' !== $p) {
                $needles[] = $this->normalize($p);
            }
        }
        if ('' !== $full) {
            $firstLine = \explode("\n", \str_replace("\r", '', $full), 2)[0];
            if ('' !== \trim($firstLine)) {
                $needles[] = $this->normalize($firstLine);
            }
        }

        foreach ($needles as $needle) {
            $max = \max($max, $this->phraseContainScore($qNorm, $needle));
        }

        if ('' !== $name) {
            $max = \max($max, $this->similarScore($qNorm, $this->normalize($name)));
        }
        if ('' !== $code) {
            $max = \max($max, $this->similarScore($qNorm, $this->normalize($code)));
        }

        /** Krátké tokeny typu GN652D bez mezer — křižovat alnum kusy OCR */
        if (\strlen($codeCompact) >= 4 && '' !== $qCompact) {
            $max = \max($max, $this->longestOverlapScore($qCompact, $codeCompact) * 0.85);
        }

        if ('' !== $fam && \str_contains(\mb_strtolower($qNorm, 'UTF-8'), \mb_strtolower($fam, 'UTF-8'))) {
            $max += 2.5;
        }

        return $max;
    }

    /** Skórovať jak „jsou řetězce blízko“. */
    private function phraseContainScore(string $haystackNorm, string $needleNorm): float
    {
        if ('' === $needleNorm) {
            return 0.0;
        }
        if ($haystackNorm === $needleNorm) {
            return 88.0;
        }

        $nl = \mb_strlen($needleNorm);
        $hl = \mb_strlen($haystackNorm);

        if (\mb_strpos($haystackNorm, $needleNorm) !== false) {
            return 72.0 + \min((float) $nl / \max((float) $hl, 1.0) * 36.0, 22.0);
        }
        if ($nl >= 8 && \mb_strpos($needleNorm, $haystackNorm) !== false) {
            return 62.0 + \min(\min((float) $hl / (float) $nl, 1.0) * 26.0, 18.0);
        }

        return 0.0;
    }

    private function similarScore(string $a, string $b): float
    {
        if ('' === $a || '' === $b) {
            return 0.0;
        }
        if ($a === $b) {
            return 82.0;
        }
        \similar_text($a, $b, $percent);

        /** similar_text udává jen podobnou část v % z průměru délek */
        return $percent * 0.85;
    }

    /** Přiblížená shoda dílčích bloků bez mezer — max 80 */
    private function longestOverlapScore(string $hayCompact, string $needleCompact): float
    {
        if ('' === $needleCompact) {
            return 0.0;
        }

        /** @see max substr of needle appearing in hay */
        $needleLen = \strlen($needleCompact);
        $best = 0;

        $from = \min(\strlen($hayCompact), \strlen($needleCompact));
        for ($len = $from; $len >= 4; --$len) {
            for ($i = 0; $i <= $needleLen - $len; ++$i) {
                $sub = \substr($needleCompact, $i, $len);
                if ('' !== $sub && \str_contains($hayCompact, $sub)) {
                    $best = \max($best, $len);
                }
            }
        }

        if (0 === $best) {
            return 0.0;
        }

        return ($best / (float) $needleLen) * 82.0;
    }

    /**
     * Extrakce typických bloků štítku – NE9 (vlákna), UNIT∅ mm, blown, Z‑kód.
     *
     * @return array{
     *   fiberCount?: int,
     *   fiberEBlockTail?: int,
     *   diameterMm?: float,
     *   diameterRaw?: string,
     *   constructionCode?: string,
     *   familyGuess?: string
     * }
     */
    private function extractStructuralHintsFromLabel(string $raw): array
    {
        $text = \trim(\str_replace(["\r\n", "\r"], "\n", $raw));
        if ('' === $text) {
            return [];
        }

        $hints = [];

        if (1 === \preg_match('/\b(\d{1,4})\s*[Ee]\s*(\d{1,4})\b/u', $text, $me)) {
            $f = (int) $me[1];
            $tail = (int) $me[2];
            if ($f > 0 && $f < 2000) {
                $hints['fiberCount'] = $f;
                $hints['fiberEBlockTail'] = $tail;
            }
        }

        if (1 === \preg_match('/\bUNIT\b[^\d\r\n]{0,16}?([0-9]{1,2})\s*[.,]\s*([0-9])\b/u', $text, $mu)) {
            $dStr = $mu[1].'.'.$mu[2];
            if (\is_numeric($dStr)) {
                $d = (float) $dStr;
                if ($d > 0.0 && $d < 100.0) {
                    $hints['diameterMm'] = $d;
                    $hints['diameterRaw'] = $dStr;
                }
            }
        }

        if (1 === \preg_match('/\b(Z\d{3,}[A-Za-z]?)\\b/u', $text, $mz)) {
            $hints['constructionCode'] = \mb_substr((string) $mz[1], 0, 31);
        }

        if (1 === \preg_match('/\b(?:BLOW[NŇ]?|BLWN)\\b/ui', $text)) {
            $hints['familyGuess'] = 'blown';
        } else {
            foreach (['mlt', 'drop', 'fletka', 'blown'] as $code) {
                if (1 === \preg_match('/\b'.\preg_quote($code, '/').'\\b/ui', $text)) {
                    $hints['familyGuess'] = $code;

                    break;
                }
            }
        }

        return $hints;
    }

    /**
     * Část „hintů“ bezpečně pro JSON odpověď (kulatá Ø v mm jako číslo).
     *
     * @param array{fiberCount?: int, fiberEBlockTail?: int, diameterMm?: float, diameterRaw?: string, constructionCode?: string, familyGuess?: string} $hints
     *
     * @return array<string, float|int|string>
     */
    private function hintsToApiPayload(array $hints): array
    {
        $out = [];

        if (isset($hints['fiberCount'])) {
            $out['fiberCount'] = (int) $hints['fiberCount'];
        }
        if (isset($hints['fiberEBlockTail'])) {
            $out['fiberEBlockTail'] = (int) $hints['fiberEBlockTail'];
        }
        if (isset($hints['constructionCode'])) {
            $out['constructionCode'] = (string) $hints['constructionCode'];
        }
        if (isset($hints['familyGuess'])) {
            $out['familyGuess'] = (string) $hints['familyGuess'];
        }
        if (isset($hints['diameterMm'])) {
            $out['diameterMm'] = \round((float) $hints['diameterMm'], 2);
        }
        if (isset($hints['diameterRaw'])) {
            $out['diameterRaw'] = (string) $hints['diameterRaw'];
        }

        return $out;
    }

    /** Bonus ke skóre podle strukturálních shod štítek ↔ záznam (omezen horní částí kvůli stabilitě). */
    private function heuristicStructuralBonus(CableType $c, array $hints): float
    {
        $bonus = 0.0;
        $fam = \mb_strtolower(\trim($c->getFamily()), 'UTF-8');

        if (isset($hints['fiberCount'])) {
            $qf = (int) $hints['fiberCount'];
            $cf = $c->getFiberCount();
            if ($cf > 0) {
                if ($cf === $qf) {
                    $bonus += 28.0;
                } elseif (\abs($cf - $qf) <= 1) {
                    $bonus += 15.0;
                } elseif (\abs($cf - $qf) <= 4) {
                    $bonus += 8.0;
                }
            }
        }

        if (isset($hints['diameterMm'])) {
            $qd = (float) $hints['diameterMm'];
            $dstr = $c->getDiameterMm();
            if (null !== $dstr && '' !== (string) $dstr) {
                $cd = (float) \str_replace(',', '.', (string) $dstr);
                if ($cd > 0.0) {
                    $delta = \abs($cd - $qd);
                    if ($delta < 0.051) {
                        $bonus += 22.0;
                    } elseif ($delta < 0.21) {
                        $bonus += 12.0;
                    } elseif ($delta < 0.51) {
                        $bonus += 6.0;
                    }
                }
            }
        }

        if (isset($hints['constructionCode']) && '' !== $hints['constructionCode']) {
            $hRaw = \mb_strtoupper(\trim((string) $hints['constructionCode']), 'UTF-8');
            $code = \mb_strtoupper(\trim((string) ($c->getConstructionCode() ?? '')), 'UTF-8');
            if ('' !== $code) {
                if ($hRaw === $code) {
                    $bonus += 20.0;
                } elseif (\str_contains($code, $hRaw) || \str_contains($hRaw, $code)) {
                    $bonus += 12.0;
                }
            }
        }

        if (isset($hints['familyGuess'])) {
            $guess = \mb_strtolower(\trim((string) $hints['familyGuess']), 'UTF-8');
            if ('' !== $guess && \str_contains($fam, $guess)) {
                $bonus += 12.0;
            }
        }

        return \min(52.0, $bonus);
    }

    /**
     * Jemné dopočty z OCR pro předběžné vyplnění nového CableType — uživatel dál upravuje.
     *
     * @return array{
     *   name: string,
     *   fullText: string,
     *   fiberCount?: int,
     *   diameterMm?: string,
     *   constructionCode?: string,
     *   familyCode?: string
     * }
     */
    public function extractSuggestedFields(string $raw): array
    {
        $raw = \trim(\str_replace(["\r\n", "\r"], "\n", $raw));
        if ('' === $raw) {
            return ['name' => '', 'fullText' => ''];
        }

        $fullText = \mb_substr($raw, 0, 8000);
        $lines = \preg_split("/\n+/u", $fullText) ?: [];
        $firstNonEmpty = '';
        foreach ($lines as $ln) {
            $t = \trim((string) $ln);
            if ('' !== $t) {
                $firstNonEmpty = $t;
                break;
            }
        }

        $name = '' !== $firstNonEmpty ? \mb_substr($firstNonEmpty, 0, 254) : \mb_substr($fullText, 0, 120);

        $hintsStruct = $this->extractStructuralHintsFromLabel($fullText);

        $fiberCount = null;
        if (isset($hintsStruct['fiberCount'])) {
            $n = (int) $hintsStruct['fiberCount'];
            if ($n > 0 && $n < 2000) {
                $fiberCount = $n;
            }
        }
        if (null === $fiberCount && 1 === \preg_match('/(?:^|[\\s,;])(\\d{1,4})\\s*(?:vl|vl\\.|x?\\s*fibr|optic|optic\\.|fib(er)?\\b)/ui', $fullText, $mf)) {
            $n = (int) $mf[1];
            if ($n > 0 && $n < 2000) {
                $fiberCount = $n;
            }
        }

        $diameterMm = null;
        if (isset($hintsStruct['diameterMm'])) {
            $diameterMm = (string) \round((float) $hintsStruct['diameterMm'], 2);
        }
        if (null === $diameterMm && 1 === \preg_match('/(?: ø|dia\\.?|\\b[pP]r[uů]?m\\.?|\\b[oOØ]|\\bdd\\s*\\.?|\\bmm)\\s*[:=]?\\s*([0-9]{1,2}[,.]?[0-9]{1,4}|[0-9]{1,2}\\b)/u', $fullText, $md)) {
            $d = \trim(\str_replace(' ', '', (string) $md[1]));
            $d = \str_replace(',', '.', $d);
            if ('' !== $d && \is_numeric($d)) {
                $diameterMm = $d;
            }
        }

        $constructionCode = null;
        if (isset($hintsStruct['constructionCode']) && '' !== (string) $hintsStruct['constructionCode']) {
            $constructionCode = (string) $hintsStruct['constructionCode'];
        }
        if (null === $constructionCode && 1 === \preg_match('/\\b(Z\\d{3,}[A-Za-z]?)\\b/u', $fullText, $mz)) {
            $constructionCode = \mb_substr((string) $mz[1], 0, 31);
        }

        $familyCode = $hintsStruct['familyGuess'] ?? null;
        if (null === $familyCode) {
            foreach (['blown', 'mlt', 'drop', 'fletka'] as $code) {
                if (1 === \preg_match('/\\b'.\preg_quote($code, '/').'\\b/ui', $fullText)) {
                    $familyCode = $code;
                    break;
                }
            }
        }

        return array_merge(
            ['name' => $name, 'fullText' => $fullText],
            null !== $fiberCount ? ['fiberCount' => $fiberCount] : [],
            null !== $diameterMm ? ['diameterMm' => $diameterMm] : [],
            null !== $constructionCode ? ['constructionCode' => $constructionCode] : [],
            null !== $familyCode ? ['familyCode' => $familyCode] : []
        );
    }
}