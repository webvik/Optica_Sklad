<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DodaciList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DodaciList>
 */
class DodaciListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DodaciList::class);
    }

    /**
     * @return list<DodaciList>
     */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.pages', 'p')->addSelect('p')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
