<?php

namespace App\Enum;

enum SpoolEventType: string
{
    case MeterReading = 'meter_reading';
    case Transfer = 'transfer';
    case Writeoff = 'writeoff';
    case Inventory = 'inventory';
    case Correction = 'correction';
    /** Záznam z nálepky: čtení metru (m) po pokládce v úseku, popis místa/stavby */
    case LaidSection = 'laid_section';
}
