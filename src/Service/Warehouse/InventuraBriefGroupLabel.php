<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\CableType;

/**
 * Jednotný text „skupiny“ jako ve zkrácené inventuře (vl. · family · Ø … mm).
 */
final class InventuraBriefGroupLabel
{
    /**
     * Jednotný klíč pro seskupení: prázdné = průměr neuveden, jinak normalizované desetinné číslo.
     */
    public static function normalizeDiameterKey(?string $diamRaw): string
    {
        if (null === $diamRaw) {
            return '';
        }
        $t = \trim((string) $diamRaw);
        if ('' === $t) {
            return '';
        }
        $t = \str_replace(',', '.', $t);
        if (!\is_numeric($t)) {
            return $t;
        }

        return (string) \round((float) $t, 2);
    }

    /** Stejný formát jako sloupec Skupina v krátké inventuře (např. 2 vl. · blown · Ø 2,0 mm). */
    public static function format(int $fiber, string $family, string $diameterKey): string
    {
        $base = $fiber.' vl. · '.$family;
        if ('' === $diameterKey) {
            return $base;
        }
        if (\is_numeric($diameterKey)) {
            $csv = \number_format((float) $diameterKey, 1, ',', '');

            return $base.' · Ø '.$csv.' mm';
        }

        return $base.' · Ø '.$diameterKey;
    }

    public static function forCableType(CableType $cableType): string
    {
        $fiber = $cableType->getFiberCount();
        $family = \trim($cableType->getFamily());
        if ('' === $family) {
            $family = '—';
        }
        $diamKey = self::normalizeDiameterKey($cableType->getDiameterMm());

        return self::format($fiber, $family, $diamKey);
    }
}
