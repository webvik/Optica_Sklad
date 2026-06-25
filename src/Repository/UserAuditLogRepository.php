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
    public function findRecent(int $limit = 400, int $offset = 0): array
    {
        return $this->findPaged($limit, $offset, 'time', 'desc');
    }

    /**
     * @return list<UserAuditLog>
     */
    public function findPaged(int $limit, int $offset, string $sort, string $direction): array
    {
        $sort = 'user' === $sort ? 'user' : 'time';
        $direction = 'asc' === strtolower($direction) ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('a');
        if ('user' === $sort) {
            $qb->orderBy('a.username', $direction)
                ->addOrderBy('a.occurredAt', 'DESC');
        } else {
            $qb->orderBy('a.occurredAt', $direction);
        }

        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $qb->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
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
