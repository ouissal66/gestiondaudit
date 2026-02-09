<?php

namespace App\Controller;

use App\Entity\Report;
use App\Form\ReportType;
use App\Repository\RecommendationRepository;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, ReportRepository $reportRepository, RecommendationRepository $recommendationRepository, EntityManagerInterface $entityManager): Response
    {
        $report = new Report();
        $report->setStatus('En cours');
        $report->setPriority('Moyenne');
        $report->setScore(0);
        $report->setSource('user');
        
        $form = $this->createForm(ReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($report);
            $entityManager->flush();

            $this->addFlash('success', 'Votre rapport a été créé avec succès.');

            return $this->redirectToRoute('app_user_dashboard');
        }

        $latestReports = $reportRepository->findBy(['status' => 'Validé'], ['id' => 'DESC'], 3);
        $latestRecommendations = $recommendationRepository->findByReportStatus('Validé', 3);

        return $this->render('home/index.html.twig', [
            'latest_reports' => $latestReports,
            'latest_recommendations' => $latestRecommendations,
            'report_form' => $form->createView(),
        ]);
    }
}
