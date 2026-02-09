<?php

namespace App\Repository;

use App\Entity\Recommendation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recommendation>
 *
 * @method Recommendation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Recommendation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Recommendation[]    findAll()
 * @method Recommendation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RecommendationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recommendation::class);
    }

    /**
     * Finds recommendations matching the search term in content or associated report title.
     */
    public function findBySearch(string $query): array
    {
        return $this->createQueryBuilder('rec')
            ->leftJoin('rec.report', 'r')
            ->andWhere('rec.content LIKE :query OR r.title LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('rec.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds recommendations for reports with a specific status.
     * @param string $status The status of the report (e.g., 'Validé')
     * @return Recommendation[]
     */
    public function findByReportStatus(string $status, int $limit = 3): array
    {
        return $this->createQueryBuilder('rec')
            ->join('rec.report', 'r')
            ->andWhere('r.status = :status')
            ->setParameter('status', $status)
            ->orderBy('rec.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
