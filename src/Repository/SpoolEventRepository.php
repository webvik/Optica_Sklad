<?php

namespace App\Repository;

use App\Entity\SpoolEvent;
use App\Enum\SpoolEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpoolEvent>
 */
class SpoolEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpoolEvent::class);
    }

    /**
     * Události s odběrem m na zakázce v období (pro seskupení jako u krátké inventury — vl., family, Ø).
     *
     * @return list<\App\Entity\SpoolEvent>
     */
    public function findUsageEventsForProjectsReport(
        \DateTimeImmutable $fromInclusive,
        \DateTimeImmutable $toInclusive,
    ): array {
        /** @var list<\App\Entity\SpoolEvent> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.spool', 's')
            ->addSelect('s')
            ->leftJoin('s.cableType', 'ct')
            ->addSelect('ct')
            ->leftJoin('e.createdBy', 'u')
            ->addSelect('u')
            ->where('e.occurredAt >= :from')
            ->andWhere('e.occurredAt <= :to')
            ->andWhere('e.usedMeters IS NOT NULL')
            ->andWhere('e.usedMeters > 0')
            ->andWhere('e.projectLabel IS NOT NULL')
            ->andWhere('e.projectLabel <> :empty')
            ->setParameter('from', $fromInclusive)
            ->setParameter('to', $toInclusive)
            ->setParameter('empty', '')
            ->orderBy('e.projectLabel', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Poslední poznámka k předání cívky (komu / kam), indexováno podle ID cívky.
     *
     * @param list<int> $spoolIds
     *
     * @return array<int, string>
     */
    public function findLatestTransferNotesBySpoolIds(array $spoolIds): array
    {
        if ($spoolIds === []) {
            return [];
        }

        /** @var list<SpoolEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->join('e.spool', 's')
            ->where('s.id IN (:ids)')
            ->andWhere('e.type = :transfer')
            ->setParameter('ids', $spoolIds)
            ->setParameter('transfer', SpoolEventType::Transfer)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($events as $event) {
            $spool = $event->getSpool();
            if (null === $spool || null === $spool->getId()) {
                continue;
            }
            $note = \trim((string) ($event->getNote() ?? ''));
            if ('' !== $note) {
                $out[$spool->getId()] = $note;
            }
        }

        return $out;
    }
}
