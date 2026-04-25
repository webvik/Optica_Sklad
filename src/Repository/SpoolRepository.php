<?php

namespace App\Repository;

use App\Entity\Spool;
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
     * @return list<Spool>
     */
    public function findFiltered(?int $cableTypeId, ?SpoolStatus $status, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.cableType', 'c')
            ->addSelect('c')
            ->orderBy('s.reelNumber', 'ASC')
            ->setMaxResults($limit);
        if (null !== $cableTypeId) {
            $qb->andWhere('c.id = :cid')
                ->setParameter('cid', $cableTypeId);
        }
        if (null !== $status) {
            $qb->andWhere('s.status = :st')
                ->setParameter('st', $status);
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
            ->addOrderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
