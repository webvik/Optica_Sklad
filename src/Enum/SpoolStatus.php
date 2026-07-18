<?php

namespace App\Enum;

enum SpoolStatus: string
{
    case InStock = 'in_stock';
    /** Přijato podle dokladu, ještě nerozbaleno (bez saře / PS). */
    case ReceivedSealed = 'received_sealed';
    case Transferred = 'transferred';
    case WrittenOff = 'written_off';

    /** Stavy ve filtru Přehled skladu (bez nerozbalených — ty mají vlastní checkbox). */
    public static function browseableCases(): array
    {
        return [
            self::InStock,
            self::Transferred,
            self::WrittenOff,
        ];
    }
}
