<?php

namespace App\Enum;

enum SpoolEventType: string
{
    case MeterReading = 'meter_reading';
    case Transfer = 'transfer';
    case Writeoff = 'writeoff';
    case Inventory = 'inventory';
    case Correction = 'correction';
    /** Dříve ve formuláři „úsek/štítek“; import, staré záznamy. Do UI formuláře se nevybírá. */
    case LaidSection = 'laid_section';
}
