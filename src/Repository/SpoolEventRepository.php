<?php

namespace App\Repository;

use App\Entity\SpoolEvent;
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
}
