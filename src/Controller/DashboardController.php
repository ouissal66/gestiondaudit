<?php

namespace App\Controller;

use App\Repository\ReportRepository;
use App\Repository\RecommendationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        Request $request,
        ReportRepository $reportRepository,
        RecommendationRepository $recommendationRepository
    ): Response {
        $data = $this->aggregateDashboardData($request, $reportRepository, $recommendationRepository);
        
        // Fetch unique types, statuses, and priorities for the filter dropdowns
        $types = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.type')
            ->where('r.type IS NOT NULL')
            ->getQuery()->getResult();
        
        $statuses = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.status')
            ->where('r.status IS NOT NULL')
            ->getQuery()->getResult();
            
        $priorities = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.priority')
            ->where('r.priority IS NOT NULL')
            ->getQuery()->getResult();
            
        return $this->render('dashboard/index.html.twig', array_merge($data, [
            'availableTypes' => array_column($types, 'type'),
            'availableStatuses' => array_column($statuses, 'status'),
            'availablePriorities' => array_column($priorities, 'priority'),
            'allReportsList' => $reportRepository->findBy([], ['title' => 'ASC']),
        ]));
    }

    #[Route('/dashboard/stats', name: 'app_dashboard_stats', methods: ['GET'])]
    public function getStats(
        Request $request,
        ReportRepository $reportRepository,
        RecommendationRepository $recommendationRepository
    ): Response {
        $data = $this->aggregateDashboardData($request, $reportRepository, $recommendationRepository);
        return $this->json($data);
    }

    private function aggregateDashboardData(
        Request $request,
        ReportRepository $reportRepository,
        RecommendationRepository $recommendationRepository
    ): array {
        $query = $request->query->get('q');
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');

        // Factory function for QueryBuilder with shared filters
        $createFilteredQB = function($repository, $alias) use ($query, $type, $status, $priority) {
            $qb = $repository->createQueryBuilder($alias);
            $reportAlias = ($repository instanceof \App\Repository\RecommendationRepository) ? 'r' : $alias;
            
            if ($repository instanceof \App\Repository\RecommendationRepository) {
                $qb->join($alias . '.report', 'r');
            }

            if ($query) {
                $qb->andWhere($reportAlias . '.title LIKE :q OR ' . $reportAlias . '.description LIKE :q')
                   ->setParameter('q', '%' . $query . '%');
            }
            if ($type) {
                $qb->andWhere($reportAlias . '.type = :type')->setParameter('type', $type);
            }
            if ($status) {
                $qb->andWhere($reportAlias . '.status = :status')->setParameter('status', $status);
            }
            if ($priority) {
                $qb->andWhere($reportAlias . '.priority = :priority')->setParameter('priority', $priority);
            }

            return $qb;
        };

        // 1. Histogram: Reports by Type
        $reportsByType = $createFilteredQB($reportRepository, 'r')
            ->select('r.type, COUNT(r.id) as count')
            ->groupBy('r.type')
            ->getQuery()->getResult();

        // 2. Pie Chart: Reports by Status
        $reportsByStatus = $createFilteredQB($reportRepository, 'r')
            ->select('r.status, COUNT(r.id) as count')
            ->groupBy('r.status')
            ->getQuery()->getResult();

        // 3. Performance Curve
        $allReports = $createFilteredQB($reportRepository, 'r')
            ->select("r.createdAt, r.score")
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()->getResult();

        $scoresByMonthRaw = [];
        foreach ($allReports as $report) {
            $month = $report['createdAt']->format('Y-m');
            if (!isset($scoresByMonthRaw[$month])) {
                $scoresByMonthRaw[$month] = ['sum' => 0, 'count' => 0];
            }
            $scoresByMonthRaw[$month]['sum'] += $report['score'];
            $scoresByMonthRaw[$month]['count']++;
        }

        $scoresByMonth = [];
        foreach ($scoresByMonthRaw as $month => $data) {
            $scoresByMonth[] = ['month' => $month, 'avg_score' => round($data['sum'] / $data['count'], 1)];
        }
        $scoresByMonth = array_slice($scoresByMonth, -6);

        // 4. Bar Chart: Recommendations by Priority
        $recommendationsByPriority = $createFilteredQB($recommendationRepository, 'rec')
            ->select('r.priority, COUNT(rec.id) as count')
            ->groupBy('r.priority')
            ->getQuery()->getResult();

        // 5. Summary Stats
        $totalReports = count($allReports);
        $totalRecommendations = array_sum(array_column($recommendationsByPriority, 'count'));
        $criticalCount = 0;
        foreach ($reportsByStatus as $s) {
            if ($s['status'] === 'Critique') $criticalCount = $s['count'];
        }
        $avgScore = ($totalReports > 0) ? round(array_sum(array_column($allReports, 'score')) / $totalReports, 1) : 0;

        return [
            'reportsByType' => $reportsByType,
            'reportsByStatus' => $reportsByStatus,
            'scoresByMonth' => $scoresByMonth,
            'recommendationsByPriority' => $recommendationsByPriority,
            'totalReports' => $totalReports,
            'totalRecommendations' => $totalRecommendations,
            'criticalCount' => $criticalCount,
            'avgScore' => $avgScore,
        ];
    }
}
