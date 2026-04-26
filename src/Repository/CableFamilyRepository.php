<?php

namespace App\Repository;

use App\Entity\CableFamily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CableFamily>
 */
class CableFamilyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CableFamily::class);
    }

    /**
     * @return list<CableFamily>
     */
    public function findForPicker(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.isActive = true')
            ->orderBy('f.sortOrder', 'ASC')
            ->addOrderBy('f.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
