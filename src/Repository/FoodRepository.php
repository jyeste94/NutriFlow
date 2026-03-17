<?php

namespace App\Repository;

use App\Entity\Food;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Food>
 */
class FoodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Food::class);
    }

    /**
     * @return Food[]
     */
    public function searchByNameOrBrand(string $query, int $limit = 20, int $offset = 0): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('LOWER(f.name) LIKE :q OR LOWER(f.brand) LIKE :q')
            ->setParameter('q', '%' . $normalized . '%')
            ->orderBy('f.name', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
