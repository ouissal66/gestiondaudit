<?php

namespace App\Controller;

use App\Repository\ReportRepository;
use App\Repository\RecommendationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserDashboardController extends AbstractController
{
    #[Route('/user/dashboard', name: 'app_user_dashboard')]
    public function index(
        Request $request,
        ReportRepository $reportRepository
    ): Response {
        // reuse the same aggregation logic or simplify it for the user
        $data = $this->aggregateDashboardData($request, $reportRepository);
        
        // Fetch unique types, statuses, and priorities for the filter dropdowns (USER only)
        $types = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.type')
            ->where('r.type IS NOT NULL')
            ->andWhere('r.source = :source')
            ->setParameter('source', 'user')
            ->getQuery()->getResult();
        
        $statuses = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.status')
            ->where('r.status IS NOT NULL')
            ->andWhere('r.source = :source')
            ->setParameter('source', 'user')
            ->getQuery()->getResult();
            
        $priorities = $reportRepository->createQueryBuilder('r')
            ->select('DISTINCT r.priority')
            ->where('r.priority IS NOT NULL')
            ->andWhere('r.source = :source')
            ->setParameter('source', 'user')
            ->getQuery()->getResult();
            
        return $this->render('user_dashboard/index.html.twig', array_merge($data, [
            'availableTypes' => array_column($types, 'type'),
            'availableStatuses' => array_column($statuses, 'status'),
            'availablePriorities' => array_column($priorities, 'priority'),
            'allReportsList' => $reportRepository->findBy(['source' => 'user'], ['title' => 'ASC']),
        ]));
    }

    #[Route('/user/dashboard/stats', name: 'app_user_dashboard_stats', methods: ['GET'])]
    public function getStats(
        Request $request,
        ReportRepository $reportRepository
    ): Response {
        $data = $this->aggregateDashboardData($request, $reportRepository);
        return $this->json($data);
    }

    private function aggregateDashboardData(
        Request $request,
        ReportRepository $reportRepository
    ): array {
        $query = $request->query->get('q');
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');

        $createFilteredQB = function($repository, $alias) use ($query, $type, $status, $priority) {
            $qb = $repository->createQueryBuilder($alias);
            
            // Filter for USER reports only
            $qb->andWhere($alias . '.source = :sourceUser')
               ->setParameter('sourceUser', 'user');

            if ($query) {
                $qb->andWhere($alias . '.title LIKE :q OR ' . $alias . '.description LIKE :q')
                   ->setParameter('q', '%' . $query . '%');
            }
            if ($type) {
                $qb->andWhere($alias . '.type = :type')->setParameter('type', $type);
            }
            if ($status) {
                $qb->andWhere($alias . '.status = :status')->setParameter('status', $status);
            }
            if ($priority) {
                $qb->andWhere($alias . '.priority = :priority')->setParameter('priority', $priority);
            }

            return $qb;
        };

        $reportsByType = $createFilteredQB($reportRepository, 'r')
            ->select('r.type, COUNT(r.id) as count')
            ->groupBy('r.type')
            ->getQuery()->getResult();

        $reportsByStatus = $createFilteredQB($reportRepository, 'r')
            ->select('r.status, COUNT(r.id) as count')
            ->groupBy('r.status')
            ->getQuery()->getResult();

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

        $totalReports = count($allReports);
        $criticalCount = 0;
        foreach ($reportsByStatus as $s) {
            if ($s['status'] === 'Critique') $criticalCount = $s['count'];
        }
        $avgScore = ($totalReports > 0) ? round(array_sum(array_column($allReports, 'score')) / $totalReports, 1) : 0;

        return [
            'reportsByType' => $reportsByType,
            'reportsByStatus' => $reportsByStatus,
            'scoresByMonth' => $scoresByMonth,
            'totalReports' => $totalReports,
            'criticalCount' => $criticalCount,
            'avgScore' => $avgScore,
        ];
    }
}
