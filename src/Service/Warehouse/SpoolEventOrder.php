<?php

namespace App\Service\Warehouse;

use App\Entity\Spool;
use App\Entity\SpoolEvent;

/**
 * Řazení záznamů dle zobrazené metráže, ne dle data zápisu.
 * Směr: meter_sign cívky +1 = rostoucí m (výchozí i u null), −1 = klesající m.
 * Řádky bez m (předání, …) těsně za posledním „s metrem“ v pořadí id.
 */
final class SpoolEventOrder
{
    /**
     * @param list<SpoolEvent> $events
     *
     * @return list<SpoolEvent>
     */
    public static function byVisibleM(?int $meterSign, array $events): array
    {
        $list = $events;
        $asc = $meterSign !== -1;
        usort(
            $list,
            static function (SpoolEvent $a, SpoolEvent $b) use ($asc): int {
                $ma = $a->getVisibleM();
                $mb = $b->getVisibleM();
                $aHas = null !== $ma;
                $bHas = null !== $mb;
                if ($aHas && $bHas) {
                    if ($ma !== $mb) {
                        return $asc ? ($ma <=> $mb) : ($mb <=> $ma);
                    }

                    return ($a->getId() ?? 0) <=> ($b->getId() ?? 0);
                }
                if ($aHas) {
                    return -1;
                }
                if ($bHas) {
                    return 1;
                }

                return ($a->getId() ?? 0) <=> ($b->getId() ?? 0);
            }
        );

        return $list;
    }

    /**
     * @return list<SpoolEvent>
     */
    public static function forSpool(Spool $spool): array
    {
        return self::byVisibleM($spool->getMeterSign(), $spool->getEvents()->toArray());
    }

    /**
     * Poslední krok v řetězci čtení m (zafuk / úsek štítek).
     */
    public static function lastVisibleChainEvent(Spool $spool): ?SpoolEvent
    {
        $last = null;
        foreach (self::forSpool($spool) as $event) {
            if (SpoolMeterService::isVisibleMeterChainEventType($event->getType())) {
                $last = $event;
            }
        }

        return $last;
    }

    public static function isLastVisibleChainEvent(Spool $spool, SpoolEvent $event): bool
    {
        $last = self::lastVisibleChainEvent($spool);
        if (null === $last || null === $last->getId() || null === $event->getId()) {
            return false;
        }

        return $last->getId() === $event->getId();
    }
}
