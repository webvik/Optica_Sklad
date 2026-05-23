<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectReportAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectReportAlias>
 */
class ProjectReportAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectReportAlias::class);
    }

    /**
     * @return array<string, string> alias_normalized => canonical_label
     */
    public function getNormalizedToCanonicalMap(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.aliasNormalized', 'a.canonicalLabel')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['aliasNormalized']] = (string) $row['canonicalLabel'];
        }

        return $map;
    }

    /**
     * @return list<ProjectReportAlias>
     */
    public function findAllOrdered(): array
    {
        /** @var list<ProjectReportAlias> */
        return $this->createQueryBuilder('a')
            ->orderBy('a.canonicalLabel', 'ASC')
            ->addOrderBy('a.aliasLabel', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByAliasNormalized(string $normalized): ?ProjectReportAlias
    {
        return $this->findOneBy(['aliasNormalized' => $normalized]);
    }

    /**
     * @return list<string>
     */
    public function findDistinctCanonicalLabels(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.canonicalLabel AS label')
            ->orderBy('label', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $rows));
    }
}
