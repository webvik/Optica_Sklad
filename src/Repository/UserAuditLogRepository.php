<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAuditLog>
 */
class UserAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAuditLog::class);
    }

    /**
     * @return list<UserAuditLog>
     */
    public function findRecent(int $limit = 400): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        $qb = $this->qbOlderThan($cutoff)->select('COUNT(a.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(UserAuditLog::class, 'a')
            ->where('a.occurredAt < :cut')
            ->setParameter('cut', $cutoff)
            ->getQuery()
            ->execute();
    }

    private function qbOlderThan(\DateTimeImmutable $cutoff): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.occurredAt < :cut')
            ->setParameter('cut', $cutoff);
    }
}
