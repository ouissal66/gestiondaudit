<?php

namespace App\Controller;

use App\Entity\Report;
use App\Form\ReportType;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/report')]
final class UserReportController extends AbstractController
{
    #[Route(name: 'app_user_report_index', methods: ['GET'])]
    public function index(ReportRepository $reportRepository): Response
    {
        return $this->render('user_report/index.html.twig', [
            'reports' => $reportRepository->findBy(['source' => 'user'], ['id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_user_report_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
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

            $this->addFlash('success', 'Le rapport a été créé avec succès.');

            return $this->redirectToRoute('app_user_report_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user_report/new.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_report_show', methods: ['GET'])]
    public function show(Report $report): Response
    {
        if ($report->getSource() !== 'user') {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à ce rapport.');
        }

        return $this->render('user_report/show.html.twig', [
            'report' => $report,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_report_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Report $report, EntityManagerInterface $entityManager): Response
    {
        if ($report->getSource() !== 'user') {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier ce rapport.');
        }

        $form = $this->createForm(ReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le rapport a été mis à jour avec succès.');

            return $this->redirectToRoute('app_user_report_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user_report/edit.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_report_delete', methods: ['POST'])]
    public function delete(Request $request, Report $report, EntityManagerInterface $entityManager): Response
    {
        if ($report->getSource() !== 'user') {
            throw $this->createAccessDeniedException('Vous ne pouvez pas supprimer ce rapport.');
        }

        if ($this->isCsrfTokenValid('delete'.$report->getId(), $request->request->get('_token'))) {
            $entityManager->remove($report);
            $entityManager->flush();
            $this->addFlash('success', 'Le rapport a été supprimé avec succès.');
        }

        return $this->redirectToRoute('app_user_report_index', [], Response::HTTP_SEE_OTHER);
    }
}
