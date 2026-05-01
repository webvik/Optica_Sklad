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
        return $this->findAllOrderedForCableTypePicker(true);
    }

    /**
     * Pořadí v nabídkách „Typ kabelu“: pole fiber_count z tabulky cable_type (PHP <=> jako celé číslo),
     * pak název — ne jako lexikografické řazení podle řetězce v name („12“ před „2“).
     *
     * @return list<CableType>
     */
    public function findAllOrderedForCableTypePicker(bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('c');
        if ($onlyActive) {
            $qb->andWhere('c.isActive = :a')->setParameter('a', true);
        }
        /** @var list<CableType> $list */
        $list = $qb->getQuery()->getResult();
        \usort($list, static function (CableType $a, CableType $b): int {
            $fc = $a->getFiberCount() <=> $b->getFiberCount();
            if (0 !== $fc) {
                return $fc;
            }

            return $a->getName() <=> $b->getName();
        });

        return $list;
    }
}
