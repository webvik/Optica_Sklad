<?php

namespace App\Repository;

use App\Entity\CableType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CableType>
 */
class CableTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CableType::class);
    }

    public function findAllActiveByName(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :a')
            ->setParameter('a', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
