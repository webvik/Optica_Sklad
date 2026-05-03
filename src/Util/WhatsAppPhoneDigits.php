<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Přibližná normalizace telefonního čísla pro odkaz „wa.me“ (jen číslice včetně mezinár. předvolby).
 */
final class WhatsAppPhoneDigits
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($raw ?? ''));
        if (!\is_string($digits)) {
            return null;
        }

        $digits = (string) $digits;
        if ('' === $digits) {
            return null;
        }

        /* Často zadávají 9 platných číslic tuzemského čísla bez +420 — doplnění. */
        if (9 === \strlen($digits) && (str_starts_with($digits, '6') || str_starts_with($digits, '7'))) {
            $digits = '420'.$digits;
        }

        if (\strlen($digits) < 10 || \strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
