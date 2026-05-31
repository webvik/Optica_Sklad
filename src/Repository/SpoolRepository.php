<?php

namespace App\Repository;

use App\Entity\Spool;
use App\Enum\SpoolEventType;
use App\Enum\SpoolStatus;
use App\Service\Warehouse\CableTypeBrowseFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Spool>
 */
class SpoolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Spool::class);
    }

    /**
     * Výběr podle 0–N typů a 0–N stavů. Prázdné pole = bez omezení v dané dimenzi.
     *
     * @param list<SpoolStatus> $statuses
     *
     * @return list<Spool>
     */
    public function findFiltered(
        CableTypeBrowseFilter $cableTypeFilter,
        array $statuses,
        ?string $reelQ = null,
        int $limit = 500,
        bool $onlyNeedsCorrection = false,
        bool $onlyWithoutWarehouseCard = false,
    ): array {
        $reelTrim = null !== $reelQ ? \trim($reelQ) : '';
        if ('' !== $reelTrim) {
            $ids = $this->searchIdsByReelWithinFilters($reelTrim, $cableTypeFilter, $statuses, $limit, $onlyNeedsCorrection, $onlyWithoutWarehouseCard);
            if ($ids === []) {
                return [];
            }

            return $this->loadSpoolsForBrowseByIds($ids);
        }

        $qb = $this->createFilteredQueryBuilder($cableTypeFilter, $statuses, $limit, $onlyNeedsCorrection, $onlyWithoutWarehouseCard);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Spool>
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.reelNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countWithoutWarehouseCard(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.warehouseCardPrintedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    public function findIdsWithoutWarehouseCard(int $limit = 500): array
    {
        /** @var list<string|int> $rowIds */
        $rowIds = $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('s.warehouseCardPrintedAt IS NULL')
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return \array_values(\array_map(static fn (mixed $v): int => (int) $v, $rowIds));
    }

    /**
     * Stejná logika jako {@see searchByReelInput}, ale jen v rámci filtrů přehledu skladu.
     *
     * @param list<SpoolStatus> $statuses
     *
     * @return list<int>
     */
    public function searchIdsByReelWithinFilters(
        string $q,
        CableTypeBrowseFilter $cableTypeFilter,
        array $statuses,
        int $limit = 500,
        bool $onlyNeedsCorrection = false,
        bool $onlyWithoutWarehouseCard = false,
    ): array {
        $q = \trim($q);
        if ('' === $q) {
            return [];
        }

        $exactQb = $this->createFilteredQueryBuilder($cableTypeFilter, $statuses, 1, $onlyNeedsCorrection, $onlyWithoutWarehouseCard)
            ->select('s.id')
            ->andWhere('LOWER(s.reelNumber) = LOWER(:q)')
            ->setParameter('q', $q);
        /** @var list<string|int> $exactIds */
        $exactIds = $exactQb->getQuery()->getSingleColumnResult();
        if ($exactIds !== []) {
            return [(int) $exactIds[0]];
        }

        $partialIds = $this->searchIdsByReelPartial($q, $limit);
        if ($partialIds === []) {
            return [];
        }

        $filterQb = $this->createQueryBuilder('s')
            ->select('s.id')
            ->leftJoin('s.cableType', 'c')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $partialIds)
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit);
        $this->applyCableTypeBrowseFilter($filterQb, $cableTypeFilter);
        if ($statuses !== []) {
            $filterQb->andWhere('s.status IN (:stss)')
                ->setParameter('stss', $statuses);
        }
        if ($onlyNeedsCorrection) {
            $filterQb->andWhere('s.needsCorrection = true');
        }
        if ($onlyWithoutWarehouseCard) {
            $filterQb->andWhere('s.warehouseCardPrintedAt IS NULL');
        }

        /** @var list<string|int> $rowIds */
        $rowIds = $filterQb->getQuery()->getSingleColumnResult();

        return \array_values(\array_map(static fn (mixed $v): int => (int) $v, $rowIds));
    }

    /**
     * @param list<SpoolStatus> $statuses
     */
    private function createFilteredQueryBuilder(
        CableTypeBrowseFilter $cableTypeFilter,
        array $statuses,
        int $limit,
        bool $onlyNeedsCorrection = false,
        bool $onlyWithoutWarehouseCard = false,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->orderBy('CASE WHEN s.fiberCount IS NOT NULL THEN s.fiberCount WHEN c.fiberCount IS NOT NULL THEN c.fiberCount ELSE 0 END', 'ASC')
            ->addOrderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit);
        $this->applyCableTypeBrowseFilter($qb, $cableTypeFilter);
        if ($statuses !== []) {
            $qb->andWhere('s.status IN (:stss)')
                ->setParameter('stss', $statuses);
        }
        if ($onlyNeedsCorrection) {
            $qb->andWhere('s.needsCorrection = true');
        }
        if ($onlyWithoutWarehouseCard) {
            $qb->andWhere('s.warehouseCardPrintedAt IS NULL');
        }

        return $qb;
    }

    private function applyCableTypeBrowseFilter(\Doctrine\ORM\QueryBuilder $qb, CableTypeBrowseFilter $filter): void
    {
        if (!$filter->restrictsCableDimension()) {
            return;
        }
        if ($filter->onlyWithAssignedType) {
            $qb->andWhere('c.id IS NOT NULL');

            return;
        }
        $hasIds = $filter->ids !== [];
        if ($hasIds && $filter->includeUnset) {
            $qb->andWhere('c.id IN (:browseCableTypeIds) OR c.id IS NULL')
                ->setParameter('browseCableTypeIds', $filter->ids);

            return;
        }
        if ($filter->includeUnset) {
            $qb->andWhere('c.id IS NULL');

            return;
        }
        $qb->andWhere('c.id IN (:browseCableTypeIds)')
            ->setParameter('browseCableTypeIds', $filter->ids);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Spool>
     */
    private function loadSpoolsForBrowseByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('CASE WHEN s.fiberCount IS NOT NULL THEN s.fiberCount WHEN c.fiberCount IS NOT NULL THEN c.fiberCount ELSE 0 END', 'ASC')
            ->addOrderBy('s.reelNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Inventurní seznam: pouze cívky na skladě (seřazené dle cívky, seskupení dělá kontroler).
     *
     * @return list<Spool>
     */
    public function findForInventuraSheet(int $limit = 5000): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->andWhere('s.status = :st')
            ->setParameter('st', SpoolStatus::InStock)
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Přesná shoda čísla saře, jinak částečné: podřetězec v reel_number, nebo
     * (pokud v dotazu aspoň 3 číslice za sebou) i shoda v „řetězci“ pouze z číslic
     * (tak se najde 450 u záznamu 450-1851 i když píšete 450, nebo 45 u 45x… až 3+ čísel).
     *
     * @return list<Spool>
     */
    public function searchByReelInput(string $q, int $limit = 25): array
    {
        $q = trim($q);
        if ('' === $q) {
            return [];
        }

        $exact = $this->createQueryBuilder('s')
            ->where('LOWER(s.reelNumber) = LOWER(:q)')
            ->setParameter('q', $q)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (null !== $exact) {
            return $this->loadSpoolsForLookupWithRelations([(int) $exact->getId()]);
        }

        $ids = $this->searchIdsByReelPartial($q, $limit);

        return $this->loadSpoolsForLookupWithRelations($ids);
    }

    /**
     * Částečné hledání: podřetězec v původním čísle + (≥3 číslice) shoda v „řetězci
     * jen číslic“ z pole i z dotazu — tak funguje 450-1470 i 4501470.
     * Vyžaduje MySQL 8+ / MariaDB 10.0.5+ (REGEXP_REPLACE).
     *
     * @return list<int>
     */
    private function searchIdsByReelPartial(string $q, int $limit): array
    {
        $lim = \max(1, \min(500, $limit));
        $likeFull = '%'.\mb_strtolower($q, 'UTF-8').'%';
        $digitRun = \preg_replace('/\D+/', '', $q) ?? '';
        $dLower = \mb_strtolower($digitRun, 'UTF-8');

        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT s.id FROM cable_spool s WHERE LOWER(s.reel_number) LIKE :likeFull';
        $params = [
            'likeFull' => $likeFull,
        ];
        if (\strlen($digitRun) >= 3) {
            $sql .= ' OR LOWER(s.reel_number) LIKE :likeDigits
                OR LOWER(REGEXP_REPLACE(s.reel_number, :nonDigit, :empt)) LIKE :likeNorm';
            $params['likeDigits'] = '%'.$dLower.'%';
            $params['likeNorm'] = '%'.$dLower.'%';
            $params['nonDigit'] = '[^0-9]+';
            $params['empt'] = '';
        }
        $sql .= ' ORDER BY s.reel_number ASC LIMIT '.$lim;

        try {
            /** @var list<string|int> $rowIds */
            $rowIds = $conn->executeQuery($sql, $params)->fetchFirstColumn();
        } catch (\Throwable) {
            return $this->searchIdsByReelPartialDqlFallback($q, $lim);
        }

        return \array_values(\array_map(static fn (mixed $v): int => (int) $v, $rowIds));
    }

    /**
     * Záloha bez REGEXP_REPLACE (např. starší DB / chyba driveru).
     *
     * @return list<int>
     */
    private function searchIdsByReelPartialDqlFallback(string $q, int $limit): array
    {
        $likeFull = '%'.\mb_strtolower($q, 'UTF-8').'%';
        $digitRun = \preg_replace('/\D+/', '', $q) ?? '';
        $dLower = \mb_strtolower($digitRun, 'UTF-8');
        $idsQb = $this->createQueryBuilder('s')
            ->select('s.id')
            ->where('LOWER(s.reelNumber) LIKE :likeFull')
            ->setParameter('likeFull', $likeFull)
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit);
        if (\strlen($digitRun) >= 3) {
            $idsQb
                ->orWhere('LOWER(s.reelNumber) LIKE :likeDigits')
                ->setParameter('likeDigits', '%'.$dLower.'%');
        }

        /** @var list<string|int> $rowIds */
        $rowIds = $idsQb
            ->getQuery()
            ->getSingleColumnResult();

        return \array_values(\array_map(static fn (mixed $v): int => (int) $v, $rowIds));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Spool>
     */
    private function loadSpoolsForLookupWithRelations(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->leftJoin('s.events', 'e')
            ->addSelect('e')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.reelNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Přesná shoda čísla saře (jen LOWER(trim) — bez dílčí digitální heuristiky vyhledávání).
     */
    public function findOneByReelNumberExactIgnoreCase(string $q): ?Spool
    {
        $q = \trim($q);
        if ('' === $q) {
            return null;
        }

        return $this->createQueryBuilder('s')
            ->where('LOWER(s.reelNumber) = LOWER(:q)')
            ->setParameter('q', $q)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Cívky s aspoň jedním záznamem, který vstupuje do řetězce m (odběr dle metru nebo úsek/štítek).
     *
     * @return list<int>
     */
    public function findIdsWithMeterReadingEvents(): array
    {
        /** @var list<int|string> $raw */
        $raw = $this->createQueryBuilder('s')
            ->select('s.id')
            ->distinct()
            ->join('s.events', 'e')
            ->where('e.type = :m OR e.type = :u')
            ->setParameter('m', SpoolEventType::MeterReading)
            ->setParameter('u', SpoolEventType::LaidSection)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return \array_values(\array_map(static fn (mixed $v): int => (int) $v, $raw));
    }

    /**
     * Cívka se všemi událostmi (pro přepočet / backfill).
     */
    public function findOneWithEventsById(int $id): ?Spool
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.events', 'e')
            ->addSelect('e')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
