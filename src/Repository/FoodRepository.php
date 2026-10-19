<?php

namespace App\Repository;

use App\Entity\Food;
use App\Entity\User;
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
    /// <summary>Searches shared foods and personal foods owned by the requesting account.</summary>
    /// <param name="query">Food name or brand fragment.</param>
    /// <param name="limit">Maximum number of results.</param>
    /// <param name="offset">Number of matching results to skip.</param>
    /// <param name="user">Account allowed to see its own personal foods.</param>
    /// <returns>Accessible matching foods.</returns>
    public function searchByNameOrBrand(string $query, int $limit = 20, int $offset = 0, ?User $user = null): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->where('LOWER(f.name) LIKE :q OR LOWER(f.brand) LIKE :q')
            ->andWhere('f.user IS NULL OR f.user = :user')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . $normalized . '%')
            ->orderBy('f.name', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
