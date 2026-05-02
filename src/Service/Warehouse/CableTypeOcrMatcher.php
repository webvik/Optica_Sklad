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

    /** Nejlepší skóre musí překročit tento práh */
    private const MIN_ACCEPT_SCORE = 62.0;

    /** Rozdíl oproti druhému kandidátovi */
    private const MIN_MARGIN = 3.5;

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
     *   normalizedQuery: string
     * }
     */
    public function matchBest(string $raw): array
    {
        $raw = \trim($raw);
        if ('' === $raw || \strlen($raw) > 65535) {
            return [
                'matched' => false,
                'score' => 0.0,
                'margin' => 0.0,
                'cableType' => null,
                'normalizedQuery' => '',
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
            ];
        }

        $qCompact = $this->alnumLower($raw);
        /** @var list<CableType> $list */
        $list = $this->cableTypes->findAllOrderedForCableTypePicker(self::ACTIVE_ONLY);

        $bestScore = -1.0;
        $bestType = null;
        $secondBest = -1.0;

        foreach ($list as $c) {
            $s = $this->scoreCableType($c, $qNorm, $qCompact);
            if ($s > $bestScore) {
                $secondBest = $bestScore;
                $bestScore = $s;
                $bestType = $c;
            } elseif ($s > $secondBest) {
                $secondBest = $s;
            }
        }

        $secondForMargin = (-1.0 === $secondBest) ? 0.0 : $secondBest;
        $margin = $bestScore - $secondForMargin;

        return [
            'matched' => $bestType instanceof CableType
                && $bestScore >= self::MIN_ACCEPT_SCORE
                && $margin >= self::MIN_MARGIN,
            'score' => \max(0.0, $bestScore),
            'margin' => $margin,
            'cableType' => ($bestScore >= self::MIN_ACCEPT_SCORE && $margin >= self::MIN_MARGIN) ? $bestType : null,
            'normalizedQuery' => $qNorm,
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

        $fiberCount = null;
        if (1 === \preg_match('/(?:^|[\\s,;])(\\d{1,4})\\s*(?:vl|vl\\.|x?\\s*fibr|optic|optic\\.|fib(er)?\\b)/ui', $fullText, $mf)) {
            $n = (int) $mf[1];
            if ($n > 0 && $n < 2000) {
                $fiberCount = $n;
            }
        }

        $diameterMm = null;
        if (1 === \preg_match('/(?: ø|dia\\.?|\\b[pP]r[uů]?m\\.?|\\b[oOØ]|\\bdd\\s*\\.?|\\bmm)\\s*[:=]?\\s*([0-9]{1,2}[,.]?[0-9]{1,4}|[0-9]{1,2}\\b)/u', $fullText, $md)) {
            $d = \trim(\str_replace(' ', '', (string) $md[1]));
            $d = \str_replace(',', '.', $d);
            if ('' !== $d && \is_numeric($d)) {
                $diameterMm = $d;
            }
        }

        $constructionCode = null;
        if (1 === \preg_match('/\\b(Z\\d{3,}[A-Za-z]?)\\b/u', $fullText, $mz)) {
            $constructionCode = \mb_substr((string) $mz[1], 0, 31);
        }

        $familyCode = null;
        foreach (['blown', 'mlt', 'drop', 'fletka'] as $code) {
            if (\preg_match('/\\b'. \preg_quote($code, '/') . '\\b/ui', $fullText) === 1) {
                $familyCode = $code;
                break;
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