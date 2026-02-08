<?php

namespace App\Controller;

use App\Entity\Report;
use App\Form\ReportType;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/report')]
final class ReportController extends AbstractController
{
    #[Route('/{id}/pdf', name: 'app_report_pdf', methods: ['GET'])]
    public function downloadPdf(Report $report): Response
    {
        // Configure Dompdf
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($pdfOptions);
        
        // Retrieve the HTML generated in our twig file
        $html = $this->renderView('report/pdf.html.twig', [
            'report' => $report
        ]);
        
        // Load HTML to Dompdf
        $dompdf->loadHtml($html);
        
        // (Optional) Setup the paper size and orientation
        $dompdf->setPaper('A4', 'portrait');
        
        // Render the HTML as PDF
        $dompdf->render();
        
        // Generate PDF content
        $output = $dompdf->output();
        
        // Create a Response object
        $response = new Response($output);
        
        // Set headers for PDF download
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="rapport_audit_' . $report->getId() . '.pdf"');

        return $response;
    }

    #[Route(name: 'app_report_index', methods: ['GET'])]
    public function index(Request $request, ReportRepository $reportRepository): Response
    {
        $query = $request->query->get('q');

        if ($query) {
            $reports = $reportRepository->findBySearch($query);
        } else {
            $reports = $reportRepository->findAll();
        }

        return $this->render('report/index.html.twig', [
            'reports' => $reports,
        ]);
    }

    #[Route('/new', name: 'app_report_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $report = new Report();
        $form = $this->createForm(ReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($report);
            $entityManager->flush();

            if ($report->getPriority() === 'Forte') {
                $this->addFlash('danger', 'Attention : Un rapport de priorité FORTE a été ajouté !');
            } else {
                $this->addFlash('success', 'Le rapport a été créé avec succès.');
            }

            return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('report/new.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_report_show', methods: ['GET'])]
    public function show(Report $report): Response
    {
        return $this->render('report/show.html.twig', [
            'report' => $report,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_report_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Report $report, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ReportType::class, $report);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le rapport a été mis à jour avec succès.');

            return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('report/edit.html.twig', [
            'report' => $report,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_report_delete', methods: ['POST'])]
    public function delete(Request $request, Report $report, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$report->getId(), $request->request->get('_token'))) {
            $entityManager->remove($report);
            $entityManager->flush();

            if ($request->isXmlHttpRequest()) {
                $this->addFlash('success', 'Le rapport a été supprimé avec succès.');
                return $this->json(['success' => true, 'message' => 'Le rapport a été supprimé avec succès.']);
            }

            $this->addFlash('success', 'Le rapport a été supprimé avec succès.');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => false, 'message' => 'Token CSRF invalide.'], 400);
        }

        return $this->redirectToRoute('app_report_index', [], Response::HTTP_SEE_OTHER);
    }
    #[Route('/{id}/recommend', name: 'app_report_recommend', methods: ['POST'])]
    public function recommend(Report $report, EntityManagerInterface $entityManager): Response
    {
        $score = $report->getScore();
        $priority = $report->getPriority();
        $description = strtolower($report->getDescription());
        
        // --- COMPONENTS FOR HIGHEST ENTROPY ---
        
        $intros = [
            "L'analyse IA de ce rapport révèle les points suivants :",
            "Voici les observations générées automatiquement pour cet audit :",
            "Basé sur les données du rapport #" . $report->getId() . ", voici nos conseils :",
            "Examen des indicateurs de performance terminé :",
            "Synthèse intelligente des points de contrôle :"
        ];

        $diagnosisOptions = [
            'critical' => [
                "<strong class='text-danger'>Situation Critique</strong> : Le score de $score/100 est bien en dessous des seuils de sécurité.",
                "<strong class='text-danger'>Alerte Majeure</strong> : Nous avons détecté des failles critiques ($score/100) nécessitant une intervention.",
                "<strong class='text-danger'>Risque Élevé</strong> : La conformité ($score/100) est compromise. Une action corrective est prioritaire.",
                "<strong class='text-danger'>Alerte Système</strong> : Score de $score/100. Les protocoles standards ne sont pas respectés."
            ],
            'warning' => [
                "<strong class='text-warning'>Avertissement</strong> : Avec $score/100, la situation est sous contrôle mais fragile.",
                "<strong class='text-warning'>Score Moyen</strong> ($score/100) : Des axes d'amélioration ont été identifiés pour stabiliser l'audit.",
                "<strong class='text-warning'>Note de Vigilance</strong> : Le résultat de $score/100 suggère un besoin de documentation supplémentaire.",
                "<strong class='text-warning'>Diagnostic</strong> : Performance de $score/100. Une surveillance accrue est recommandée."
            ],
            'success' => [
                "<strong class='text-success'>Excellente Performance</strong> : Score de $score/100. Les standards sont parfaitement respectés.",
                "<strong class='text-success'>Validation</strong> : Votre score de $score/100 démontre une gestion rigoureuse des processus.",
                "<strong class='text-success'>Succès</strong> : Conformité totale identifiée ($score/100). Aucun écart majeur détecté.",
                "<strong class='text-success'>Résultat Optimal</strong> : Félicitations pour ce score de $score/100."
            ]
        ];

        $contextAdvice = [
            'sécurité' => [
                "<span class='text-primary font-weight-bold'>Sécurité</span> : Renforcez le chiffrement des logs.",
                "<span class='text-primary font-weight-bold'>Sécurité</span> : Auditez les accès périmétriques.",
                "<span class='text-primary font-weight-bold'>Sécurité</span> : Changez les clés d'accès périodiquement."
            ],
            'accès' => [
                "<span class='text-primary font-weight-bold'>Accès</span> : Vérifiez la hiérarchie des permissions.",
                "<span class='text-primary font-weight-bold'>Accès</span> : Supprimez les comptes inactifs immédiatement.",
                "<span class='text-primary font-weight-bold'>Accès</span> : Activez l'authentification double facteur."
            ],
            'donnée' => [
                "<span class='text-primary font-weight-bold'>Données</span> : Testez l'intégrité des backups.",
                "<span class='text-primary font-weight-bold'>Données</span> : Vérifiez la cohérence des flux entrants.",
                "<span class='text-primary font-weight-bold'>Données</span> : Archivez les historiques de plus de 2 ans."
            ],
            'réseau' => [
                "<span class='text-primary font-weight-bold'>Réseau</span> : Scannez les ports ouverts inutilement.",
                "<span class='text-primary font-weight-bold'>Réseau</span> : Optimisez la latence des points de terminaison.",
                "<span class='text-primary font-weight-bold'>Réseau</span> : Isolez les segments de production."
            ]
        ];

        $priorities = [
            "<span class='text-info font-weight-bold'>Top Priorité</span> : Traitez ce dossier avant la fin de journée.",
            "<span class='text-info font-weight-bold'>Calendrier</span> : Fixez une échéance de remédiation à J+7.",
            "<span class='text-info font-weight-bold'>Équipe</span> : Demandez une contre-expertise à un collègue.",
            "<span class='text-info font-weight-bold'>Technique</span> : Lancez un diagnostic approfondi du sous-système."
        ];

        $closings = [
            "Nous restons en veille sur ce dossier.",
            "Action recommandée suite à cette analyse.",
            "Fin de la synthèse IA.",
            "Bonne mise en œuvre des recommandations.",
            "IA MindAudit à votre service."
        ];

        // --- SELECTION LOGIC ---
        
        $content = "<strong class='text-dark'>Analyse IA (" . date('H:i:s') . ")</strong>\n\n";
        
        // 1. Intro
        $content .= $intros[array_rand($intros)] . "\n\n";
        
        // 2. Diagnosis
        $cat = $score < 50 ? 'critical' : ($score < 80 ? 'warning' : 'success');
        $content .= $diagnosisOptions[$cat][array_rand($diagnosisOptions[$cat])] . "\n\n";
        
        // 3. Contextual (if any)
        $hasContext = false;
        foreach ($contextAdvice as $key => $advices) {
            if (str_contains($description, $key)) {
                if (!$hasContext) { $content .= "<strong>Focus spécifique :</strong>\n"; $hasContext = true; }
                $content .= "- " . $advices[array_rand($advices)] . "\n";
            }
        }
        if ($hasContext) $content .= "\n";

        // 4. Priority / Action
        $content .= $priorities[array_rand($priorities)] . "\n\n";

        // 5. Closing
        $content .= "<em class='text-muted'>— " . $closings[array_rand($closings)] . "</em>";

        $recommendation = new \App\Entity\Recommendation();
        $recommendation->setContent($content);
        $recommendation->setReport($report);
        
        $entityManager->persist($recommendation);
        $entityManager->flush();

        $this->addFlash('success', 'Une nouvelle recommandation stylisée a été générée.');

        return $this->redirectToRoute('app_report_show', ['id' => $report->getId()]);
    }
}
