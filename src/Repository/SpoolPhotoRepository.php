<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SpoolPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpoolPhoto>
 */
final class SpoolPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpoolPhoto::class);
    }
}
