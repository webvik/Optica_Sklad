<?php

namespace App\Repository;

use App\Entity\Spool;
use App\Enum\SpoolEventType;
use App\Enum\SpoolStatus;
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
     * @param list<int>         $cableTypeIds
     * @param list<SpoolStatus> $statuses
     *
     * @return list<Spool>
     */
    public function findFiltered(array $cableTypeIds, array $statuses, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit);
        if ($cableTypeIds !== []) {
            $qb->andWhere('c.id IN (:cids)')
                ->setParameter('cids', $cableTypeIds);
        }
        if ($statuses !== []) {
            $qb->andWhere('s.status IN (:stss)')
                ->setParameter('stss', $statuses);
        }

        return $qb->getQuery()->getResult();
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
