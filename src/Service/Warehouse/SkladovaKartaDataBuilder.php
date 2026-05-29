<?php

declare(strict_types=1);

namespace App\Service\Warehouse;

use App\Entity\Spool;
use App\Entity\SpoolEvent;

/**
 * Data pro export skladové karty do Excelu.
 */
final class SkladovaKartaDataBuilder
{
    /** Deník na přední straně (řádky 9–25). */
    public const MAX_DIARY_ROWS_PER_PAGE = 17;

    /** Deník na zadní straně (řádky 27–48). */
    public const MAX_DIARY_ROWS_PAGE2 = 22;

    public const MAX_DIARY_ROWS = self::MAX_DIARY_ROWS_PER_PAGE + self::MAX_DIARY_ROWS_PAGE2;

    public function __construct(
        private readonly SpoolMeterService $meter,
    ) {
    }

    /**
     * @return array{
     *   registeredAt: ?\DateTimeImmutable,
     *   fiberLabel: string,
     *   familyLabel: string,
     *   note: string,
     *   diaryRows: list<array{occurredAt: \DateTimeImmutable, projectLabel: string, visibleM: ?int, remainingM: int}>,
     *   truncated: bool,
     *   totalDiaryCount: int
     * }
     */
    public function build(Spool $spool): array
    {
        $allRows = $this->buildDiaryRows($spool);
        $total = \count($allRows);
        $truncated = $total > self::MAX_DIARY_ROWS;
        $diaryRows = $truncated ? \array_slice($allRows, 0, self::MAX_DIARY_ROWS) : $allRows;

        $family = trim($spool->getFamily());

        return [
            'registeredAt' => $spool->getRegisteredAt() ?? $spool->getCreatedAt(),
            'fiberLabel' => $spool->getEffectiveFiberCount().' vl',
            'familyLabel' => '' !== $family ? mb_strtoupper($family, 'UTF-8') : '—',
            'note' => trim((string) ($spool->getNote() ?? '')),
            'diaryRows' => $diaryRows,
            'truncated' => $truncated,
            'totalDiaryCount' => $total,
        ];
    }

    /**
     * @return list<array{occurredAt: \DateTimeImmutable, projectLabel: string, visibleM: ?int, remainingM: int}>
     */
    private function buildDiaryRows(Spool $spool): array
    {
        $events = $spool->getEvents()->toArray();
        usort(
            $events,
            static function (SpoolEvent $a, SpoolEvent $b): int {
                $da = $a->getOccurredAt() ?? new \DateTimeImmutable('@0');
                $db = $b->getOccurredAt() ?? new \DateTimeImmutable('@0');
                $cmp = $da <=> $db;
                if (0 !== $cmp) {
                    return $cmp;
                }

                return ($a->getId() ?? 0) <=> ($b->getId() ?? 0);
            }
        );

        $running = $spool->getTotalLengthM();
        $rows = [];
        foreach ($events as $event) {
            $cells = $this->meter->diaryCells($event);
            $used = $event->getUsedMeters();
            if (null !== $used) {
                $running -= $used;
            }
            $rows[] = [
                'occurredAt' => $event->getOccurredAt() ?? new \DateTimeImmutable(),
                'projectLabel' => (string) ($cells['projectLabel'] ?? ''),
                'visibleM' => $cells['visibleM'],
                'remainingM' => $running,
            ];
        }

        return $rows;
    }
}
