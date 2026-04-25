<?php

namespace App\Enum;

enum SpoolStatus: string
{
    case InStock = 'in_stock';
    case Transferred = 'transferred';
    case WrittenOff = 'written_off';
}
