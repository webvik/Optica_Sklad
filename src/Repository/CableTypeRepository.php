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

    public function findOneByCodeIgnoreCase(string $code): ?CableType
    {
        $code = \trim($code);
        if ('' === $code) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.code) = LOWER(:code)')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * Jedna ukázka kódu zásoby pro šedý placeholder ve formuláři (aktivní řádky).
     * Volitelně vynechá $excludeCode (např. aktuální při úpravě — ukáže jiný existující kód).
     */
    public function findExampleStockCode(?string $excludeCode = null): ?string
    {
        $first = $this->fetchExampleStockCodeQuery($excludeCode);
        if (null !== $first && '' !== $first) {
            return $first;
        }

        return $this->fetchExampleStockCodeQuery(null);
    }

    /**
     * @return non-empty-string|null
     */
    private function fetchExampleStockCodeQuery(?string $excludeCode): ?string
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.code')
            ->andWhere('c.isActive = :a')
            ->setParameter('a', true)
            ->orderBy('c.code', 'ASC')
            ->setMaxResults(1);
        if (null !== $excludeCode && '' !== $excludeCode) {
            $qb->andWhere('c.code != :nx')->setParameter('nx', $excludeCode);
        }

        $col = $qb->getQuery()->getSingleColumnResult();

        if ([] === $col) {
            return null;
        }

        $code = (string) $col[0];

        return '' === \trim($code) ? null : $code;
    }
}
